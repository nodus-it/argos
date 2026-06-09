#!/usr/bin/env bash
# .tools/bin/dev-reset.sh [basic|full|live] — kompletter Reset der lokalen
# Dev-Umgebung mit Wahl des Demo-Profils (Default: full).
#
# Profile:
#   basic — nur Admin-User; Onboarding startet bei null (BasicDemoSeeder)
#   full  — jede Ansicht mit allen Varianten gefüllt (FullDemoSeeder)
#   live  — echtes OAuth aus .env, ein echter Task startklar (LiveReadySeeder)
#
# Pipeline:
#   1. migrate:fresh + <Profil>-Seeder im app-Container
#   2. Task-Workspace-Volumes (task_ws_*) entfernen
#   3. Worker-Images (argos-worker:*, argos-stack:*) entfernen
#   4. Laravel-Cache leeren + queue-Worker neustarten
#
# Adressiert wave-1-retrospective.md C16 ("Räume alles auf" als Single-
# Action) und indirekt C9/C13 (Vendor-Cache + Queue-Worker-OPCache).
#
# Voraussetzung: Compose-Stack läuft (db + app + queue).

set -euo pipefail
IFS=$'\n\t'

MODE="${1:-full}"
case "$MODE" in
    basic) SEEDER="BasicDemoSeeder" ;;
    full)  SEEDER="FullDemoSeeder" ;;
    live)  SEEDER="LiveReadySeeder" ;;
    *)
        echo "usage: $(basename "$0") [basic|full|live]" >&2
        exit 64
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# Canonical base + local dev overlay (build, bind mounts, phpMyAdmin).
COMPOSE_BASE="$REPO_ROOT/.tools/docker/docker-compose.yml"
COMPOSE_DEV="$REPO_ROOT/.tools/docker/docker-compose.dev.yml"

compose() {
    docker compose -f "$COMPOSE_BASE" -f "$COMPOSE_DEV" "$@"
}

echo "==> migrate:fresh + ${SEEDER} (${MODE} profile)"
compose exec -T app php artisan migrate:fresh --force --seed --seeder="$SEEDER"

echo "==> rm task_ws_* volumes"
mapfile -t volumes < <(docker volume ls --filter 'name=task_ws_' --format '{{.Name}}')
if [[ ${#volumes[@]} -gt 0 ]]; then
    for vol in "${volumes[@]}"; do
        # A leftover worker/live-demo container (e.g. argos-demo:* from a live
        # preview) may still mount the workspace volume and block its removal.
        # Force-remove any such container first, then drop the volume. Cleanup
        # is best-effort: a single failure must not abort the whole reset.
        mapfile -t holders < <(docker ps -aq --filter "volume=${vol}")
        if [[ ${#holders[@]} -gt 0 ]]; then
            docker rm -f "${holders[@]}" >/dev/null || true
        fi
        docker volume rm "$vol" >/dev/null || echo "    (konnte ${vol} nicht entfernen — übersprungen)"
    done
else
    echo "    (keine vorhanden)"
fi

# Per-Tag entfernen (nicht per-ID), damit mehrere Tags auf derselben Image-ID
# einzeln untagged werden. Worker zuerst, dann Stack — sonst blockieren die
# Worker-Children das Löschen ihrer Stack-Parents.
prune_image_tags() {
    local pattern="$1"
    mapfile -t tags < <(docker images "$pattern" --format '{{.Repository}}:{{.Tag}}' | grep -v '^<none>:')
    if [[ ${#tags[@]} -gt 0 ]]; then
        docker rmi -f "${tags[@]}"
    else
        echo "    (keine ${pattern})"
    fi
}

echo "==> rm argos-worker:* images"
prune_image_tags 'argos-worker:*'

echo "==> rm argos-stack:* images"
prune_image_tags 'argos-stack:*'

echo "==> prune dangling layers"
docker image prune -f >/dev/null

echo "==> optimize:clear + queue restart"
compose exec -T app php artisan optimize:clear
compose restart queue

echo "==> done."
