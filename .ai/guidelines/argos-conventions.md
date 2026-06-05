# Argos — Project Conventions

> AI guideline for Claude Code and other agents working on Argos. This file
> complements Laravel Boost's generated guidelines with Argos-specific
> conventions. Every line costs context — keep it tight, prune monthly.

## What this project is

You are building **Argos** — a web-first dev agent with two Docker images
(Manager + Worker).

## Where things live

| Question | Source |
| --- | --- |
| System prompts for the Claude sessions in the Worker | `worker/prompts/*.system.md` |
| Schemas for state and outputs | `worker/schemas/*.schema.json` |
| Architecture and implementation details | The code itself |

The Laravel application lives at the repo root (`artisan`, `app/`, `config/`,
etc.). The `worker/` directory contains the Docker Worker only.

## License

Argos is licensed under AGPL-3.0-or-later. Commercial-only features are
planned but not yet active. All current code is open source. When in doubt
about license-sensitive areas (license enforcement, billing, telemetry),
ask before introducing patterns.

## Conventions

### Bash style

- `#!/usr/bin/env bash` as shebang in every executable file.
- `set -euo pipefail` and `IFS=$'\n\t'` are set **only by the top-level
  executor** (e.g. `.tools/docker/worker/worker-entrypoint.sh`,
  `.tools/bin/*.sh`, `worker/tests/run-*.sh`). A source-only library
  (`worker/lib/*.sh`, `worker/phases/*.sh`, `worker/lib/agents/*.sh`) must
  **not** set these flags at the top level — they would leak into the
  caller's scope and break, for example, `bats` tests (which reference
  `$BATS_TEST_NAME` etc. without a default). Source libs rely on the
  entrypoint and must be written `set -u`-safe (`${VAR:-default}` for
  optional vars).
- Function names: `<module>_<action>` (e.g. `state_init`, `lock_acquire`).
- Every function in `worker/lib/` has a docstring comment at the top:

  ```bash
  # state_init: Creates the initial state.json for a new task.
  # Args: $1=task_id, $2=repo_url, $3=base_branch
  # Returns: 0 on success, otherwise an exit code
  state_init() {
      ...
  }
  ```

- No `eval`. No `cmd $args` without quoting. Always quote variables: `"$var"`.
- Declare local variables with `local`.
- Use `[[ ... ]]` instead of `[ ... ]`, `(( ... ))` for arithmetic.

### Shellcheck

All Bash files must be `shellcheck`-clean (severity `error`/`warning`).
`info` and `style` are optional.

CI runs `shellcheck` (via `worker/tests/run-tests.sh`) over `worker/lib/`,
`worker/phases/`, `.tools/docker/worker/worker-entrypoint.sh`, and the test
runners (`run-tests.sh`, `run-bats.sh`).

### File layout

- One library per file in `worker/lib/`. No multi-responsibility files.
- Phase scripts in `worker/phases/<name>.sh` contain *only* the functions
  `phase_<name>_run`, `phase_<name>_preconditions`, `phase_<name>_help`.
  Helper functions belong in `worker/lib/`.
- Docker files live under `.tools/docker/`, strictly separated by role:
  `app/` (manager-side app image) and `worker/` (worker image). No further
  top-level directories.
  - `.tools/docker/app/Dockerfile` — multi-target: `base` (system packages +
    PHP extensions), `app` (PHP-FPM image, the only one we actually build).
  - `.tools/docker/app/entrypoint.sh` — entrypoint for the `app`/`queue`
    containers (docker.sock GID mapping, composer sync, migrations gated
    via `ARGOS_ROLE`).
  - `.tools/docker/app/nginx.conf` — Nginx config for the Compose stack
    (proxies to `app:9000`).
  - `.tools/docker/app/php-fpm.conf` — PHP-FPM pool config, listens on
    `0.0.0.0:9000`.
  - `.tools/docker/worker/Dockerfile` — worker image.
  - `.tools/docker/docker-compose.yml` — stack (db + app + nginx + queue +
    worker-build).
  - Build context is always the repo root.

### Documentation

- Every new function in `worker/lib/` needs a docstring.
- User-facing docs (setup, configuration, provider howtos) live in `docs/`.
- Architecture notes live inline with the code (class/method PHPDoc, a
  README per module folder if needed). Do not maintain a parallel concept
  document.

### Tests

- Bash unit tests with `bats-core` under `worker/tests/bats/`. One test file
  per lib file. They run in a Docker image (bats + jq) via
  `worker/tests/run-bats.sh`, so tests that need a real `/workspace` work.
- Laravel (PHP) tests live under `tests/` at the repo root.
- For bug fixes: write a test that reproduces the bug first, then fix.
- Tests must run offline — no real GitHub, no real Claude API.
- Filament components (RelationManager, Widgets, Header actions, etc.)
  **must always also be tested via the embedding page**, not just in
  isolation. A `Livewire::test(FooRelationManager::class, [...])` passes
  even when the manager isn't actually registered on the Resource (e.g.
  wrong hook name like `getRelationManagers()` instead of `getRelations()`).
  At least one test per page must verify the wiring, e.g.
  `Livewire::test(ViewFooPage::class, ['record' => $r->getKey()])->assertSeeLivewire(FooRelationManager::class)`.

### Git

- Conventional Commits style: `feat:`, `fix:`, `chore:`, `docs:`,
  `refactor:`, `test:`.
- Subject ≤ 72 characters, imperative ("add foo", not "added foo").
- Body if needed: what and why, not how.

### Security

- Tokens are **never** logged — not even for diagnostics.
- `set +x` for code regions that handle tokens, in case debug-trace is on.
- `credentials.env` must always be mode 600 (`umask 077`, OR `chmod 600`
  immediately after creation).
- `set -u` catches unset variables — we use it. For optional env vars, use
  `${VAR:-default}`.

## Cross-layer checklist — when you change X, also check Y

This table answers a recurring wave-1 pain: a patch applies cleanly at one
layer, but the parallel-affected places (migration, locale strings, worker
schema, tests, …) get forgotten. The table is allowed to grow — if you
notice a missing row during a patch, add it.

| When you change… | …also check |
| --- | --- |
| New enum case | DB migration (`enum()` values) — `EnumPersistenceTest` catches drift, but you must write the migration yourself; `lang/{de,en}/enums.php`; `color()` / `label()` / other `match` paths on the enum; Filament filter options / `SelectFilter::options()`; if a demo profile should showcase the case, add it to the relevant `database/seeders/Support/*Builder` and assert it in `tests/Feature/Seeders/` |
| New DB column | Model `$fillable` / `$casts`; **factory** (otherwise `factory()->create()` silently breaks on NOT-NULL); Filament form field + table column if relevant; JSON schema in `worker/schemas/` if the worker reads/writes the field; if a demo seeder writes the model via raw `create()`/`updateOrCreate()`, set the new NOT-NULL column there too — the `tests/Feature/Seeders/*` tests run the seeders against MariaDB in CI and fail on the missing value |
| New Filament page (Resource or Page) | `RedirectToOnboarding` whitelist if reachable pre-onboarding; `getNavigationGroup` / Heroicon; **wiring test via the embedding page** (`Livewire::test(ViewFooPage::class, [...])->assertSeeLivewire(FooRelationManager::class)`) — isolated RelationManager test alone is not enough; locale strings `de` + `en` |
| New phase helper | `worker/lib/<module>.sh` with docstring; `bats` test in `worker/tests/bats/`; `shellcheck`-clean; sourced by `worker-entrypoint.sh`; which phase script calls the helper |
| UI hint claiming a behavior | The backend implements it **for every relevant path** — every agent, every provider, every status. Helper text that's only true for the default path is a lie. |

## Test levels

We run five test levels — each with a clear scope, so no single test has
to know everything and friction is caught early. A new test file should
fit into exactly one of these levels; when in doubt, pick the narrower one.

| Level | Tool | Coverage | Today |
| --- | --- | --- | --- |
| Unit | Pest (SQLite + mocks) | Service / class logic in isolation, without DB schema dependencies | ✅ |
| Integration | Pest + MariaDB sidecar (CI) | DB schema, migrations, enum persistence, queue lifecycle | ✅ (retro M2) |
| Backend-E2E | Pest + `FakeWorkerProcess` (`tests/Support/`) | Workflow phase run incl. recovery paths — RunPhaseJob → PhaseRunner → DB state | ✅ (retro M7) |
| UI smoke | `Livewire::test(ViewTask::class, ['record' => …])` | UI renders correct status string per phase, action wiring | ✅ (retro M10) |
| Browser-E2E | Playwright against the compose stack | Full flow (login → onboarding → project → task → concept/implement) over the 4-run matrix + a mask smoke walk. Run: `npx playwright test` (see "Common commands"). | ✅ (retro M11) |

Browser-E2E runs locally only today — the discipline of running
`npx playwright test` before a commit is the first line of defense. CI
integration deliberately not built (effort vs. value not justified yet).

Two layers: **gemockt** (default, deterministic) runs the stack's `app`
container with `ARGOS_E2E_FAKE=1`, which boots `E2eFakeServiceProvider` —
offline fakes for the Anthropic validator, the git providers, and the worker
run (`FakePhaseRunner`), so no tokens, API calls, or `docker run` are needed.
The provider is double-gated (`ARGOS_E2E_FAKE` **and** not-production) and
throws if it ever boots in production; an arch test keeps `App\Testing` out of
production code paths. The **echt** layer (`full-flow.real.spec.ts`) is opt-in
via `ARGOS_E2E_REAL=1` against a real stack + test repo — never in CI.

## Architecture tests

Pest architecture tests live in `tests/Arch/`. They enforce structural
rules verified on every test run. Current rules:

- No debug calls in `app/` (`dd`, `dump`, `ray`, `var_dump`, `print_r`).
- All `app/` files declare `strict_types=1`.
- `app/Workers/` may not depend on `app/Filament/`.

To propose new architecture rules: surface via `/retro`, do not add
inline during feature work.

## Caches & resets

**Anticipation duty**: when changing any of the layers below, **proactively**
run the reset action or announce it — don't wait until the user asks "do I
need to rebuild or something?".

- Local, fast reset actions (`dev-reload.sh`) you run **yourself directly**,
  no confirmation needed.
- Disruptive resets (`dev-reset.sh` deletes volumes + worker images) you
  announce briefly and wait for OK.

| Changed | Cache | Reset action |
| --- | --- | --- |
| Manager PHP (`app/`, `config/`, `resources/`) | OPCache + queue worker holds old classes | `.tools/bin/dev-reload.sh` — automatic |
| New migration | DB schema stale | `.tools/bin/dev-reset.sh` (fresh schema + demo data) — announce |
| `composer.json` | `vendor/` in workspace + in app container | `composer install` in the app container |
| `worker/lib/*.sh` or `worker/phases/*.sh` | Worker image | nothing — `libHash` triggers a rebuild on the next phase run |
| `.tools/docker/app/Dockerfile` | App image | `docker compose -f .tools/docker/docker-compose.yml build app` + restart |
| `.tools/docker/worker/Dockerfile` | Worker image (all variants) | `.tools/bin/dev-reset.sh` removes tags, the next phase run rebuilds |

## Common pitfalls

Wave-1 lessons with commit refs — if you recognize a symptom, look there
first before debugging anew. The list grows curated (only things that would
recur without an anchor — not a garbage dump).

- **SQLite silently ignores ENUM constraints.** Drift between `app/Enums/*`
  and the MariaDB ENUM column only shows up against MariaDB.
  `tests/Feature/EnumPersistenceTest.php` catches this automatically today
  via reflection over model `$casts`; for new DB-backed enums, verify that
  auto-discovery picks them up. (`f952aa0`)
- **`set -euo pipefail` in a source-only lib leaks into the caller scope.**
  Strict mode is set only by the top-level executor (entrypoint,
  `.tools/bin/*.sh`, test runner). Worker libs / phase scripts are
  sourced — they must **not** set this — otherwise bats setups break that
  reference `$BATS_*` without a default. (`d50d3fe`)
- **Phase jobs are too expensive for blind retries.** `RunPhaseJob::$tries = 1`;
  on exception `failed()` actively marks the task as Failed so the user
  sees it in the UI and retries manually. The default 3 retries cost
  200–800 k tokens per attempt. (`f952aa0`)
- **`$_SERVER['HOME']` is empty in PHP's built-in server.** Config defaults
  must use `getenv('HOME')` as a fallback, otherwise the SQLite default
  path lands at `/root/...` and `artisan serve` throws 500. Affects every
  "works in CLI, not in server" bug. (`9b1046f`)

## Things you do NOT do without checking back

- Reverse architecture decisions from the spec documents — in particular:
  no AI in the manager container; the worker has no Docker socket.
- New top-level dependencies (Bash tools that aren't in the `bookworm`
  standard set, without explicit justification).
- New volumes or services in `.tools/docker/docker-compose.yml`.
- Introduce new phases or fundamentally change existing ones (extending
  phases via `phases/` is fine, but concept → implement → diff → push as
  the default flow is set).
- Change the auth flow (tokens come from the DB into the worker as env vars,
  never from files).
- Change the branch naming scheme.
- Bypass the state schema versions and introduce a new structure — if the
  structure changes, bump `schema_version` and consider migration logic.

## When you finish a step

**Bash changes:**

1. `shellcheck` over every changed Bash file.
2. `bash worker/tests/run-bats.sh`.

**PHP changes:**

1. **While working**: after every `Edit` / `Write` on a PHP file
   *immediately* run `vendor/bin/pint --dirty --format agent`. Not at the
   end of the step. Style issues fixed early cost seconds — style issues
   surfaced by user feedback at commit time cost minutes.
2. **End of step**: run the relevant test filter —
   `php artisan test --compact --filter=<TestName>`. Only when that's green,
   run the whole suite (`php artisan test --compact`).
3. **Before commit**: `vendor/bin/phpstan analyse --no-progress
   --memory-limit=1G`. PHPStan catches Larastan-specific smells (`env()`
   outside config, wrong cast targets) that PHPUnit/Pest doesn't see —
   wave-1 hit: the M1 DemoSeeder produced 4 PHPStan errors that only
   surfaced in M2 because M1 validation only ran tests.

**Both:**

- Commit in Conventional Commits style.
- If an assumption from the spec turned out to be wrong while building,
  adjust the affected spec document *in the same commit or the follow-up
  commit*.

## Common commands

```bash
# Bring up the Compose stack (db + app + nginx + queue). Worker images are
# built on demand by WorkerImageResolver on the first phase run, from
# .tools/docker/worker/Dockerfile + the active worker_stacks entry — no
# manual pre-build needed.
docker compose -f .tools/docker/docker-compose.yml up -d

# Build the app image manually (Compose does this automatically)
docker build -t argos-app:local -f .tools/docker/app/Dockerfile --target app .

# Run Bash tests (shellcheck + bats)
./worker/tests/run-tests.sh

# Run PHP tests
php artisan test

# Local development (web UI without Docker)
php artisan serve

# Full dev reset (DB + task_ws_* volumes + argos-worker/stack images +
# optimize:clear + queue restart) with a chosen demo profile (default full):
#   composer dev:basic — admin user only; onboarding starts from scratch
#   composer dev:full  — every view filled with all variants (FullDemoSeeder)
#   composer dev:live  — real OAuth from .env, one real task startklar (local only)
# Live-Ready reads SEED_GITHUB_OAUTH_TOKEN / SEED_REPO_URL / SEED_CLAUDE_OAUTH_TOKEN
# (+ optional SEED_GITHUB_USER / SEED_GITHUB_REFRESH_TOKEN / SEED_REPO_BRANCH) from
# the root .env. The composer scripts wrap `.tools/bin/dev-reset.sh [basic|full|live]`.
composer dev:full

# Fast reload after manager PHP changes (optimize:clear + queue restart,
# without DB/volume/image cleanup). Addresses OPCache + queue worker
# staleness.
bash .tools/bin/dev-reload.sh

# Browser-E2E (Playwright) — runs against the running compose stack.
# One-time prereq: `npm install` + `npx playwright install chromium`.
# Stack prereq: `docker compose -f .tools/docker/docker-compose.yml up -d`,
# with the app/queue containers started with ARGOS_E2E_FAKE=1 for the gemockt
# suite (the reset helper re-seeds via migrate:fresh per test). baseURL follows
# ARGOS_PORT (default 8080).
composer test:browser

# Real (opt-in) flow: real worker, real credentials, real test repo. Not in CI.
ARGOS_E2E_REAL=1 composer test:browser:real
```

## Output language

- Code, comments, commit messages, docs, error messages: **English**.
- User-facing strings rendered by Filament: localized per `lang/{de,en}/`.
- The retrospective inbox (`.ai/learnings/inbox.md`) is the one place that
  is written in **German** — it is the user's private notebook.

## Questions are welcome

If something in the spec is unclear, contradictory, or under-specified:
**ask before you guess.** The spec deliberately leaves some details open
to the concrete build — but when you're not sure whether something is
"intentionally open" or "forgotten to specify", ask.

Same with architecture pressure: when you notice while implementing that
a decision hurts in practice — say so, instead of silently deviating.
