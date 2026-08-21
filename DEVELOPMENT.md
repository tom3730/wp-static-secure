# Development

## Requirements

- PHP 8.1 or newer
- Composer 2

A local WordPress installation is optional for the unit test suite. GitHub Actions performs an additional activation smoke test against a real WordPress installation.

## Install dependencies

```bash
composer install
```

## Run tests

```bash
composer test
```

Validate Composer metadata without imposing package-publication requirements that are not yet decided for this pre-alpha project:

```bash
composer validate --no-check-publish
```

## Syntax check

On Unix-like systems:

```bash
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Build an installable plugin ZIP

From a clean checkout, with Composer 2 and the PHP `zip` extension available:

```bash
php scripts/package-plugin.php
```

The default artifact is written to:

```text
build/wp-static-secure.zip
```

An alternate output path may be supplied as the first argument.

Packaging is allowlist-based. The ZIP contains the plugin bootstrap, `src/`, runtime CLI files in `bin/`, `composer.json`, and the production `vendor/` tree prepared with `composer install --no-dev --classmap-authoritative`. Development-only material such as `.git`, `.github/`, `tests/`, `scripts/`, PHPUnit configuration, development documentation, and local build output is not copied into the package.

Verify a package explicitly with:

```bash
php scripts/verify-package.php build/wp-static-secure.zip
```

The packaging script sorts archive entries and normalizes ZIP timestamps. CI builds the same source tree twice and requires the resulting ZIP files to compare byte-for-byte equal before the artifact is accepted.

## Validate a generated build

After exporting a site, validate the generated output before publishing it:

```bash
php bin/validate-build.php /absolute/path/to/dist https://wp.internal.example https://www.example.com
```

Validation fails with exit status `1` when generated output contains a broken local reference or a reference to the private authoring origin. Unsupported dynamic behavior, such as forms or WordPress application endpoints, is reported as a warning rather than being silently treated as a static fallback.

To inspect the generated site through a plain static HTTP server, serve only the output directory:

```bash
php -S 127.0.0.1:8080 -t /absolute/path/to/dist
```

The automated acceptance test performs the same architectural check with a representative site: navigation and common assets must work from the generated directory, while an application path such as `/wp-login.php` must terminate at the static server with a 404 rather than reaching WordPress.

## WordPress activation smoke test

The GitHub Actions `wordpress-smoke` job builds `build/wp-static-secure.zip`, verifies its contents and reproducibility, installs that ZIP into a real WordPress instance with WP-CLI, activates it, and runs the submission-to-Inbox smoke check against the packaged plugin. CI no longer relies on a symlink to the development checkout for activation coverage.
