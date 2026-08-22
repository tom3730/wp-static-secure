# WP Static Secure

> Keep WordPress for editing. Remove WordPress from the public web.

WP Static Secure is an open-source publishing system for WordPress that treats WordPress as an **authoring environment**, not as the public web server.

It publishes a static version of a WordPress site, minimizes the public attack surface, and provides the dynamic pieces that static sites actually need — starting with forms and a submission inbox.

## Why this exists

WordPress is excellent as a CMS, but a conventional deployment exposes a large application stack to anonymous Internet traffic:

```text
Internet
   ↓
Web server
   ↓
PHP
   ↓
WordPress Core
   ↓
Theme + Plugins
   ↓
Database
```

Even requests for nonexistent paths may reach WordPress through rewrite rules. Internet-wide scanners continuously probe paths such as `/wp-login.php`, `/xmlrpc.php`, vulnerable plugin paths, `.env`, and arbitrary URLs. A site can spend application and database resources answering traffic that should have been a cheap 404.

There is a second problem: WordPress security often depends on administrators keeping core, themes, and plugins patched perfectly. Real users do not always do that.

Our preferred architecture is different:

```text
Private / Restricted                       Public Internet
────────────────────                       ───────────────
WordPress                                   CDN / Web Server
├─ wp-admin                                 ├─ HTML
├─ PHP                                      ├─ CSS
├─ Database        ── publish ───────────→  ├─ JavaScript
├─ Themes                                   ├─ Images
└─ Plugins                                  └─ Other static assets
                                                │
                                                └─ explicit dynamic APIs
                                                   (forms, etc.)
```

Public traffic should not need to reach WordPress at all.

## Principles

1. **Public traffic should not reach WordPress.**
2. **WordPress is an authoring system, not a web server.**
3. **Publishing should produce static assets.**
4. **Dynamic features should be explicit and minimal.**
5. **Forms are submissions, not emails.**
6. **Security should come from architecture, not perfect user discipline.**
7. **The open-source edition should be genuinely useful, not a crippled funnel for a paid edition.**

## Forms are submissions, not emails

Static publishing often turns forms into someone else's problem: configure SMTP, SES, DNS records, a serverless function, or a third-party form SaaS.

We want forms to work as a first-class part of the publishing architecture.

```text
Static form
    ↓ POST
Submission API
    ↓
Inbox
├─ New
├─ In progress
├─ Done
└─ Spam
```

Email, Slack, Teams, webhooks, browser push, and similar mechanisms are notification channels. They are not the submission itself.

## Security posture

Static publishing does not make WordPress magically secure. The private WordPress installation still needs sensible access control, patching, backups, and operational security.

The goal is to change the failure mode: a forgotten plugin update should not automatically mean that the vulnerable PHP application is directly reachable by the entire Internet.

We intend to document a recommended architecture and track security incidents that occur while using it. If the recommended architecture fails, we want to understand why.

See [SECURITY.md](SECURITY.md).

## Initial scope

The first useful release focuses on content-oriented WordPress sites such as corporate sites, blogs, news sites, landing pages, documentation, and recruiting sites.

### Implemented MVP core

The current pre-alpha implementation includes the core local publishing and form-submission path:

- configuration for authoring origin, public origin, and local output directory;
- normalized URL discovery with configured crawl-scope boundaries;
- HTTP page fetching and deterministic static HTML persistence;
- discovery and export of common HTML/CSS assets, including responsive `srcset` references;
- rewriting of authoring-site URLs to the configured public site;
- deterministic build validation for private-origin leaks, broken local references, unsupported WordPress dynamic paths, and supported-form output;
- an explicit form-adapter boundary with opt-in generic HTML and conservative Contact Form 7 adapters;
- rewriting supported forms to a configured absolute HTTP(S) submission endpoint;
- a bounded `POST` / `application/x-www-form-urlencoded` submission transport with request-size and exact-Origin validation;
- schema-constrained form submission and durable submission storage;
- a WordPress-managed Submission Inbox with authenticated administration and submission status changes;
- CLI-oriented build validation and automated PHPUnit / WordPress activation coverage.

See [ARCHITECTURE.md](ARCHITECTURE.md) and [FORM_SUBMISSIONS.md](FORM_SUBMISSIONS.md) for the implemented boundaries and current form behavior.

### Remaining before a broadly promoted release

The MVP core being implemented does **not** mean the project is production-ready. The first tagged build is intended to be `0.1.0-alpha.1`, an evaluation-only pre-alpha. Before a broadly promoted release, remaining work includes hardening, realistic end-to-end deployment validation, clearer installation/operator workflows, a dedicated private vulnerability-reporting channel, and explicit documentation of supported compatibility cases.

Deployment-specific controls also remain operator responsibilities where documented, including restricting WordPress itself and applying rate limiting in front of the public form transport.

### Not an initial goal

- WooCommerce checkout or carts.
- Logged-in public experiences.
- Membership sites.
- Arbitrary AJAX-heavy WordPress applications.
- Perfect compatibility with every plugin on day one.

## Longer-term direction

Potential capabilities include incremental builds, WP-CLI, S3-compatible deployment, rsync/SFTP, Git-based publishing, CDN invalidation, static search, additional form adapters, webhooks, integrity manifests, deployment verification, and security records.

The project should remain modular: static publishing is the core; dynamic capabilities are explicit adapters.

## Status

**Pre-alpha.** The initial MVP core is implemented and covered by automated tests, including the local static build pipeline, build validation, the opt-in generic form flow, the bounded public form submission transport, and the WordPress Submission Inbox.

The project is still under active development and should not yet be presented as production-ready or broadly compatible with arbitrary WordPress sites, plugins, themes, or hosting environments. Compatibility claims should be based on repeatable tests or documented verification.

## License

Licensed under the [Apache License 2.0](LICENSE).
