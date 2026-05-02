#!/usr/bin/env bash
# Manager container entrypoint.
# Runs as root, initialises MariaDB and runtime prerequisites, then hands off to CMD.

set -euo pipefail
IFS=$'\n\t'

# Map the docker socket's gid onto www-data so PHP can talk to dockerd.
if [[ -S /var/run/docker.sock ]]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group docker >/dev/null 2>&1; then
        groupadd -g "${DOCKER_GID}" docker
    else
        groupmod -g "${DOCKER_GID}" docker
    fi
    usermod -aG docker www-data
fi

# Storage permissions for the dev bind mount.
chmod -R a+w /app/storage/logs /app/storage/framework /app/bootstrap/cache 2>/dev/null || true

# Make the data volume writable for www-data.
mkdir -p /data/config
chown -R www-data:www-data /data

# Generate and persist APP_KEY on first boot if not provided. Without this the
# Laravel encrypter throws on every request and sessions don't survive.
if [[ -z "${APP_KEY:-}" ]]; then
    KEY_FILE=/data/app-key
    if [[ ! -s "$KEY_FILE" ]]; then
        (umask 077 && printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE")
        chown www-data:www-data "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld

if [[ -d /var/lib/mysql ]]; then
    chown -R mysql:mysql /var/lib/mysql
fi

# Initialise the data directory on first start.
if [[ ! -d /var/lib/mysql/mysql ]]; then
    echo "Initialising MariaDB data directory..."
    mariadb-install-db \
        --user=mysql \
        --datadir=/var/lib/mysql \
        --skip-test-db \
        --auth-root-authentication-method=normal \
        >/dev/null 2>&1
fi

# Boot MariaDB temporarily for setup + migrations.
/usr/sbin/mysqld --user=mysql &
MYSQLD_PID=$!

echo "Waiting for MariaDB..."
for _i in $(seq 1 30); do
    if mariadb --socket=/run/mysqld/mysqld.sock -u root --password='' \
            -e 'SELECT 1' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

# Create the database and user (idempotent).
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

php /app/artisan migrate --force --no-interaction

# Stop the temporary instance cleanly — supervisord restarts MariaDB next.
mariadb-admin --socket=/run/mysqld/mysqld.sock -u root --password='' shutdown
wait "${MYSQLD_PID}" 2>/dev/null || true

exec "$@"
