<div align="center">

<img src=".github/logo.svg" alt="Argos" width="640">

### Every agent. One view.

**Claude Code in the browser, for your team.** Work with Claude in isolated
workspaces — or let it plan, implement, check and open the pull request on its
own — all on your Claude subscription, not the API.

[![License: AGPL-3.0](https://img.shields.io/github/license/nodus-it/argos?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/github/v/tag/nodus-it/argos?style=flat-square&label=version&include_prereleases&sort=semver)](https://github.com/nodus-it/argos/releases)
[![GHCR](https://img.shields.io/badge/ghcr.io-argos--app-2496ED?style=flat-square&logo=docker&logoColor=white)](https://github.com/nodus-it/argos/pkgs/container/argos-app)
[![CI](https://img.shields.io/github/actions/workflow/status/nodus-it/argos/ci.yml?branch=develop&style=flat-square&label=tests)](https://github.com/nodus-it/argos/actions/workflows/ci.yml)

<br>

<img src=".github/screenshots/login.png" alt="Argos — sign in to your control room" width="900">

</div>

<!--
  Screenshot gallery — drop PNGs into .github/screenshots/ and uncomment.
  Suggested shots: workspace (chat + workbench), review (diff), auto-thread.

<div align="center">
<img src=".github/screenshots/workspace.png" alt="Workspace — chat and workbench" width="49%">
&nbsp;
<img src=".github/screenshots/review.png" alt="Review of the changes" width="49%">
</div>
-->

---

Argos lets you work with Claude two ways, both on your own subscription:

- **Workspaces** — interactive, like Claude Code on your machine but in the
  browser and together: chat with Claude while it works, decide its
  approvals, edit in VS Code, click through a live preview, review the diff and
  ship a pull request. See [Workspaces](docs/WORKSPACES.md).
- **Automatic runs** — hands-off: an auto-thread plans, implements, fixes
  failing quality gates and opens a pull request you can review. Start one in
  the UI, through the REST API or MCP, or let new issues trigger it. See
  [Auto-threads](docs/WORKSPACES.md#auto-threads).

> [!IMPORTANT]
> - **Runs on your Claude subscription, not the API.** Argos uses the Claude
>   Code OAuth token from `claude setup-token` — your existing Pro / Max /
>   Team plan covers it. No per-token API billing.
> - **Currently optimised for PHP / Laravel projects.** The quality gates wire
>   up Composer, Pint, Pest/PHPUnit and PHPStan. Other stacks work, but the
>   gates are skipped there.

## Quick Start

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh | bash
```

That installs Argos into the **current directory** — drops a `docker-compose.yml`,
generates a fresh `.env` with random secrets, brings up the stack, and prints
the admin password.

**Installer flags** (append after `bash -s --` or pass as env vars):

| Flag | Env var | Effect |
|---|---|---|
| `--dir PATH` | `ARGOS_INSTALL_DIR` | Install into `PATH` instead of `$PWD` |
| `--version REF` | `ARGOS_VERSION` | Pin a specific Git tag or branch |
| `--stage` | `ARGOS_STAGE=1` | Use rolling `:stage` images from `develop` |
| `--next` | `ARGOS_NEXT=1` | Use rolling `:next` images from the `next` integration branch |
| `--beta` | `ARGOS_BETA=1` | Use the latest release including pre-releases |
| `--reset` | — | Tear down the stack and wipe all data (destructive) |
| `--force` | — | Skip safety prompts (required for `--reset` in non-interactive shells) |
| `--help` | — | Show all options |

Open <http://localhost:8080/admin> — an in-app onboarding wizard walks you
through pasting your Claude token and creating your first project.

To update later, re-run the same command in the same directory: the
installer pulls newer images, merges any new keys from the upstream
`.env.example` into your `.env` without touching existing values, and
restarts the stack. Customisations belong in `docker-compose.override.yml`
next to the compose file — the installer never touches that.

### What this gets you

- ✓ Interactive workspaces with chat, approvals, VS Code, live preview and review
- ✓ Automatic runs → pull requests on GitHub, GitLab, or Bitbucket
- ✓ Authentication via Personal Access Token, or full OAuth (repo/branch
  pickers + per-user account binding) — OAuth apps are managed in the UI
- ✓ Self-hosted GitLab (set the instance URL when you add the account / OAuth app)
- ✓ Optimised for PHP / Laravel projects out of the box
- ✓ Runs on your Claude Pro / Max / Team subscription
- ✓ Drive Argos from Claude Code via the built-in [MCP server](docs/SETUP-MCP.md),
  or programmatically via the **REST API v1** (Sanctum bearer tokens, `/api/v1`)
- ✓ Start automatic runs from GitHub / GitLab / Linear issues via [issue triggers](docs/SETUP-TASK-PROVIDERS.md)
- ✓ Repo-defined worker images — drop a `.argos/worker.dockerfile` to control
  the build environment (BYOI)
- ✓ Live preview per workspace — click through the change in a throwaway
  container before merging

### What this does **not** get you out of the box

- ✗ Repository / branch dropdowns + per-user account binding until you connect
  an OAuth app (Configuration → OAuth Apps)
- ✗ Custom domain / TLS (terminate at your reverse proxy)

For any of those: see **[Extended Setup](docs/SETUP.md)**.

After install, open <http://localhost:8080/admin>: the onboarding wizard takes
you from your Claude token to your first project and workspace.

## Documentation

The full operator documentation is **built into Argos** — open **Help →
Documentation** in the running app (setup, integrations, configuration, and
operations, with deep links from the relevant screens). The same pages live as
Markdown under [`docs/`](docs/) and are readable here on GitHub.

Start with **[Setup](docs/SETUP.md)**. To make a target repository Argos-ready,
see **[Preparing a Project](docs/PREPARE-PROJECT.md)** (covers the
`.argos/worker.dockerfile` build environment and the `.argos/demo.*` live-demo
contract).

## Contributing

Pull requests welcome. See [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for
local setup, the test suite, and code conventions.

## License

Released under the [GNU Affero General Public License v3.0 or later](LICENSE).

For commercial use or alternative licensing terms, contact Nodus IT at
[argos@nodus-it.de](mailto:argos@nodus-it.de).
