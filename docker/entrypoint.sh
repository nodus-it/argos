#!/usr/bin/env bash
# Manager-Container Entrypoint.
# Läuft als root, initialisiert MariaDB und Laufzeit-Abhängigkeiten, übergibt dann an CMD.

set -euo pipefail
IFS=$'\n\t'

# Docker-Socket: Gruppe dynamisch auf www-data übertragen
if [[ -S /var/run/docker.sock ]]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group docker >/dev/null 2>&1; then
        groupadd -g "${DOCKER_GID}" docker
    else
        groupmod -g "${DOCKER_GID}" docker
    fi
    usermod -aG docker www-data
fi

# Storage-Permissions für Bind-Mount (lokale Entwicklung)
chmod -R a+w /app/storage/logs /app/storage/framework /app/bootstrap/cache 2>/dev/null || true

# Daten-Volume: config-Verzeichnis für www-data beschreibbar machen
mkdir -p /data/config
chown -R www-data:www-data /data

# MariaDB runtime directory
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld

# Data-Directory und Ownership sicherstellen
if [[ -d /var/lib/mysql ]]; then
    chown -R mysql:mysql /var/lib/mysql
fi

# Daten-Verzeichnis beim ersten Start initialisieren
if [[ ! -d /var/lib/mysql/mysql ]]; then
    echo "Initialisiere MariaDB-Datenverzeichnis..."
    mariadb-install-db \
        --user=mysql \
        --datadir=/var/lib/mysql \
        --skip-test-db \
        --auth-root-authentication-method=normal \
        >/dev/null 2>&1
fi

# MariaDB temporär starten (für Setup + Migrations)
/usr/sbin/mysqld --user=mysql &
MYSQLD_PID=$!

echo "Warte auf MariaDB..."
for _i in $(seq 1 30); do
    if mariadb --socket=/run/mysqld/mysqld.sock -u root --password='' \
            -e 'SELECT 1' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

# Datenbank und User anlegen (idempotent)
DB_NAME="${ARGOS_DB_DATABASE:-argos}"
DB_USER="${ARGOS_DB_USERNAME:-argos}"
DB_PASS="${ARGOS_DB_PASSWORD:-argos}"

mariadb --socket=/run/mysqld/mysqld.sock -u root --password='' <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost'
    IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Migrations ausführen
php /app/artisan migrate --force --no-interaction

# Temporäre Instanz sauber beenden — supervisord startet MariaDB danach neu
mariadb-admin --socket=/run/mysqld/mysqld.sock -u root --password='' shutdown
wait "${MYSQLD_PID}" 2>/dev/null || true

exec "$@"
