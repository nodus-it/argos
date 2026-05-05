# Setup Guide

This guide covers everything beyond the README's one-liner: persistent
storage, custom worker images, MariaDB, pre-release builds, and pre-seeded
secrets.

For just trying Argos out, follow the [Quick Start in the README](../README.md#quick-start).

## Architecture in one paragraph

Argos runs as a Docker Compose stack: an **app** container (Laravel + PHP-FPM)
fronted by **nginx**, backed by **MariaDB**, with a separate **queue** worker.
The app container spawns short-lived **worker** containers via the host
Docker socket — all AI runs inside the worker, the app process never touches
Claude directly. The compose file is the deployment unit; you only need the
worker image tag, the app pulls workers on demand.

| Image | Purpose |
|---|---|
| `ghcr.io/nodus-it/argos-app` | Web UI + queue. Needs the Docker socket to spawn workers. |
| `ghcr.io/nodus-it/argos-worker:php8.4` | Claude Code, Git, PHP 8.4, Node, Composer. Spawned per task. |
| `ghcr.io/nodus-it/argos-worker:php8.3` | Same, with PHP 8.3 for older projects. |

The compose stack also runs `mariadb:11` and `nginx:1.27-alpine` — these are
stock upstream images, no Argos build.

## Production-ish setup

Run the installer with a dedicated install directory, then layer your
customisations into a `docker-compose.override.yml` next to the shipped
compose file:

```bash
mkdir -p /srv/argos
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/install.sh \
    | bash -s -- --dir /srv/argos
```

```yaml
# /srv/argos/docker-compose.override.yml
services:
  app:
    environment:
      APP_URL: https://argos.example.com
    volumes:
      - /srv/argos-backups/data:/data
  db:
    volumes:
      - /srv/argos-backups/db:/var/lib/mysql
```

```bash
docker compose -f /srv/argos/docker-compose.yml up -d
```

The override gives you:

- **bind mounts** instead of named volumes, so backups and snapshots happen
  with your host tooling
- `APP_URL` so OAuth callbacks resolve correctly behind a reverse proxy

The installer already generated a strong `ADMIN_PASSWORD` and DB passwords in
`/srv/argos/.env` — don't override those in the compose file (you'd be
hard-coding secrets in plaintext); edit `.env` if you need to change them.

The app container will pull `ghcr.io/nodus-it/argos-worker:php8.4` the first
time a task runs. To pre-pull:

```bash
docker compose -f /srv/argos/docker-compose.yml exec app \
    docker pull ghcr.io/nodus-it/argos-worker:php8.4
```

## Pre-seeding the Claude token

Skip the in-app onboarding step by setting the token in your `.env`:

```
CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-...
```

Generate the token with the Claude Code CLI (signed in to your Pro / Max /
Team plan):

```bash
claude setup-token
```

The token is read on every boot and takes precedence over what the UI shows.
Tokens expire after a few weeks — re-run `claude setup-token` and update the
env var (or paste the new token in the UI).

## Choosing a worker image

| Use case | `ARGOS_WORKER_IMAGE` |
|---|---|
| Standard Laravel projects (default) | `ghcr.io/nodus-it/argos-worker:php8.4` |
| Legacy PHP 8.3 projects | `ghcr.io/nodus-it/argos-worker:php8.3` |
| Pre-release (auto-built from `dev`) | `ghcr.io/nodus-it/argos-worker:stage-php8.4` |
| Locally built (compose) | `argos-worker:local-php8.4` |
| Custom image | `your-registry/your-worker:tag` |

The image is also overridable per project and per task in the UI.

## Pre-release / stage builds

Every push to the `develop` branch publishes:

- `ghcr.io/nodus-it/argos-app:stage`
- `ghcr.io/nodus-it/argos-worker:stage-php8.3`
- `ghcr.io/nodus-it/argos-worker:stage-php8.4`

These tags track unreleased work and may break — useful for previewing fixes,
**not** for production. To install the stage stack, point the installer at
the develop branch and edit `.env` afterwards to pin the worker tag:

```bash
ARGOS_VERSION=develop \
    curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/install.sh \
    | bash -s -- --dir ./argos-stage

# Edit ./argos-stage/.env:
#   ARGOS_APP_IMAGE=ghcr.io/nodus-it/argos-app:stage
#   ARGOS_WORKER_IMAGE=ghcr.io/nodus-it/argos-worker:stage-php8.4

docker compose -f ./argos-stage/docker-compose.yml pull
docker compose -f ./argos-stage/docker-compose.yml up -d
```

When working on Argos itself (developing against a checkout of the repo),
`composer run stage` wraps this flow against `.tools/bash/.env.stage` instead
of using the installer.

For stable use, stick with `:latest` or a `vX.Y.Z` tag.

## OAuth setup (optional)

By default Argos works with Personal Access Tokens (PATs) — paste a token per
project and you're done. **OAuth** is the optional upgrade that enables:

- Repository and branch dropdowns when creating a project
- Per-user authentication (each user connects their own accounts)
- Self-hosted GitLab support without per-project token management

See provider-specific guides:

- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md)
- [Bitbucket Setup](SETUP-BITBUCKET.md)
- [OAuth Overview](OAUTH.md) — when you're not sure which mode to pick

## Reverse proxy

Argos serves plain HTTP on the host port set by `ARGOS_PORT` (default `8080`,
mapped to nginx:80 in the compose stack). Terminate TLS at your reverse proxy
(Caddy, nginx, Traefik) and forward there. Make sure to:

- set `APP_URL` to the public URL (`https://argos.example.com`)
- forward the `X-Forwarded-Proto: https` header
- use that same URL when registering OAuth apps (the redirect URI must match)

## Environment variables

See [Configuration Reference](CONFIGURATION.md) for the complete list with
defaults.
