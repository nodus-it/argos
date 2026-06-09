# What Argos runs — Worker & Demo command reference

A precise list of the shell / Docker commands Argos executes against a target
repository. There are two execution contexts:

- **The Worker** — one ephemeral container per task *phase* (clone, install
  deps, run the agent, run quality gates, push).
- **The Demo deployer** — an ephemeral preview stack spun up *after* a
  successful implement, when previews are enabled.

> This is a snapshot for orientation. The commands live in code (see
> *Where this lives* at the bottom); if in doubt, the source is the truth.
> `$BASE_BRANCH`, `$REPO_URL`, `$auth_header`, `$feature_branch` etc. are
> injected per task.

---

## 1. The Worker

### How it is launched

The manager builds one `docker run` per phase and streams its output. The
worker is a **single container**, runs as the non-root user `agent` (uid 1000),
and has **no Docker socket**.

```
docker run --rm \
  -v <task-volume>:/workspace \
  -v composer_cache:/home/agent/.composer/cache \
  -v npm_cache:/home/agent/.npm \
  --memory <argos.docker.memory_limit> --cpus <argos.docker.cpu_limit> \
  -e PHASE=<phase> -e TASK_ID=<task> -e REPO_URL=… -e REPO_TOKEN=… \
  -e REPO_PLATFORM=… -e BASE_BRANCH=… -e AGENT_NAME=… \
  -e TASK_DESCRIPTION=… -e PHASE_FLAGS=<json> -e MAX_TURNS=… \
  -e CLAUDE_CONFIG_DIR=/workspace/.agent/claude-state -e CLAUDE_MODEL=… \
  -e APP_KEY=<dummy> \
  [agent credential env] [backing-service --network + DB_HOST/REDIS_HOST env] \
  [project env: COMPOSER_AUTH + custom secrets] \
  <worker-image> <phase> <task>
```

Backing services (MySQL/Redis), when enabled, are booted by the manager as
ephemeral sidecars on a private per-run network *before* this command and torn
down afterwards (only for the `implement` / `respond` phases).

The manager also writes notes/feedback into the volume via a throwaway helper
before some phases, e.g.:

```
docker run --rm -i -v <task-volume>:/workspace alpine \
  sh -c 'mkdir -p /workspace/.agent && cat > /workspace/.agent/concept.notes.md && chown -R 1000:1000 /workspace'
```

### Clone & branch (first run / concept)

```
git init --quiet --initial-branch="$BASE_BRANCH"
git remote add origin "$REPO_URL"
git -c "http.extraheader=$auth_header" fetch --quiet --depth=1 origin "$BASE_BRANCH"
git checkout -B "$feature_branch" "origin/$BASE_BRANCH"
```

### Dependency install (concept & implement)

```
# .env is seeded from .env.example if missing; a public/hot stub is dropped
composer install --no-interaction --prefer-dist --no-progress   # if composer.json exists
npm ci --no-audit --no-fund                                     # implement only, if package-lock.json exists
```

### Per phase

| Phase | What it runs (in order) |
| --- | --- |
| **concept** | clone & branch → `composer install` → **agent session** (writes the concept) |
| **implement** | (if fresh) `git fetch` + `git reset --hard origin/$BASE_BRANCH` + `git clean -fd` → `composer install` / `npm ci` → **capture test baseline** (`vendor/bin/pest … --log-junit …`) → **agent session** → **quality gates** (below); on a failing gate, up to 3 agent fix sessions |
| **diff** | read-only: `git diff [--stat] origin/$BASE_BRANCH` · `git status --short` · `git diff --numstat …` · `git ls-files --others --exclude-standard` |
| **push** | `git add -A` → `git commit -m "<subject>" [-m "<body>"]` (author `"<name> via Argos"`) → `git push -u --force-with-lease origin "$feature_branch"` (GitLab adds MR push options) → PR via API `curl` (GitHub/Bitbucket) |
| **commit-message** | short **agent session** (`--max-turns 8`, Haiku) that produces the commit subject/body — invoked by `push` |
| **respond** | **agent session** → the same **quality gates** as implement |

The **agent session** is the agent CLI (`claude` / `codex`) run with a system
prompt, the task/notes as input, a per-phase turn budget (`MAX_TURNS`, set per
project/task, defaults in `config/argos.php`) and a model (`CLAUDE_MODEL`),
streaming JSON which Argos parses for the result.

### Quality gates (implement & respond)

Run in this order. Each gate is **skipped** when its tool/file is absent. A
baseline is captured on the clean checkout first, so only **new** failures
(versus pre-existing ones) block.

| # | Gate | Command | Runs when |
| --- | --- | --- | --- |
| 1 | artisan smoke | `php artisan list --no-ansi` | `/workspace/artisan` exists |
| 2 | Pint (style) | `vendor/bin/pint --test <changed php files>` | `vendor/bin/pint` exists + changed PHP files |
| 3 | tests | `vendor/bin/pest --no-coverage --log-junit <…>.xml` (or `vendor/bin/phpunit --log-junit <…>.xml`) | the runner exists |
| 4 | PHPStan | `vendor/bin/phpstan analyse --no-progress` | `phpstan.neon`/`.dist` + `vendor/bin/phpstan` exist |
| 5 | migration syntax | `php -l <new migration file>` | new files under `database/migrations/` |
| 6 | debug-code | `grep -lE '\bdd\(\|\bdump\(\|\bray\(\|\bvar_dump\(\|\bddd\('` over changed non-test PHP | changed non-test PHP |
| 7 | test-presence | checks new app classes for a matching `*Test.php` | warn-only (never blocks) |

The gate commands are **fixed** — a project does not configure them; a tool is
simply "off" when it is not installed. The worker image provides the toolchain
(PHP + extensions incl. `sockets`, Composer, Node, the MySQL client, …).

---

## 2. The Demo deployer

### Image build (on demand)

The default demo runtime is built once and content-hash cached:

```
docker build -t argos-demo:<8-hex-hash> -f .tools/docker/demo/Dockerfile <repo root>
```

### Deploy lifecycle (after a successful implement, when enabled)

```
# 1. tear down any previous demo for this task
docker compose -p <slug> down -v --remove-orphans

# 2. bring the stack up (project compose + Argos override)
docker compose -p <slug> -f demo.compose.yml -f override.yml up -d --remove-orphans

# 3. run each boot command inside the entry service
docker compose -p <slug> exec -T <service> sh -c '<command>'

# 4. health probe until ready (retry every 3s until health.timeout)
docker compose -p <slug> exec -T <service> \
  sh -c 'curl -fsS "http://localhost:<port><health.path>" >/dev/null'
```

### Bundled default boot commands

When the repo ships no `.argos/demo.*`, these run in order (each via
`docker compose … exec -T app sh -c '<command>'`), from
`resources/stubs/demo/laravel/demo.yml`:

```
1. composer install --no-interaction --prefer-dist --no-progress
2. [ -f .env ] || cp .env.example .env
3. php artisan migrate --force --seed
4. [ -f package.json ] && npm ci && npm run build || true
5. rm -f public/hot
6. php artisan storage:link || true
7. chown -R www-data:www-data storage bootstrap/cache …; chmod -R ug+rwX storage bootstrap/cache …; true
```

A project's own `.argos/demo.yml` **replaces** this `commands:` list (and its
`health.path`); the demo DB credentials and an optional Redis service follow the
project's backing-service config.

---

## Where this lives

| Area | Source |
| --- | --- |
| `docker run` assembly | `app/Services/Workflow/PhaseCommandBuilder.php` |
| phase execution + log streaming | `app/Services/Workflow/PhaseRunner.php` |
| backing-service sidecars | `app/Services/Workflow/WorkerSidecarManager.php` |
| container entrypoint (clone, lock, dispatch) | `.tools/docker/worker/worker-entrypoint.sh` |
| per-phase scripts | `worker/phases/{concept,implement,diff,push,commit-message,respond}.sh` |
| quality gates | `worker/lib/quality.sh` |
| worker image (toolchain) | `.tools/docker/worker/stacks/Dockerfile.php-8.{3,4}` |
| demo image build | `app/Services/Demo/DemoImageBuilder.php` + `.tools/docker/demo/Dockerfile` |
| demo deploy (compose lifecycle) | `app/Services/Demo/DemoDeployer.php` |
| demo default contract | `resources/stubs/demo/laravel/demo.{yml,compose.yml}` |
