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
curl --fail --silent "$WP_SITE_URL/acceptance-post/" | grep -q '/acceptance-about/'
curl --fail --silent "$WP_SITE_URL/acceptance-post/" | grep -q 'acceptance.pdf'
curl --fail --silent "$WP_SITE_URL/generic-form/" | grep -q 'data-wpss-form="acceptance-contact"'
echo "All headless acceptance checks passed."
