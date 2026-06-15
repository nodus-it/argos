#!/usr/bin/env bash
# .tools/bin/e2e-up.sh — bring the stack up in deterministic browser-E2E mode.
#
# Layers the E2E overlay (docker-compose.e2e.yml) on top of base + dev so the
# Playwright suite (tests/e2e/) can drive a fully offline stack over plain
# http://127.0.0.1:${ARGOS_PORT}. See docker-compose.e2e.yml for the why; the
# short version is: it pins ARGOS_E2E_FAKE=1 + an http/127.0.0.1 APP_URL and the
# matching non-secure, host-only session cookie, INDEPENDENT of the personal
# .env (which may be tuned for domain dev with secure cookies).
#
# This shares the `argos` compose project with base/dev, so it REPLACES a
# running domain dev stack. Switch back afterwards with `composer dev:full`
# (or your usual base+dev[+proxy] bring-up).

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_BASE="$REPO_ROOT/.tools/docker/docker-compose.yml"
COMPOSE_DEV="$REPO_ROOT/.tools/docker/docker-compose.dev.yml"
COMPOSE_E2E="$REPO_ROOT/.tools/docker/docker-compose.e2e.yml"

compose() {
    docker compose -f "$COMPOSE_BASE" -f "$COMPOSE_DEV" -f "$COMPOSE_E2E" "$@"
}

echo "==> Bringing the stack up in E2E mode (ARGOS_E2E_FAKE=1, http://127.0.0.1)…"
compose up -d --remove-orphans

echo "==> Waiting for the app container to report healthy…"
for _ in $(seq 1 60); do
    status="$(compose ps app --format '{{.Health}}' 2>/dev/null || true)"
    if [[ "$status" == "healthy" ]]; then
        break
    fi
    sleep 2
done
if [[ "${status:-}" != "healthy" ]]; then
    echo "!! app did not become healthy in time (last status: ${status:-unknown})." >&2
    echo "   Check: $(basename "$COMPOSE_BASE") logs app" >&2
    exit 1
fi

# In local APP_ENV config is not cached, but clear it anyway so the freshly
# pinned E2E env (APP_URL / session cookie) is what the app reads.
compose exec -T app php artisan config:clear >/dev/null

echo "==> Stack is up in E2E mode. Run the suite with: composer test:browser"
echo "    Switch back to domain dev afterwards with: composer dev:full"
