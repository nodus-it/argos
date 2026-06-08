# Changelog

All notable changes to Argos will be documented here.
Pre-releases (alpha/beta/rc) are tracked only in GitHub Releases —
this file lists stable releases.

## Unreleased

- **Breaking — install layout:** the `installer/` directory is gone; the single canonical `docker-compose.yml` is now the source of truth and the installer pulls it directly. Custom config belongs in a `docker-compose.override.yml` next to it (the installer never touches that). Existing self-hosters: see [docs/SETUP.md](docs/SETUP.md).
- **Queue:** Migrated to Laravel Horizon (Redis). Updates are automatic — leftover database-queue jobs are drained on first boot. The compose stack now includes `redis`, a Horizon `queue` worker, and a `scheduler` service.
- **OAuth & credentials:** OAuth apps (GitHub / GitLab / Bitbucket / Linear) and agent credentials are managed in the UI and stored in the database — no more `*_CLIENT_ID` / `*_CLIENT_SECRET` env vars. A guided onboarding wizard walks through agent token + first project.
- **REST API v1:** Sanctum-authenticated `/api/v1` endpoints (projects, tasks, concept / implement / pr, feedback) with scoped token abilities.
- **MCP server:** Built-in MCP server at `<APP_URL>/mcp` (OAuth 2.1 via Passport, scope `mcp:use`) so clients like Claude Code can create tasks and run phases. See [docs/SETUP-MCP.md](docs/SETUP-MCP.md).
- **Task providers:** Import issues from GitHub / GitLab / Linear and comment back as tasks progress. See [docs/SETUP-TASK-PROVIDERS.md](docs/SETUP-TASK-PROVIDERS.md).
- **Live demos:** Optional ephemeral per-task preview deployed after the implement phase, with `none` / `session` / `basic` access protection. See the live-demo settings in [docs/CONFIGURATION.md](docs/CONFIGURATION.md).
- **BYOI (build your own image):** Repos can ship a `.argos/worker.dockerfile` to control the worker build environment.
- **UI:** Redesigned admin (warm-paper / terracotta) with a control-room dashboard and task views.
