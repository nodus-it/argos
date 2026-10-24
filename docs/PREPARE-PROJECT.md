# Preparing a Repository

This guide is for **developers getting a repository ready for Argos**: what
"Argos-ready" means, the files Argos reads from `.argos/`, the **setup task**
that writes them for you, the quality gates, backing services and secrets, and
the live-preview contract.

Laravel repositories need nothing: Argos picks the PHP stack, prepares every
task with the Laravel defaults and previews it with a bundled contract. For
everything else, the [setup task](#the-setup-task) has Claude write the files
and tests them.

Output language for files you create in your repo is **English** (code,
comments, YAML comments). Do not invent fields — every key below maps to a
real one Argos reads.

- [What "Argos-ready" means](#what-argos-ready-means)
- [What Argos reads from your repo](#what-argos-reads-from-your-repo)
- [The setup task](#the-setup-task)
- [Setup steps (.argos/setup.sh)](#setup-steps-argossetupsh)
- [Execution environment](#execution-environment)
  - [Stacks and your own image](#stacks-and-your-own-image)
  - [The quality gates](#the-quality-gates)
  - [Backing services (MySQL / Redis)](#backing-services-mysql-redis)
  - [Private registries and secrets](#private-registries-and-secrets)
- [Live preview](#live-preview)
  - [The bundled Laravel contract](#the-bundled-laravel-contract)
  - [Ship your own contract](#ship-your-own-contract)
- [Final checklist](#final-checklist)

## What "Argos-ready" means

A repository is Argos-ready when, inside the workspace container, Argos can
**create a task's worktree, install its dependencies, let Claude implement a
change and have that change pass the quality gates** — offline except for the
package registries you configure. Optionally it also **boots as a live
preview**.

In practice:

1. Dependencies install with a [built-in stack](WORKER-STACKS.md) (PHP 8.5,
   PHP 8.4 — both with Composer and Node 22 — or Node 22) or with your own
   `.argos/worker.dockerfile`.
2. A fresh worktree becomes usable with the
   [setup steps](#setup-steps-argossetupsh) — your `.argos/setup.sh` or the
   Laravel defaults.
3. The tests run **offline** and reach only services Argos can boot (MySQL /
   MariaDB, Redis), or run on SQLite / in memory.
4. For a preview: the [preview contract](#live-preview) — your own or the
   bundled Laravel one.

## What Argos reads from your repo

All files live under `.argos/` and are optional. Argos reads them through the
Git provider API at the workspace's **setup ref**: the default branch — or,
while a tested setup is not merged yet, the setup task's branch (see
[below](#the-setup-task)).

| File | What it controls | Default if absent |
| --- | --- | --- |
| `.argos/setup.sh` | Steps a fresh task worktree runs before Claude starts (dependencies, `.env`, keys, migrations). | Laravel defaults: `composer install`, `APP_KEY`, `php artisan migrate`. |
| `.argos/demo.compose.yml` + `.argos/demo.yml` | The live preview of a task (both files or neither). | Bundled Laravel contract (`app` php-fpm/nginx + MariaDB `db`). |
| `.argos/worker.dockerfile` | The image the workspace container runs in (instead of a built-in stack). | Built-in stack picked from `composer.json` / `package.json` (default PHP 8.5). |

Secrets never go into `.argos/` — they belong in the workspace settings
(see [Private registries and secrets](#private-registries-and-secrets)).

## The setup task

When a workspace is created, Argos inspects the repository:

| Missing | Counts as missing when |
| --- | --- |
| a fitting stack | no `composer.json`, no `package.json` and no `.argos/worker.dockerfile` |
| the preview contract | not a Laravel app (`composer.json` + `artisan`) and no `.argos/demo.yml` |
| the setup steps | not a Laravel app and no `.argos/setup.sh` |

If anything is missing, Argos starts a **setup task** — a task of kind
*Setup*, owned by the admin who created the workspace and running on their
Claude subscription. It runs in *Bypass permissions* mode when that admin may
use it in the workspace, otherwise in *Accept edits*; the composer switches
the mode while the task runs. Claude gets a brief (what is
missing, the detected project, the stack) and writes:

1. `.argos/setup.sh` — idempotent, non-interactive setup steps,
2. `.argos/demo.compose.yml` and `.argos/demo.yml` — the preview contract,
3. `.argos/worker.dockerfile` — only if no built-in stack fits.

Claude tries `.argos/setup.sh` in the task's worktree. When a turn ends with
changes under `.argos/`, Argos runs **Test setup** on its own, at most five
times in a row before someone writes in the chat again; the button in the
banner of the task starts it by hand. Secrets stay out of the files;
Claude names the ones you have to add under Settings → Environment. If it
cannot tell how the project is meant to run, it asks in the chat.

### Test setup

**Test setup** runs four steps and stops at the first that fails:

| Step | Passes when |
|---|---|
| Pull request | The files are committed, the branch is pushed and a pull request is opened (*chore: set up the Argos workspace*). A re-test without changes reuses it. |
| Image and tools | With a `.argos/worker.dockerfile`: the image builds and has the required tools. With a built-in stack: nothing to build. |
| Setup steps in a fresh working copy | `.argos/setup.sh` of the branch runs through in a new worktree with its own throw-away database. |
| Preview and health check | The preview of the setup task starts and its health check is green. Skipped (counts as passed) when previews are off for the installation or the workspace. |

A failed step's log goes to Claude in the task, with the request to fix it;
its fix triggers the next test. The chat and **Settings → Environment → Setup**
show the result per step.

**A passed test applies right away**: new tasks run the setup steps and
previews from the setup branch, and the workspace uses the repository's own
image when the branch has one. The settings say *Tested setup from
`<branch>` — applies to new tasks until its pull request is merged.* After the
merge, everything comes from the default branch again. Closing the pull
request without a merge drops the branch and sets the status back to *Needs
setting up*.

### Setup status

| Status | Meaning |
|---|---|
| Needs setting up | Something is missing, or the repository could not be read. |
| Not tested yet | Nothing is missing (for example a Laravel app on the defaults), but no test has passed yet — or the automatic tests stopped after five rounds. |
| Being set up | The setup task is open. |
| Being tested | **Test setup** is running. |
| Set up | The last test passed. |
| Test failed | The last test failed — see its log in the setup task. |
| Needs a Claude subscription | The admin who should run the setup task has no usable agent. |

**Settings → Environment → Setup** (workspace admins) shows the status, the
last test, where the preview contract and the setup steps come from (*in the
repository*, *Laravel defaults*, *missing*), the current image with its build
log and **Rebuild**, and **Revise setup** — it opens the setup task or starts a
new one, also when nothing is missing, on your subscription.

## Setup steps (.argos/setup.sh)

Every new task runs its setup steps in its own worktree before Claude starts:

1. Argos creates the task's database (`task_<slug>`) on the MySQL sidecar,
   when MySQL is enabled.
2. The daemon writes the task's values into `.env` (from `.env.example` when
   there is no `.env`): `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`,
   `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `REDIS_PORT`,
   `REDIS_PREFIX` and `CACHE_PREFIX` (`<slug>_`) — only for the services the
   workspace enabled. The same values override the container environment
   for everything in the worktree.
3. It runs `bash .argos/setup.sh` from the worktree root — or, without the
   file, the Laravel defaults:

   ```
   composer install --no-interaction --no-progress --prefer-dist   # if composer.json exists
   php artisan key:generate --force --no-interaction               # if .env has an empty APP_KEY
   php artisan migrate --force --no-interaction                    # if artisan + vendor/ exist
   ```

Rules for your own `.argos/setup.sh`:

- Idempotent and non-interactive — it may run in a worktree picked up again.
- Exit non-zero only when the environment is really unusable. The chat then
  says the setup commands failed and links the log; the task stays usable and
  Claude can look into it.
- Use the values from `.env` / the environment; do not hard-code database
  names (each task has its own).
- Output goes to the setup log the chat shows.
- Composer and npm caches are shared between tasks, so repeated installs are
  fast.

## Execution environment

### Stacks and your own image

The workspace container runs on a built-in stack — **PHP 8.5** (default),
**PHP 8.4** or **Node 22** — or on your `.argos/worker.dockerfile`. See
[Stacks and Images](WORKER-STACKS.md) and, for the Dockerfile contract,
[Custom Worker Image](BYOI.md).

For a precise list of every shell/Docker command Argos runs in the workspace
and for previews, see [EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### The quality gates

The gates run a fixed sequence of checks in the task's worktree. A gate that
applies to your repo must pass; gates that don't apply are skipped. A failing
gate can be handed to Claude as a fix prompt — an automatic task does that by
itself, up to its fix-round limit.

| Gate | Runs when… | Blocks on failure? |
| --- | --- | --- |
| **artisan smoke** | `artisan` exists | yes — `php artisan list` must boot the app |
| **Pint** (style) | `vendor/bin/pint` exists | yes — but only over files the task **changed** |
| **Pest** / **PHPUnit** | `vendor/bin/pest` or `vendor/bin/phpunit` exists | yes — but only on failures the task **newly introduces** |
| **PHPStan** | `phpstan.neon`(`.dist`) **and** `vendor/bin/phpstan` exist | yes — only on new errors |
| **migration syntax** | new files under `database/migrations/` | yes — `php -l` on each new migration |
| **debug code** | non-test PHP files changed | yes — `dd()`, `dump()`, `ray()`, `var_dump()`, `ddd()` are rejected |
| **test presence** | new files under `app/` | no — a warning only |

- **Baseline per base commit.** Argos records once which tests and PHPStan
  errors already fail on the base commit; only failures a task **introduces**
  block. No baseline → every failure counts.
- **Infra-crash skip.** A test/PHPStan run that dies on infrastructure (OOM,
  broken config, a missing binary) is skipped rather than treated as a
  fixable failure.

So: your test suite must **run** in the worktree (offline; SQLite/in-memory
or the backing services), Pint debt is fine, and a `phpstan-baseline.neon`
keeps PHPStan to new issues.

### Backing services (MySQL / Redis)

In **Settings → Environment**, *Backing services for tests* toggles:

- **MySQL / MariaDB** (image `mariadb:11`) — host `db`, port `3306`.
- **Redis** (image `redis:7-alpine`) — host `redis`, port `6379`.

They run as sidecars of the workspace on a private network; their data is
kept until the workspace is deleted. Each task gets its own database and its
own Redis prefix (see [Setup steps](#setup-steps-argossetupsh)). The MySQL
credentials can be overridden in the form (defaults `argos` / `argos` /
`argos`); the database name per task is set by Argos.

For **non-standard** env names use the placeholders in the values of your
environment variables: `${mysql.host}`, `${mysql.port}`, `${mysql.database}`,
`${mysql.username}`, `${mysql.password}`, `${redis.host}`, `${redis.port}` —
see [Workspace Settings](WORKSPACE-SETTINGS.md#environment-variables-and-placeholders).

Need another service (Postgres, …)? Run it inside your own image or the
preview contract.

### Private registries and secrets

Auth-protected Composer registries and any other secret the build or tests
need go into **Settings → Environment → Environment & secrets**, never into
`.argos/`:

- **Private Composer registries** — host + username + token per row; Argos
  builds one `COMPOSER_AUTH` from them for the workspace and the preview.
- **Additional environment variables** — name/value pairs (a hand-written
  `COMPOSER_AUTH` wins over the generated one).

Argos-owned keys (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, `ASSET_URL`,
`SESSION_COOKIE`, `ARGOS_DEMO`, `CLAUDE_CODE_OAUTH_TOKEN`, the daemon wiring,
…) cannot be overridden. A private registry needs **no** own image.

## Live preview

A task's preview mounts the task's worktree into a small container stack, runs
the contract's boot commands and routes it under its own subdomain, guarded by
the workspace gate (members only). It needs the workspace's **Enable live
demo** toggle (Settings → Environment) and `ARGOS_PREVIEW_ENABLED` (on by
default). See [Previews](LIVE-DEMOS.md).

Argos reads two files at the setup ref (a setup task reads them from its own
branch):

- **`.argos/demo.yml`** — the routed service and port, where the worktree is
  mounted, the boot commands and the health probe.
- **`.argos/demo.compose.yml`** — the Compose stack.

On top of your compose, Argos layers an **override** (you don't write it) that
mounts the worktree, joins the `argos_edge` network, injects `APP_URL` /
`ASSET_URL` / a throwaway `APP_KEY` / a per-preview `SESSION_COOKIE` /
`ARGOS_DEMO=1`, and caps CPU/memory.

> **Both files are required together.** Shipping only one is a hard error —
> the preview build fails with a clear message. Ship both, or neither.

### The bundled Laravel contract

Without `.argos/demo.*`, Argos uses a bundled contract for a standard Laravel
app: an `app` container (php-fpm + nginx + Node) and a MariaDB `db`, with these
boot commands:

```
composer install --no-interaction --prefer-dist --no-progress
[ -f .env ] || cp .env.example .env
php artisan migrate --force --seed
[ -f package.json ] && npm ci && npm run build || true
rm -f public/hot
php artisan storage:link || true
chown/chmod storage bootstrap/cache
```

The preview database uses the MySQL credentials of the backing services
(`demo` / `demo` / `demo` if none are set); a Redis service is added when Redis
is enabled. The preview keeps its own database — it does not use the task's
working database.

It fits when: the app serves HTTP on port 80, boots with `composer install` +
`php artisan migrate --force --seed` (+ `npm ci && npm run build`), has a
`.env.example`, needs only one MySQL database (plus optional Redis), and
`GET /` answers 2xx/3xx.

### Ship your own contract

Write **both** `.argos/demo.compose.yml` and `.argos/demo.yml`. Rules for the
compose file:

- **No host `ports:`** and **no Traefik labels** — routing is done by Argos.
- For the bundled runtime image use the placeholder `__ARGOS_DEMO_IMAGE__`;
  otherwise name your image.
- Put your services on a **private** network, or leave out `networks:` so
  they share the implicit `default` one. Argos adds `argos_edge` to the entry
  service and keeps it on `default`.
- The entry service serves HTTP on the declared `entry.port`.
- Do **not** cache config (`config:cache`) in the boot commands — Laravel must
  keep reading the env vars Argos injects.
- Argos starts the stack on the host's Docker, so it only accepts a safe
  subset per service: `image`, `command`, `entrypoint`, `environment`,
  `working_dir`, `user`, `depends_on`, `healthcheck`, `networks`, `volumes`,
  `tmpfs`, `restart`, `expose`, `stop_grace_period`, `stop_signal`, `init`,
  `hostname`. Volumes must be **named** volumes declared under `volumes:`
  (no host paths, no driver options); networks other than `default` must be
  declared under `networks:` (not external). `build`, `privileged`, capabilities, devices,
  `network_mode`, `pid`, `env_file` and `ports` are refused with a clear
  message. Every service runs with `no-new-privileges` and a process limit.

`.argos/demo.compose.yml`:

```yaml
# Stack for the task preview. Argos layers an override on top
# (worktree mount, argos_edge alias, APP_URL/APP_KEY, resource limits).
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

# Where the task's worktree is mounted in the entry service.
workspace_mount: /var/www/html

# Run in order inside the entry container after `compose up`. First failure
# fails the deploy. Output is captured in the build log.
commands:
  - composer install --no-interaction --prefer-dist --no-progress
  - "[ -f .env ] || cp .env.example .env"
  - php artisan migrate --force --seed
  - "[ -f package.json ] && npm ci && npm run build || true"
  - rm -f public/hot

# Readiness probe before the preview is marked live.
health:
  path: /
  timeout: 120
```

## Final checklist

1. Laravel app? The defaults usually fit; the status says *Not tested yet*
   until a setup task has passed **Test setup**.
2. Otherwise let the [setup task](#the-setup-task) write `.argos/` (or write
   it yourself), let **Test setup** run until all four steps pass, and merge its
   pull request.
3. Enable the [backing services](#backing-services-mysql-redis) your tests
   need; put registry auth and secrets into Settings → Environment — never into
   `.argos/`.
4. Turn on **Enable live demo** if you want previews.
5. Make sure the gates can run: tests offline, `phpstan-baseline.neon` for
   existing PHPStan issues.
