# Preparing a project for Argos

This guide is for **developers preparing their own repository** so Argos can
work on it: what "Argos-ready" means, the optional `.argos/` contract files,
the per-project worker environment and secrets, the backing services for tests
and demos, and the live-demo basics.

Output language for any file you create in your repo is **English** (code,
comments, YAML comments). Do not invent fields — every key below maps to a real
one Argos reads.

- [What "Argos-ready" means](#what-argos-ready-means)
- [What Argos reads from your repo](#what-argos-reads-from-your-repo)
- [Part A — Execution environment](#part-a--execution-environment)
  - [How Argos uses it](#how-argos-uses-it)
  - [The quality gate your project must pass](#the-quality-gate-your-project-must-pass)
  - [Is my project ready as-is?](#is-my-project-ready-as-is)
  - [Backing services (MySQL / Redis)](#backing-services-mysql--redis)
  - [Private registries & secrets](#private-registries--secrets)
  - [Bring your own image (BYOI)](#bring-your-own-image-byoi)
  - [Adjust the project instead](#adjust-the-project-instead)
- [Part B — Live demo](#part-b--live-demo)
  - [How Argos uses it](#how-argos-uses-it-1)
  - [Is my project ready as-is?](#is-my-project-ready-as-is-1)
  - [Ship your own contract](#ship-your-own-contract)
  - [Adjust the project instead](#adjust-the-project-instead-1)
- [Final checklist](#final-checklist)

## What "Argos-ready" means

A repository is "Argos-ready" when Argos can, for any task, **clone it, install
its dependencies, let the agent implement a change, and have that change pass
the quality gate** — all inside one ephemeral worker container, fully offline
except for the package registries you configure.

In practice that means:

1. Dependencies install with the toolchain Argos provides (PHP 8.3/8.4,
   Composer, Node 22) or one you supply via a custom image.
2. The test suite runs **offline** — no real external APIs — and reaches only
   services Argos can boot for you (MySQL/MariaDB, Redis), or runs on SQLite /
   in-memory.
3. The [quality gate](#the-quality-gate-your-project-must-pass) can run and not
   regress: a phase succeeds only when the gates the project supports pass.

Optionally, if you want a clickable preview after each implement, the repo also
needs to satisfy the [live-demo contract](#part-b--live-demo).

## What Argos reads from your repo

Argos integrates with a target repository through **two independent contracts**,
both optional, both living under `.argos/` at the repository's **default
branch** (the live demo) or the **task's base branch** (the worker image). They
are read via the Git provider API — Argos does not clone to detect them.

| Contract | Files | What it controls | Default if absent |
| --- | --- | --- | --- |
| **A — Execution environment** | `.argos/worker.dockerfile` | The base image of the container the agent *works in* (clone, install deps, run quality gate). | Built-in stack `php-8.4` (PHP 8.4 CLI + Composer + Node 22). |
| **B — Live demo** | `.argos/demo.compose.yml` + `.argos/demo.yml` | An ephemeral preview deployment spun up *after* a successful implement, routed under its own subdomain. | Bundled Laravel contract (`app` php-fpm/nginx + `mariadb` `db`). |

They are **orthogonal**: a repo can keep the default execution environment but
ship a custom demo, or vice versa. The decision shape is the same for both:

```
Does the built-in default already fit this repo?
├─ yes → nothing to add. (For the demo, just enable it.)
└─ no  → choose ONE:
         ├─ Option 1 — bring your own .argos/ contract
         └─ Option 2 — adjust the project so the default fits
```

When you reach a "no", weigh both options — the right route depends on how far
the project diverges from the default and whether you want `.argos/` files in
your history.

Secrets are configured **per project in the Argos UI**, never committed under
`.argos/`. See [Private registries & secrets](#private-registries--secrets).

---

## Part A — Execution environment

### How Argos uses it

For every task, Argos builds (on demand) one worker image and runs a single
container. Inside it the agent:

1. Clones the repo and creates the feature branch.
2. Installs the dependencies it detects: `composer install` when
   `composer.json` exists, `npm ci` when a lockfile exists.
3. Runs the agent (Claude Code / Codex) to implement the task.
4. Runs the **quality gate** on the result (see below).

The default image is the built-in **`php-8.4`** stack: PHP 8.4 CLI with the
common extensions (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `intl`, `zip`,
`bcmath`, `gd`, `pcntl`, `sockets`, `redis`, …), Composer, Git, `gh`, `jq`, and
Node 22. A `php-8.3` stack exists too.

For a precise, audit-level list of every shell/Docker command Argos runs in the
worker (and the demo deployer), see
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### The quality gate your project must pass

After the agent implements, the worker runs a sequence of gates. A gate that
applies to your repo must pass for the phase to succeed; gates that don't apply
are skipped. The agent gets up to a few focused fix attempts per failing gate.

| Gate | Runs when… | Blocks the phase on failure? |
| --- | --- | --- |
| **artisan smoke** | `artisan` exists | yes — `php artisan list` must boot the app |
| **Pint** (style) | `vendor/bin/pint` exists | yes — but only over files the agent **changed** |
| **Pest** / **PHPUnit** | `vendor/bin/pest` or `vendor/bin/phpunit` exists | yes — but only on failures the agent **newly introduces** (see baseline below) |
| **PHPStan** | `phpstan.neon`(`.dist`) **and** `vendor/bin/phpstan` exist | yes |
| **migration syntax** | new files under `database/migrations/` | yes — `php -l` on each new migration |
| **debug code** | non-test PHP files changed | yes — `dd()`, `dump()`, `ray()`, `var_dump()`, `ddd()` are rejected |
| **test presence** | new files under `app/` | no — a warning only |

Two things make this forgiving so the gate is achievable on real repos:

- **Pest/PHPUnit baseline.** Before the agent touches anything, Argos records
  which tests are *already* red on the clean checkout. Only failures the agent
  **newly introduces** block the phase — pre-existing red tests are reported but
  never block. (No baseline captured → strict gating, every failure counts.)
- **Infra-crash skip.** If a test/PHPStan run dies on infrastructure (OOM,
  broken config, a missing binary) rather than a real finding, that gate is
  skipped rather than treated as a fixable failure.

What this means for "Argos-ready":

- Your test suite must **run** in the worker (offline; SQLite/in-memory, or the
  MySQL/Redis backing services). A suite that cannot even start fails the gate.
- Pint runs only over changed files, so pre-existing style debt is fine.
- If you ship a `phpstan.neon`, keep a `phpstan-baseline.neon` for pre-existing
  issues — the agent is told to leave baselined entries untouched.

### Is my project ready as-is?

If **all** of these are yes, the built-in stack works — do nothing:

- [ ] It's a PHP project (8.3/8.4) **or** a Node project — the toolchain it
      needs is PHP, Composer, and/or Node 22, nothing else.
- [ ] `composer install` (and `npm ci`, if there's a lockfile) succeeds with no
      system packages beyond the stack's set.
- [ ] The test suite runs **offline** and either uses `sqlite` / `:memory:`,
      **or** talks only to MySQL/Redis — which you can enable as
      [backing services](#backing-services-mysql--redis).
- [ ] No exotic runtime (Python, Go, Ruby, a native toolchain). A **private
      Composer registry** alone is fine — it needs no custom image; see
      [Private registries & secrets](#private-registries--secrets).

Any "no" → either a [custom image (BYOI)](#bring-your-own-image-byoi) or
[adjust the project](#adjust-the-project-instead).

### Backing services (MySQL / Redis)

The worker container is single-image, but Argos can boot **backing services**
alongside it for the test run. In the project's **Worker** tab, the field
**Backing services for tests** (`worker_services`) lets you toggle:

- **MySQL / MariaDB** (image `mariadb:11`) — reachable at host `db`, port `3306`.
- **Redis** (image `redis:7-alpine`) — reachable at host `redis`, port `6379`.

Each enabled service comes up on a **private network per run** and is torn down
afterwards. Argos injects the standard Laravel connection env automatically:

- MySQL → `DB_HOST=db`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD` (defaults `argos`/`argos`/`argos`, overridable in the form).
- Redis → `REDIS_HOST=redis`, `REDIS_PORT=6379`.

So a project that reads the **standard** env names via `env()` needs no extra
config. If your project uses **non-standard** env names, wire them with the
placeholders Argos exposes in the env-secrets section — `${mysql.host}`,
`${mysql.port}`, `${mysql.database}`, `${mysql.username}`, `${mysql.password}`,
`${redis.host}`, `${redis.port}` — usable inside the values of your custom env
variables (see [Private registries & secrets](#private-registries--secrets)).

These same services and credentials are reused for the [live demo](#part-b--live-demo)
when you don't ship your own `.argos/demo.compose.yml`.

Need a service Argos doesn't offer (Postgres, etc.) → run it inside a
[custom worker image (BYOI)](#bring-your-own-image-byoi).

### Private registries & secrets

Auth-protected Composer registries (Private Packagist, Satis, Flux Pro,
Filament plugins, Scramble Pro, …) and any other secret the build or tests need
do **not** belong in `.argos/` — they go in the project's **Worker** tab, under
the **Environment & secrets** section. Argos stores them encrypted and injects
them into **both** the worker and the live demo.

- **Private Composer registries** (`composer_registries`) — host + username +
  token per row. Argos builds a single `COMPOSER_AUTH` http-basic blob from
  them, so `composer install` reaches them in both the worker and the demo.
  (Username defaults to `token` when left blank.)
- **Additional environment variables** (`worker_env`) — arbitrary `NAME` /
  value pairs (credentials, API keys, or a hand-written `COMPOSER_AUTH`, which
  then wins over the generated one). Values may use the backing-service
  placeholders described above.

Argos-owned keys cannot be overridden by a project secret — they are stripped
before injection. These include `PHASE`, `TASK_ID`, `REPO_URL`, `REPO_TOKEN`,
`REPO_PLATFORM`, `BASE_BRANCH`, `AGENT_NAME`, `APP_KEY`, `APP_URL`, `ASSET_URL`,
`SESSION_COOKIE`, `ARGOS_DEMO`, `CLAUDE_CODE_OAUTH_TOKEN`,
`CODEX_AUTH_JSON_CONTENT`, and the commit-identity vars.

A project with private dependencies therefore needs **no** custom worker image
just for registry auth.

### Bring your own image (BYOI)

When the built-in stacks genuinely don't fit — an exotic runtime, a system
package, a pinned base image — ship a `.argos/worker.dockerfile`. It replaces
**only the base image**; Argos still layers the agent CLI and its own worker
scripts on top, so do **not** add an `ENTRYPOINT`/`CMD` or install the agent
yourself, and the base must provide Node.js, a non-root `agent` user (UID
`1000`), and the tools `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`.

The full reference — the file template, the image-validation contract, how to
select it in the project form (**Image source → Own Dockerfile in the repo
(BYOI)**), and how rebuilds are triggered — lives in
[BYOI.md](BYOI.md). Start there for the custom-image path.

### Adjust the project instead

Often cheaper than a custom image — make the project fit the default stack:

- Make the test suite run on SQLite / in-memory (a `sqlite` `:memory:`
  connection in `phpunit.xml` / `.env.testing`), or rely on the MySQL/Redis
  backing services.
- Add a `.env.example` so config resolves in the worker.
- Remove hard dependencies on services Argos can't boot (mock them, or gate the
  affected tests).
- If a single extra system package is all that's missing, prefer a three-line
  BYOI Dockerfile over contorting the project.

---

## Part B — Live demo

### How Argos uses it

After a task's implement phase succeeds, Argos can deploy an **ephemeral
preview** of the working branch: it mounts the task workspace into a container,
runs your boot commands, and routes it at `demo-<task>.<base-domain>` via
Traefik. The user clicks one URL and sees the change running.

Enable it per project with the **Enable live demo** toggle (`live_demo_enabled`)
in the **Worker** tab → **Live demo** section. (Previews are on platform-side by
default; an operator can disable them globally with
`ARGOS_PREVIEW_ENABLED=false`.)

Argos reads two files from the **default branch**:

- **`.argos/demo.yml`** — settings: the routed service, its port, where to mount
  the workspace, the boot commands, and a health probe.
- **`.argos/demo.compose.yml`** — the Compose stack (your app, plus any services
  it needs).

On top of your compose, Argos layers an **override** (you don't write this) that
mounts the task workspace, joins the `argos_edge` network, injects `APP_URL` /
`ASSET_URL` / a throwaway `APP_KEY` / a per-demo `SESSION_COOKIE` / `ARGOS_DEMO=1`,
and caps CPU/memory.

> **Both files are required together.** With the toggle on, shipping only one is
> a hard error — the demo build fails with a clear message rather than silently
> falling back to the default. Ship both, or neither.

If the repo ships **neither** (but the toggle is on), Argos uses a bundled
default for a standard Laravel app: an `app` container (php-fpm + nginx + Node)
plus a `mariadb` `db`, with these boot commands:

```
composer install --no-interaction --prefer-dist --no-progress
[ -f .env ] || cp .env.example .env
php artisan migrate --force --seed
[ -f package.json ] && npm ci && npm run build || true
rm -f public/hot
php artisan storage:link || true
chown/chmod storage bootstrap/cache
```

The bundled default is **unified with the backing services** (Part A): the demo
DB uses the same MySQL credentials you configure there (defaulting to
`demo`/`demo`/`demo` if you set none), and a **Redis** service is added to the
demo when you enable Redis. A custom `.argos/demo.compose.yml` is never
auto-modified — there you're in full control.

For the exact deploy lifecycle, boot-command execution, and health probing, see
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md#the-demo-deployer).

### Is my project ready as-is?

If **all** are yes, the bundled default works — you only need to **enable** the
demo. Do not add `.argos/demo.*`.

- [ ] It's a Laravel app served over HTTP on port 80.
- [ ] It boots with `composer install` + `php artisan migrate --force --seed`,
      and (if there's a frontend) `npm ci && npm run build`.
- [ ] A `.env.example` exists and the app boots from it (a throwaway `APP_KEY`
      is injected for you — no `key:generate` needed).
- [ ] The only backing service it needs is **one** MySQL/MariaDB database (plus
      optionally Redis, which the default adds when you enable it).
- [ ] `GET /` returns 2xx/3xx once booted (used as the health probe).

Any "no" → either [ship your own contract](#ship-your-own-contract) or
[adjust the project](#adjust-the-project-instead-1).

### Ship your own contract

Write **both** `.argos/demo.compose.yml` and `.argos/demo.yml`. Hard rules for
the compose file:

- **No host `ports:`** and **no Traefik labels** — routing is done by Argos via
  Traefik's file provider, not by you.
- For the bundled default runtime image use the literal placeholder
  `__ARGOS_DEMO_IMAGE__` (Argos substitutes a content-hashed tag). If you bring
  your own image (a public one or a build target), set it directly.
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
      DB_PORT: "3306"
      DB_DATABASE: demo
      DB_USERNAME: demo
      DB_PASSWORD: demo
      CACHE_STORE: file
      SESSION_DRIVER: file
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

### Adjust the project instead

If the project is *almost* a standard Laravel app, fix the gaps instead:

- Add a `.env.example` the app boots from.
- Make `php artisan migrate --force --seed` work without manual steps (clean
  migrations; seeders that don't depend on external data).
- Ensure `GET /` answers 2xx/3xx (a landing route or a redirect to login).
- If you only need a different boot command (e.g. a build step), you still need
  **both** files — ship `.argos/demo.yml` and a minimal `.argos/demo.compose.yml`
  that reuses `__ARGOS_DEMO_IMAGE__`.

---

## Final checklist

1. **Part A** — decide: built-in stack OK? If not, use [BYOI](BYOI.md) or
   adjust the project, and verify the [quality gate](#the-quality-gate-your-project-must-pass)
   can run.
2. **Part A** — enable any [backing services](#backing-services-mysql--redis)
   your tests need, and put private-registry auth / extra secrets in
   **Worker → Environment & secrets** — never in `.argos/`.
3. **Part B** — decide: bundled demo OK? If not, ship the contract or adjust the
   project. Flip **Enable live demo** on if you want previews.
4. If you created `.argos/` files, verify they are committed to the branch Argos
   reads — the **default branch** for the demo contract, the **task's base
   branch** for `worker.dockerfile`.
5. Never put secrets in `.argos/` files — tokens come from Argos credentials,
   and per-project secrets go in **Worker → Environment & secrets**.
