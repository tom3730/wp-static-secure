#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
if [[ ! -f acceptance/.env ]]; then
  echo "Acceptance environment is already absent."
  exit 0
fi
COMPOSE=(docker compose -f acceptance/docker-compose.yml --env-file acceptance/.env -p wp-static-secure-acceptance)
"${COMPOSE[@]}" down --volumes --remove-orphans
rm -f acceptance/.env
echo "Removed only wp-static-secure-acceptance containers, network, dedicated volumes, and generated acceptance/.env."
