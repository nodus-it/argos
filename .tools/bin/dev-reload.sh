#!/usr/bin/env bash
# .tools/bin/dev-reload.sh — Laravel-Cache leeren + queue-Worker neustarten.
#
# Schneller Reload nach Manager-PHP-Änderungen. Reset ohne DB-/Volume-/
# Image-Cleanup — dafür siehe `.tools/bin/dev-reset.sh`.
#
# Adressiert wave-1-retrospective.md C13 (Queue-Worker hält alten Code) und
# allgemeines OPCache-Stale.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# Canonical base + local dev overlay (build, bind mounts, phpMyAdmin).
COMPOSE_BASE="$REPO_ROOT/.tools/docker/docker-compose.yml"
COMPOSE_DEV="$REPO_ROOT/.tools/docker/docker-compose.dev.yml"

compose() {
    docker compose -f "$COMPOSE_BASE" -f "$COMPOSE_DEV" "$@"
}

compose exec -T app php artisan optimize:clear
compose restart queue scheduler
