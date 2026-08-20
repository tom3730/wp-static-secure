# AGENTS.md

This repository is intended to be developed heavily with coding agents. Agents must preserve the project's architecture and security intent rather than optimizing only for feature completion.

## Project mission

Keep WordPress for editing. Remove WordPress from the public web.

The public delivery path should be static by default. Dynamic capabilities must be explicit, minimal, and independently testable.

## Core principles

1. Public traffic should not reach WordPress.
2. WordPress is an authoring system, not a web server.
3. Publishing should produce static assets.
4. Dynamic features should be explicit and minimal.
5. Forms are submissions, not emails.
6. Security should come from architecture, not perfect user discipline.
7. Do not introduce artificial feature gates into the open-source core.

Read `README.md`, `ARCHITECTURE.md`, and `SECURITY.md` before making architectural changes.

## Development strategy

- Prefer small, reviewable changes.
- Implement one issue or one coherent capability per PR.
- Do not perform broad refactors while implementing unrelated features.
- Avoid speculative abstractions unless at least two concrete use cases justify them.
- Keep the static builder independent from deployment transports.
- Keep form-plugin compatibility behind adapters.
- Do not couple core logic to AWS, Cloudflare, GitHub, Netlify, or any specific hosting provider.
- Prefer deterministic output and pure/testable transformation functions where practical.

## Security rules

- Never proxy arbitrary public requests to WordPress.
- Never place private credentials, API secrets, nonces intended for private use, or administrative endpoints in generated static artifacts.
- Treat URL parsing and normalization as security-sensitive code.
- Crawl only within explicitly configured scope.
- Defend against path traversal when mapping URLs to local output paths.
- Do not allow remote content to escape the configured output directory.
- Treat HTML rewriting, form handling, redirects, and deployment deletion behavior as security-sensitive.
- Dynamic APIs must validate and constrain input.
- Do not make CORS permissive by default without an explicit documented reason.
- Do not assume email delivery is required for form correctness.

## Initial implementation priorities

The implementation should progress in this order unless an issue explicitly changes it:

1. minimal WordPress plugin/bootstrap and test scaffolding;
2. configuration model for authoring URL, public URL, and output path;
3. URL discovery and normalization;
4. page fetching;
5. static HTML persistence;
6. asset discovery/download;
7. URL rewriting;
8. deterministic build validation;
9. form adapter interface;
10. first form adapter and submission storage/inbox.

Do not start cloud deployment integrations before the local static build is reliable.

## Testing expectations

Every meaningful behavior should have an automated test when reasonably possible.

At minimum, tests should cover:

- URL normalization;
- same-origin/scope decisions;
- URL-to-filesystem mapping;
- query-string handling decisions;
- internal URL rewriting;
- `srcset` parsing/rewriting;
- asset discovery;
- output path traversal prevention;
- deterministic output for fixed inputs;
- form adapter detection and rewrite behavior once forms are implemented.

Prefer fixtures representing real WordPress HTML over mocks that hide parser behavior.

For bugs, add a regression test that fails before the fix when practical.

## Compatibility

Do not claim compatibility with a WordPress plugin, theme, page builder, hosting provider, or deployment target unless there is a repeatable test or documented verification for it.

Compatibility should be earned from reproducible cases.

## PR requirements

A PR should state:

- what problem it solves;
- why the chosen approach fits the architecture;
- files/components changed;
- tests run and results;
- security-relevant behavior or trust-boundary changes;
- known limitations or follow-up work.

If runtime testing was impossible, say so explicitly rather than implying success.

## Documentation

Update architecture/security documentation when a change alters:

- a trust boundary;
- public reachability;
- data storage;
- secrets handling;
- a dynamic API;
- deployment behavior;
- the supported threat model.

## Things to avoid

- Building a generic headless CMS framework.
- Reimplementing the entire WordPress runtime in JavaScript.
- Adding WooCommerce/application compatibility before basic publishing works.
- Making SMTP/SES a mandatory dependency.
- Building a generic public-to-WordPress proxy as a shortcut for dynamic features.
- Silently ignoring failed resources during builds; failures should be visible and classifiable.

## Definition of done for a task

A task is done when the requested behavior is implemented, tests pass or limitations are documented, generated behavior respects the trust boundaries, documentation is updated when necessary, and the diff contains no unrelated changes.
