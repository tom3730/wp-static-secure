#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
bash acceptance/bin/env.sh
set -a; source acceptance/.env; set +a
COMPOSE=(docker compose -f acceptance/docker-compose.yml --env-file acceptance/.env -p wp-static-secure-acceptance)
"${COMPOSE[@]}" up -d db wordpress
for _ in $(seq 1 60); do
  if curl --fail --silent --max-time 2 "$WP_SITE_URL/wp-admin/install.php" >/dev/null 2>&1 || curl --fail --silent --max-time 2 "$WP_SITE_URL/" >/dev/null 2>&1; then break; fi
  sleep 2
done
curl --fail --silent --max-time 5 "$WP_SITE_URL/" >/dev/null 2>&1 || curl --fail --silent --max-time 5 "$WP_SITE_URL/wp-admin/install.php" >/dev/null
"${COMPOSE[@]}" run --rm wpcli sh /acceptance/bin/bootstrap.sh
echo "Acceptance environment ready: $WP_SITE_URL"
echo "Admin: $WP_SITE_URL/wp-admin/ (credentials: acceptance/.env)"
