# Changelog

All notable changes to Argos are documented here, newest first. Entries are
curated highlights, not a per-commit log — see the [GitHub Releases](https://github.com/nodus-it/argos/releases)
for the full commit list of each tag. Versions follow SemVer; pre-1.0 betas
may carry breaking changes between releases.

## [Unreleased]

- **Fix:** Issue-Tracker-Kommentare wurden bei jeder Phase doppelt gepostet (Listener-Doppelregistrierung durch Laravel-Event-Discovery + manuelle Registrierung in `AppServiceProvider`). Auto-Discovery ist jetzt explizit deaktiviert; `AppServiceProvider::boot()` bleibt die einzige Quelle der Wahrheit.

## [0.3.0-beta.1] - 2026-06-15

A consolidation release: a service-layer architecture pass, in-app docs, a
mobile-ready UI, and three workflow/quality improvements (localization, task
identity, external branch collaboration).

- **Architecture pass:** resource writes routed through entity services (Task / RepoProfile / credentials / ApiClient), presentation-layer write/IO purity, Task domain events unified under `DomainEvent`, the integration ingest path split into providers + an inbound DTO + an ingest event, and EE-seams prepared (gate-panel access, account-listing) — verified by new Pest architecture rules. No behavior change for self-hosters.
- **In-app documentation viewer:** the curated operator/user docs render at `/admin/docs` (with deep-links from relevant screens), including a German variant; integrity, coverage, and translation-freshness are test-enforced.
- **Mobile-ready UI:** the control-room and key task screens are responsive at 375px with ≥44px tap targets, guarded by a Playwright mobile gate.
- **Task identity:** a task's **name** is now a free, renameable, non-unique display label; a frozen **slug** carries the operational identity (workspace volume, feature-branch prefix, log paths). Existing tasks keep their exact keys (the slug backfills from the name) and the branch naming scheme is unchanged.
- **External branch collaboration:** you can check out a task's feature branch, push your own commits, and Argos pulls them before continuing (remote-wins on refine/respond). Pushing over external commits fails with a clear message instead of a cryptic git error, and a demo rebuild reflects the pushed remote state.
- **Localization:** user-facing German strings that were hardcoded (task-provider bindings, log/diff screens, issue write-back comments, OAuth token-refresh errors) moved into `lang/{de,en}` — the app honours the configured locale consistently.
- **Teardown:** task resource cleanup (containers, volumes, networks) centralized and triggered on delete / abort / orphan sweep, with an explicit aborted status.
- **Fixes & tooling:** quality gate skipped on worker infra crashes (no wasted remediation), helper text shows the inherited defaults on task override fields, Tailwind dark utilities aligned with Filament's `.dark` toggle, adopted `nodus-it/dev-tools` for commands/QA, and added the `--next` installer channel tracking the rolling `:next` images.

## [0.2.0-beta.1] - 2026-06-09

A large release: the full live-demo system, the REST API, BYOI worker images,
the credentials/onboarding UI, a UI redesign, and project-level test
infrastructure (backing services + secrets).

- **Breaking — install layout:** the `installer/` directory is gone; a single canonical `docker-compose.yml` is the source of truth and the installer pulls it directly. Custom config belongs in a `docker-compose.override.yml` next to it (never touched by the installer). Existing self-hosters: see [docs/SETUP.md](docs/SETUP.md).
- **Live demos:** ephemeral per-task preview, deployed after a successful implement and routed by an in-stack Traefik under its own subdomain, with `none` / `session` / `basic` access protection. On by default platform-side — the per-project toggle is the real gate; disable with `ARGOS_PREVIEW_ENABLED=false`.
- **Backing services for tests:** opt-in ephemeral MySQL/Redis sidecars per worker run (and unified into the demo), with configurable credentials and `${mysql.*}` / `${redis.*}` placeholders so non-standard test env names bridge without hardcoding internals.
- **Project environment & secrets:** per-project encrypted secrets — private Composer registries (auto-built `COMPOSER_AUTH`) and arbitrary env vars — injected into **both** the worker and the live demo.
- **REST API v1:** Sanctum-authenticated `/api/v1` (projects, tasks, concept / implement / pr, feedback) with scoped token abilities, plus auto-generated OpenAPI docs (Scramble).
- **Credentials & onboarding UI:** OAuth apps (GitHub / GitLab / Bitbucket / Linear) and agent credentials are managed in the UI and stored in the database — no more `*_CLIENT_ID` / `*_CLIENT_SECRET` env vars. A guided onboarding wizard covers the agent token + first project; tokens/OAuth are verified on save.
- **BYOI (bring your own image):** repos can ship a `.argos/worker.dockerfile` to control the worker build environment.
- **Worker toolchain:** built-in stacks on Node 22 with `sockets` + the MySQL client and a lifted `memory_limit`, so large (e.g. Filament) projects install and run their quality gates cleanly. An agent guide ([docs/PREPARE-PROJECT.md](docs/PREPARE-PROJECT.md)) walks an AI through preparing a repo.
- **UI:** Warm-Paper / Terracotta redesign with a control-room dashboard, a reworked task-detail view (stage stepper, live refresh), a redesigned login, and CLI-near colored agent-stream logs. Optional media-library (file/image) support.

## [0.1.0-beta.4] - 2026-05-31

- **Worker:** Vite hot-file stub so target apps boot without built assets; `pcntl` / `exif` / `pdo_pgsql` added to the PHP stacks; task volume chowned to the worker uid on creation; leaner, budget-aware concept agent.
- **Workflow:** default concept turn budget raised 30 → 50; queue `retry_after` lifted above the phase-job timeout; the source issue is closed when the task completes (outbound status-sync).
- **Images:** `:stage` images stamped with a build-dated version.

## [0.1.0-beta.3] - 2026-05-31

- **Queue:** migrated to Laravel Horizon (Redis); the stack gains `redis`, a Horizon `queue` worker, and a `scheduler`.
- **MCP server:** built-in MCP server (OAuth 2.1 via Passport) so clients like Claude Code can create tasks and run phases. See [docs/SETUP-MCP.md](docs/SETUP-MCP.md).
- **Task providers:** import issues from GitHub / GitLab / Linear and comment back as tasks progress. See [docs/SETUP-TASK-PROVIDERS.md](docs/SETUP-TASK-PROVIDERS.md).
- **Resilience:** detect the Claude Max plan limit and recover from worker crashes; resumable concept phase on max-turns; `max_turns` split into concept + implement.
- **Observability:** quality-gate logs persisted and visualized in the UI; per-task log-bundle ZIP download; waiting-for-worker status.
- **Worker boot:** dummy `APP_KEY` and a seeded `/workspace/.env` so the target Laravel boots without warnings.

## [0.1.0-beta.2] - 2026-05-10

- **Multi-stack / multi-agent worker:** refactored into individual stacks and an `AgentRunner` contract for multiple agents; per-phase Claude model selection with overrides.
- **MCP (worker):** Laravel Boost MCP wired into the worker phases over stdio.
- **Architecture:** task actions centralized in `TaskService` with domain events; task table unified with status tabs.
- **Installer:** `--beta` flag with pre-release fallback.
- **Fixes:** show untracked files in the diff tab; persist content before the status update to avoid an empty UI on poll.

## [0.1.0-beta.1] - 2026-05-07

First public beta. Argos is a web-first dev agent: tasks are delegated through a
browser UI to a Claude-powered worker that runs per task in an isolated
container and ships its work as a PR/MR.

- **Web UI** (Filament) for creating, monitoring, and managing tasks.
- **Self-host via `install.sh`** with a Compose stack (db + app + nginx + queue + worker).
- **Per-task worker container** with clean state across the phases *concept → implement → diff → push*.
- **Providers:** GitHub, GitLab, and Bitbucket via OAuth or Personal Access Token (GitHub PRs via REST, GitLab MRs via `git push -o`).
- **Multi-PHP worker images** (PHP 8.3 / 8.4), reverse-proxy aware, with log download + a feedback shortcut from the UI.
