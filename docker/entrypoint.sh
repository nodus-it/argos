#!/usr/bin/env bash
# Manager-Container Entrypoint.
# Läuft als root, richtet Laufzeit-Abhängigkeiten ein, dann übergibt an CMD.

set -euo pipefail

# Docker-Socket: Gruppe des Sockets dynamisch auf www-data übertragen,
# damit PHP-Prozesse (www-data) worker container starten können.
if [[ -S /var/run/docker.sock ]]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group docker >/dev/null 2>&1; then
        groupadd -g "$DOCKER_GID" docker
    else
        groupmod -g "$DOCKER_GID" docker
    fi
    usermod -aG docker www-data
fi

# Migrations bei jedem Start — idempotent, notwendig beim ersten Start.
php /app/artisan migrate --force --no-interaction

exec "$@"
