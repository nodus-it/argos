# Changelog

All notable changes to Argos will be documented here.
Pre-releases (alpha/beta/rc) are tracked only in GitHub Releases —
this file lists stable releases.

## Unreleased

- **Queue:** Migrated to Laravel Horizon (Redis). Updates are automatic — leftover database-queue jobs are drained on first boot. The compose stack now includes `redis`, a Horizon `queue` worker, and a `scheduler` service.
- **MCP server:** Built-in MCP server at `<APP_URL>/mcp` (OAuth 2.1 via Passport, scope `mcp:use`) so clients like Claude Code can create tasks and run phases. See [docs/SETUP-MCP.md](docs/SETUP-MCP.md).
- **Task providers:** Import issues from GitHub / GitLab / Linear and comment back as tasks progress. See [docs/SETUP-TASK-PROVIDERS.md](docs/SETUP-TASK-PROVIDERS.md).
