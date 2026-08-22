# Release acceptance environment

This harness is a disposable operator/acceptance environment for a **published WP Static Secure release**. It is not a production topology and it does not mount repository plugin source into WordPress.

## Prerequisites

- Docker with Docker Compose v2
- GNU Make
- Bash, curl, and OpenSSL
- host port `8080` available on loopback (override `WP_HTTP_PORT` and `WP_SITE_URL` together in the generated `acceptance/.env` if needed)

## Commands

```sh
make acceptance-up
make acceptance-test
make acceptance-down
```

`make acceptance-up` generates `acceptance/.env` with random disposable database/admin credentials, starts MariaDB and WordPress, waits for WordPress, then uses the WP-CLI container to bootstrap the site. The generated file is mode `0600`, ignored by Git, and removed by `make acceptance-down`.

The initial pin is:

- release: `v0.1.0-alpha.1`
- release source commit: `2e5501aafb1fadc54e6a59930bcf6b1934259a4d`
- release asset: `wp-static-secure.zip`
- WordPress image: `wordpress:6.8.2-php8.3-apache`
- WP-CLI image: `wordpress:cli-2.12.0-php8.3`
- MariaDB image: `mariadb:11.4.7`
- theme: Twenty Twenty-Five `1.3`
- Contact Form 7: `6.1.1`

Before downloading the plugin, the release verifier requires the GitHub Release to be a published pre-release with the pinned tag, requires the tag to be annotated and to resolve to the pinned source commit, and requires the Release notes to contain that same source commit plus a valid `Artifact SHA-256`. If GitHub asset metadata also exposes a SHA-256 digest, it must agree with the Release notes. The downloaded bytes must then match that digest. An independently recorded digest may additionally be pinned through `WPS_RELEASE_SHA256`; if supplied, it must also agree with the published Release notes.

The plugin is then installed and activated from `/tmp/wp-static-secure.zip` inside the WP-CLI container. Repository `src/`, `vendor/`, or the development plugin tree are never mounted into WordPress as the plugin under test. The only repository bind mount is the read-only `acceptance/` harness used by WP-CLI.

## Fixture site

Bootstrap is deterministic/idempotent enough for repeated acceptance runs and creates:

- a published post;
- published pages and internal links;
- a PNG media item;
- a PDF download;
- an opt-in generic form using `data-wpss-form`;
- a conservative Contact Form 7 form/page created through Contact Form 7's own save API;
- a seeded WP Static Secure Submission Inbox row.

## Automated checks

`make acceptance-test` returns non-zero on failure. It verifies:

- released plugin activation and exact `WPStaticSecure\\Plugin::VERSION`;
- pinned theme and Contact Form 7 activation/version;
- page/post/media/PDF fixtures;
- generic form rewrite using the release-installed classes;
- conservative Contact Form 7 rewrite using the release-installed classes;
- Submission Inbox table and persisted submission;
- server-side rendering of the CF7 fixture;
- HTTP availability of representative fixture URLs;
- the post's internal link, PDF link, and generic form marker.

These checks exercise the installed Release ZIP code from the WordPress volume. They do not run the repository's development autoloader as the plugin under test.

## Human browser checklist

After `make acceptance-up`, check only the following:

1. Open `http://127.0.0.1:8080/` and confirm the site renders with Twenty Twenty-Five.
2. Open `/acceptance-post/`; confirm the image renders, the internal link opens `/acceptance-about/`, and the PDF link downloads/opens.
3. Open `/generic-form/` and `/cf7-form/`; confirm both forms render sensibly. This authoring view is not expected to show the post-export rewritten action.
4. Sign in at `/wp-admin/` using `acceptance/.env`, confirm **WP Static Secure** and **Contact Form 7** are active, then open **Tools → Submission Inbox** and confirm the seeded acceptance submission is visible.

## Local-only exposure

The WordPress port is published as `127.0.0.1:${WP_HTTP_PORT}:80`; it is not bound to `0.0.0.0`. This is an acceptance authoring environment, not a new public WordPress path.

## Safe destruction and reset

`make acceptance-down` runs Compose with the fixed project name `wp-static-secure-acceptance` and removes only that project's containers, network, and named volumes with `down --volumes --remove-orphans`. It then deletes only the generated `acceptance/.env` file.

It never runs Docker prune, wildcard volume removal, arbitrary host-path deletion, or deletion based on generated site content. Repeated `acceptance-down` is safe. `make acceptance-reset` is simply `acceptance-down` followed by `acceptance-up`.

## CI

`.github/workflows/acceptance.yml` runs the same `make acceptance-up` and `make acceptance-test` path on GitHub-hosted Ubuntu, then always executes `make acceptance-down`. CI therefore validates the Docker/Release-ZIP path without credentials, privileged infrastructure, or a publicly reachable WordPress service.

Human-only rendering/admin checks remain intentionally outside CI.
