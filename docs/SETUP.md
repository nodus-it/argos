# Setup Guide

This is the install and setup guide for operators running an Argos instance.
It covers prerequisites, the one-command installer and its release channels,
what the stack runs, first boot and first login, the reverse-proxy / `APP_URL`
note, updating, and where to go next (onboarding, OAuth, worker stacks).

For the complete environment-variable reference, see
[CONFIGURATION.md](CONFIGURATION.md). For a quick try-out, the
[Quick Start in the README](../README.md#quick-start) is the same one-liner
with less detail.

## Contents

- [Prerequisites](#prerequisites)
- [One-command install](#one-command-install)
- [Release channels](#release-channels)
- [What the stack runs](#what-the-stack-runs)
- [First boot](#first-boot)
- [First login and onboarding](#first-login-and-onboarding)
- [Reverse proxy and APP_URL](#reverse-proxy-and-app_url)
- [OAuth (optional)](#oauth-optional)
- [Choosing a worker stack and agent](#choosing-a-worker-stack-and-agent)
- [Pre-seeding the Claude token](#pre-seeding-the-claude-token)
- [Production-style install](#production-style-install)
- [Updating](#updating)
- [Reset and backup](#reset-and-backup)
- [Environment variables](#environment-variables)

## Prerequisites

You need one Linux host with:

- **Docker Engine 20.10+** with the **Compose v2 plugin** (`docker compose`,
  not the legacy `docker-compose`). The installer checks this and refuses to
  run otherwise.
- Your user able to talk to the Docker daemon (in the `docker` group, or run
  the installer as root). The `app` and `queue` containers mount the host
  Docker socket to spawn short-lived worker containers.
- `curl`, `openssl`, and `sha256sum` on the host (the installer uses them to
  download manifests and generate secrets).

Optional but recommended for anything beyond local trial:

- **A domain and a TLS-terminating reverse proxy** (Caddy, nginx, Traefik,
  HAProxy). You need this for OAuth callbacks to resolve correctly and for
  HTTPS. See [Reverse proxy and APP_URL](#reverse-proxy-and-app_url).

You do **not** need to pre-build anything. The manager image is pulled from
GHCR; worker images are built on demand from the local manifests on the first
phase run.

## One-command install

Run the installer. It downloads `docker-compose.yml` and `.env.example`,
generates a `.env` with random secrets, and brings the stack up:

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh | bash
```

By default this installs into the **current directory** (`$PWD`). To install
elsewhere, pass `--dir` (or set `ARGOS_INSTALL_DIR`):

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /opt/argos
```

When it finishes, the installer prints a summary with the URL, the admin
login, and the generated admin password.

Installer flags (append after `bash -s --`, or set the env var):

| Flag | Env var | Effect |
|---|---|---|
| `--dir PATH` | `ARGOS_INSTALL_DIR` | Install into `PATH` instead of `$PWD` |
| `--version REF` | `ARGOS_VERSION` | Pin a specific Git tag or branch |
| `--stage` | `ARGOS_STAGE=1` | Track the rolling `:stage` images from `develop` |
| `--next` | `ARGOS_NEXT=1` | Track the rolling `:next` images from the `next` integration branch |
| `--beta` | `ARGOS_BETA=1` | Install the latest release including pre-releases |
| `--reset` | — | Tear down the stack and wipe all data (destructive) |
| `--force` | — | Skip safety prompts (required for `--reset` in non-interactive shells) |
| `--help` | — | Show all options |

The installer refuses to drop files into a non-empty directory unless you pass
`--force`. Layer your own customisations into a `docker-compose.override.yml`
next to the shipped compose file — the installer never touches that file (see
[Production-style install](#production-style-install)).

## Release channels

The channel decides which image tag the `app`/`queue`/`scheduler` services pull
(`ARGOS_APP_IMAGE` in `.env`) and which branch the installer fetches manifests
from. The choice is **per invocation** — re-pass the flag on update to keep
tracking the same channel.

| Channel | How to select | Branch / source | Image tag | When to use |
|---|---|---|---|---|
| **release** (default) | no flag | latest stable release tag | `:latest` | Production. The default. |
| **beta** | `--beta` | latest release incl. pre-releases | the resolved tag | Track RC builds, or before the first stable release ships |
| **stage** | `--stage` | `develop` | `:stage` | Preview the next release line — not for production |
| **next** | `--next` | `next` (integration line) | `:next` | Preview the upcoming version — ahead of `develop`, least stable |

If no stable release exists yet, the default channel transparently falls back
to the newest pre-release (with a warning), and ultimately to the `develop`
branch.

```bash
# stage (rolling develop)
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/.tools/install.sh \
    | bash -s -- --dir ./argos-stage --stage

# next (rolling integration line)
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/next/.tools/install.sh \
    | bash -s -- --dir ./argos-next --next
```

`--stage` and `--next` are mutually exclusive; pick one.

## What the stack runs

Argos is a Laravel app deployed as a Docker Compose stack. The compose file is
the deployment unit. Only the **manager image** (`ghcr.io/nodus-it/argos-app`)
is pulled; worker images are built on demand on the host.

| Service | Image | Purpose |
|---|---|---|
| `db` | `mariadb:11` | Application database. Data lives in the `argos-db` volume. |
| `redis` | `redis:7-alpine` | Queue backend for Horizon. Data in the `argos-redis` volume. |
| `app` | `ghcr.io/nodus-it/argos-app` | Web UI + [MCP server](SETUP-MCP.md) + REST API (PHP-FPM). Runs migrations on boot; mounts the Docker socket to spawn workers. |
| `traefik` | `traefik:v3.5` | The single port-80 entry point. Published on the host via `ARGOS_PORT`. Routes to nginx (and to live-demo containers). |
| `nginx` | `nginx:1.27-alpine` | Serves static assets and proxies dynamic requests to `app:9000`. Sits behind Traefik. |
| `queue` | `ghcr.io/nodus-it/argos-app` | Laravel Horizon worker — runs the task phases (`RunPhaseJob`) on Redis. Same image as `app`. |
| `scheduler` | `ghcr.io/nodus-it/argos-app` | Laravel scheduler (`schedule:work`) — dispatches recurring jobs (e.g. issue polling). Same image as `app`. |
| `worker` (transient) | `argos-worker:<stack>-<hash>-<agent>-<version>` | Short-lived container spawned per phase by the `app`/`queue` services. **All AI runs here** — the manager never touches Claude directly. Built on demand from the `worker_stacks` row × the chosen agent. |

The host port is set by **`ARGOS_PORT`** (default `8080`), mapped to
**Traefik's** port 80. Everything not matched by a live-demo host rule falls
through Traefik → nginx → app, so `http://localhost:8080` keeps serving the
app.

## First boot

On the first boot the `app` container's entrypoint runs (in order):

1. Maps the Docker socket's GID onto `www-data` so PHP can spawn worker
   containers.
2. Resolves `APP_KEY` — uses the value from `.env` if set (the installer
   generated one), otherwise persists an auto-generated key under the
   `argos-data` volume.
3. Syncs Composer dependencies and the package-discovery cache when stale.
4. Seeds the Traefik route, the nginx config, and the public assets into their
   shared volumes; generates the Passport signing keys (once) under
   `argos-data`.
5. Waits for the database, then — **only on the `app` role** — runs
   `migrate --force`, seeds the admin user (`AdminUserSeeder`), and dispatches
   pre-warm builds for the default worker image and live-demo image.

The `queue` and `scheduler` services share the same image but skip the schema
work — the `app` service owns migrations and seeding.

The `app` container reports healthy only once the database is reachable **and**
the schema is fully migrated; `nginx`, `queue`, and `scheduler` wait for that
gate. The first phase a fresh install runs builds its worker image (1–3 minutes
cold; the pre-warm step on boot hides most of this), then subsequent runs use
the cached image.

## First login and onboarding

Open the admin UI:

- `http://localhost:8080/admin` (or your `APP_URL` + `/admin`). Visiting `/`
  redirects to `/admin`.

Log in with the credentials from the installer summary:

- **Email:** `admin@argos.local`
- **Password:** the generated `ADMIN_PASSWORD` from the summary (also in
  `.env`).

Change the admin password under **Profile** after the first login.

An in-app **onboarding wizard** then walks you through pasting your Claude
token and creating your first project. To make a target repository
"Argos-ready" (a custom build environment or a live-demo contract), see
[PREPARE-PROJECT.md](PREPARE-PROJECT.md).

## Reverse proxy and APP_URL

The stack serves plain HTTP on the host port set by `ARGOS_PORT` (default
`8080`, mapped to Traefik's port 80). For anything public, terminate TLS at
your reverse proxy (Caddy, nginx, Traefik, HAProxy) and forward to that port.

Make sure to:

- Set **`APP_URL`** to the public URL (e.g. `https://argos.example.com`).
  `APP_URL` is the single source of truth for host and scheme — OAuth
  callbacks, the session-cookie domain, and live-demo subdomains all derive
  from it.
- Forward `X-Forwarded-Proto: https` (and `X-Forwarded-For` /
  `X-Forwarded-Host`). Argos and the bundled Traefik trust forwarded headers
  from any upstream proxy. Without `X-Forwarded-Proto`, asset URLs render as
  `http://` and the browser flags mixed content.
- Use that same `APP_URL` when registering OAuth apps — the redirect URI must
  match.

HAProxy snippet (paste into the Argos backend's *Advanced pass thru*):

```
option forwardfor
http-request set-header X-Forwarded-Proto https if { ssl_fc }
```

See the session/HTTPS variables (`SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`) in
[CONFIGURATION.md#sessions-reverse-proxy--https](CONFIGURATION.md#sessions-reverse-proxy--https)
— both derive from `APP_URL` and rarely need setting.

## OAuth (optional)

By default Argos works with Personal Access Tokens (PATs) — paste a token per
project and you're done. **OAuth** is the optional upgrade that enables:

- Repository and branch dropdowns when creating a project
- Per-user authentication (each user connects their own accounts)
- Self-hosted GitLab support without per-project token management

OAuth client credentials are managed entirely in the UI (Configuration → OAuth
Apps) and stored in the database — there is no ENV path. See:

- [OAuth Overview](OAUTH.md) — when you're not sure which mode to pick
- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md)
- [Bitbucket Setup](SETUP-BITBUCKET.md)

## Choosing a worker stack and agent

Worker images are **not** pulled from GHCR — the manager builds them on demand
from the `worker_stacks` table the first time a task with that combination of
(stack × agent × version) runs. The first run takes 1–3 minutes; subsequent
runs use the cached image.

Pick the stack and agent in **Worker → Stacks** and **Worker → Agent
Credentials** in the admin UI, then assign defaults per project and (optionally)
overrides per task. Built-in stacks (`php-8.3`, `php-8.4`, …) are mirrored from
the repo manifest into the DB on every migration; you can add your own user
stack in the same UI.

To tailor a *target* repository to Argos — a custom build environment
(`.argos/worker.dockerfile`) or a live-demo contract (`.argos/demo.*`) — see
[PREPARE-PROJECT.md](PREPARE-PROJECT.md).

## Pre-seeding the Claude token

The token lives **per agent in the database**. The normal path is the in-app
onboarding step, or **Worker → Agent Credentials** in the admin — a DB
credential always wins (there is no `CLAUDE_CODE_OAUTH_TOKEN` env-var path).

Generate a token with the Claude Code CLI (signed in to your Pro / Max / Team
plan):

```bash
claude setup-token
```

Paste it into onboarding / Agent Credentials. Tokens expire after a few weeks —
refresh them in the UI and re-run `claude setup-token`.

For an unattended local-dev seed you can instead drop the raw token into a file
at `$ARGOS_CONFIG_DIR/claude_token` (default `/data/config/claude_token` in the
compose stack): the worker reads it as a last-resort fallback, and the next
migration imports it into an Agent Credential.

## Production-style install

Run the installer with a dedicated install directory, then layer your
customisations into a `docker-compose.override.yml` next to the shipped compose
file. Compose merges the override automatically and the installer never touches
it:

```bash
mkdir -p /srv/argos
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
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

The override above gives you:

- **bind mounts** instead of named volumes, so backups and snapshots happen
  with your host tooling
- `APP_URL` so OAuth callbacks resolve correctly behind a reverse proxy

The installer already generated a strong `ADMIN_PASSWORD`, `APP_KEY`, and DB
passwords in `/srv/argos/.env` — don't move those into the compose file (you'd
hard-code secrets in plaintext). Edit `.env` if you need to change them.

## Updating

Re-run the installer in the **same install directory** to update. It downloads
any newer `docker-compose.yml`, merges new keys from the upstream
`.env.example` into your `.env` without touching existing values, then pulls
images and restarts the stack:

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /srv/argos
```

Notes:

- Re-pass `--stage` / `--next` on update to keep tracking that channel —
  otherwise the next update reverts to the default release tag.
- If you locally edited the installer-managed `docker-compose.yml`, the
  installer refuses to overwrite it and points you at
  `docker-compose.override.yml`. Move custom config there.
- After update, `app` re-runs migrations on boot — no manual migrate step.

## Reset and backup

**Backup.** The state to back up lives in the named volumes:

- `argos-db` — the MariaDB database (the primary backup target)
- `argos-data` — persisted app state (`APP_KEY` fallback, Passport keys,
  `ARGOS_CONFIG_DIR`)

Use bind mounts (see [Production-style install](#production-style-install)) to
back these up with your host tooling, or snapshot the volumes directly. The
generated `.env` (mode `600`) holds your secrets — back it up too, since
`APP_KEY` is required to decrypt stored credentials.

**Reset (destructive).** `--reset` tears down the stack and wipes all of its
named volumes, including the database:

```bash
# from a local checkout of the install dir (interactive: prompts for "yes")
bash /srv/argos/../argos-src/.tools/install.sh --dir /srv/argos --reset

# non-interactive (curl | bash): --force is required
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /srv/argos --reset --force
```

`--force` is required for `--reset` in a non-interactive shell (e.g.
`curl | bash`). The reset only touches volumes declared in this stack's compose
file — never unrelated compose projects.

## Environment variables

See the [Configuration Reference](CONFIGURATION.md) for every variable Argos
reads, with defaults. The keys most operators set:

- `APP_URL` — public URL (host + scheme), see
  [Reverse proxy and APP_URL](#reverse-proxy-and-app_url)
- `ARGOS_PORT` — host port for the stack (default `8080`)
- `ARGOS_APP_IMAGE` — the manager image tag (set by the channel flags)
- `ADMIN_PASSWORD`, `APP_KEY`, `ARGOS_DB_PASSWORD`, `ARGOS_DB_ROOT_PASSWORD` —
  generated by the installer; don't hand-edit unless you know why
