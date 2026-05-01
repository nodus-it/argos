# syntax=docker/dockerfile:1.7

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
COPY lib/      /usr/local/share/agent/lib/
COPY phases/   /usr/local/share/agent/phases/
COPY prompts/  /usr/local/share/agent/prompts/
COPY schemas/  /usr/local/share/agent/schemas/

# Entrypoint
COPY docker/worker-entrypoint.sh /usr/local/bin/worker-entrypoint.sh
RUN chmod +x /usr/local/bin/worker-entrypoint.sh \
    && chown -R agent:agent /usr/local/share/agent \
    && mkdir -p /workspace \
    && chown agent:agent /workspace \
    && mkdir -p /run/agent \
    && chown agent:agent /run/agent

USER agent
WORKDIR /workspace

ENTRYPOINT ["/usr/local/bin/worker-entrypoint.sh"]
CMD []
