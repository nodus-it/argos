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
> - **OAuth apps and the Claude/Codex credentials are UI/DB-managed, not ENV.**
>   See [OAuth & credentials](#oauth--credentials-ui-managed) below.

## Contents

- [Core / App & URL](#core--app--url)
- [Sessions (reverse proxy / HTTPS)](#sessions-reverse-proxy--https)
- [Database](#database)
- [Queue, Redis & Horizon](#queue-redis--horizon)
- [Worker (phase containers)](#worker-phase-containers)
- [Worker backing services (test sidecars)](#worker-backing-services-test-sidecars)
- [Live demos (optional)](#live-demos-optional)
- [Integrations & polling](#integrations--polling)
- [OAuth & credentials (UI-managed)](#oauth--credentials-ui-managed)
- [MCP server (Argos API)](#mcp-server-argos-api)
- [Target-project MCP (Laravel Boost)](#target-project-mcp-laravel-boost)
- [Media library (optional)](#media-library-optional)
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
| `ARGOS_VERSION` | – | Overrides the baked-in app version string. CI bakes a `stage-…` value into stage images; leave unset for releases. |

## Sessions (reverse proxy / HTTPS)

Both values **derive from `APP_URL`** and rarely need setting:

| Variable | Default | Purpose |
|---|---|---|
| `SESSION_DOMAIN` | derived: `.<APP_URL host>` for a real domain, host-only for localhost/IP/`nip.io` | Cookie domain. The leading dot lets the session span demo subdomains (`demo-<task>.<host>`). Override only when demos live on a domain different from the app. |
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

Background jobs — task phase runs and issue polling — run on **Laravel
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
| `ARGOS_QUEUE_TASKS_PROCESSES` | `2` | Horizon worker processes for the `tasks` queue (phase runs). |

> `ARGOS_REDIS_*` take precedence; the plain `REDIS_HOST` / `REDIS_PORT` /
> `REDIS_PASSWORD` Laravel defaults are honored as a fallback (and are what the
> compose stack actually sets).

## Worker (phase containers)

Each task phase runs in an ephemeral worker container. These govern the image
selection and per-container resource limits.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_DEFAULT_STACK` | `php-8.4` | Slug of the worker stack used when neither the task nor the project pins one. Must match a row in `worker_stacks` (built-ins are mirrored on `migrate`). |
| `ARGOS_MEM_LIMIT` | `4g` | Memory limit per worker container. |
| `ARGOS_CPU_LIMIT` | `2` | CPU limit per worker container. |
| `ARGOS_CONCEPT_MAX_TURNS_DEFAULT` | `50` | Default max-turns for the concept phase (overridable per task). max-turns is a ceiling, not a budget — well-scoped tasks finish early regardless; large repos need the headroom to explore *and* write. |
| `ARGOS_MAX_TURNS_DEFAULT` | `200` | Default max-turns for the implement phase (overridable per task). |

## Worker backing services (test sidecars)

Argos can boot ephemeral backing-service sidecars (one private network per phase
run, torn down afterwards) so a project's tests can talk to a real MySQL/Redis.
A repo profile opts in per service; only the test-running phases start them.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_WORKER_SERVICE_TIMEOUT` | `60` | Seconds to wait for a backing service to become ready before failing the phase. |
| `ARGOS_WORKER_MYSQL_IMAGE` | `mariadb:11` | Image used for the MySQL/MariaDB sidecar. |
| `ARGOS_WORKER_REDIS_IMAGE` | `redis:7-alpine` | Image used for the Redis sidecar. |

## Live demos (optional)

Ephemeral per-task demo deployments, routed by Traefik under their own
subdomain. On by default at the platform level, but a per-project toggle
(*Live-Demo* / `live_demo_enabled`) is the real gate that opts each project in.
Base domain + scheme **derive from `APP_URL`** — override only for a demo
domain different from the app. Requires wildcard DNS `*.<host>` resolving to
this host (and the Traefik + `argos_edge` preview infra).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_PREVIEW_ENABLED` | `true` (compose forwards `false`) | Master switch for the live-demo infrastructure. Set `false` to disable platform-wide (e.g. no Traefik/preview infra). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | derived from `APP_URL` host (`127.0.0.1.nip.io` for bare/localhost) | Demos live at `demo-<task>.<base_domain>`. |
| `ARGOS_PREVIEW_SCHEME` | derived from `APP_URL` scheme | `http` or `https` used in the demo URL. |
| `ARGOS_PREVIEW_PORT` | falls back to `ARGOS_PORT`, then `8080` | External port the demo URL is reachable on (the public endpoint, independent of the host port Traefik binds). Behind a TLS proxy on 443, set `scheme=https` and `ARGOS_PREVIEW_PORT=443` so the URL drops the port. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Hours before an idle demo is torn down. |
| `ARGOS_PREVIEW_AUTH` | `none` | Stack-wide default access protection for demos set to *inherit* (`none` / `session` / `basic`); per-task overrides win. |
| `ARGOS_PREVIEW_BASIC_USER` | `demo` | HTTP Basic username for `basic`-protected demos. |
| `ARGOS_PREVIEW_BASIC_PASSWORD` | – | Global HTTP Basic password fallback for tasks that merely inherit the `basic` default (per-task passwords are generated when a task is switched to `basic`). |
| `ARGOS_PREVIEW_AUTH_GATE_URL` | `http://nginx:80/_argos/demo-gate` | Internal URL Traefik's forwardAuth middleware calls to validate the Argos session for `session`-protected demos. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Cap on concurrently running demos (`0` = unlimited; when exceeded, the oldest demos of other tasks are evicted). |
| `ARGOS_PREVIEW_CPU_LIMIT` | `1.0` | Per-demo CPU limit (separate from the worker limits). |
| `ARGOS_PREVIEW_MEM_LIMIT` | `1g` | Per-demo memory limit. |
| `ARGOS_PREVIEW_NETWORK` | `argos_edge` | External Docker network shared with Traefik (defined in `docker-compose.yml`). |
| `ARGOS_PREVIEW_DEFAULT_IMAGE` | `argos-demo` | Built-in default demo runtime (php-fpm + nginx + node), used when a repo ships no `.argos/demo.*` contract. A content hash is appended (`argos-demo:<hash>`). |
| `ARGOS_TRAEFIK_DIR` | `/data/traefik` | Shared volume where the manager writes one Traefik file-provider route per demo (Traefik mounts it read-only). |

## Integrations & polling

Issue/task providers (GitHub, GitLab, Bitbucket, Linear) are polled on a
schedule by the `scheduler` worker. The provider connections themselves are
UI/DB-managed — see [OAuth & credentials](#oauth--credentials-ui-managed).

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_POLL_INTERVAL_MINUTES` | `5` (clamped to 1–59) | How often the scheduler polls issue providers and checks concept-comment reactions. Default keeps API usage low at scale; set `1` locally for fast feedback. |

## OAuth & credentials (UI-managed)

OAuth apps for GitHub / GitLab / Bitbucket / Linear are **managed in the UI**
(Configuration → OAuth Apps) and stored in the database — there are **no**
`*_CLIENT_ID` / `*_CLIENT_SECRET` environment variables. Self-hosted GitLab
instances are configured per app via the `instance_url` field.

The callback path is fixed at `${APP_URL}/auth/<provider>/callback` — register
that URL in the provider's OAuth app. See [OAuth Overview](OAUTH.md) and the
per-provider setup guides ([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
[Bitbucket](SETUP-BITBUCKET.md), [Linear](SETUP-LINEAR.md)).

The **Claude / Codex agent credentials** are likewise **not** environment
variables — add them in the onboarding wizard / credentials UI, where they are
stored per agent in the database.

> The `SEED_*` variables (e.g. `SEED_USER_EMAIL`, `SEED_REPO_URL`,
> `SEED_CLAUDE_OAUTH_TOKEN`, `SEED_CODEX_AUTH_JSON_B64`) are **dev-only seeding
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

## Target-project MCP (Laravel Boost)

The worker checks the cloned target project's `boost.json` for `"mcp": true`.
If present, the active agent runner attaches the project's MCP server as a
local `stdio` subprocess (`php artisan boost:mcp`) before each session — no
network access, no Argos database connection. The target repo decides; no
manager-side flag.

**Requirements on the target project:**
- `laravel/boost ^2.4` in `composer.json`
- `boost.json` with `"mcp": true`
- `composer install` has run (vendor directory present)

**Wiring per agent (handled automatically):**
- Claude Code: `claude --mcp-config <file>` with a generated config file.
- Codex: `-c mcp_servers.laravel-boost.{command,args}=…` overrides spliced
  into the `codex exec` invocation.

The MCP server runs entirely inside the worker container. It gives the agent
access to Boost tools (e.g. `search-docs`, `database-schema`) scoped to the
target project. To opt out, set `"mcp": false` in `boost.json` or remove the
file.

## Media library (optional)

File and image uploads attached to models go through
[spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary).

| Variable | Default | Purpose |
|---|---|---|
| `MEDIA_DISK` | `public` | Filesystem disk (from `config/filesystems.php`) uploads are stored on. Point at `s3` etc. for off-box storage. |

See [Media library setup](SETUP-MEDIA-LIBRARY.md).

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
