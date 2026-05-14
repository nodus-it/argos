# syntax=docker/dockerfile:1.7

# ============================================================
# base: PHP 8.4-FPM + system tools
# ============================================================
FROM php:8.4-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libfreetype6-dev \
        libjpeg-dev \
        libonig-dev \
        libxml2-dev \
        libsqlite3-dev \
        zlib1g-dev; \
    install -m 0755 -d /etc/apt/keyrings; \
    curl -fsSL https://download.docker.com/linux/debian/gpg \
        -o /etc/apt/keyrings/docker.asc; \
    chmod a+r /etc/apt/keyrings/docker.asc; \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
        https://download.docker.com/linux/debian bookworm stable" \
        > /etc/apt/sources.list.d/docker.list; \
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -; \
    apt-get update; \
    apt-get install -y --no-install-recommends docker-ce-cli nodejs; \
    rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN set -eux; \
    docker-php-ext-configure intl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        pdo_sqlite \
        zip \
        bcmath \
        gd \
        opcache; \
    docker-php-ext-enable opcache

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# ============================================================
# app: Laravel app on PHP-FPM. Runs migrations + queue work; spawns worker
# containers via the host docker socket. Designed for the split compose stack
# (db + app + nginx + queue) — does not bundle nginx/mariadb/supervisor.
# ============================================================
FROM base AS app

WORKDIR /app

# Install PHP deps first (separate layer for caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

# Copy app source
COPY . .

# Ensure required runtime directories exist (storage structure may be absent from context)
RUN mkdir -p bootstrap/cache \
        storage/logs \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views

# Finish install + frontend build
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm ci \
    && npm run build \
    && chown -R www-data:www-data storage bootstrap/cache

# Make /var/www writable for www-data so the docker CLI (called from
# PhaseRunner via php-fpm/queue-worker) can manage its own ~/.docker config.
RUN chown www-data:www-data /var/www

# PHP-FPM: disable default pool, use our config (listens on 0.0.0.0:9000 so
# the separate nginx container can reach it).
RUN rm -f /usr/local/etc/php-fpm.d/www.conf
COPY .tools/docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/argos.conf

# Bake the nginx site config into the image. The app entrypoint copies it
# into a shared volume on boot so the nginx container can mount it RO without
# the installer having to drop a file into the host install dir.
COPY .tools/docker/app/nginx.conf /usr/local/share/argos/nginx.conf

# Runtime entrypoint: docker socket group fix + migrations + asset/config sync.
COPY .tools/docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Compose-stack defaults — the `db` hostname resolves to the mariadb service
# on the compose network. Callers (compose, k8s, etc.) can still override any
# of these via their environment block.
ENV DB_CONNECTION=mariadb \
    ARGOS_DB_HOST=db \
    ARGOS_DB_PORT=3306 \
    ARGOS_DB_DATABASE=argos \
    ARGOS_DB_USERNAME=argos \
    ARGOS_DB_PASSWORD=argos \
    ARGOS_CONFIG_DIR=/data/config \
    QUEUE_CONNECTION=redis \
    REDIS_HOST=redis \
    REDIS_PORT=6379 \
    REDIS_CLIENT=predis \
    SESSION_DRIVER=database \
    CACHE_STORE=database

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]
