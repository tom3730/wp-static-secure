# WP Staic Secure

> Keep WordPress for editing. Remove WordPress from the public web.

WP Staic Secure is an open-source publishing system for WordPress that treats WordPress as an **authoring environment**, not as the public web server.

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

The first useful release should focus on content-oriented WordPress sites such as corporate sites, blogs, news sites, landing pages, documentation, and recruiting sites.

### MVP

- Discover public WordPress URLs.
- Fetch and export pages as static HTML.
- Download required CSS, JavaScript, images, fonts, PDFs, and related assets.
- Rewrite internal URLs for the public static site.
- Handle common responsive image references such as `srcset`.
- Produce a deterministic local output directory.
- Detect supported WordPress forms.
- Convert supported forms to static-compatible submissions.
- Store submissions independently of email delivery.
- Provide a WordPress-admin Inbox for submissions.
- Provide a CLI-friendly architecture suitable for automation.

### Not an initial goal

- WooCommerce checkout or carts.
- Logged-in public experiences.
- Membership sites.
- Arbitrary AJAX-heavy WordPress applications.
- Perfect compatibility with every plugin on day one.

## Longer-term direction

Potential capabilities include incremental builds, WP-CLI, S3-compatible deployment, rsync/SFTP, Git-based publishing, CDN invalidation, static search, form adapters, webhooks, integrity manifests, deployment verification, and security records.

The project should remain modular: static publishing is the core; dynamic capabilities are explicit adapters.

## Status

**Pre-alpha.** Architecture and MVP are being defined before implementation.

## License

A permissive/open-source license will be selected before the first public release. Do not assume a license until a `LICENSE` file is committed.
