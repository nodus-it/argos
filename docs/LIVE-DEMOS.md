# Live Demos

A **live demo** — in a workspace simply called the **preview** — is a running
deployment of a [workspace](WORKSPACES.md)'s current files. Argos boots the
code in its own small container stack and publishes it under a temporary
subdomain, so you can click through the change in a browser **before** the
pull request is merged.

This page explains what a preview is, how it is built, who can open it, and how
its lifecycle (build → live → stopped) works.

## Contents

- [What a live demo is](#what-a-live-demo-is)
- [When a preview is available](#when-a-preview-is-available)
- [Starting and opening a preview](#starting-and-opening-a-preview)
- [The demo contract](#the-demo-contract)
- [Access](#access)
- [Lifecycle](#lifecycle)
- [Operator configuration](#operator-configuration)

## What a live demo is

A workspace keeps its checkout in a Docker volume (`ws_<workspace>`). The
preview mounts **that volume** into a small container stack, runs the
contract's setup commands, and publishes a route through Traefik, so the
deployment is reachable at `demo-ws-<workspace>.<base-domain>`. Because it
mounts the volume, the preview always shows the workspace's current files — PHP
changes appear without a rebuild.

A preview is **ephemeral** and **per workspace**:

- There is at most one current preview per workspace. **Rebuild** replaces it
  cleanly (old containers, volumes and route are torn down first).
- A preview has a **time-to-live (TTL)**. Once it expires it is stopped
  automatically.
- It runs alongside Argos itself — it is meant for quick review, not as a
  production deployment.

## When a preview is available

A workspace can start a preview only when **both** of these are true:

1. **Live demos are enabled on the project.** Each project has a *Live demo*
   toggle. See [PROJECTS.md](PROJECTS.md#live-demo).
2. **Previews are enabled platform-wide.** The operator must have preview
   infrastructure switched on (`ARGOS_PREVIEW_ENABLED`, on by default — see
   [Operator configuration](#operator-configuration)).

If either is off, the workbench says why and offers no preview.

## Starting and opening a preview

The preview controls sit in the workbench bar of the workspace:

- **Start** builds the preview in the background; the bar shows *Building the
  preview…* until it is live.
- **Open preview** opens it in its own browser tab (one per workspace, reused
  on the next click). The bar shows when it expires.
- **Rebuild** replaces the running preview with a fresh build.
- **Re-run setup commands** runs the contract's `commands` again in the running
  preview — after a migration, a `composer`/`npm` change or a build-config
  edit — without a rebuild. The output is appended to the build log.
- **Stop preview** removes its containers, volumes and route.
- When a build fails, the bar shows the **build log**.

The contract commands run as the workspace's agent user, so files they create
(e.g. `vendor/`, compiled views) stay editable in the workspace.

## The demo contract

How a preview is built and what it runs is defined by a **demo contract** — two
files in the repository at `.argos/`:

| File | Purpose |
| --- | --- |
| `.argos/demo.compose.yml` | The Docker Compose services the preview runs (the app, its database, any backing services). |
| `.argos/demo.yml` | Settings: which service/port to route to, where to mount the workspace, the setup commands to run, and an optional health check. |

Both files are read from the project's **default branch**. They must exist
**together** — a repository that ships only one of them is treated as a
mistake, and the build fails with an error rather than silently falling back.

For details on writing a contract and the commands it runs, see
[PREPARE-PROJECT.md](PREPARE-PROJECT.md) and
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### Default Laravel demo

If a repository ships **no** `.argos/demo.*` files at all, Argos uses a
**built-in default Laravel contract**. It boots a PHP runtime (nginx + php-fpm)
plus a MariaDB database, mounts the workspace, and runs a standard Laravel
bring-up — roughly:

- `composer install`
- copy `.env.example` to `.env` if missing
- `php artisan migrate --force --seed`
- `npm ci && npm run build` (when a `package.json` is present)
- `php artisan storage:link`

The default contract reuses the project's backing-service settings: the preview
database uses the same credentials the project configured for its MySQL
service, and a Redis service is added automatically when the project enabled
Redis. The default runtime routes to the `app` service on port `80`.

A repository that ships its own contract keeps full control and is not touched
by these defaults.

## Access

A preview is **members-only**. Every request passes Traefik's forward-auth
check against the workspace gate, which lets through only members of the
workspace (owners, editors and viewers). There is no public or password-only
mode.

## Lifecycle

A preview moves through these states:

| State | Meaning |
| --- | --- |
| **Building** | The deployment is being built (containers up, setup commands, health check). |
| **Live** | The preview is reachable at its URL. |
| **Failed** | The build failed — the build log explains why; any partial containers are cleaned up. |
| **Stopped** | The preview's containers, volumes and route have been removed. It can be started again. |

How a preview leaves the **Live** state:

- **TTL expiry.** A preview lives for `ARGOS_PREVIEW_TTL_HOURS` (default 24
  hours) from when it was built. A scheduled cleanup stops any preview past its
  TTL.
- **Workspace stops.** Stopping a workspace (by hand or after idling) stops its
  preview; deleting the workspace removes it.
- **Concurrency cap.** The platform limits how many previews run at once
  (`ARGOS_PREVIEW_MAX_CONCURRENT`, default 10). When a new one would exceed the
  cap, the **oldest** running previews of *other* workspaces are stopped to
  make room. Evictions are logged, never silent. Set the cap to `0` to disable
  it.
- **Manual stop** with **Stop preview**.

## Operator configuration

Live demos are governed by the `preview.*` settings in `config/argos.php`,
driven by environment variables. The most relevant ones:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ARGOS_PREVIEW_ENABLED` | `true` | Platform-wide master switch for previews. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | How long a preview lives before automatic teardown. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Cap on simultaneously running previews (`0` disables). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | derived from `APP_URL` | Base domain previews are served under (`demo-ws-<workspace>.<base-domain>`). |

Previews require preview infrastructure on the host (a Traefik edge with the
shared `argos_edge` network and a wildcard-capable base domain). Operators
without that infrastructure can turn previews off entirely with
`ARGOS_PREVIEW_ENABLED=false`.

For the full list of settings and how to set environment variables, see
[CONFIGURATION.md](CONFIGURATION.md).
