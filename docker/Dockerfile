# syntax=docker/dockerfile:1.7

# ============================================================
# base: PHP 8.4-FPM + system tools
# ============================================================
FROM php:8.4-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive

# System packages: nginx, supervisor, mariadb, docker CLI, node 22
RUN set -eux; \
    # policy-rc.d prevents apt postinstall scripts from starting services in the container.
    printf '#!/bin/sh\nexit 101\n' > /usr/local/sbin/policy-rc.d; \
    chmod +x /usr/local/sbin/policy-rc.d; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        git \
        mariadb-server \
        nginx \
        supervisor \
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
    rm /usr/local/sbin/policy-rc.d; \
    # Docker CLI (official apt repo)
    install -m 0755 -d /etc/apt/keyrings; \
    curl -fsSL https://download.docker.com/linux/debian/gpg \
        -o /etc/apt/keyrings/docker.asc; \
    chmod a+r /etc/apt/keyrings/docker.asc; \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
        https://download.docker.com/linux/debian bookworm stable" \
        > /etc/apt/sources.list.d/docker.list; \
    # Node 22
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
# manager: Laravel app baked in
# ============================================================
FROM base AS manager

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

# Nginx: remove default site, add Laravel config
RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/nginx.conf /etc/nginx/sites-enabled/argos.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/argos.conf

# PHP-FPM: disable default pool, use our config
RUN rm -f /usr/local/etc/php-fpm.d/www.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/argos.conf

# Runtime entrypoint: docker socket group fix + migrations
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Standalone defaults: the entrypoint provisions a local MariaDB with these
# credentials, so the bare `docker run ...argos-manager` invocation in the
# README boots without requiring callers to pass them in. docker-compose can
# still override any of these via its `environment:` block.
ENV DB_CONNECTION=mariadb \
    ARGOS_DB_HOST=127.0.0.1 \
    ARGOS_DB_PORT=3306 \
    ARGOS_DB_SOCKET=/run/mysqld/mysqld.sock \
    ARGOS_DB_DATABASE=argos \
    ARGOS_DB_USERNAME=argos \
    ARGOS_DB_PASSWORD=argos \
    ARGOS_CONFIG_DIR=/data/config \
    QUEUE_CONNECTION=database \
    SESSION_DRIVER=database \
    CACHE_STORE=database

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/argos.conf"]
