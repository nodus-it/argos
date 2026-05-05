#!/usr/bin/env bash
# .tools/bash/start-stage.sh — Stage-Compose-Stack lokal hochfahren.
#
# Pullt das aktuelle Stage-Image für `argos-app` und bringt den Compose-Stack
# (db + app + nginx + queue) unter dem Projekt-Namen `argos-stage` hoch — so
# bleiben Stage-Container und -Volumes von einer parallelen lokalen Dev-
# Instanz (`name: argos`) sauber getrennt.
#
# Geheime und umgebungsspezifische Werte werden in .tools/bash/.env.stage
# abgelegt — die Datei ist in .gitignore. Beim ersten Lauf wird sie aus
# .env.example als komplett auskommentierte Vorlage erzeugt; unkommentierte
# Einträge überschreiben die Defaults dieses Scripts.
#
# Verwendung:
#   bash .tools/bash/start-stage.sh

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.stage"
ENV_EXAMPLE="$REPO_ROOT/.env.example"
COMPOSE_FILE="$REPO_ROOT/.tools/docker/docker-compose.yml"
PROJECT_NAME="argos-stage"

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

# ── Compose-Defaults für Stage ───────────────────────────────────────────────
# Image-Tags zeigen auf die GHCR-Stage-Builds; ARGOS_PORT bleibt überschreibbar
# über .env.stage. Composer-eigene Variablen (APP_KEY etc.) werden weitergegeben,
# wenn sie im env-File gesetzt sind — das compose-File nutzt sie via ${VAR:-…}.
export APP_ENV="${APP_ENV:-staging}"
export APP_DEBUG="${APP_DEBUG:-false}"
export ARGOS_PORT="${ARGOS_PORT:-80}"
export ARGOS_APP_IMAGE="${ARGOS_APP_IMAGE:-ghcr.io/nodus-it/argos-app:stage}"
export ARGOS_WORKER_IMAGE="${ARGOS_WORKER_IMAGE:-ghcr.io/nodus-it/argos-worker:stage-php8.4}"
export ARGOS_DB_DATABASE="${ARGOS_DB_DATABASE:-argos}"
export ARGOS_DB_USERNAME="${ARGOS_DB_USERNAME:-argos}"
export ARGOS_DB_PASSWORD="${ARGOS_DB_PASSWORD:-argos}"

DOCKER_COMPOSE=(docker compose -p "$PROJECT_NAME" -f "$COMPOSE_FILE")

# ── Image aktualisieren ───────────────────────────────────────────────────────
# Nur das `app`-Image pullen — die queue teilt es sich, db/nginx haben eigene
# Image-Tags die compose ohnehin pullt, und worker-build wollen wir hier
# explizit *nicht* triggern (das baut nur lokal, brauchen wir auf Stage nicht).
echo "Pulling $ARGOS_APP_IMAGE ..."
docker pull "$ARGOS_APP_IMAGE"

# ── Stack neu starten ────────────────────────────────────────────────────────
echo "Bringing up $PROJECT_NAME stack ..."
"${DOCKER_COMPOSE[@]}" up -d --no-deps --remove-orphans db app nginx queue

echo ""
echo "  Argos Stage läuft unter http://localhost:${ARGOS_PORT}"
echo "  Logs: ${DOCKER_COMPOSE[*]} logs -f app"
echo "  Stoppen: ${DOCKER_COMPOSE[*]} down"
