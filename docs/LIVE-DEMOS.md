# Previews

A **preview** (live demo) is a running deployment of one
[task](TASKS.md)'s current files. Argos boots the task's worktree in its own
small container stack and publishes it under a temporary subdomain, so you can
click through the change in a browser **before** the pull request is merged.

## Contents

- [What a preview is](#what-a-preview-is)
- [When a preview is available](#when-a-preview-is-available)
- [Starting and opening a preview](#starting-and-opening-a-preview)
- [The preview contract](#the-preview-contract)
- [Access](#access)
- [Lifecycle](#lifecycle)
- [Operator configuration](#operator-configuration)

## What a preview is

A workspace keeps its checkout in a Docker volume; each task has its worktree
there. The preview mounts **only that task's worktree** into a small container
stack, runs the contract's boot commands and publishes a route through
Traefik at `demo-<workspace>-<task>.<base-domain>` (cut to 63 characters).
Because it mounts the worktree, the preview always shows the task's current
files — PHP changes appear without a rebuild.

A preview is **ephemeral** and **per task**:

- At most one preview per task. **Rebuild** replaces it cleanly (old
  containers, volumes and route are torn down first).
- It has its own database inside its compose stack — not the task's working
  database.
- It has a **time-to-live (TTL)** and is meant for quick review, not as a
  production deployment.

## When a preview is available

A task can start a preview only when **both** are true:

1. **Live demo is enabled for the workspace** (Settings → Environment →
   *Enable live demo*; on for new workspaces). See
   [Workspace Settings](WORKSPACE-SETTINGS.md#live-demo).
2. **Previews are enabled platform-wide** (`ARGOS_PREVIEW_ENABLED`, on by
   default).

If either is off, the sidebar says why and offers no preview.

## Starting and opening a preview

The preview controls sit in the **Environment** part of the task's sidebar
(`/preview` shows the sidebar):

- **Start** builds the preview in the background; the row shows *Building the
  preview…* until it is live.
- **Open** opens it in its own browser tab (one per task, reused on the next
  click). The row shows when it expires.
- **Rebuild** replaces the running preview with a fresh build.
- **Re-run setup commands** runs the contract's `commands` again in the running
  preview — after a migration, a `composer`/`npm` change or a build-config
  edit — without a rebuild. The output is appended to the build log.
- **Stop preview** removes its containers, volumes and route.
- When a build fails, the **build log** opens.

The contract commands run as the workspace's `agent` user, so files they
create (e.g. `vendor/`, compiled views) stay editable in the task.

## The preview contract

How a preview is built is defined by two files in the repository:

| File | Purpose |
| --- | --- |
| `.argos/demo.compose.yml` | The Docker Compose services the preview runs (the app, its database, any backing services). |
| `.argos/demo.yml` | Settings: which service/port to route to, where to mount the worktree, the boot commands, and a health check. |

They are read at the workspace's setup ref — the default branch, or a tested,
unmerged setup branch; the [setup task](PREPARE-PROJECT.md#the-setup-task)
previews its own branch. Both must exist **together**; shipping only one fails
the build with an error rather than silently falling back.

Without either file, Argos uses a **bundled Laravel contract**: nginx +
php-fpm + Node, a MariaDB database, and a standard bring-up (`composer
install`, `.env` from `.env.example`, `php artisan migrate --force --seed`,
`npm ci && npm run build`, `php artisan storage:link`). Its database uses the
workspace's MySQL credentials, and a Redis service is added when the workspace
enabled Redis.

How to write a contract: [Preparing a Repository](PREPARE-PROJECT.md#live-preview);
the exact commands: [EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md#the-preview-deployer).

## Access

A preview is **members-only**. Every request passes Traefik's forward-auth
check against the workspace gate, which lets through only members of the
workspace (admins, members and viewers) and Argos administrators. There is no
public or password-only mode.

## Lifecycle

| State | Meaning |
| --- | --- |
| **Building** | Containers come up, boot commands and the health check run. |
| **Live** | The preview is reachable at its URL. |
| **Failed** | The build failed — the build log explains why; partial containers are cleaned up. |
| **Stopped** | Containers, volumes and route are removed. It can be started again. |

A preview leaves the **Live** state by

- **TTL expiry** — `ARGOS_PREVIEW_TTL_HOURS` (default 24) after it was built;
  a scheduled cleanup stops it,
- **the workspace's limit** — *Parallel previews* (Settings → Resources,
  default 3): starting one more stops the oldest preview of the workspace,
- **the platform cap** — `ARGOS_PREVIEW_MAX_CONCURRENT` (default 10, `0`
  disables): the oldest previews of other tasks are stopped to make room
  (logged, never silent),
- **the workspace stopping** (by hand or after idling),
- **task cleanup** — `cleanup_after_days` after the task is done or cancelled,
  or when the task is deleted,
- **Stop preview**.

## Operator configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `ARGOS_PREVIEW_ENABLED` | `true` | Platform-wide master switch for previews. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | How long a preview lives before automatic teardown. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Cap on simultaneously running previews (`0` disables). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | derived from `APP_URL` | Base domain previews are served under. |

Previews need a Traefik edge with the shared `argos_edge` network and a
wildcard-capable base domain. Without that infrastructure, turn them off with
`ARGOS_PREVIEW_ENABLED=false`. All settings: [Configuration](CONFIGURATION.md#live-previews).
