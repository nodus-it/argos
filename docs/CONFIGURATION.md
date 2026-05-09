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
| `APP_URL` | `http://localhost` | Base URL of the Argos instance. **Must match the public URL** for OAuth callbacks. |
| `APP_LOCALE` | `en` | Default UI language (`en` or `de`). |
| `ADMIN_PASSWORD` | `12345` | Password for the auto-created admin user. **Change before exposing the instance.** |
| `CLAUDE_CODE_OAUTH_TOKEN` | – | Pre-seed the Claude OAuth token instead of pasting it into the UI. |

## Sessions (reverse proxy / HTTPS)

| Variable | Default | Purpose |
|---|---|---|
| `SESSION_DOMAIN` | – | Cookie domain. Set when sharing a session across subdomains. |
| `SESSION_SECURE_COOKIE` | auto | Force `Secure` on the session cookie. Set `true` when terminating TLS at a proxy that doesn't forward `X-Forwarded-Proto`. |

## Worker

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_DEFAULT_STACK` | `php-8.4` | Slug of the worker stack used when neither the task nor the project pins one. Must match a row in `worker_stacks` (built-ins are mirrored on `migrate`). |
| `ARGOS_MEM_LIMIT` | `4g` | Memory limit per worker container. |
| `ARGOS_CPU_LIMIT` | `2` | CPU limit per worker container. |
| `ARGOS_MAX_TURNS_DEFAULT` | `200` | Default max-turns for the implement phase (overridable per task). |
| `ARGOS_CONFIG_DIR` | `~/.config/argos` | Persisted config / SQLite path inside the manager. |
| `ARGOS_MCP_ENABLED` | – | Set to `true` to enable the target project's Laravel Boost MCP server for Claude sessions. See [MCP Setup](#mcp-laravel-boost) below. |

## MCP — Laravel Boost

When `ARGOS_MCP_ENABLED=true`, the worker checks the target project's `boost.json`
for `"mcp": true`. If present, it configures the Claude CLI to start the project's
MCP server as a `stdio` subprocess via `php artisan boost:mcp` inside the worker
container before each Claude session.

**Requirements on the target project:**
- `laravel/boost ^2.4` in `composer.json`
- `boost.json` with `"mcp": true`
- `composer install` has run (vendor directory present)

The MCP server runs entirely inside the worker container — no external network
access, no Argos database connection. It gives Claude access to Boost tools
(e.g. `search-docs`, `database-schema`) scoped to the target project.

**Setup:**
1. Set `ARGOS_MCP_ENABLED=true` in `.env`.
2. No other configuration is required — the worker reads `boost.json` from the
   cloned project automatically.

## OAuth (optional)

Enables repo and branch dropdowns when creating a project. The callback path
is fixed at `${APP_URL}/auth/<provider>/callback` — register that URL in the
provider's OAuth app and Argos resolves the rest from `APP_URL`. See
[OAuth Overview](OAUTH.md) and the per-provider setup guides.

### GitHub — see [GitHub Setup](SETUP-GITHUB.md)

| Variable | Default | Purpose |
|---|---|---|
| `GITHUB_CLIENT_ID` | – | OAuth App client ID. |
| `GITHUB_CLIENT_SECRET` | – | OAuth App client secret. |

### GitLab — see [GitLab Setup](SETUP-GITLAB.md)

| Variable | Default | Purpose |
|---|---|---|
| `GITLAB_CLIENT_ID` | – | OAuth App ID. |
| `GITLAB_CLIENT_SECRET` | – | OAuth App secret. |
| `GITLAB_INSTANCE_URL` | `https://gitlab.com` | Override for self-hosted GitLab (no trailing slash). |

### Bitbucket — see [Bitbucket Setup](SETUP-BITBUCKET.md)

| Variable | Default | Purpose |
|---|---|---|
| `BITBUCKET_CLIENT_ID` | – | OAuth Consumer key. |
| `BITBUCKET_CLIENT_SECRET` | – | OAuth Consumer secret. |

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
