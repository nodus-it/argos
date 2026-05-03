#!/usr/bin/env bash
# .tools/bash/start-stage.sh — Stage-Manager lokal hochfahren.
#
# Pullt das aktuelle Stage-Image, stoppt einen eventuell laufenden Container
# und startet neu. Alle relevanten ENV-Variablen sind hier eingetragen.
# Sensible Werte (Tokens, Secrets) bitte in .tools/bash/.env.stage ablegen —
# die Datei ist in .gitignore und wird von diesem Script automatisch geladen.
#
# Verwendung:
#   bash .tools/bash/start-stage.sh

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.stage"

# ── Secrets aus .env.stage laden ─────────────────────────────────────────────
if [[ -f "$ENV_FILE" ]]; then
    # shellcheck disable=SC1090
    set -o allexport
    source "$ENV_FILE"
    set +o allexport
else
    echo ""
    echo "  Hinweis: $ENV_FILE nicht gefunden."
    echo "  Erstelle die Datei mit folgendem Inhalt:"
    echo ""
    echo "    APP_KEY=base64:..."
    echo "    ADMIN_PASSWORD=..."
    echo "    GITHUB_CLIENT_ID=..."
    echo "    GITHUB_CLIENT_SECRET=..."
    echo "    CLAUDE_CODE_OAUTH_TOKEN=..."
    echo ""
fi

# ── Konfiguration ─────────────────────────────────────────────────────────────
CONTAINER_NAME="argos-stage"
IMAGE="ghcr.io/nodus-it/argos-manager:stage"
HOST_PORT="${ARGOS_PORT:-80}"

# ── Image aktualisieren ───────────────────────────────────────────────────────
echo "Pulling $IMAGE ..."
docker pull "$IMAGE"

# ── Laufenden Container stoppen ───────────────────────────────────────────────
if docker inspect "$CONTAINER_NAME" &>/dev/null; then
    echo "Stopping existing container $CONTAINER_NAME ..."
    docker rm -f "$CONTAINER_NAME"
fi

# ── Volumes sicherstellen ─────────────────────────────────────────────────────
docker volume create argos-stage-data  &>/dev/null || true
docker volume create argos-stage-db    &>/dev/null || true

# ── Container starten ────────────────────────────────────────────────────────
echo "Starting $CONTAINER_NAME on port $HOST_PORT ..."
docker run -d \
    --name "$CONTAINER_NAME" \
    --restart unless-stopped \
    -p "${HOST_PORT}:80" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v argos-stage-data:/data \
    -v argos-stage-db:/var/lib/mysql \
    \
    -e APP_ENV=production \
    -e APP_DEBUG=false \
    -e APP_URL="${APP_URL:-http://localhost:${HOST_PORT}}" \
    -e APP_KEY="${APP_KEY:-}" \
    -e ADMIN_PASSWORD="${ADMIN_PASSWORD:-}" \
    \
    -e GITHUB_CLIENT_ID="${GITHUB_CLIENT_ID:-}" \
    -e GITHUB_CLIENT_SECRET="${GITHUB_CLIENT_SECRET:-}" \
    -e GITHUB_REDIRECT_URI="${GITHUB_REDIRECT_URI:-${APP_URL:-http://localhost:${HOST_PORT}}/auth/github/callback}" \
    \
    -e CLAUDE_CODE_OAUTH_TOKEN="${CLAUDE_CODE_OAUTH_TOKEN:-}" \
    -e ARGOS_WORKER_IMAGE="${ARGOS_WORKER_IMAGE:-ghcr.io/nodus-it/argos-worker:stage-php8.4}" \
    -e ARGOS_CONFIG_DIR=/data/config \
    \
    -e DB_CONNECTION=mariadb \
    -e ARGOS_DB_HOST=127.0.0.1 \
    -e ARGOS_DB_PORT=3306 \
    -e ARGOS_DB_SOCKET=/run/mysqld/mysqld.sock \
    -e ARGOS_DB_DATABASE="${ARGOS_DB_DATABASE:-argos}" \
    -e ARGOS_DB_USERNAME="${ARGOS_DB_USERNAME:-argos}" \
    -e ARGOS_DB_PASSWORD="${ARGOS_DB_PASSWORD:-argos}" \
    \
    -e QUEUE_CONNECTION=database \
    -e SESSION_DRIVER=database \
    -e CACHE_STORE=database \
    \
    "$IMAGE"

echo ""
echo "  Argos Stage läuft unter http://localhost:${HOST_PORT}"
echo "  Logs: docker logs -f $CONTAINER_NAME"
