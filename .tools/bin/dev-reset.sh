#!/usr/bin/env bash
# .tools/bin/dev-reset.sh — kompletter Reset der lokalen Dev-Umgebung.
#
# Pipeline:
#   1. migrate:fresh + DemoSeeder + ProviderDemoSeeder im app-Container
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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/.tools/docker/docker-compose.yml"

compose() {
    docker compose -f "$COMPOSE_FILE" "$@"
}

echo "==> migrate:fresh + DemoSeeder"
compose exec -T app php artisan migrate:fresh --force --seed --seeder=DemoSeeder

echo "==> ProviderDemoSeeder (demo task-provider bindings)"
compose exec -T app php artisan db:seed --force --class=ProviderDemoSeeder

echo "==> rm task_ws_* volumes"
mapfile -t volumes < <(docker volume ls --filter 'name=task_ws_' --format '{{.Name}}')
if [[ ${#volumes[@]} -gt 0 ]]; then
    docker volume rm "${volumes[@]}"
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
