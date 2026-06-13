# What Argos runs — Worker & Demo command reference

A precise list of the shell / Docker commands Argos executes on your behalf
against a target repository, for operators who want to audit exactly what the
agent does. There are two execution contexts:

- **The Worker** — one ephemeral container per task *phase* (clone, install
  deps, run the agent, run quality gates, commit & push).
- **The Demo deployer** — an ephemeral preview stack spun up *after* a
  successful implement, when live previews are enabled for the project.

> This is a snapshot for orientation. The commands live in code (see
> [Where this lives](#where-this-lives) at the bottom); if in doubt, the source
> is the truth. Placeholders like `$BASE_BRANCH`, `$REPO_URL`, `$auth_header`,
> `$feature_branch` are injected per task; `<slug>`, `<service>`, `<port>` are
> resolved from the task / demo contract.

## Contents

- [The Worker](#the-worker)
  - [How it is launched](#how-it-is-launched)
  - [Clone & branch](#clone--branch)
  - [Dependency install](#dependency-install)
  - [Per phase](#per-phase)
  - [The agent session](#the-agent-session)
  - [Quality / test gate](#quality--test-gate)
- [The Demo deployer](#the-demo-deployer)
  - [Runtime image build](#runtime-image-build)
  - [Deploy lifecycle](#deploy-lifecycle)
  - [Boot commands & health probe](#boot-commands--health-probe)
  - [The demo contract](#the-demo-contract)
  - [Teardown](#teardown)
- [Configurable vs fixed](#configurable-vs-fixed)
- [Where this lives](#where-this-lives)

---

## The Worker

### How it is launched

The manager builds one `docker run` per phase and streams its output. The
worker is a **single container**, runs as the non-root user `agent` (uid 1000),
and has **no Docker socket** — it can only touch the repo, never the host
daemon.

```
docker run --rm \
  -v <task-volume>:/workspace \
  -v composer_cache:/home/agent/.composer/cache \
  -v npm_cache:/home/agent/.npm \
  --memory <argos.docker.memory_limit> --cpus <argos.docker.cpu_limit> \
  -e PHASE=<phase> -e TASK_ID=<task> \
  -e REPO_URL=… -e REPO_TOKEN=… -e REPO_PLATFORM=… -e BASE_BRANCH=… \
  -e AGENT_NAME=… -e TASK_DESCRIPTION=… -e PHASE_FLAGS=<json> \
  -e MAX_TURNS=… -e LOG_LEVEL=info \
  -e CLAUDE_CONFIG_DIR=/workspace/.agent/claude-state -e CLAUDE_MODEL=… \
  -e APP_KEY=<deterministic dummy> \
  [agent credential env] \
  [--network <run-net> + DB_HOST/REDIS_HOST… when backing services are on] \
  [project env: COMPOSER_AUTH + the project's custom secrets] \
  [-e RESUME_SESSION_ID=… on a --continue resume] \
  [-e COMMIT_USER_NAME=… -e COMMIT_USER_EMAIL=… from the task creator] \
  [-e FORCE_UNLOCK=1 when the run was force-unlocked] \
  <worker-image> <phase> <task>
```

Notes for auditing:

- `REPO_TOKEN` and the agent credential are passed as env vars from the
  database — never from mounted files. The git token never lands in the
  `origin` URL or in `/workspace/.git/config`; it is supplied per-command via
  `http.extraheader` and stripped from logs.
- `APP_KEY` is a **fixed deterministic dummy** so the target repo's Laravel
  boot pipeline (`package:discover`, `boost:mcp`) does not crash on
  encrypted-cast code. The worker persists nothing, so it has no security role.
- Backing services (MySQL / Redis), when the project enabled them, are booted
  by the manager as ephemeral sidecars on a private per-run network *before*
  the worker `docker run` and torn down afterwards — **only for the
  `implement` and `respond` phases** (the phases that run tests). The worker
  reaches them at the conventional hosts `db` / `redis`.

Before a phase that consumes notes/feedback, the manager writes that text into
the volume via a throwaway root helper (`alpine`), chowning back to uid 1000:

```
docker run --rm -i -v <task-volume>:/workspace alpine \
  sh -c 'mkdir -p /workspace/.agent && cat > /workspace/.agent/<file> && chown -R 1000:1000 /workspace'
```

where `<file>` is `concept.notes.md` (concept), `implement.notes.md`
(implement), or `respond.feedback.md` (respond).

### Clone & branch

On the **first** run (concept, when `/workspace/.git` does not yet exist) the
worker initialises the repo in-place — `git clone` would refuse the non-empty
`/workspace` (the `.agent/` state dir already exists):

```
git init --quiet --initial-branch="$BASE_BRANCH"
git remote add origin "$REPO_URL"          # token-less URL
git -c "http.extraheader=$auth_header" fetch --quiet --depth=1 origin "$BASE_BRANCH"
git checkout -B "$feature_branch" "origin/$BASE_BRANCH"
```

`$feature_branch` is `feat/<task-name-slug>` (German umlauts transliterated,
then stripped to a git-safe slug). The branch naming scheme is fixed. The
`.agent/` state dir is marked locally ignored so later `git clean` never wipes
it.

### Dependency install

On `implement` (and, best-effort, `concept`) the worker first seeds a `.env`
from `.env.example` if missing and drops a Vite `public/hot` stub so artisan /
Boost can boot without built assets, then:

```
composer install --no-interaction --prefer-dist --no-progress   # if composer.json exists
npm ci --no-audit --no-fund                                     # implement only, if package-lock.json exists
```

On `concept`, a failing `composer install` is **non-fatal** (the plan can still
be produced); on `implement` it aborts the phase.

### Per phase

| Phase | What the worker runs, in order |
| --- | --- |
| **concept** | clone & branch (first run) → `composer install` (best-effort) → archive any prior concept → [agent session](#the-agent-session) that writes `concept.md`. Read-only on the repo by design. |
| **implement** | (default `--fresh`) `git fetch` + `git reset --hard origin/$BASE_BRANCH` + `git clean -fd` (keeps gitignored `vendor/`, `node_modules/`) → `composer install` / `npm ci` → **capture test baseline** on the clean checkout → agent session → **quality gate**; on a blocking gate failure, up to 3 focused agent fix sessions (`GATE_RETRY_LIMIT`). `--refine` skips the reset and builds on the prior iteration; `--continue` resumes the paused agent session. |
| **diff** | read-only. `git diff [--stat] [-- <file>] origin/$BASE_BRANCH` (working tree vs base — implement leaves changes uncommitted) → `git status --short` → `git diff --numstat` + `git ls-files --others --exclude-standard` for the change counters. |
| **push** | invoke the **commit-message** sub-phase → set git identity (`"<name> via Argos"`) → `git add -A` → `git commit -m "<subject>" [-m "<body>"]` → `git push -u --force-with-lease origin "$feature_branch"` (GitLab adds `-o merge_request.create` push options) → open / update the PR/MR (see below). Skips entirely with status `no_changes` when there is nothing to push. |
| **commit-message** | short agent session (`--max-turns 8`, JSON-schema output, Claude pinned to Haiku) over the concept + diff that produces the commit subject/body. Invoked only by `push`; a failure falls back to `chore: apply implementation changes (N files)`. |
| **respond** | agent session over `respond.feedback.md` (review feedback from the UI) + concept → the same quality gate as implement. Applies the feedback to the existing feature branch; run `push` afterwards. |

PR / MR creation in **push** is provider-specific:

- **GitHub** — `curl POST https://api.github.com/repos/<owner>/<repo>/pulls`
  (Bearer `REPO_TOKEN`). On HTTP 422 (PR exists) it looks the PR up and updates
  the description + adds an iteration comment. It also `PATCH`es the repo to
  squash-only + auto-delete-branch-on-merge (best-effort; a warning if the
  token lacks admin rights).
- **GitLab** — no API call; the MR URL is parsed out of the `git push` output
  produced by the `merge_request.create` push options.
- **Bitbucket** — `curl POST …/2.0/repositories/<ws>/<slug>/pullrequests`
  (Basic auth for a `user:app_password` token, else Bearer). HTTP 409 → looks
  up the existing PR.

### The agent session

The agent session is the agent CLI (`claude` for `claude-code`, or `codex`),
run with:

- a per-phase **system prompt** (`worker/prompts/*.system.md`),
- the task description / concept / feedback as the user prompt,
- a per-phase turn budget `--max-turns "$MAX_TURNS"` (resolved task → project →
  `config/argos.php`; defaults 30 for concept, 200 for implement/respond),
- a model `CLAUDE_MODEL` (resolved task → project → agent default),
- `--resume "$RESUME_SESSION_ID"` when continuing a paused session,

streaming `stream-json` which the worker tees to the phase log and parses for
the `result` event (session id, cost, token usage). A max-turns hit pauses the
phase (resumable) rather than failing it. The session is expected to leave the
working tree changed but **never commits or pushes** — that is the push phase's
job.

### Quality / test gate

Run by **implement** and **respond** after the agent session, then re-run after
each fix session. Each gate is **skipped** when its tool or trigger file is
absent. A test baseline is captured on the clean checkout first, so only
**new** test failures (versus the repo's pre-existing red tests) block.

| # | Gate | Command | Runs when | Blocks? |
| --- | --- | --- | --- | --- |
| 1 | artisan smoke | `php artisan list --no-ansi` | `/workspace/artisan` exists | yes |
| 2 | Pint (style) | `vendor/bin/pint --test <changed php files>` | `vendor/bin/pint` exists + changed PHP files | yes |
| 3 | tests | `vendor/bin/pest --no-coverage --log-junit <…>.xml` (else `vendor/bin/phpunit --log-junit <…>.xml`) | the runner exists | yes, on **new** failures only |
| 4 | PHPStan | `vendor/bin/phpstan analyse --no-progress` | `phpstan.neon`/`.dist` + `vendor/bin/phpstan` exist | yes |
| 5 | migration syntax | `php -l <new migration file>` | new files under `database/migrations/` | yes |
| 6 | debug-code | `grep -lE '\bdd\(\|\bdump\(\|\bray\(\|\bvar_dump\(\|\bddd\('` over changed non-test PHP | changed non-test PHP | yes |
| 7 | test-presence | new `app/` classes checked for a matching `*Test.php` | new files under `app/` | no (warn only) |

The gate commands are **fixed** — a project does not configure them; a tool is
simply "off" when not installed. A gate that dies on infrastructure (OOM
`exit 137`, broken config) is **skipped** rather than sent to remediation, so
the fix-session budget isn't burned on an unfixable crash. The fix loop also
bails early if a fix produces byte-identical gate output (no progress). The
worker image ships the toolchain (PHP + extensions incl. `sockets`, Composer,
Node, the MySQL client, …).

---

## The Demo deployer

When live previews are enabled, a successful implement triggers an ephemeral
preview stack. The implemented code already lives in the task workspace volume,
so the deployer **mounts that volume** into the demo's entry service rather than
checking the repo out again. The stack is published under its own subdomain via
a Traefik file-provider route. Manager-side only (it needs the Docker socket).

### Runtime image build

When a repo ships **no** demo contract, Argos uses a built-in Laravel runtime
image, built once and content-hash cached (rebuilt only when the recipe
changes). Warmed at boot by `argos:warm-demo-image`, otherwise built on demand:

```
docker build -t argos-demo:<8-hex-hash> -f .tools/docker/demo/Dockerfile <repo root>
```

A repo that ships its own `.argos/demo.compose.yml` provides its own image and
this build is skipped.

### Deploy lifecycle

For each deploy (the compose project name is the demo `<slug>`, derived from the
task name):

```
# 1. tear down any previous demo of this task (idempotent replace)
docker compose -p <slug> down -v --remove-orphans

# 2. evict the oldest running demos if over argos.preview.max_concurrent

# 3. bring the stack up — repo (or default) compose + the Argos override
docker compose -p <slug> \
  -f <workdir>/demo.compose.yml -f <workdir>/override.yml \
  up -d --remove-orphans

# 4. run each boot command inside the entry service (see below)

# 5. health probe until ready (see below)

# 6. write the Traefik route → the demo is reachable under its subdomain
```

The **override** (`override.yml`, generated per task by `DemoComposeBuilder`)
carries no host `ports:` and no Traefik labels — routing is via the file
provider. It mounts the task workspace volume at the contract's
`workspace_mount`, joins the shared `argos_edge` network under the demo alias,
pins `APP_URL`/`ASSET_URL` to the public URL, injects a throwaway `APP_KEY` and
a per-demo `SESSION_COOKIE`, sets `ARGOS_DEMO=1`, and caps CPU/memory from
`argos.preview.*`.

### Boot commands & health probe

Each configured boot command runs in order inside the entry service; the first
failure fails the deploy. The health probe then polls until ready:

```
# boot command (per entry in the contract's `commands:` list)
docker compose -p <slug> exec -T <service> sh -c '<command>'

# health probe — retried every 3s until health.timeout, from inside the
# container, using whichever of curl/wget exists (skipped if neither does)
docker compose -p <slug> exec -T <service> \
  sh -c 'curl -fsS "http://localhost:<port><health.path>" >/dev/null
         || wget -qO- "http://localhost:<port><health.path>" >/dev/null'
```

When the repo ships no contract, the bundled default Laravel `commands:` run in
this order (from `resources/stubs/demo/laravel/demo.yml`):

```
1. composer install --no-interaction --prefer-dist --no-progress
2. [ -f .env ] || cp .env.example .env
3. php artisan migrate --force --seed
4. [ -f package.json ] && npm ci && npm run build || true
5. rm -f public/hot
6. php artisan storage:link || true
7. chown/chmod storage bootstrap/cache (best-effort)
```

The default entry service is `app` (nginx on port 80), the workspace mounts at
`/var/www/html`, and the health path is `/` with a 120s timeout.

### The demo contract

A project controls its demo by shipping **two** files at the default branch —
both are required together; a half-written contract is an error, not a silent
fall-back to the default:

| File | Purpose |
| --- | --- |
| `.argos/demo.compose.yml` | the compose services. May reference the built-in runtime via the `__ARGOS_DEMO_IMAGE__` placeholder, or ship its own image. |
| `.argos/demo.yml` | settings: `entry.service`, `entry.port`, `workspace_mount`, the ordered `commands:` boot list, and `health.path` / `health.timeout`. |

The project's `commands:` and `health` **replace** the defaults entirely. For
the bundled default contract, the demo DB credentials and an optional Redis
service follow the project's backing-service config (same credentials the
worker sidecar uses).

### Teardown

A demo is torn down (on replace, eviction over the concurrency cap, or TTL
expiry — `argos.preview.ttl_hours`) by slug, so it still works after the task
row is gone:

```
docker compose -p <slug> down -v --remove-orphans   # containers + volumes
# + remove the Traefik route file
```

---

## Configurable vs fixed

| Configurable by the project / task | Fixed by Argos |
| --- | --- |
| Test runner & its outcome (whichever of pest/phpunit/pint/phpstan is installed) | The gate **command lines** and ordering |
| Backing services (MySQL / Redis on/off, credentials) | The set of phases that get sidecars (`implement`, `respond`) |
| Agent, model per phase, and `max_turns` per phase | The phase flow concept → implement → push, branch naming, no-Docker-socket worker |
| Project secrets / `COMPOSER_AUTH` injected into worker & demo | Reserved Argos env keys (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, …) can't be overridden |
| The full demo contract (`commands`, `health`, services, image, entry) | The compose lifecycle (down → up → exec → probe → route) and the override-injected env |

---

## Where this lives

| Area | Source |
| --- | --- |
| `docker run` assembly for a phase | `app/Services/Workflow/PhaseCommandBuilder.php` |
| phase execution, note/feedback writes, log streaming | `app/Services/Workflow/PhaseRunner.php` |
| backing-service sidecars | `app/Services/Workflow/WorkerSidecarManager.php` |
| container entrypoint (credential materialise, dispatch) | `.tools/docker/worker/worker-entrypoint.sh` |
| per-phase scripts | `worker/phases/{concept,implement,diff,push,commit-message,respond}.sh` |
| quality gate | `worker/lib/quality.sh` |
| worker image (toolchain) | `.tools/docker/worker/Dockerfile.compose` + `.tools/docker/worker/stacks/Dockerfile.php-8.{3,4}` |
| demo deploy (compose lifecycle, boot, health, teardown) | `app/Services/Demo/DemoDeployer.php` |
| demo override (volume mount, edge network, env) | `app/Services/Demo/DemoComposeBuilder.php` |
| demo runtime image build | `app/Services/Demo/DemoImageBuilder.php` + `.tools/docker/demo/Dockerfile` |
| demo contract default / detection | `app/Services/Demo/DemoContractBuilder.php`, `DemoConfigLocator.php`, `resources/stubs/demo/laravel/demo.{yml,compose.yml}` |
</content>
</invoke>
