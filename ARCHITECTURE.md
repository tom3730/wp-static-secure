# Architecture

## Goal

WP Static Secure separates the WordPress authoring environment from the public delivery environment.

The security objective is not to claim that WordPress becomes invulnerable. The objective is to ensure that anonymous public traffic does not normally execute WordPress, PHP, themes, plugins, or database queries.

## Recommended topology

```text
                         PRIVATE / RESTRICTED
                    ┌──────────────────────────┐
                    │ WordPress                │
                    │ ├─ wp-admin              │
                    │ ├─ PHP                   │
                    │ ├─ Database              │
                    │ ├─ Themes / Plugins      │
                    │ ├─ Static Builder        │
                    │ └─ Submission Inbox      │
                    └────────────┬─────────────┘
                                 │ publish
                                 │
                                 v
PUBLIC INTERNET         ┌──────────────────────┐
Users ────────────────→ │ Static Delivery      │
Attack scanners ──────→ │ CDN / object store   │
                        │ or static web server │
                        └──────────┬───────────┘
                                   │
                                   │ explicit dynamic request
                                   v
                        ┌──────────────────────┐
                        │ Dynamic API Surface  │
                        │ initially: forms     │
                        └──────────┬───────────┘
                                   │
                                   v
                        ┌──────────────────────┐
                        │ Submission Storage   │
                        └──────────────────────┘
```

## Trust boundaries

### 1. Public static delivery

This is the primary Internet-facing surface.

It SHOULD serve only generated static artifacts and explicit static responses such as 404 pages.

A request for an unknown WordPress path such as `/wp-login.php`, `/xmlrpc.php`, `/wp-admin/`, a random plugin exploit path, or an arbitrary nonexistent URI SHOULD NOT be proxied to the private WordPress installation.

### 2. WordPress authoring environment

WordPress remains a privileged application and MUST still be maintained securely.

Recommended controls include:

- private network access, VPN, Zero Trust access, IP allowlisting, or equivalent restrictions;
- TLS;
- normal WordPress/core/plugin/theme patching;
- strong authentication;
- backups;
- least-privilege database and filesystem permissions.

The project should reduce exposure, not encourage neglect.

### 3. Dynamic API surface

Dynamic capabilities are exceptions to the static model and therefore need explicit boundaries.

The initial dynamic capability is form submission.

A dynamic adapter MUST expose only the minimum operations needed for its feature. It MUST NOT become a generic proxy into WordPress.

The MVP public form transport accepts only bounded `POST` requests using `application/x-www-form-urlencoded`, forwards the request `Origin` into exact-origin validation, and then delegates only to registered form schemas through `SubmissionEndpoint`. Unsupported methods or media types, oversized or malformed bodies, unknown forms, and unknown fields are rejected before persistence.

Rate limiting belongs immediately in front of this transport as a deployment control. The transport does not register a public WordPress REST/AJAX route and does not provide arbitrary forwarding to WordPress. A trusted runtime may write to the configured submission-storage boundary, including the WordPress-managed Inbox store, without making the WordPress HTTP application publicly reachable.

## Static build pipeline

The core builder should be deterministic and decomposed into stages:

```text
Discover URLs
    ↓
Fetch documents
    ↓
Parse HTML
    ↓
Discover assets and linked pages
    ↓
Normalize URLs
    ↓
Rewrite public URLs
    ↓
Persist static artifacts
    ↓
Validate output
```

Each stage should be independently testable.

### Discovery

Initial discovery sources may include:

- WordPress-generated sitemap indexes and sitemaps;
- links discovered while crawling rendered public pages;
- explicit seed URLs;
- WordPress APIs where useful.

Discovery must deduplicate normalized URLs and stay within configured origin/scope boundaries.

### Asset handling

The builder should support at minimum:

- stylesheet and script URLs;
- images;
- `srcset` candidates;
- fonts referenced by CSS;
- CSS `url(...)` resources;
- PDFs and ordinary downloadable assets;
- favicons and common metadata assets.

### URL rewriting

The authoring URL and published URL may differ.

Example:

```text
Authoring: https://wp.internal.example
Public:    https://www.example.com
```

Generated HTML and applicable assets must refer to the configured public URL where absolute URLs are required.

Rewriting needs explicit tests for canonical URLs, Open Graph metadata, internal links, assets, feeds/sitemaps where supported, and responsive image attributes.

## Forms

The form architecture follows one rule:

> Forms are submissions, not emails.

A form submission is durable application data. Email or chat notification is optional delivery metadata.

### Initial flow

```text
Static page
   ↓ POST
Submission HTTP transport
   ↓ method / content-type / size / form decoding
Submission endpoint
   ↓ exact Origin / form schema validation
Submission store
   ↓
Inbox
   ├─ new
   ├─ in_progress
   ├─ done
   └─ spam
```

### MVP HTTP transport

The generated form action points to an explicitly configured absolute HTTP(S) submission URL. The public runtime maps that request into `SubmissionHttpTransport`, which is intentionally provider-neutral and has no network client, WordPress route registration, or generic RPC behavior.

The transport accepts only `POST` plus `application/x-www-form-urlencoded`, enforces a configurable request-body byte limit, rejects ambiguous/malformed URL-encoded input, and passes the browser `Origin` unchanged to `SubmissionEndpoint`. Accepted submissions are persisted before a success response is returned.

Deployment-specific rate limiting MUST run before the transport. CORS MUST NOT be wildcarded by default; ordinary HTML form POSTs do not require wildcard CORS. If JavaScript access is added, responses may expose CORS headers only for explicitly allowed origins.

### MVP WordPress Inbox storage

The MVP WordPress-managed system of record is a dedicated submission table rather than ordinary posts or post meta. It stores the normalized form identifier and fields, submission status, and UTC receipt time. Request metadata such as IP address or User-Agent is not collected by default.

The WordPress admin Inbox is a private authoring capability. Viewing and status changes require an administrative capability, and mutations require WordPress nonces. The Inbox does not add an anonymous REST route, AJAX action, or other public WordPress endpoint.

This storage choice does not change the core dynamic API rule: public form submission must remain a narrow explicit capability and must not become a generic proxy into WordPress. The store is an implementation of the submission-storage boundary, not authorization for arbitrary public-to-WordPress connectivity.

### Form adapters

Form-plugin compatibility should use an adapter boundary rather than embedding plugin-specific behavior throughout the core.

Conceptually:

```text
FormAdapter
├─ detect()
├─ extract_schema()
├─ rewrite()
└─ validate_submission()
```

Initial adapters should be deliberately limited. A generic HTML form and Contact Form 7 are likely first targets, but implementation should be driven by tests and issues rather than premature compatibility claims.

## Deployment

The static builder and deployment transports should remain separate.

```text
build → dist/
            ├─ local filesystem
            ├─ S3-compatible storage
            ├─ rsync / SFTP
            └─ Git-based deployment
```

The MVP only needs deterministic local output. Remote deployment belongs behind an interface so it can be added without coupling the crawler to a provider.

Before a transport is allowed to synchronize a target, the core can produce a
provider-neutral deployment plan from a `ValidatedBuild`, an explicit
`DeploymentTarget`, and a separately obtained `DeploymentInventory`. A target
contains only narrow ASCII identity values for the selected destination and
its root; it does not contain or enumerate a local filesystem. The filesystem
scanner is a separate adapter that produces the inventory used by tests and
future local transports.

`ValidatedBuild` binds a successful `BuildValidator` report to one canonical
output root and an exact relative-path/SHA-256 snapshot. It rejects missing,
empty, out-of-boundary, or unsafe builds and the planner rechecks the snapshot
before returning a plan. The planner compares manifests and emits
deterministic `create`, `update`, `keep`, and `delete` operations sorted by
relative path.

The plan is inspectable and dry-runnable data. Plan generation has no upload,
delete, network, or provider side effects. Deletions are calculated only after
the explicit target identity and root identity have been matched to the
inventory, and every operation contains only a validated relative path and
expected hashes. A future S3, rsync, SFTP, or Git executor MUST reverify the
target identity, source hash, target hash/version, and no-follow containment
immediately before mutation, aborting on any mismatch. It must not infer a
target from generated content or turn the planner into a generic
synchronization API.

## Security design principles

1. Default-deny public reachability to WordPress.
2. Static content should fail statically: unknown paths should not wake the application stack.
3. Dynamic capabilities must be explicit, narrow, and independently threat-modeled.
4. Never require secrets in generated public artifacts.
5. Never turn the form endpoint into a general WordPress RPC bridge.
6. Treat input validation, authorization, CSRF/CORS behavior, rate limits, and spam controls as security features, not polish.
7. Deployment artifacts should eventually support integrity verification.

## Non-goals for the first release

The first release is not intended to statically reproduce arbitrary application behavior. In particular:

- WooCommerce carts/checkouts;
- authenticated public sessions;
- membership applications;
- arbitrary AJAX workflows;
- arbitrary REST-dependent themes/plugins;
- every page-builder edge case.

Compatibility should expand from real reproducible cases.

## Architectural success criteria for v0.1

A small representative WordPress site can be configured with a private authoring URL and public URL, exported to a local directory, and served by a plain static web server without public requests being routed to WordPress.

The exported site should preserve ordinary navigation and assets, and its generated output should be testable without a running WordPress instance.
