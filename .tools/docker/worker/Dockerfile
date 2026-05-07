# syntax=docker/dockerfile:1.7

# ============================================================
# toolchain: shared system packages, Node, claude-code, Composer
# (no PHP — each variant brings its own php base image)
# ============================================================
FROM debian:bookworm-slim AS toolchain

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg \
        git unzip jq coreutils vim nano less; \
    install -m 0755 -d /etc/apt/keyrings; \
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        -o /etc/apt/keyrings/githubcli-archive-keyring.gpg; \
    chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg; \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list; \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -; \
    apt-get update; \
    apt-get install -y --no-install-recommends gh nodejs; \
    npm install -g @anthropic-ai/claude-code; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# ============================================================
# base: PHP 8.3 + Toolchain (apt, PECL, composer, node, claude-code)
# ============================================================
FROM php:8.3-cli-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive

# System packages: git, gh, jq, build deps for PHP extensions, Node 20 source
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        git \
        unzip \
        jq \
        coreutils \
        vim \
        nano \
        less \
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
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        -o /etc/apt/keyrings/githubcli-archive-keyring.gpg; \
    chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg; \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list; \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -; \
    apt-get update; \
    apt-get install -y --no-install-recommends gh nodejs; \
    rm -rf /var/lib/apt/lists/*

# PHP extensions: Laravel-Standard + Redis (PECL)
RUN set -eux; \
    docker-php-ext-configure intl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        pdo_sqlite \
        zip \
        bcmath \
        gd; \
    pecl install redis; \
    docker-php-ext-enable redis

# Composer (latest stable) aus dem offiziellen Image
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Claude Code CLI global
RUN npm install -g @anthropic-ai/claude-code

# Non-root user `agent`
RUN useradd --create-home --shell /bin/bash --uid 1000 agent

# ============================================================
# worker: Application Layer
# ============================================================
FROM base AS worker

# App-Files unter /usr/local/share/agent/ ablegen
COPY worker/lib/      /usr/local/share/agent/lib/
COPY worker/phases/   /usr/local/share/agent/phases/
COPY worker/prompts/  /usr/local/share/agent/prompts/
COPY worker/schemas/  /usr/local/share/agent/schemas/

# Entrypoint
COPY .tools/docker/worker/worker-entrypoint.sh /usr/local/bin/worker-entrypoint.sh
RUN chmod +x /usr/local/bin/worker-entrypoint.sh \
    && chown -R agent:agent /usr/local/share/agent \
    && mkdir -p /workspace \
    && chown agent:agent /workspace \
    && mkdir -p /run/agent \
    && chown agent:agent /run/agent \
    && mkdir -p /home/agent/.npm /home/agent/.composer/cache \
    && chown -R agent:agent /home/agent/.npm /home/agent/.composer

USER agent
# safe.directory: clones happen as `agent` into bind-mounted /workspace, where
# the host UID may differ from the container's — git would otherwise refuse.
# http.postBuffer + http.version: workaround for the GnuTLS-linked Debian git
# dropping the sideband stream on large pack files (curl 56 / GnuTLS -110)
# behind some reverse proxies. 500 MiB postBuffer forces a single request,
# HTTP/1.1 dodges proxies with broken HTTP/2 sideband handling.
RUN git config --global --add safe.directory '*' \
    && git config --global http.postBuffer 524288000 \
    && git config --global http.version HTTP/1.1
WORKDIR /workspace

ENTRYPOINT ["/usr/local/bin/worker-entrypoint.sh"]
CMD []

# ============================================================
# base-php84: PHP 8.4 variant of the base toolchain
# ============================================================
FROM php:8.4-cli-bookworm AS base-php84

ENV DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg \
        git unzip jq coreutils vim nano less \
        libzip-dev libicu-dev libpng-dev libfreetype6-dev \
        libjpeg-dev libonig-dev libxml2-dev libsqlite3-dev zlib1g-dev; \
    install -m 0755 -d /etc/apt/keyrings; \
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        -o /etc/apt/keyrings/githubcli-archive-keyring.gpg; \
    chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg; \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list; \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -; \
    apt-get update; \
    apt-get install -y --no-install-recommends gh nodejs; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    docker-php-ext-configure intl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        intl pdo_mysql pdo_sqlite zip bcmath gd; \
    pecl install redis; \
    docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
RUN npm install -g @anthropic-ai/claude-code
RUN useradd --create-home --shell /bin/bash --uid 1000 agent

# ============================================================
# worker-php84: Application layer on PHP 8.4 base
# ============================================================
FROM base-php84 AS worker-php84

COPY worker/lib/      /usr/local/share/agent/lib/
COPY worker/phases/   /usr/local/share/agent/phases/
COPY worker/prompts/  /usr/local/share/agent/prompts/
COPY worker/schemas/  /usr/local/share/agent/schemas/

COPY .tools/docker/worker/worker-entrypoint.sh /usr/local/bin/worker-entrypoint.sh
RUN chmod +x /usr/local/bin/worker-entrypoint.sh \
    && chown -R agent:agent /usr/local/share/agent \
    && mkdir -p /workspace \
    && chown agent:agent /workspace \
    && mkdir -p /run/agent \
    && chown agent:agent /run/agent \
    && mkdir -p /home/agent/.npm /home/agent/.composer/cache \
    && chown -R agent:agent /home/agent/.npm /home/agent/.composer

USER agent
# safe.directory: clones happen as `agent` into bind-mounted /workspace, where
# the host UID may differ from the container's — git would otherwise refuse.
# http.postBuffer + http.version: workaround for the GnuTLS-linked Debian git
# dropping the sideband stream on large pack files (curl 56 / GnuTLS -110)
# behind some reverse proxies. 500 MiB postBuffer forces a single request,
# HTTP/1.1 dodges proxies with broken HTTP/2 sideband handling.
RUN git config --global --add safe.directory '*' \
    && git config --global http.postBuffer 524288000 \
    && git config --global http.version HTTP/1.1
WORKDIR /workspace

ENTRYPOINT ["/usr/local/bin/worker-entrypoint.sh"]
CMD []
