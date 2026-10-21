# What Argos runs — Workspace & Demo command reference

A precise list of the shell / Docker commands Argos executes on your behalf
against a target repository, for operators who want to audit exactly what the
agent does. There are two execution contexts:

- **The workspace** — one long-lived container per workspace (clone, the
  agent's sessions, quality gates, commit & push).
- **The Demo deployer** — an ephemeral preview stack of a workspace, when live
  previews are enabled for the project.

> This is a snapshot for orientation. The commands live in code (see
> [Where this lives](#where-this-lives) at the bottom); if in doubt, the source
> is the truth. Placeholders like `<slug>`, `<branch>`, `<base>` are resolved
> per workspace; `<service>`, `<port>` come from the demo contract.

## Contents

- [The workspace](#the-workspace)
  - [How it is launched](#how-it-is-launched)
  - [Clone & branch](#clone--branch)
  - [The agent session](#the-agent-session)
  - [Quality / test gate](#quality--test-gate)
  - [Ship: commit, push, pull request](#ship-commit-push-pull-request)
- [The Demo deployer](#the-demo-deployer)
  - [Runtime image build](#runtime-image-build)
  - [Deploy lifecycle](#deploy-lifecycle)
  - [Boot commands & health probe](#boot-commands--health-probe)
  - [The demo contract](#the-demo-contract)
  - [Teardown](#teardown)
- [Configurable vs fixed](#configurable-vs-fixed)
- [Where this lives](#where-this-lives)

---

## The workspace

### How it is launched

The manager prepares two volumes (the checkout and the agent state) and starts
one container from the workspace image — the worker image (stack + agent +
gate helpers) plus the workspace daemon and OpenVSCode Server. The container
runs as the non-root user `agent` (uid 1000) and has **no Docker socket**.

```
docker run -d --name argos-ws-<slug> --network argos_ws --restart unless-stopped \
  --label argos.workspace=<slug> \
  --cpus <argos.workspaces.cpus> --memory <argos.workspaces.memory> \
  -v ws_<slug>:/workspace \
  -v ws_<slug>_agents:/home/agent/.claude-agents \
  -v composer_cache:/home/agent/.composer/cache \
  -v npm_cache:/home/agent/.npm \
  -e ARGOS_DAEMON_TOKEN=… -e ARGOS_EVENTS_URL=… -e ARGOS_REPO_CREDENTIAL_URL=… \
  -e ARGOS_DAEMON_PORT=7777 -e ARGOS_EDITOR_PORT=3000 \
  -e APP_KEY=<fixed dummy> \
  [DB_HOST/REDIS_HOST… when backing services are on] \
  [project env: COMPOSER_AUTH + the project's custom secrets] \
  <workspace-image>
```

Notes for auditing:

- No repository token and no Claude token are in the container environment.
  Git asks the Argos credential helper, which fetches the token from the
  manager on demand; the Claude token is handed to the daemon in memory when a
  thread session starts.
- `APP_KEY` is a **fixed dummy** so the project's Laravel boots for artisan
  and tests. The workspace volume holds nothing this key protects.
- Backing services (MySQL / Redis), when the project enabled them, run as
  sidecars on a private per-workspace network (`ws_<slug>_svc`) the container
  joins. Their data volumes live until the workspace is deleted.

### Clone & branch

On the first start the daemon clones into the empty volume and checks out the
workspace branch:

```
git clone --no-checkout <repo-url> /workspace     # token via the credential helper
git checkout -B <branch> origin/<branch>          # branch exists on the remote
git checkout -b <branch> origin/<base>            # otherwise: new branch off the base
```

The branch is `ws/<slug>` (or `ws/<issue-number>-<slug>` for an issue
trigger). `.agent/` is marked locally ignored. On later starts the existing
checkout is left alone.

### The agent session

Each thread is a Claude Code session hosted by the daemon through the Claude
Agent SDK, with the repository's project and local settings. The chosen model,
permission mode and — for automatic runs — the turn budget come from the
thread. The agent edits the working tree; it does **not** push.

### Quality / test gate

Gates run on request (the *Gates* panel, the API, or an automatic run after
implementing). The daemon calls `run-gates.sh`, which sources the gate helpers
(`worker/lib/quality.sh`). A baseline is captured on the fresh checkout
(`composer install` when `vendor/` is missing, then the test and PHPStan
baselines), so only **new** failures block. Each gate is **skipped** when its
tool or trigger file is absent.

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
simply "off" when not installed. A failing gate can be sent to the agent as a
fix prompt; an automatic run does that on its own, up to its fix-round limit.

### Ship: commit, push, pull request

Shipping drafts a commit message with a short one-shot session (Haiku, JSON
output) over the diff, then:

```
git add -A
git -c user.name=<person> -c user.email=<email> commit --no-verify -m "<subject>\n\n<body>"
git push --set-upstream origin HEAD:refs/heads/<branch>
```

The pull / merge request is opened or updated by the **manager** over the
provider API (GitHub, GitLab, Bitbucket), not from inside the container.
Automatic runs with ship mode `none` stop after the push.

---

## The Demo deployer

When live previews are enabled, a workspace can start an ephemeral preview
stack. The code already lives in the workspace volume, so the deployer
**mounts that volume** into the demo's entry service rather than
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

For each deploy (the compose project name is the demo key `demo-ws-<slug>`):

```
# 1. tear down any previous demo of this workspace (idempotent replace)
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

The **override** (`override.yml`, generated per demo by `DemoComposeBuilder`)
carries no host `ports:` and no Traefik labels — routing is via the file
provider. It mounts the workspace volume at the contract's
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
workspace sidecars use).

### Teardown

A demo is torn down (on replace, eviction over the concurrency cap, or TTL
expiry — `argos.preview.ttl_hours`) by slug, so it still works after the
workspace row is gone. The route is always guarded by the workspace gate
(members only):

```
docker compose -p <slug> down -v --remove-orphans   # containers + volumes
# + remove the Traefik route file
```

---

## Configurable vs fixed

| Configurable by the project / run | Fixed by Argos |
| --- | --- |
| Test runner & its outcome (whichever of pest/phpunit/pint/phpstan is installed) | The gate **command lines** and ordering |
| Backing services (MySQL / Redis on/off, credentials) | Sidecars on a private per-workspace network |
| Model, plan / ship mode and budgets of an automatic run | Branch naming, no-Docker-socket workspace, tokens never in the container env |
| Project secrets / `COMPOSER_AUTH` injected into workspace & demo | Reserved Argos env keys (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, …) can't be overridden |
| The full demo contract (`commands`, `health`, services, image, entry) | The compose lifecycle (down → up → exec → probe → route) and the override-injected env |

---

## Where this lives

| Area | Source |
| --- | --- |
| volumes + `docker run` of a workspace | `app/Services/Workspace/WorkspaceContainerManager.php` |
| provisioning order (image → volumes → sidecars → container → clone) | `app/Services/Workspace/WorkspaceProvisioner.php` |
| backing-service sidecars | `app/Services/Worker/WorkerSidecarManager.php` |
| clone, git operations, agent sessions, gates | `daemon/src/{workspaceInit,gitOps,threadSession,gates}.ts` |
| gate script + helpers | `.tools/docker/worker/workspace/run-gates.sh`, `worker/lib/quality.sh` |
| ship (commit message, push, pull request) | `app/Services/Workspace/WorkspaceShipService.php` |
| images (toolchain, daemon, editor) | `.tools/docker/worker/Dockerfile.compose`, `.tools/docker/worker/stacks/Dockerfile.php-8.{3,4}`, `.tools/docker/worker/workspace/Dockerfile` |
| demo deploy (compose lifecycle, boot, health, teardown) | `app/Services/Demo/DemoDeployer.php` |
| demo override (volume mount, edge network, env) | `app/Services/Demo/DemoComposeBuilder.php` |
| demo runtime image build | `app/Services/Demo/DemoImageBuilder.php` + `.tools/docker/demo/Dockerfile` |
| demo contract default / detection | `app/Services/Demo/DemoContractBuilder.php`, `DemoConfigLocator.php`, `resources/stubs/demo/laravel/demo.{yml,compose.yml}` |
