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

## WordPress activation smoke test

With the repository installed as `wp-content/plugins/wp-static-secure` and Composer dependencies installed:

```bash
wp plugin activate wp-static-secure
wp plugin status wp-static-secure
```

The GitHub Actions `wordpress-smoke` job provisions WordPress and MySQL, installs this repository as a plugin, and executes the same activation check automatically.
