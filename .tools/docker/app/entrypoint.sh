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

# Resolve APP_KEY before any artisan/composer command boots the framework.
# composer install's post-autoload-dump hook runs package:discover, which boots
# the app (OAuthConfigHydrator decrypts an encrypted client_secret at boot) —
# that needs APP_KEY present. So this must precede the composer sync and discover
# blocks below, not follow them.
#
# Resolution order: explicit container env (compose) → repo /app/.env → a
# persisted auto-generated key. The auto-generated key is a LAST resort: it must
# never be exported when /app/.env already defines one. Laravel's dotenv is
# immutable (it won't overwrite an already-set env var), so an exported
# auto-key would silently shadow the real /app/.env key — running the app under
# the wrong key and orphaning every encrypted DB value ("MAC is invalid").
if [[ -z "${APP_KEY:-}" ]]; then
    # Compose injects APP_KEY="" (empty but PRESENT) whenever it's not in its
    # env-file. Drop it so it can't shadow /app/.env, then fall back to the
    # persisted auto-key only if /app/.env carries none either.
    unset APP_KEY
    if ! grep -qE '^APP_KEY=.+' /app/.env 2>/dev/null; then
        KEY_FILE=/data/app-key
        mkdir -p /data
        if [[ ! -s "$KEY_FILE" ]]; then
            (umask 077 && printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE")
            chown www-data:www-data "$KEY_FILE"
        fi
        APP_KEY="$(cat "$KEY_FILE")"
        export APP_KEY
    fi
fi

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

# Seed the static Traefik route for the Argos app (file provider). Traefik does
# NOT use the docker provider here — it pins Docker API 1.24, which modern
# daemons (Engine 29, min API 1.40) reject — so routes are plain YAML in the
# shared argos-traefik volume. The manager writes one file per live demo into
# the same dir; their higher-priority Host(`demo-…`) rules win, everything else
# falls through to this catch-all → nginx. Only the `app` role seeds it (queue
# shares the volume; avoid a write race), but the dir/perms are ensured for all.
TRAEFIK_DIR="${ARGOS_TRAEFIK_DIR:-/data/traefik}"
mkdir -p "$TRAEFIK_DIR"
chown -R www-data:www-data "$TRAEFIK_DIR"
if [[ "${ARGOS_ROLE:-app}" == "app" ]]; then
    cat > "$TRAEFIK_DIR/argos.yml" <<'YML'
# Static catch-all route for the Argos app itself (file provider).
# Managed by the app entrypoint — do not edit by hand.
http:
  routers:
    argos:
      rule: "PathPrefix(`/`)"
      priority: 1
      entryPoints: ["web"]
      service: argos
  services:
    argos:
      loadBalancer:
        servers:
          - url: "http://nginx:80"
YML
    chown www-data:www-data "$TRAEFIK_DIR/argos.yml"
fi

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

# Passport signing keys for the MCP OAuth server live on the persistent /data
# volume so issued tokens survive image rebuilds. AppServiceProvider reads
# PASSPORT_KEYS_PATH and calls Passport::loadKeysFrom(); every role exports it
# so token validation works in app/queue alike. Keys are generated once (never
# with --force — that would invalidate live tokens on each boot).
export PASSPORT_KEYS_PATH="${PASSPORT_KEYS_PATH:-/data/passport}"
mkdir -p "$PASSPORT_KEYS_PATH"
chown www-data:www-data "$PASSPORT_KEYS_PATH"

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
        # One-time drain of leftover database-queue jobs after migration to Horizon.
        # Runs in ~1 s when the jobs table is empty; processes any stranded jobs otherwise.
        if php /app/artisan tinker --execute="exit(\DB::table('jobs')->exists() ? 0 : 1);" >/dev/null 2>&1; then
            echo "Draining leftover database queue jobs (one-time migration to Horizon)..."
            php /app/artisan queue:work database --stop-when-empty --tries=1 --max-time=120 || true
        fi

        php /app/artisan migrate --force --no-interaction
        php /app/artisan db:seed --class=AdminUserSeeder --force --no-interaction
        # Generate Passport keys once, into the persistent volume. www-data must
        # own them so php-fpm can read the 0600 key files.
        if [[ ! -f "${PASSPORT_KEYS_PATH}/oauth-private.key" ]]; then
            php /app/artisan passport:keys --no-interaction
            chown www-data:www-data "${PASSPORT_KEYS_PATH}"/oauth-private.key "${PASSPORT_KEYS_PATH}"/oauth-public.key
        fi
        # Pre-warm the default worker image: dispatches a queued build for
        # the configured stack × claude-code so the first phase a fresh
        # install kicks off does not pay the 1-3 min cold-build cost. The
        # queue worker picks the job up once the app reports healthy.
        # Failure here is non-fatal — the resolver still builds on demand.
        php /app/artisan argos:warm-builtin-images --default-only --no-interaction || true
        # Pre-warm the default live-demo runtime image (no-op when previews are
        # off or the image is already cached). Dispatched to the queue worker.
        php /app/artisan argos:warm-demo-image --no-interaction || true
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
