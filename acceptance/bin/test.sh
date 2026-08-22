#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
[[ -f acceptance/.env ]] || { echo "Run make acceptance-up first." >&2; exit 2; }
set -a; source acceptance/.env; set +a
COMPOSE=(docker compose -f acceptance/docker-compose.yml --env-file acceptance/.env -p wp-static-secure-acceptance)
"${COMPOSE[@]}" run --rm wpcli wp eval-file /acceptance/bin/smoke.php
for path in / /acceptance-about/ /acceptance-post/ /generic-form/ /cf7-form/; do
  curl --fail --silent --show-error --max-time 10 "$WP_SITE_URL$path" >/dev/null
  echo "PASS: HTTP $path"
done
post_html="$(curl --fail --silent --show-error --max-time 10 "$WP_SITE_URL/acceptance-post/")"
[[ "$post_html" == *'/acceptance-about/'* ]] || { echo "FAIL: acceptance post internal link" >&2; exit 1; }
[[ "$post_html" == *'acceptance.pdf'* ]] || { echo "FAIL: acceptance post PDF link" >&2; exit 1; }
generic_html="$(curl --fail --silent --show-error --max-time 10 "$WP_SITE_URL/generic-form/")"
[[ "$generic_html" == *'data-wpss-form="acceptance-contact"'* ]] || { echo "FAIL: generic form marker" >&2; exit 1; }
echo "PASS: acceptance post internal link"
echo "PASS: acceptance post PDF link"
echo "PASS: generic form marker"
echo "All headless acceptance checks passed."
