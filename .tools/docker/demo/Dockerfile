# Argos default live-demo runtime.
#
# A generic Laravel-capable image used when a repo ships no .argos/demo.*
# contract (see app/Services/Demo/DemoImageBuilder.php). It bundles php-fpm +
# nginx (via supervisord) plus Composer and Node, so the mounted task workspace
# can be `composer install`-ed, migrated, asset-built and served — all driven by
# the default contract's `commands` (resources/stubs/demo/laravel/demo.yml).
#
# nginx serves /var/www/html/public and proxies *.php to php-fpm on 127.0.0.1:9000.
# The workspace volume is mounted at /var/www/html by the deployer's override.
FROM php:8.4-fpm-bookworm

# System packages: nginx (web server), supervisor (runs fpm+nginx in one
# container), git + unzip for composer, plus the libs the PHP extensions need.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        git \
        unzip \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions via the de-facto standard installer (handles apt deps for us).
# Kept in parity with the worker stack (.tools/docker/worker/stacks/*) so the
# SAME `composer install` the worker ran also passes in the demo — notably
# `sockets` (hard platform req of pest-plugin-browser, fails composer install
# otherwise) and `redis` (projects that use phpredis at runtime).
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        mbstring \
        bcmath \
        gd \
        intl \
        zip \
        exif \
        pcntl \
        sockets \
        redis \
        opcache

# Lift the CLI memory_limit (base caps it at 128M) so `composer install` and
# `migrate --seed` on large apps don't OOM. Bounded by the demo's container cap.
RUN echo 'memory_limit = -1' > /usr/local/etc/php/conf.d/zz-argos-memory.ini

# Composer (binary only).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node + npm for Vite asset builds. node:22-bookworm shares glibc with the php
# bookworm base, so the binary + global modules copy across cleanly. We copy
# the node binary and its module tree explicitly (NOT all of /usr/local/bin,
# which would clobber php/php-fpm) and symlink the npm/npx CLIs.
COPY --from=node:22-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY .tools/docker/demo/nginx.conf /etc/nginx/sites-available/default
COPY .tools/docker/demo/supervisord.conf /etc/supervisor/conf.d/demo.conf

WORKDIR /var/www/html
EXPOSE 80

# Both fpm and nginx start immediately; requests 500 until the workspace has
# been `composer install`-ed by the contract commands, then go green — the
# deployer's health probe waits this out.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/demo.conf"]
