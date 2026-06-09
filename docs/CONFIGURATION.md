# Configuration Reference

All Argos configuration is controlled via environment variables passed to the
`app` service in the compose stack (or your `.env` file when running locally).

> [!NOTE]
> Argos generates a Laravel `APP_KEY` on first boot and persists it to
> `/data/app-key`. Nothing in the table below is required at startup — only
> override what you need.

## Core

| Variable | Default | Purpose |
|---|---|---|
| `APP_NAME` | `Argos` | Display name used in the browser title and headers. |
| `APP_ENV` | `production` | `local`, `staging`, or `production`. Selects the worker-image preset and toggles dev tooling. |
| `APP_KEY` | auto-generated, persisted to `/data/app-key` | Laravel encryption key. Pin only when restoring backups. |
| `APP_PREVIOUS_KEYS` | – | Comma-separated list of past `APP_KEY` values, kept available for decrypting old data after a key rotation. |
| `APP_DEBUG` | `false` | Enable detailed error pages. **Never enable in production.** |
| `APP_URL` | `http://localhost` | Base URL of the Argos instance and the **single source of truth** for host + scheme. OAuth callbacks, the session cookie domain and the live-demo subdomains all derive from it. **Must match the public URL.** |
| `APP_LOCALE` | `en` | Default UI language (`en` or `de`). |
| `ADMIN_PASSWORD` | `12345` | Password for the auto-created admin user. **Change before exposing the instance.** |

> The Claude OAuth token is **not** an environment variable. Add it in the
> onboarding wizard / credentials UI — it is stored per agent in the database.

## Sessions (reverse proxy / HTTPS)

Both values **derive from `APP_URL`** and rarely need setting:

| Variable | Default | Purpose |
|---|---|---|
| `SESSION_DOMAIN` | derived: `.<APP_URL host>` for a real domain, host-only for localhost/IP/nip.io | Cookie domain. The leading dot lets the session span demo subdomains (`demo-<task>.<host>`). Override only when demos live on a domain different from the app. |
| `SESSION_SECURE_COOKIE` | derived: `true` when `APP_URL` is `https://` | Force `Secure` on the session cookie. Set explicitly only when terminating TLS at a proxy that rewrites the scheme. |

## Worker

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_DEFAULT_STACK` | `php-8.4` | Slug of the worker stack used when neither the task nor the project pins one. Must match a row in `worker_stacks` (built-ins are mirrored on `migrate`). |
| `ARGOS_MEM_LIMIT` | `4g` | Memory limit per worker container. |
| `ARGOS_CPU_LIMIT` | `2` | CPU limit per worker container. |
| `ARGOS_CONCEPT_MAX_TURNS_DEFAULT` | `50` | Default max-turns for the concept phase (overridable per task). max-turns is a ceiling, not a budget — well-scoped tasks finish early regardless; large repos need the headroom to explore *and* write. |
| `ARGOS_MAX_TURNS_DEFAULT` | `200` | Default max-turns for the implement phase (overridable per task). |
| `ARGOS_CONFIG_DIR` | `~/.config/argos` | Persisted config / SQLite path inside the manager. |

## Queue & Redis

Background jobs — task phase runs and issue polling — run on **Laravel
Horizon**, backed by **Redis**. In the compose stack the `redis` service and
the `queue` (Horizon) + `scheduler` workers are wired up automatically; you
normally only tune the process counts.

| Variable | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | `redis` (compose) / `database` (local) | Queue driver. The compose stack sets `redis` so Horizon processes jobs; bare `artisan serve` dev and the test suite fall back to the database queue. |
| `REDIS_HOST` | `redis` | Redis host. Use `redis` for the compose sidecar. |
| `REDIS_PORT` | `6379` | Redis port. |
| `ARGOS_QUEUE_DEFAULT_PROCESSES` | `5` | Horizon worker processes for the `default` queue. |
| `ARGOS_QUEUE_TASKS_PROCESSES` | `2` | Horizon worker processes for the `tasks` queue (phase runs). |

## MCP Server (Argos API)

Argos exposes a built-in [MCP server](SETUP-MCP.md) at `<APP_URL>/mcp` so an
external client like Claude Code can drive it. Auth is OAuth 2.1 via Laravel
Passport (scope `mcp:use`).

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost` | Doubles as the OAuth **issuer**. Must be the public URL the MCP client can reach, or client registration/login fails. |
| `PASSPORT_KEYS_PATH` | `/data/passport` (compose) | Directory for the Passport signing keys. Generated once on first boot and kept on the persistent volume so issued tokens survive image rebuilds. |

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

## Live demos (optional)

Ephemeral per-task demo deployments, routed by Traefik under their own
subdomain. Off by default; a per-project toggle (`live_demo_enabled`) then opts
each project in. Base domain + scheme **derive from `APP_URL`** — override only
for a demo domain different from the app. Requires wildcard DNS `*.<host>`
resolving to this host.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_PREVIEW_ENABLED` | `true` | Master switch for the live-demo infrastructure. On by default; the per-project *Live-Demo* toggle is the real gate. Set `false` to disable platform-wide (e.g. no Traefik/preview infra). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | derived from `APP_URL` (nip.io locally) | Demos live at `demo-<task>.<base_domain>`. |
| `ARGOS_PREVIEW_SCHEME` | derived from `APP_URL` | `http` or `https` used in the demo URL. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Hours before an idle demo is torn down. |
| `ARGOS_PREVIEW_AUTH` | `none` | Default access protection (`none` / `session` / `basic`); per-task overrides win. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Cap on concurrently running demos (`0` = unlimited). |
| `ARGOS_PREVIEW_CPU_LIMIT` / `_MEM_LIMIT` | `1.0` / `1g` | Per-demo resource limits. |

## OAuth (UI-managed)

OAuth apps for GitHub / GitLab / Bitbucket / Linear are **managed in the UI**
(Configuration → OAuth Apps) and stored in the database — there are no
`*_CLIENT_ID` / `*_CLIENT_SECRET` environment variables anymore. Self-hosted
GitLab instances are configured per app via the `instance_url` field.

The callback path is fixed at `${APP_URL}/auth/<provider>/callback` — register
that URL in the provider's OAuth app. See [OAuth Overview](OAUTH.md) and the
per-provider setup guides ([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
[Bitbucket](SETUP-BITBUCKET.md)).

## Database

`DB_CONNECTION` selects the driver. SQLite is the default and needs no further
config. Set it to `mariadb` to use the MariaDB sidecar (compose) or an external
server.

| Variable | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `sqlite` or `mariadb`. |
| `DB_DATABASE` | `~/.config/argos/argos.db` | SQLite file path. Ignored for `mariadb`. |
| `ARGOS_DB_HOST` | `127.0.0.1` | MariaDB host. Use `db` for the compose sidecar. |
| `ARGOS_DB_PORT` | `3306` | MariaDB port. |
| `ARGOS_DB_DATABASE` | `argos` | Database name. |
| `ARGOS_DB_USERNAME` | `argos` | Database user. |
| `ARGOS_DB_PASSWORD` | – | Database password. |
| `ARGOS_DB_SSL_CA` | – | Optional path to a TLS CA bundle. |
| `ARGOS_DB_URL` | – | Full DSN — overrides the individual fields above. |

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
| `LOG_LEVEL` | `debug` | Standard Laravel log level. |

---

For interactive setup walkthroughs see the [Onboarding](#) page in the app
once it is running, or the provider-specific guides:

- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md)
- [Bitbucket Setup](SETUP-BITBUCKET.md)
