# Configuration Reference

All Argos configuration is controlled via environment variables passed to the
`app` (and `queue` / `scheduler`) services in the compose stack — or your
`.env` file when running locally. This page is the operator reference for every
ENV var Argos actually reads.

> [!NOTE]
> Argos runs with zero config locally — SQLite, all defaults. Only override
> what you need. Two boundaries to keep in mind:
>
> - **`APP_KEY`** is auto-generated on first manager boot and persisted; you
>   never set it unless restoring a backup.
> - **OAuth apps and the Claude agent credentials are UI/DB-managed, not ENV.**
>   See [OAuth & credentials](#oauth--credentials-ui-managed) below.

## Contents

- [Core / App & URL](#core--app--url)
- [Sessions (reverse proxy / HTTPS)](#sessions-reverse-proxy--https)
- [Database](#database)
- [Queue, Redis & Horizon](#queue-redis--horizon)
- [Worker image](#worker-image)
- [Worker backing services (test sidecars)](#worker-backing-services-test-sidecars)
- [Live previews](#live-previews)
- [Workspaces](#workspaces)
- [Realtime (Reverb)](#realtime-reverb)
- [Integrations & polling](#integrations--polling)
- [OAuth & credentials (UI-managed)](#oauth--credentials-ui-managed)
- [MCP server (Argos API)](#mcp-server-argos-api)
- [Repository settings in tasks](#repository-settings-in-tasks)
- [Logging](#logging)
- [Compose / OPS-level keys](#compose--ops-level-keys)

## Core / App & URL

| Variable | Default | Purpose |
|---|---|---|
| `APP_NAME` | `Argos` | Display name used in the browser title and headers. Also seeds the Horizon Redis prefix. |
| `APP_ENV` | `production` | `local`, `staging`, or `production`. Toggles dev-only tooling (e.g. the one-click developer login is `local`-only). |
| `APP_KEY` | auto-generated on first boot | Laravel encryption key. Pin only when restoring backups. |
| `APP_PREVIOUS_KEYS` | – | Comma-separated list of past `APP_KEY` values, kept available for decrypting old data after a key rotation. |
| `APP_DEBUG` | `false` | Enable detailed error pages. **Never enable in production.** |
| `APP_URL` | `http://localhost` | Base URL of the Argos instance and the **single source of truth** for host + scheme. OAuth callbacks, the session cookie domain and the live-demo subdomains all derive from it. **Must match the public URL.** |
| `APP_LOCALE` | `en` | Default UI language (`en` or `de`). |
| `ADMIN_PASSWORD` | `12345` | Password for the auto-created admin user. **Change before exposing the instance.** |
| `ARGOS_CONFIG_DIR` | `~/.config/argos` (compose: `/data/config`) | Directory for persisted config / the default SQLite path inside the manager. |
| `ARGOS_SOURCE_URL` | `https://github.com/nodus-it/argos` | AGPL-3.0 §13 source-offer URL surfaced in the UI. **Forks must override this** with their own source URL. |
| `ARGOS_FEEDBACK_URL` | *(empty)* | Where *Send feedback* in the user menu leads — e.g. your own tracker or chat channel for a beta. A GitHub or GitLab `…/issues/new` link is prefilled with version and page. Empty: the upstream issue chooser. |
| `ARGOS_ACCESS_LINK_HOURS` | `72` | How long an access link (set or reset a password, see [Adding colleagues](SETUP.md#adding-colleagues)) stays valid. |
| `ARGOS_VERSION` | – | Overrides the baked-in app version string. CI bakes a `stage-…` value into stage images; leave unset for releases. |

## Sessions (reverse proxy / HTTPS)

Both values **derive from `APP_URL`** and rarely need setting:

| Variable | Default | Purpose |
|---|---|---|
| `SESSION_DOMAIN` | derived: `.<APP_URL host>` for a real domain, host-only for localhost/IP/`nip.io` | Cookie domain. The leading dot lets the session span preview subdomains (`demo-<workspace>-<task>.<host>`). Override only when demos live on a domain different from the app. |
| `SESSION_SECURE_COOKIE` | derived: `true` when `APP_URL` is `https://` | Force `Secure` on the session cookie. Set explicitly only when terminating TLS at a proxy that rewrites the scheme. |
| `SESSION_COOKIE` | `argos_session` | Name of the session cookie. Rarely changed. |

## Database

`DB_CONNECTION` selects the driver. SQLite is the default and needs no further
config. Set it to `mariadb` to use the MariaDB sidecar (compose) or an external
server. The compose stack sets `mariadb`.

| Variable | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `sqlite` or `mariadb`. |
| `DB_DATABASE` | `~/.config/argos/argos.db` | SQLite file path. Ignored for `mariadb`. |
| `ARGOS_DB_HOST` | `127.0.0.1` | MariaDB host. The compose stack sets `db`. |
| `ARGOS_DB_PORT` | `3306` | MariaDB port. |
| `ARGOS_DB_DATABASE` | `argos` | Database name. |
| `ARGOS_DB_USERNAME` | `argos` | Database user. |
| `ARGOS_DB_PASSWORD` | – (empty) | Database password. |
| `ARGOS_DB_SSL_CA` | – | Optional path to a TLS CA bundle for the MariaDB connection. |
| `ARGOS_DB_URL` | – | Full DSN — overrides the individual fields above. |

## Queue, Redis & Horizon

Background jobs — workspace provisioning, task preparation, image builds, previews and issue polling — run on **Laravel
Horizon**, backed by **Redis**. In the compose stack the `redis` service and
the `queue` (Horizon) + `scheduler` workers are wired up automatically; you
normally only tune the process counts.

| Variable | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | `database` (compose: `redis`) | Queue driver. The compose stack sets `redis` so Horizon processes jobs; bare `artisan serve` dev and the test suite fall back to the database queue. |
| `ARGOS_REDIS_HOST` | falls back to `REDIS_HOST`, then `redis` | Redis host. The compose stack sets `REDIS_HOST=redis`. |
| `ARGOS_REDIS_PORT` | falls back to `REDIS_PORT`, then `6379` | Redis port. |
| `ARGOS_REDIS_PASSWORD` | falls back to `REDIS_PASSWORD` | Redis password (if your Redis requires auth). |
| `ARGOS_QUEUE_DEFAULT_PROCESSES` | `5` | Horizon worker processes for the `default` queue (`minProcesses` = `maxProcesses`). |
| `ARGOS_QUEUE_TASKS_PROCESSES` | `2` | Horizon worker processes for the `tasks` queue (workspace provisioning and stopping). |

> `ARGOS_REDIS_*` take precedence; the plain `REDIS_HOST` / `REDIS_PORT` /
> `REDIS_PASSWORD` Laravel defaults are honored as a fallback (and are what the
> compose stack actually sets).

## Worker image

The worker image (stack + agent + gate helpers) is the base every workspace
image is built from. Resource limits of a running workspace are set under
[Workspaces](#workspaces).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_DEFAULT_STACK` | `php-8.5` | Slug of the stack used when a workspace sets none: `php-8.5`, `php-8.4` or `node-22` (the built-ins, mirrored into `worker_stacks` on `migrate`). See [Stacks and Images](WORKER-STACKS.md). |

## Worker backing services (test sidecars)

Argos can boot backing-service sidecars next to a workspace (one private
network per workspace, data kept until the workspace is deleted) so a project's
tests can talk to a real MySQL/Redis. Each task gets its own database and Redis
prefix on them. A workspace opts in per service (Settings → Environment).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_WORKER_SERVICE_TIMEOUT` | `60` | Seconds to wait for a backing service to become ready before the workspace start fails. |
| `ARGOS_WORKER_MYSQL_IMAGE` | `mariadb:11` | Image used for the MySQL/MariaDB sidecar. |
| `ARGOS_WORKER_REDIS_IMAGE` | `redis:7-alpine` | Image used for the Redis sidecar. |

## Live previews

Live previews of tasks, routed by Traefik under their own subdomain and
guarded by the workspace gate (members only). On by default at the platform level, but the per-workspace toggle
(*Enable live demo* / `live_demo_enabled`, on for new workspaces) is the real gate per workspace. See [Previews](LIVE-DEMOS.md).
Base domain + scheme **derive from `APP_URL`** — override only for a demo
domain different from the app. Requires wildcard DNS `*.<host>` resolving to
this host (and the Traefik + `argos_edge` preview infra).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_PREVIEW_ENABLED` | `true` (compose forwards `false`) | Master switch for the live-demo infrastructure. Set `false` to disable platform-wide (e.g. no Traefik/preview infra). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | derived from `APP_URL` host (`127.0.0.1.nip.io` for bare/localhost) | Previews live at `demo-<workspace>-<task>.<base_domain>`, editors at `code-<workspace>.<base_domain>`. |
| `ARGOS_PREVIEW_SCHEME` | derived from `APP_URL` scheme | `http` or `https` used in the demo URL. |
| `ARGOS_PREVIEW_PORT` | falls back to `ARGOS_PORT`, then `8080` | External port the demo URL is reachable on (the public endpoint, independent of the host port Traefik binds). Behind a TLS proxy on 443, set `scheme=https` and `ARGOS_PREVIEW_PORT=443` so the URL drops the port. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Hours before an idle demo is torn down. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Cap on concurrently running previews across all workspaces (`0` = unlimited; when exceeded, the oldest previews of other tasks are evicted). Each workspace also has its own *Parallel previews* limit. |
| `ARGOS_PREVIEW_CPU_LIMIT` | `1.0` | Per-preview CPU limit (separate from the workspace limits). |
| `ARGOS_PREVIEW_MEM_LIMIT` | `1g` | Per-preview memory limit. |
| `ARGOS_PREVIEW_NETWORK` | `argos_edge` | External Docker network shared with Traefik (defined in `docker-compose.yml`). |
| `ARGOS_PREVIEW_DEFAULT_IMAGE` | `argos-demo` | Built-in default demo runtime (php-fpm + nginx + node), used when a repo ships no `.argos/demo.*` contract. A content hash is appended (`argos-demo:<hash>`). |
| `ARGOS_TRAEFIK_DIR` | `/data/traefik` | Shared volume where the manager writes one Traefik file-provider route per demo (Traefik mounts it read-only). |

## Workspaces

Interactive workspaces (see [Workspaces](WORKSPACES.md)): one long-lived
container per workspace running the workspace daemon and OpenVSCode Server on
the internal `argos_ws` network. The editor is served at
`code-<workspace>.<base_domain>`, a task's preview at
`demo-<workspace>-<task>.<base_domain>`. Per-workspace limits (parallel
sessions and previews, CPU, memory, cleanup) are set in the workspace settings
(see [Workspace Settings](WORKSPACE-SETTINGS.md#resources)).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_WORKSPACE_COLLABORATION` | `true` | Whether workspaces may have members besides their creator and tasks collaborators. Backs the `workspace-collaboration` gate. |
| `ARGOS_WORKSPACE_MAX_ACTIVE` | `5` | Workspace containers running at the same time. Starting beyond it is refused — running workspaces are never evicted. `0` disables the cap. |
| `ARGOS_WORKSPACE_IDLE_MINUTES` | `60` | Stop a running workspace after this many minutes without activity (never while a task is being prepared, queued, working, waiting for a decision or running automatically). `0` disables. |
| `ARGOS_WORKSPACE_CPUS` | `2` | Default CPU limit per workspace container (a workspace can set its own). |
| `ARGOS_WORKSPACE_MEMORY` | `4g` | Default memory limit per workspace container (a workspace can set its own). |
| `ARGOS_INTERNAL_URL` | `http://nginx` | Base URL the workspace daemon uses to reach the manager on `argos_ws`. |
| `ARGOS_WORKSPACE_NETWORK` | `argos_ws` | Docker network shared by the manager and workspace containers. |
| `ARGOS_WORKSPACE_GATE_URL` | `http://nginx:80/_argos/workspace-gate` | forwardAuth target Traefik calls for `code-*` and `demo-*` hosts (membership check). |
| `ARGOS_WORKSPACE_EDITOR_COOKIE_HOURS` | `12` | Lifetime of the access cookie on workspace hosts. |
| `ARGOS_WORKSPACE_PR_SYNC_MINUTES` | `3` | How often the pull requests of open tasks (state, checks, reviews, conflicts) are re-read from the provider (`argos:tasks:sync-prs`). See [Pull Requests](PULL-REQUESTS.md#sync). |
| `ARGOS_WORKSPACE_UPLOAD_MAX_MB` | `5` | Size limit per image attached to a task message (up to 5 images per message). |
| `ARGOS_OPENVSCODE_VERSION` | `1.109.5` | OpenVSCode Server release baked into the workspace image. |

### Automatic tasks

Installation defaults for [automatic tasks](TASKS.md#automatic-tasks); a workspace overrides them under *Settings → Automation*, a single run overrides the workspace.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_AUTO_PLAN` | `auto` | `off` (implement right away), `auto` (plan, then continue) or `approve` (plan, then wait for approval). |
| `ARGOS_AUTO_SHIP` | `pr` | `pr`, `draft_pr` or `none` (commit and push only). |
| `ARGOS_AUTO_MAX_FIX_ROUNDS` | `3` | Rounds Claude may fix failing quality gates before shipping anyway. |
| `ARGOS_AUTO_MAX_TURNS` | `60` | Turn budget per run. |
| `ARGOS_AUTO_MAX_COST_USD` | `0` | Cost budget per run; `0` = none (subscriptions are flat). |
| `ARGOS_AUTO_MAX_MINUTES` | `120` | Runtime budget per run. |

## Realtime (Reverb)

Live updates of tasks travel over [Laravel Reverb](https://reverb.laravel.com)
(the `reverb` service). Browsers connect through Traefik on the Argos host at
`/app/…`; the manager publishes to `reverb:8080` internally. The compose file
sets the connection for you.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_REVERB_APP_SECRET` | generated by the installer | Signs messages between the manager and Reverb. Keep it stable. |
| `ARGOS_REVERB_APP_KEY` | `argos` | Public app key the browser connects with. |

## Integrations & polling

Issue trackers (GitHub, GitLab, Linear) are polled on a schedule by the
`scheduler` worker — new issues of bindings in poll mode, 👍 approvals on plan
comments, and new comments on the tickets of open tasks. The provider
connections themselves are UI/DB-managed — see
[OAuth & credentials](#oauth--credentials-ui-managed).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_POLL_INTERVAL_MINUTES` | `5` (clamped to 1–59) | How often the scheduler polls issue trackers (`argos:poll-issues`), checks plan-comment reactions (`argos:check-plan-approvals`) and reads ticket comments (`argos:tasks:sync-ticket-comments`). Default keeps API usage low at scale; set `1` locally for fast feedback. |

## OAuth & credentials (UI-managed)

OAuth apps for GitHub / GitLab / Bitbucket / Linear are **managed in the UI**
(Administration → OAuth apps, `/system/oauth-apps`) and stored in the database — there are **no**
`*_CLIENT_ID` / `*_CLIENT_SECRET` environment variables. Self-hosted GitLab
instances are configured per app via the `instance_url` field.

The callback path is fixed at `${APP_URL}/auth/<provider>/callback` — register
that URL in the provider's OAuth app. See [OAuth Overview](OAUTH.md) and the
per-provider setup guides ([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
[Bitbucket](SETUP-BITBUCKET.md), [Linear](SETUP-LINEAR.md)).

The **Claude agent credentials** are likewise **not** environment variables —
add them in the setup wizard or under **My account → Claude subscriptions**
(`/account/agents`), where they are stored in the database.

> The `SEED_*` variables (e.g. `SEED_USER_EMAIL`, `SEED_REPO_URL`,
> `SEED_CLAUDE_OAUTH_TOKEN`) are **dev-only seeding
> overrides** read by `.tools/bin/dev-reset.sh`. They are not operator
> configuration and have no effect outside seeding.

## MCP server (Argos API)

Argos exposes a built-in [MCP server](SETUP-MCP.md) at `${APP_URL}/mcp` so an
external client like Claude Code can drive it. Auth is OAuth 2.1 via Laravel
Passport (scope `mcp:use`).

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost` | Doubles as the OAuth **issuer**. Must be the public URL the MCP client can reach, or client registration/login fails. |
| `PASSPORT_KEYS_PATH` | – (unset) | Optional directory to load the Passport signing keys from. When unset, Passport uses its default key resolution. Set this to a persistent path if you want issued tokens to survive image rebuilds. |

See the [MCP Server guide](SETUP-MCP.md) for the connect flow and the available
tools.

## Repository settings in tasks

Tasks run Claude Code in their worktree with the repository's *project* and *local* settings,
so project-level configuration (`CLAUDE.md`, `.claude/settings.json`,
`.mcp.json`) applies as it would on a developer machine. There is no
manager-side flag; the repository decides.

## Logging

| Variable | Default | Purpose |
|---|---|---|
| `LOG_LEVEL` | `debug` | Standard Laravel log level for the stack/single/stderr channels. |

## Compose / OPS-level keys

These are read by the compose stack itself (`.tools/docker/docker-compose.yml`)
rather than by the Laravel app, but operators set them in the same place.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_PORT` | `8080` | Host port Traefik binds (the single port-80 entry of the stack). `APP_URL` and `ARGOS_PREVIEW_PORT` default to it. |
| `ARGOS_APP_IMAGE` | `argos-app:local` | Image used for the `app` / `queue` / `scheduler` services. Self-host installs pin this to a published tag (e.g. `ghcr.io/nodus-it/argos-app:latest`). |

---

For interactive setup walkthroughs see the [Setup guide](SETUP.md) and the
provider-specific guides:

- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md)
- [Bitbucket Setup](SETUP-BITBUCKET.md)
- [Linear Setup](SETUP-LINEAR.md)
