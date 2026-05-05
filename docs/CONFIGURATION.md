# Configuration Reference

All Argos configuration is controlled via environment variables passed to the
`argos-manager` container (or your `.env` file when running locally).

> [!NOTE]
> Argos generates a Laravel `APP_KEY` on first boot and persists it to
> `/data/app-key`. Nothing in the table below is required at startup — only
> override what you need.

## Core

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY` | auto-generated, persisted to `/data/app-key` | Laravel encryption key. Pin only when restoring backups. |
| `APP_URL` | `http://localhost` | Base URL of the Argos instance. **Must match the public URL** for OAuth callbacks. |
| `APP_LOCALE` | `en` | Default UI language (`en` or `de`). |
| `ADMIN_PASSWORD` | `12345` | Password for the auto-created admin user. **Change before exposing the instance.** |
| `CLAUDE_CODE_OAUTH_TOKEN` | – | Pre-seed the Claude OAuth token instead of pasting it into the UI. |

## Worker

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_WORKER_IMAGE` | `ghcr.io/nodus-it/argos-worker:php8.4` | Worker image used to spawn task containers. |
| `ARGOS_MEM_LIMIT` | `4g` | Memory limit per worker container. |
| `ARGOS_CPU_LIMIT` | `2` | CPU limit per worker container. |
| `ARGOS_MAX_TURNS_DEFAULT` | `200` | Default max-turns for the implement phase (overridable per task). |
| `ARGOS_CONFIG_DIR` | `~/.config/argos` | Persisted config / SQLite path inside the manager. |
| `ARGOS_REPO_ROOT` | repo root | Internal use; rarely needed. |

## GitHub OAuth (optional)

Enables repo and branch dropdowns when creating a project. See
[OAuth Overview](OAUTH.md) and [GitHub Setup](SETUP-GITHUB.md).

| Variable | Default | Purpose |
|---|---|---|
| `GITHUB_CLIENT_ID` | – | OAuth App client ID. |
| `GITHUB_CLIENT_SECRET` | – | OAuth App client secret. |
| `GITHUB_REDIRECT_URI` | `${APP_URL}/auth/github/callback` | Must match the OAuth App's callback URL. |

## GitLab OAuth (optional)

See [GitLab Setup](SETUP-GITLAB.md).

| Variable | Default | Purpose |
|---|---|---|
| `GITLAB_CLIENT_ID` | – | OAuth App ID. |
| `GITLAB_CLIENT_SECRET` | – | OAuth App secret. |
| `GITLAB_REDIRECT_URI` | `${APP_URL}/auth/gitlab/callback` | Must match the OAuth App's callback URL. |
| `GITLAB_INSTANCE_URL` | `https://gitlab.com` | Override for self-hosted GitLab (no trailing slash). |

## Bitbucket OAuth (optional)

See [Bitbucket Setup](SETUP-BITBUCKET.md).

| Variable | Default | Purpose |
|---|---|---|
| `BITBUCKET_CLIENT_ID` | – | OAuth Consumer key. |
| `BITBUCKET_CLIENT_SECRET` | – | OAuth Consumer secret. |
| `BITBUCKET_REDIRECT_URI` | `${APP_URL}/auth/bitbucket/callback` | Must match the OAuth Consumer's callback URL. |

## MariaDB Sidecar (optional)

Without these vars Argos falls back to SQLite (good for single-user setups).
Set them when you run the bundled MariaDB sidecar via Docker Compose.

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_DB_HOST` | `127.0.0.1` | MariaDB host. Use `db` for the compose sidecar. |
| `ARGOS_DB_PORT` | `3306` | MariaDB port. |
| `ARGOS_DB_DATABASE` | `argos` | Database name. |
| `ARGOS_DB_USERNAME` | `argos` | Database user. |
| `ARGOS_DB_PASSWORD` | – | Database password. |
| `ARGOS_DB_SOCKET` | – | Optional Unix socket path. |
| `ARGOS_DB_SSL_CA` | – | Optional path to SSL CA bundle. |
| `ARGOS_DB_URL` | – | Full DSN — overrides the individual fields above. |
| `MARIADB_ROOT_PASSWORD` | – | Root password for the bundled MariaDB sidecar (compose only). |

## Logging

| Variable | Default | Purpose |
|---|---|---|
| `LOG_LEVEL` | `debug` | Standard Laravel log level. |
| `LOG_DAILY_DAYS` | `14` | Days to keep daily log files. |

---

For interactive setup walkthroughs see the [Onboarding](#) page in the app
once it is running, or the provider-specific guides:

- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md)
- [Bitbucket Setup](SETUP-BITBUCKET.md)
