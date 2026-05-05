# Setup Guide

This guide covers everything beyond the README's one-liner: persistent
storage, custom worker images, MariaDB, pre-release builds, and pre-seeded
secrets.

For just trying Argos out, follow the [Quick Start in the README](../README.md#quick-start).

## Architecture in one paragraph

Argos ships as two images. The **manager** runs the web UI, queue, and
database, and spawns short-lived **worker** containers via the Docker socket.
All AI runs inside the worker — the manager process never touches Claude
directly. You only ever pull and run the manager; it pulls workers on demand.

| Image | Purpose |
|---|---|
| `ghcr.io/nodus-it/argos-manager` | Web UI + queue + DB. Needs the Docker socket to spawn workers. |
| `ghcr.io/nodus-it/argos-worker:php8.4` | Claude Code, Git, PHP 8.4, Node, Composer. Spawned per task. |
| `ghcr.io/nodus-it/argos-worker:php8.3` | Same, with PHP 8.3 for older projects. |

## Production-ish setup

```bash
docker run -d --name argos \
  -p 8080:80 \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v /srv/argos/data:/data \
  -v /srv/argos/db:/var/lib/mysql \
  -e APP_URL=https://argos.example.com \
  -e ADMIN_PASSWORD='change-me' \
  ghcr.io/nodus-it/argos-manager:latest
```

Compared to the README one-liner, this:

- uses **bind mounts** (`/srv/argos/...`) instead of Docker volumes, so
  backups and snapshots happen with your host tooling
- sets `APP_URL` so OAuth callbacks resolve correctly behind a reverse proxy
- sets `ADMIN_PASSWORD` instead of relying on the `12345` default

The manager will pull `ghcr.io/nodus-it/argos-worker:php8.4` the first time a
task runs. To pre-pull:

```bash
docker exec argos docker pull ghcr.io/nodus-it/argos-worker:php8.4
```

## Pre-seeding the Claude token

Skip the in-app onboarding step by passing the token at startup:

```bash
-e CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-...
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

Every push to the `dev` branch publishes:

- `ghcr.io/nodus-it/argos-manager:stage`
- `ghcr.io/nodus-it/argos-worker:stage-php8.3`
- `ghcr.io/nodus-it/argos-worker:stage-php8.4`

These tags track unreleased work and may break — useful for previewing fixes,
**not** for production. When you run the manager from `:stage`, point it at
the matching worker tag:

```bash
-e ARGOS_WORKER_IMAGE=ghcr.io/nodus-it/argos-worker:stage-php8.4
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

Argos serves plain HTTP on port 80 inside the container. Terminate TLS at
your reverse proxy (Caddy, nginx, Traefik) and forward to the manager. Make
sure to:

- set `APP_URL` to the public URL (`https://argos.example.com`)
- forward the `X-Forwarded-Proto: https` header
- use that same URL when registering OAuth apps (the redirect URI must match)

## Environment variables

See [Configuration Reference](CONFIGURATION.md) for the complete list with
defaults.
