#!/usr/bin/env bash
# .tools/bash/start-stage.sh — Stage-Manager lokal hochfahren.
#
# Pullt das aktuelle Stage-Image, stoppt einen eventuell laufenden Container
# und startet neu. Geheime und umgebungsspezifische Werte werden in
# .tools/bash/.env.stage abgelegt — die Datei ist in .gitignore. Beim ersten
# Lauf wird sie aus .env.example als komplett auskommentierte Vorlage erzeugt;
# unkommentierte Einträge überschreiben die Defaults dieses Scripts.
#
# Verwendung:
#   bash .tools/bash/start-stage.sh

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.stage"
ENV_EXAMPLE="$REPO_ROOT/.env.example"

# ── .env.stage erstellen falls fehlend ───────────────────────────────────────
# Kopiert .env.example und kommentiert sicherheitshalber jede Zeile aus,
# damit die Vorlage nichts versehentlich überschreibt. Wer .env.example
# bereits durchgängig auskommentiert pflegt, sieht hier keinen Unterschied.
if [[ ! -f "$ENV_FILE" ]]; then
    if [[ ! -f "$ENV_EXAMPLE" ]]; then
        echo "Fehler: weder $ENV_FILE noch $ENV_EXAMPLE vorhanden." >&2
        exit 1
    fi
    sed -E 's/^([A-Z_])/# \1/' "$ENV_EXAMPLE" > "$ENV_FILE"
    echo ""
    echo "  $ENV_FILE wurde aus .env.example erzeugt (alles auskommentiert)."
    echo "  Trage die nötigen Werte ein und starte das Script erneut."
    echo ""
    exit 0
fi

# ── Secrets aus .env.stage laden ─────────────────────────────────────────────
set -o allexport
# shellcheck source=/dev/null
source "$ENV_FILE"
set +o allexport

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

# Pflicht- und Default-Werte werden unbedingt gesetzt.
DOCKER_ENV_ARGS=(
    -e APP_ENV=staging
    -e APP_DEBUG=false
    -e "APP_URL=${APP_URL:-http://localhost:${HOST_PORT}}"
    -e ARGOS_CONFIG_DIR=/data/config
    -e DB_CONNECTION=mariadb
    -e ARGOS_DB_HOST=127.0.0.1
    -e ARGOS_DB_PORT=3306
    -e ARGOS_DB_SOCKET=/run/mysqld/mysqld.sock
    -e "ARGOS_DB_DATABASE=${ARGOS_DB_DATABASE:-argos}"
    -e "ARGOS_DB_USERNAME=${ARGOS_DB_USERNAME:-argos}"
    -e "ARGOS_DB_PASSWORD=${ARGOS_DB_PASSWORD:-argos}"
    -e "ARGOS_WORKER_IMAGE=${ARGOS_WORKER_IMAGE:-ghcr.io/nodus-it/argos-worker:stage-php8.4}"
)

# argos_add_optional_env: Hängt -e NAME=VALUE nur an, wenn VALUE nicht leer ist.
# Ein leerer Wert würde sonst Laravels env('NAME', 'default') überschreiben
# (env() betrachtet "" als gesetzt und liefert "" statt des Defaults zurück).
# Args: $1=Variablen-Name; gelesen wird der Wert aus der aktuellen Shell.
argos_add_optional_env() {
    local name="$1"
    local value="${!name:-}"
    if [[ -n "$value" ]]; then
        DOCKER_ENV_ARGS+=(-e "${name}=${value}")
    fi
}

argos_add_optional_env APP_KEY
argos_add_optional_env ADMIN_PASSWORD
argos_add_optional_env GITHUB_CLIENT_ID
argos_add_optional_env GITHUB_CLIENT_SECRET
argos_add_optional_env GITLAB_CLIENT_ID
argos_add_optional_env GITLAB_CLIENT_SECRET
argos_add_optional_env GITLAB_INSTANCE_URL
argos_add_optional_env BITBUCKET_CLIENT_ID
argos_add_optional_env BITBUCKET_CLIENT_SECRET
argos_add_optional_env CLAUDE_CODE_OAUTH_TOKEN

docker run -d \
    --name "$CONTAINER_NAME" \
    --restart unless-stopped \
    -p "${HOST_PORT}:80" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v argos-stage-data:/data \
    -v argos-stage-db:/var/lib/mysql \
    "${DOCKER_ENV_ARGS[@]}" \
    "$IMAGE"

echo ""
echo "  Argos Stage läuft unter http://localhost:${HOST_PORT}"
echo "  Logs: docker logs -f $CONTAINER_NAME"
