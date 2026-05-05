# Contributing

Thanks for considering a contribution. This guide covers local setup, the
test suite, and the conventions that keep the codebase coherent.

For project-internal conventions read by Claude (the AI agent powering
Argos), see [`CLAUDE.md`](../CLAUDE.md).

## Repository layout

```
app/                 ← Laravel application (controllers, models, services)
worker/              ← Bash worker (phases, libs, prompts, schemas)
.tools/docker/       ← Dockerfiles + compose for manager and worker
docs/                ← User-facing docs
tests/               ← PHP feature & unit tests
worker/tests/        ← Bash unit + integration tests (bats)
```

The Laravel app lives in the repo root. Everything under `worker/` is the
Bash runtime that runs *inside* the worker container.

## Local development

### Prerequisites

- Docker + Compose v2
- PHP 8.4
- Composer 2
- Node 22+

### One-time setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Run everything

```bash
composer run dev
```

That spins up the manager via Docker Compose **and** runs the Vite watcher
locally. Open <http://localhost:8080/admin>.

Useful sub-commands:

```bash
composer run dev:stop      # tear it down
composer run dev:logs      # follow compose logs
composer run dev:exec      # bash into the manager container
```

The compose file lives at `.tools/docker/docker-compose.yml`. It builds the
worker image automatically on first start (`worker-build` service).

### Stage profile

To preview the released `:stage` images (manager + worker built from the
`dev` branch):

```bash
composer run stage          # start
composer run stage:stop     # tear down
composer run stage:logs     # follow logs
composer run stage:exec     # bash into it
```

## Tests

### PHP

```bash
composer run test                    # all PHP feature & unit tests
php artisan test --compact \
  tests/Feature/SomeTest.php          # one file
php artisan test --compact \
  --filter=testName                   # one method
```

### Bash

```bash
./worker/tests/run-tests.sh                # everything: shellcheck + bats + integration
./worker/tests/run-tests.sh --bats         # unit tests only
./worker/tests/run-tests.sh --integration  # phase lifecycle against mock-claude
./worker/tests/run-tests.sh --shellcheck   # lint
```

Tests run **offline** — no real GitHub access, no real Claude API. The mock
implementations live under `worker/tests/fixtures/`.

### External provider tests

The contract suite under `tests/External/` hits real GitHub/GitLab/Bitbucket
APIs and is **not** part of the normal test run. To use it, copy
`.env.testing.external.example` to `.env.testing.external`, fill in the PATs,
then run:

```bash
composer run test:external
```

Or use the helper that seeds three RepoProfiles per provider (PAT + OAuth)
and runs both auth modes:

```bash
php artisan test:providers          # interactive
php artisan test:providers --reset  # clean up afterwards
```

## Conventions

### PHP / Laravel

- Run `vendor/bin/pint --dirty` before finalising any change. Pint is the
  source of truth for style.
- Filament resources follow the existing patterns under
  `app/Filament/Admin/Resources/` — check sibling files before introducing a
  new structure.
- Eloquent enums are preferred over string columns; new enums go under
  `app/Enums/`.
- Tests must cover happy paths, failure paths, and edge cases. Filament
  components must additionally be tested via the page they're embedded in
  (see [`CLAUDE.md`](../CLAUDE.md) for why).

### Bash / Worker

See [`CLAUDE.md`](../CLAUDE.md) for the full Bash style guide. The
high-level rules:

- `set -euo pipefail` and `IFS=$'\n\t'` at the top of every script
- Functions are named `<module>_<action>` (e.g. `state_init`)
- Each function in `worker/lib/` carries a docstring
- All Bash files must pass `shellcheck` (severity error/warning)

### Commit messages

Conventional Commits style:

```
feat: add concept replay button
fix: handle 422 from GitHub PR creation
docs: split README into landing + extended setup
refactor: extract WorkerImage::optionsFor
test: cover OAuth callback redirect
chore: bump bats-core to 1.11
```

Subject ≤ 72 chars, imperative mood ("add", not "added").

### Docs

User-facing docs go under `docs/`. Internal architecture notes go inline in
the code or in `worker/`-adjacent README files. Don't create planning or
status documents — those belong in PR descriptions.

## Pull requests

- Reference the issue or backlog entry the PR addresses.
- Include the test you wrote that reproduces the bug, if applicable.
- For UI changes, attach a screenshot.
- For architecture changes, link to a discussion or design doc first.

## Reporting issues

Open an issue on <https://github.com/nodus-it/argos/issues>. Include:

- Argos image tag (`:latest`, `:stage`, `vX.Y.Z`)
- Worker image tag if relevant
- Provider (GitHub / GitLab / Bitbucket) + auth mode (PAT / OAuth)
- Reproduction steps and any logs from the affected task

Logs are visible per-task in the UI under **Task → Logs**.
