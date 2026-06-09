# Setup Guide

This guide covers everything beyond the README's one-liner: persistent
storage, custom worker images, MariaDB, pre-release builds, and pre-seeded
secrets.

For just trying Argos out, follow the [Quick Start in the README](../README.md#quick-start).

## Architecture in one paragraph

Argos runs as a Docker Compose stack of six services: an **app** container
(Laravel + PHP-FPM) fronted by **nginx**, backed by **MariaDB**, with **Redis**
as the queue backend, a **queue** worker running **Laravel Horizon**, and a
**scheduler** running Laravel's scheduler. The app container spawns short-lived
**worker** containers via the host Docker socket — all AI runs inside the
worker, the app process never touches Claude directly. The compose file is the
deployment unit; only the manager image is pulled, worker images are built on
demand from the local repo.

| Service | Image | Purpose |
|---|---|---|
| `app` | `ghcr.io/nodus-it/argos-app` | Web UI + [MCP server](SETUP-MCP.md). Needs the Docker socket to spawn workers. |
| `queue` | `ghcr.io/nodus-it/argos-app` | Laravel Horizon worker — runs the task phases (`RunPhaseJob`) on Redis. |
| `scheduler` | `ghcr.io/nodus-it/argos-app` | Laravel scheduler — dispatches recurring jobs (e.g. issue polling). |
| `worker` | `argos-worker:<stack>-<hash>-<agent>-<version>` | Built on demand by the manager from `worker_stacks` rows × the chosen agent. |

The stack also runs `mariadb:11`, `redis:7-alpine`, and `nginx:1.27-alpine` —
stock upstream images, no Argos build.

## Production-ish setup

Run the installer with a dedicated install directory, then layer your
customisations into a `docker-compose.override.yml` next to the shipped
compose file:

```bash
mkdir -p /srv/argos
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/.tools/install.sh \
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

The app container builds the worker image the first time a task runs (1-3
minutes cold, instant after). To pre-warm the default `php-8.4 × claude-code`
image manually:

```bash
docker compose -f /srv/argos/docker-compose.yml exec app \
    php artisan tinker --execute='app(App\Workers\Compose\WorkerImageBuilder::class)
        ->build(app(App\Workers\Compose\WorkerImageResolver::class)->resolveFor(
            App\Models\WorkerStack::where("name", "php-8.4")->firstOrFail(),
            App\Enums\AgentName::ClaudeCode,
        ));'
```

## Pre-seeding the Claude token

The token lives **per agent in the database**. The normal path is the in-app
onboarding step, or **Worker → Agent Credentials** in the admin — a DB
credential always wins (there is no `CLAUDE_CODE_OAUTH_TOKEN` env-var path
anymore).

Generate a token with the Claude Code CLI (signed in to your Pro / Max / Team
plan):

```bash
claude setup-token
```

Paste it into onboarding / Agent Credentials. For an unattended local-dev
seed you can instead drop the raw token into a file at
`$ARGOS_CONFIG_DIR/claude_token` (default `~/.config/argos/claude_token`): the
worker reads it as a last-resort fallback, and the next `migrate` imports it
into an Agent Credential. Tokens expire after a few weeks — refresh them in the
UI (or update the file) and re-run `claude setup-token`.

## Choosing a worker stack and agent

Worker images are no longer pulled from GHCR — they are built on demand by
the manager from the `worker_stacks` table the first time a task with that
combination of (stack × agent × version) runs. The first run takes 1-3
minutes; subsequent runs use the cached image.

Pick the stack and agent in **Worker → Stacks** and **Worker → Agent
Credentials** in the admin UI, then assign defaults per project and (if you
want) overrides per task. Built-in stacks (`php-8.3`, `php-8.4`, …) are
mirrored from the repo manifest into the DB on every `migrate`; you can add
your own user stack in the same UI.

To tailor a *target* repository to Argos — a custom build environment
(`.argos/worker.dockerfile`) or a live-demo contract (`.argos/demo.*`) — see
the agent-oriented guide in [PREPARE-PROJECT.md](PREPARE-PROJECT.md).

## Pre-release / develop builds

Every push to the `develop` branch publishes the rolling manager image
`ghcr.io/nodus-it/argos-app:stage`. It tracks unreleased work and may break —
useful for previewing fixes, **not** for production.

To track it, install from the develop branch and pin `ARGOS_APP_IMAGE` to the
rolling tag in your `.env`:

```bash
ARGOS_VERSION=develop \
    curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/.tools/install.sh \
    | bash -s -- --dir ./argos-develop

# in ./argos-develop/.env:
#   ARGOS_APP_IMAGE=ghcr.io/nodus-it/argos-app:stage
docker compose -f ./argos-develop/docker-compose.yml pull
docker compose -f ./argos-develop/docker-compose.yml up -d
```

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
(Caddy, nginx, Traefik, HAProxy) and forward there. Make sure to:

- set `APP_URL` to the public URL (`https://argos.example.com`)
- forward `X-Forwarded-Proto: https` (and `X-Forwarded-For` / `X-Forwarded-Host`)
- use that same URL when registering OAuth apps (the redirect URI must match)

Argos trusts the forwarded headers from any upstream proxy, so the app sees
`https` once the proxy sets `X-Forwarded-Proto`. Without that header asset
URLs render as `http://` and the browser flags mixed content.

HAProxy snippet (paste into the Argos backend's *Advanced pass thru*):

```
option forwardfor
http-request set-header X-Forwarded-Proto https if { ssl_fc }
```

## Environment variables

See [Configuration Reference](CONFIGURATION.md) for the complete list with
defaults.
