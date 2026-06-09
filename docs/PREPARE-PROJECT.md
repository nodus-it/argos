# Preparing a project for Argos

> **Audience: an AI coding agent.** You were pointed at this file with an
> instruction like *"prepare my project for Argos."* Follow it top to bottom.
> It tells you how to decide whether the repository works with Argos **as-is**,
> and — when it doesn't — gives you exactly two routes: ship a contract under
> `.argos/`, or adjust the project so the built-in defaults fit.
>
> Output language for any file you create here is **English** (code, comments,
> YAML comments). Do not invent fields — every key in this document maps to a
> real one Argos reads.

## What Argos reads from your repo

Argos integrates with a target repository through **two independent contracts**,
both optional, both living under `.argos/` at the repository's **default
branch** (read via the Git provider API — Argos does not need to clone to detect
them):

| Contract | Files | What it controls | Default if absent |
| --- | --- | --- | --- |
| **A — Execution environment** | `.argos/worker.dockerfile` | The container the agent *works in* while it implements a task (clone, install deps, run quality gates). | Built-in stack `php-8.4` (PHP 8.4 CLI + Node + Composer). |
| **B — Live-Demo** | `.argos/demo.compose.yml` + `.argos/demo.yml` | An ephemeral preview deployment spun up *after* a successful implement phase, routed under its own subdomain. | Bundled Laravel contract (`app` php-fpm/nginx + `mariadb`). |

They are **orthogonal**. A repo can keep the default execution environment but
ship a custom demo, or vice versa. Handle each part below on its own.

The decision shape is identical for both:

```
Does the built-in default already fit this repo?
├─ yes → nothing to add. (For the demo, just make sure it's enabled.)
└─ no  → choose ONE:
         ├─ Option 1 — bring your own: write the .argos/ contract file(s)
         └─ Option 2 — adjust the project so the default fits
```

When you reach a "no", **present both options to the user** with a short
recommendation rather than silently picking one — the right route depends on
how far the project diverges from the default and whether the user wants to
keep `.argos/` files in their history.

---

## Part A — Execution environment

### How Argos uses it

For every task, Argos builds (on demand) one worker image and runs a single
container. Inside it the agent:

1. Clones the repo and creates the feature branch.
2. Installs dependencies it detects: `composer install` when `composer.json`
   exists, `npm ci` when a lockfile exists.
3. Runs the agent (Claude Code / Codex) to implement the task.
4. Runs **quality gates** on the result: Pint (style), Pest/PHPUnit (tests,
   with a baseline so pre-existing failures don't block), PHPStan when
   configured.

The default image is the built-in **`php-8.4`** stack: PHP 8.4 CLI with the
common extensions (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `intl`, `zip`,
`bcmath`, `gd`, `pcntl`, `redis`, …), Composer, Git, `gh`, `jq`, and Node 22.
A `php-8.3` stack exists too.

> The container is **single-image** — there is no database server running
> alongside it. Tests must run self-contained (e.g. SQLite / in-memory), or
> spin up what they need inside the same image.

### Is my project ready as-is?

Answer these. If **all** are yes, the built-in stack works — do nothing.

- [ ] It's a PHP project (8.3/8.4) **or** a Node project — the toolchain it
      needs is PHP, Composer, and/or Node 22, nothing else.
- [ ] `composer install` (and `npm ci`, if there's a lockfile) succeeds with no
      system packages beyond the list above.
- [ ] The test suite runs **offline** and **without external services** — no
      real database server, no network calls. (Laravel: tests use the `sqlite`
      / `:memory:` connection, not a live MySQL.)
- [ ] No exotic runtime (Python, Go, Ruby, a native toolchain, a private
      package registry needing credentials).

Any "no" → pick one of the two options.

### Option 1 — Bring your own image (`.argos/worker.dockerfile`)

Ship a `.argos/worker.dockerfile`. It replaces **only the base image** of the
worker — Argos still layers the agent CLI and its own worker scripts on top, so
**do not** add an `ENTRYPOINT`/`CMD` or install the agent yourself.

Requirements your base must satisfy (the build smoke-tests these and **untags
the image if any is missing**):

- `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`
- **Node.js** (the Claude Code / Codex CLI is an npm package layered on top)
- a non-root user `agent` with UID `1000` (the worker runs as it)
- whatever **your** project needs to install deps and run its tests

Template — adapt the `FROM` and the extra tooling to your stack:

```dockerfile
# .argos/worker.dockerfile — base image for the Argos worker.
# Argos layers the agent CLI + worker scripts on top; provide only the base.
FROM python:3.12-bookworm

# Tools the worker harness requires, plus Node for the agent CLI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git jq curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Your project's toolchain goes here, e.g.:
# RUN pip install --no-cache-dir poetry

# Argos runs the worker as this user.
RUN useradd --create-home --shell /bin/bash --uid 1000 agent
```

Then set the repo profile's **Worker Source** to *BYOI* in the Argos UI (or ask
the user to). Argos reads the file from the default branch on the next task.

### Option 2 — Adjust the project

Often cheaper than a custom image: make the project fit the default stack.

- Make the test suite run on SQLite / in-memory (Laravel: a `sqlite`
  `:memory:` connection in `phpunit.xml` / `.env.testing`).
- Add a `.env.example` so config resolves in the worker.
- Remove hard dependencies on services that aren't present (mock them, or
  gate the affected tests).
- If a single extra system package is all that's missing, prefer Option 1 — a
  three-line Dockerfile beats contorting the project.

---

## Part B — Live-Demo

### How Argos uses it

After a task's implement phase succeeds, Argos can deploy an **ephemeral
preview** of the working branch: it mounts the task workspace into a container,
runs your boot commands, and routes it at `demo-<task>.<base-domain>` via
Traefik. The user clicks one URL and sees the change running.

Argos reads two files from the default branch:

- **`.argos/demo.yml`** — settings: the routed service, its port, where to mount
  the workspace, the boot commands, and a health probe.
- **`.argos/demo.compose.yml`** — the Compose stack (your app, plus any
  services it needs, e.g. a DB).

On top of your compose, Argos layers an **override** (you don't write this) that
mounts the task workspace, joins the `argos_edge` network, injects `APP_URL` /
`ASSET_URL` / a throwaway `APP_KEY` / a per-demo `SESSION_COOKIE` / `ARGOS_DEMO=1`,
and caps CPU/memory.

> **Both files are required together.** Shipping only one is a hard error
> (Argos will not silently fall back to the default). Ship both, or neither.

If the repo ships **neither**, Argos uses a bundled default for a standard
Laravel app: an `app` container (php-fpm + nginx + Node) plus a `mariadb` `db`,
with these boot commands:

```
composer install --no-interaction --prefer-dist --no-progress
[ -f .env ] || cp .env.example .env
php artisan migrate --force --seed
[ -f package.json ] && npm ci && npm run build || true
rm -f public/hot
php artisan storage:link || true
chown/chmod storage bootstrap/cache
```

### Is my project ready as-is?

If **all** are yes, the bundled default works — you only need to **enable** the
demo (toggle *Live-Demo* on the repo profile; the operator must have
`ARGOS_PREVIEW_ENABLED=true`). Do not add `.argos/demo.*`.

- [ ] It's a Laravel app served over HTTP on port 80.
- [ ] It boots with `composer install` + `php artisan migrate --force --seed`,
      and (if there's a frontend) `npm ci && npm run build`.
- [ ] A `.env.example` exists and the app boots from it (a throwaway `APP_KEY`
      is injected for you — no `key:generate` needed).
- [ ] The only backing service it needs is **one** MySQL/MariaDB database.
- [ ] `GET /` returns 2xx/3xx once booted (used as the health probe).

Any "no" → pick one of the two options.

### Option 1 — Ship your own contract (`.argos/demo.compose.yml` + `.argos/demo.yml`)

Write both files. Hard rules for the compose file:

- **No host `ports:`** and **no Traefik labels** — routing is done by Argos via
  Traefik's file provider, not by you.
- For the bundled default runtime image use the literal placeholder
  `__ARGOS_DEMO_IMAGE__` (Argos substitutes a content-hashed tag). If you bring
  your own image (e.g. a public one or a build target), set it directly.
- Put your app and its services on a **private** network; Argos adds
  `argos_edge` to the entry service additively.
- The entry service must serve HTTP on the `entry.port` you declare.
- Do **not** cache config (`config:cache`) in your boot commands — Laravel must
  keep reading the env vars Argos injects via `env()`.

`.argos/demo.compose.yml`:

```yaml
# Stack for the post-implement preview. Argos layers an override on top
# (workspace mount, argos_edge alias, APP_URL/APP_KEY, resource limits).
# Do NOT add host ports: or Traefik labels.
services:
  app:
    image: __ARGOS_DEMO_IMAGE__   # or your own image
    working_dir: /var/www/html
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      LOG_CHANNEL: stderr
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_DATABASE: demo
      DB_USERNAME: demo
      DB_PASSWORD: demo
      QUEUE_CONNECTION: sync
    networks: [demo-internal]
    depends_on:
      db:
        condition: service_healthy

  # Example extra service — drop or replace as needed.
  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: demo
      MARIADB_USER: demo
      MARIADB_PASSWORD: demo
      MARIADB_ROOT_PASSWORD: demo
    networks: [demo-internal]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 30

networks:
  demo-internal:
```

`.argos/demo.yml`:

```yaml
# Which service Argos routes, and how the preview boots.
entry:
  service: app    # service name from demo.compose.yml
  port: 80        # internal HTTP port of that service

# Where the task's checked-out workspace is mounted in the entry service.
workspace_mount: /var/www/html

# Run in order inside the entry container after `compose up`. First failure
# fails the deploy. Output is captured in the demo build log.
commands:
  - composer install --no-interaction --prefer-dist --no-progress
  - "[ -f .env ] || cp .env.example .env"
  - php artisan migrate --force --seed
  - "[ -f package.json ] && npm ci && npm run build || true"
  - rm -f public/hot

# Best-effort readiness probe before the demo is marked live.
health:
  path: /
  timeout: 120
```

### Option 2 — Adjust the project

If the project is *almost* a standard Laravel app, fix the gaps instead:

- Add a `.env.example` the app boots from.
- Make `php artisan migrate --force --seed` work without manual steps (clean
  migrations; seeders that don't depend on external data).
- Ensure `GET /` answers 2xx/3xx (a landing route or a redirect to login).
- If you only need a different boot command (e.g. a build step), prefer
  shipping just `.argos/demo.yml` **and** a minimal `.argos/demo.compose.yml`
  that reuses `__ARGOS_DEMO_IMAGE__` — remember both files are required.

---

## Final checklist for the agent

1. **Part A** — decide: built-in stack OK? If not, propose BYOI vs. adjust, and
   apply the chosen route.
2. **Part B** — decide: bundled demo OK? If not, propose contract vs. adjust,
   and apply the chosen route.
3. If you created `.argos/` files, verify they are committed to the **default
   branch** (that's the only ref Argos reads them from).
4. Tell the user which toggles to flip in the Argos UI: *Worker Source = BYOI*
   (Part A) and/or *Live-Demo enabled* (Part B), and that Live-Demo also needs
   the operator-side `ARGOS_PREVIEW_ENABLED=true`.
5. Never put secrets in `.argos/` files — they live in the repo. Tokens come
   from Argos credentials, not from these contracts.
