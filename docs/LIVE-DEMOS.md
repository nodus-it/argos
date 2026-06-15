# Live Demos

A **live demo** is an ephemeral, running deployment of a task's implemented
branch. After Argos finishes the Implement phase, it can spin up the code in
its own container stack and publish it under a temporary subdomain so you can
click through the result in a browser **before** the pull request is merged.

This page explains what a live demo is, when it appears, how to control who can
open it, and how its lifecycle (build → live → stopped) works.

## Contents

- [What a live demo is](#what-a-live-demo-is)
- [When a demo appears](#when-a-demo-appears)
- [Where to find the demo URL](#where-to-find-the-demo-url)
- [The demo contract](#the-demo-contract)
- [Access modes](#access-modes)
- [Lifecycle](#lifecycle)
- [Rebuild and stop](#rebuild-and-stop)
- [Operator configuration](#operator-configuration)

## What a live demo is

When a task's Implement phase completes, the implemented code already lives in
the task's workspace volume. Argos mounts that volume into a small container
stack, runs the build/setup commands, and publishes a route through Traefik so
the deployment is reachable under its own subdomain — typically
`demo-<task>.<base-domain>`.

A demo is **ephemeral** and **per task**:

- There is exactly one current demo per task. A new Implement run replaces the
  previous demo cleanly (old containers, volumes, and route are torn down
  first).
- A demo has a **time-to-live (TTL)**. Once it expires it is stopped
  automatically.
- The demo runs alongside Argos itself — it is meant for quick review, not as a
  production deployment.

## When a demo appears

A demo is built automatically after a **successful Implement run**, but only
when **both** of these are true:

1. **Live demos are enabled on the project.** Each project (repo profile) has a
   `live_demo_enabled` toggle. See [PROJECTS.md](PROJECTS.md) for where to set
   it.
2. **Previews are enabled platform-wide.** The operator must have preview
   infrastructure switched on (`ARGOS_PREVIEW_ENABLED`, on by default — see
   [Operator configuration](#operator-configuration)).

If either is off, no demo is built and the demo actions stay hidden on the
task.

When the conditions are met, finishing the Implement phase dispatches the
deploy in the background — the task page shows **"Building demo…"** while it
runs and switches to the live URL once it is ready.

## Where to find the demo URL

The **Live demo** panel is shown on the task's view page. Open the task in
Argos (`${APP_URL}`) and look for the "Live demo" section. Depending on state
it shows:

- **Building demo…** while the deployment is being built.
- The demo **URL** and an **Expires** timestamp once the demo is live.
- A hint and a **Show build log** link when the build failed.
- A hint that no demo exists yet (it is built after the next successful
  Implement run).

## The demo contract

How a demo is built and what it runs is defined by a **demo contract** — two
files in the repository at `.argos/`:

| File | Purpose |
| --- | --- |
| `.argos/demo.compose.yml` | The Docker Compose services the demo runs (the app, its database, any backing services). |
| `.argos/demo.yml` | Settings: which service/port to route to, where to mount the workspace, the setup commands to run, and an optional health check. |

Both files are read from the project's **default branch**. They must exist
**together** — a repository that ships only one of them is treated as a
mistake, and the demo build fails with an error rather than silently falling
back.

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

The default contract reuses the project's configured backing-service settings:
the demo database uses the same credentials the project configured for its
worker MySQL sidecar, and a Redis service is added automatically when the
project enabled Redis. The default runtime routes to the `app` service on port
`80`.

A repository that ships its own contract keeps full control and is not touched
by these defaults.

## Access modes

Each demo is protected according to an **access mode**. You set it per task via
the **Demo access** action on the task page. Changes apply **immediately** to a
running demo (the route is re-written without a full rebuild); for a stopped
demo they take effect on the next build.

| Mode | Meaning |
| --- | --- |
| **Inherit** (default) | Use the stack-wide default access setting (`ARGOS_PREVIEW_AUTH`). The action shows what that currently resolves to. |
| **Session** | Require an Argos login. Traefik checks your Argos session via a forward-auth gate before serving the demo. |
| **Basic** | Protect the demo with shared HTTP Basic credentials (username + password). |
| **Public** | No protection — anyone with the URL can open it. |

Notes:

- **Inherit** resolves against the operator default `ARGOS_PREVIEW_AUTH`
  (`none`, `session`, or `basic`). A default of `none` resolves to **Public**.
- For **Basic**, the **username** is the stack-wide value
  `ARGOS_PREVIEW_BASIC_USER` (default `demo`). The **password** is either one
  you type in the access dialog or, if you leave it empty, a 16-character
  password Argos generates — a basic-protected demo is never left without a
  password. After saving, Argos shows you the resulting credentials in a
  notification.

## Lifecycle

A demo moves through these states:

| State | Meaning |
| --- | --- |
| **Building** | The deployment is being built (containers up, setup commands, health check). |
| **Live** | The demo is reachable at its URL. |
| **Failed** | The build failed — the build log explains why; any partial containers are cleaned up. |
| **Stopped** | The demo's containers, volumes, and route have been removed. It can be restarted. |

How a demo leaves the **Live** state:

- **TTL expiry.** A demo lives for `ARGOS_PREVIEW_TTL_HOURS` (default 24 hours)
  from when it was built. A scheduled cleanup runs periodically and tears down
  any demo past its TTL.
- **After the pull request.** When the task's Push phase opens the pull
  request, the demo is torn down automatically. You can restart it at any time
  from the task page.
- **Concurrency cap.** The platform limits how many demos run at once
  (`ARGOS_PREVIEW_MAX_CONCURRENT`, default 10). When a new demo would exceed
  the cap, the **oldest** running demos of *other* tasks are evicted (stopped)
  to make room. Evictions are logged, never silent. Set the cap to `0` to
  disable it.
- **Manual stop.** See below.

## Rebuild and stop

The task page offers two actions (visible only when live demos are enabled for
the project and the Implement phase has completed):

- **Rebuild demo** — replaces the current demo with a fresh build of the latest
  implemented code. A running demo for the task is torn down first; the build
  runs in the background. (For a previously stopped demo this is shown as
  **Restart demo**.)
- **Stop demo** — removes the demo's containers, volumes, and route. Available
  while the demo is **Building** or **Live**. A stopped demo can be restarted
  later.

## Operator configuration

Live demos are governed by the `preview.*` settings in `config/argos.php`,
driven by environment variables. The most relevant for users and operators:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ARGOS_PREVIEW_ENABLED` | `true` | Platform-wide master switch for demos. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | How long a demo lives before automatic teardown. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Cap on simultaneously running demos (`0` disables). |
| `ARGOS_PREVIEW_AUTH` | `none` | Default access mode for tasks set to *Inherit* (`none` \| `session` \| `basic`). |
| `ARGOS_PREVIEW_BASIC_USER` | `demo` | HTTP Basic username for basic-protected demos. |
| `ARGOS_PREVIEW_BASIC_PASSWORD` | *(unset)* | Fallback Basic password for tasks that merely inherit the basic default. |
| `ARGOS_PREVIEW_BASE_DOMAIN` | derived from `APP_URL` | Base domain demos are served under (`demo-<task>.<base-domain>`). |

Demos require preview infrastructure on the host (a Traefik edge with the
shared `argos_edge` network and a wildcard-capable base domain). Operators
without that infrastructure can turn demos off entirely with
`ARGOS_PREVIEW_ENABLED=false`.

For the full list of settings and how to set environment variables, see
[CONFIGURATION.md](CONFIGURATION.md).
