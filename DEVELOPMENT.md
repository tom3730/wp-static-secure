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

With the repository installed as `wp-content/plugins/wp-static-secure` and Composer dependencies installed:

```bash
wp plugin activate wp-static-secure
wp plugin status wp-static-secure
```

The GitHub Actions `wordpress-smoke` job provisions WordPress and MySQL, installs this repository as a plugin, and executes the same activation check automatically.
