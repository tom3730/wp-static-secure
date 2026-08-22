#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="$ROOT/acceptance/.env"

if [[ -f "$ENV_FILE" ]]; then
  exit 0
fi

command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 1; }
rand() { openssl rand -hex 18; }
cat > "$ENV_FILE" <<EOF
WP_DB_NAME=wpss_acceptance
WP_DB_USER=wpss
WP_DB_PASSWORD=$(rand)
WP_DB_ROOT_PASSWORD=$(rand)
WP_ADMIN_USER=acceptance-admin
WP_ADMIN_PASSWORD=$(rand)
WP_ADMIN_EMAIL=acceptance@example.invalid
WP_HTTP_PORT=8080
WP_SITE_URL=http://127.0.0.1:8080
WPS_RELEASE_TAG=v0.1.0-alpha.1
WPS_RELEASE_ASSET=wp-static-secure.zip
WPS_RELEASE_COMMIT=2e5501aafb1fadc54e6a59930bcf6b1934259a4d
WPS_RELEASE_SHA256=
WPS_THEME=twentytwentyfive
WPS_THEME_VERSION=1.3
WPS_CF7_VERSION=6.1.1
EOF
chmod 600 "$ENV_FILE"
echo "Created acceptance/.env with local disposable credentials."
