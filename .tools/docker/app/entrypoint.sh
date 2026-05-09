#!/usr/bin/env bash
# App container entrypoint (split compose stack).
# Runs as root, prepares runtime, runs migrations, then hands off to CMD (php-fpm by default).
# DB lives in the separate `db` service — no MariaDB boot logic here.

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

# Sync composer dependencies if the host's vendor/ is missing or stale.
if [[ -f /app/composer.lock ]]; then
    if [[ ! -f /app/vendor/autoload.php ]] \
            || [[ /app/composer.lock -nt /app/vendor/composer/installed.json ]]; then
        echo "Composer dependencies out of sync — running composer install..."
        composer install \
            --working-dir=/app \
            --no-interaction \
            --optimize-autoloader
    fi
fi

# Refresh the package-discovery cache when it's stale relative to composer.lock.
if [[ -f /app/composer.lock ]]; then
    if [[ ! -f /app/bootstrap/cache/packages.php ]] \
            || [[ /app/composer.lock -nt /app/bootstrap/cache/packages.php ]]; then
        echo "Package discovery cache stale — running package:discover..."
        php /app/artisan package:discover --no-interaction --ansi || true
    fi
fi

# Make the data volume writable for www-data.
mkdir -p /data/config
chown -R www-data:www-data /data

# Sync nginx-served assets and config into shared volumes if they are mounted.
# The compose stack mounts:
#   /srv/argos-public      ↔ nginx:/app/public:ro
#   /srv/argos-nginx       ↔ nginx:/etc/nginx/conf.d:ro
# Skip silently when the directories aren't mounted (image started outside the
# split stack — e.g. a one-shot artisan invocation).
if [[ -d /srv/argos-public ]]; then
    # Wipe stale entries so files removed from /app/public in a new image are
    # not served forever. Race window is short and bracketed by the depends_on
    # service_healthy gate before nginx (re)starts.
    find /srv/argos-public -mindepth 1 -delete
    cp -a /app/public/. /srv/argos-public/
fi
if [[ -d /srv/argos-nginx ]]; then
    install -m 0644 /usr/local/share/argos/nginx.conf /srv/argos-nginx/default.conf
fi

# Generate and persist APP_KEY on first boot if not provided.
if [[ -z "${APP_KEY:-}" ]]; then
    KEY_FILE=/data/app-key
    if [[ ! -s "$KEY_FILE" ]]; then
        (umask 077 && printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE")
        chown www-data:www-data "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# Wait for the database to accept connections. The compose healthcheck on `db`
# already gates startup, but a belt-and-braces TCP probe here keeps this
# entrypoint usable outside compose too.
DB_HOST="${ARGOS_DB_HOST:-db}"
DB_PORT="${ARGOS_DB_PORT:-3306}"
echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
for _i in $(seq 1 60); do
    if (echo > "/dev/tcp/${DB_HOST}/${DB_PORT}") >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

# ARGOS_ROLE gates which side-effects this container takes. The `app` service
# owns the schema (migrations + seeds); `queue` and any future workers/scheduler
# services share the same image but skip the schema work to avoid noisy
# duplicate runs on every restart. Default = app so a bare `docker run` of the
# image (e.g. for one-shot artisan invocations) still self-heals the schema.
case "${ARGOS_ROLE:-app}" in
    app)
        php /app/artisan migrate --force --no-interaction
        php /app/artisan db:seed --class=AdminUserSeeder --force --no-interaction
        # Pre-warm the default worker image: dispatches a queued build for
        # the configured stack × claude-code so the first phase a fresh
        # install kicks off does not pay the 1-3 min cold-build cost. The
        # queue worker picks the job up once the app reports healthy.
        # Failure here is non-fatal — the resolver still builds on demand.
        php /app/artisan argos:warm-builtin-images --default-only --no-interaction || true
        ;;
    worker|queue|scheduler)
        echo "ARGOS_ROLE=${ARGOS_ROLE} — skipping migrations (app service owns the schema)."
        ;;
    *)
        echo "Unknown ARGOS_ROLE='${ARGOS_ROLE}' — refusing to start." >&2
        exit 1
        ;;
esac

exec "$@"
