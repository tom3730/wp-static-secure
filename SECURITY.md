# Security Policy

## Security model

WP Static Secure is designed around reducing public attack surface by separating WordPress authoring from static public delivery.

The project does **not** claim that WordPress, PHP, plugins, themes, form APIs, deployment infrastructure, or generated websites are immune to compromise.

The recommended model is:

1. WordPress is restricted from anonymous public access.
2. Public visitors receive generated static artifacts.
3. Unknown public paths terminate at the CDN/static web server rather than being rewritten into WordPress.
4. Dynamic functionality is exposed only through narrow, explicit APIs.
5. WordPress and all private infrastructure continue to receive normal security maintenance.

See [ARCHITECTURE.md](ARCHITECTURE.md) for trust boundaries.

## Public form submission boundary

The MVP form transport is an explicit exception to static-only delivery. It is not a general application gateway.

A conforming deployment exposes only the configured form-submission route and must preserve these controls before untrusted data reaches submission storage:

- accept only `POST` requests using the supported form content type;
- enforce a bounded request size;
- require an exact configured HTTP(S) `Origin`;
- reject malformed, duplicate, unknown, or non-scalar form fields;
- route only known form identifiers through their registered schema/adapter;
- apply deployment-level request rate limiting before the core transport;
- never add arbitrary request forwarding to WordPress.

Generated forms must not contain API secrets, private credentials, privileged WordPress nonces, or administrative endpoints. The core HTTP transport intentionally returns coarse validation errors and does not enable wildcard CORS.

If a deployment stores submissions in the WordPress-managed Inbox, the trusted runtime may reach the submission-storage boundary, but anonymous public traffic must still not gain general HTTP access to WordPress.

## Recommended architecture incidents

If a site is compromised while following the documented recommended architecture, we want to understand why.

Maintainers intend to prioritize technically reproducible reports involving compromise of the recommended architecture, including cases where:

- public traffic unexpectedly reached the restricted WordPress application;
- generated static output introduced an exploitable security defect;
- a project-provided dynamic API enabled unauthorized access or modification;
- a project deployment mechanism exposed credentials or private data;
- project-generated behavior materially violated a documented security boundary.

This is an investigation commitment, **not a warranty, insurance policy, incident-response SLA, or promise of compensation**.

> If our recommended architecture fails, we want to know why.

## Deployment synchronization safety

Deployment deletion is a security-sensitive operation. A deployment
implementation must validate a non-empty local build before it can calculate
stale-file deletions. The build must be inside the configured output boundary,
must pass build validation bound to the same canonical root and exact file
snapshot, and must not contain symbolic-link escapes or ambiguous relative
paths.

Deletion planning must also require an explicit target identity and root
identity plus a separately obtained target inventory. The identity must be
checked against the operator-selected target before any deletion set is
computed; it must never be inferred from generated files or untrusted
content. All planned paths must be validated relative paths. A deployment
plan should be inspectable/dry-runnable, deterministic, and separate from the
provider transport so that unsafe plans fail before any remote mutation is
attempted.

An eventual executor MUST reverify the target identity, source hash, target
hash or provider version, and no-follow containment immediately before every
mutation. It must abort on any mismatch or inability to prove the target
boundary; a plan is not authorization to delete a different target.

## Security record

The project intends to maintain a transparent security record as it matures.

Useful metrics may include:

- confirmed compromises attributable to project defects;
- published security advisories;
- affected versions;
- time to remediation;
- root-cause/postmortem links;
- changes to the recommended architecture resulting from incidents.

A zero count should never be represented as proof of perfect security.

## Reporting a vulnerability

Do **not** publish exploitable vulnerability details in a public issue.

Use the repository's [private vulnerability reporting form](https://github.com/tom3730/wp-static-secure/security/advisories/new). This form accepts a private report only while GitHub private vulnerability reporting is enabled. If the form is unavailable, open a public issue containing only the title “Private security contact requested” and no vulnerability or exploit details; a maintainer can then enable or establish the private reporting channel. This fallback is coordination only, not a place to disclose the report.

A useful report includes:

- affected version/commit;
- deployment topology;
- reproduction steps;
- expected and actual security boundary;
- impact;
- proof of concept with sensitive values removed;
- suggested mitigation, if known.

## Scope priorities

Highest priority:

- remote code execution;
- authentication/authorization bypass;
- access from the public static surface into private WordPress;
- arbitrary file write/read;
- credential/secret exposure;
- stored or reflected injection introduced by generated output or project APIs;
- form submission access-control failures;
- supply-chain compromise of released artifacts.

Other security issues are still welcome and will be triaged based on impact and exploitability.

## Supported versions

The project is currently pre-alpha. Only the latest tagged pre-release and the latest code on the default branch should be assumed to receive security fixes; older pre-releases are unsupported.

## Safe harbor intent

Good-faith security research that avoids privacy violations, service disruption, data destruction, and access beyond what is necessary to demonstrate a vulnerability is welcome.

A more formal coordinated-disclosure and safe-harbor policy should be adopted before the first stable release.
