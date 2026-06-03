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

That spins up the compose stack (db + redis + app + nginx + queue + scheduler)
**and** runs the Vite watcher locally. Jobs run on Laravel Horizon (Redis), so
the `redis` service is part of the stack — no separate install needed. Open
<http://localhost:8080/admin>.

Useful sub-commands:

```bash
composer run dev:stop      # tear it down
composer run dev:logs      # follow compose logs
composer run dev:exec      # bash into the app container
```

The canonical compose file lives at `.tools/docker/docker-compose.yml` — the
same file the self-host installer ships. Local development layers
`.tools/docker/docker-compose.dev.yml` on top (build from source, bind-mounts,
phpMyAdmin); the `composer dev` script and the `.tools/bin/dev-*.sh` helpers
pass both `-f` files for you.

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
refactor: extract StackAgentCompatibility
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

## License of contributions

Argos is licensed under the
[GNU Affero General Public License v3.0 or later](../LICENSE). By submitting
a pull request you agree that your contribution is licensed under the same
terms and may be redistributed under AGPL-3.0-or-later.

If you need different licensing terms — for example to integrate Argos into
a closed-source product — contact Nodus IT at
[argos@nodus-it.de](mailto:argos@nodus-it.de).

## AI-assisted development setup

Argos is configured for AI coding agents via
[Laravel Boost](https://laravel.com/docs/boost).

**Auto-generated (git-ignored):**

- `CLAUDE.md`, `AGENTS.md` — agent guidelines, regenerated by Boost
- `.mcp.json` — MCP server configuration
- `.claude/settings.local.json` — your personal Claude Code overrides

**Committed (team-shared):**

- `boost.json` — which agents the project supports
- `.ai/guidelines/` — Argos-specific AI guidelines (Phase 1 onwards)
- `.ai/skills/` — workflow skills (Phase 2 onwards)
- `.claude/settings.json` — shared Claude Code hooks and permissions
- `.claude/scripts/` — hook scripts

**On a fresh clone:**

```bash
composer install   # auto-runs boost:install, generates CLAUDE.md etc.
```

**When you change `.ai/` files:** a `SessionStart` hook auto-regenerates
`CLAUDE.md` next time you open Claude Code. No manual step needed.

**Personal overrides:** copy `.claude/settings.local.json.example` to
`.claude/settings.local.json` and add machine-specific permissions or hooks.
