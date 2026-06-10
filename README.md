<div align="center">

<img src=".github/logo.svg" alt="Argos" width="640">

### Every agent. One view.

**From a task description to a reviewed pull request.** Argos drafts a concept,
implements it in an isolated worker container, and opens the PR — all on your
Claude subscription, not the API.

[![License: AGPL-3.0](https://img.shields.io/github/license/nodus-it/argos?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/github/v/tag/nodus-it/argos?style=flat-square&label=version&include_prereleases&sort=semver)](https://github.com/nodus-it/argos/releases)
[![GHCR](https://img.shields.io/badge/ghcr.io-argos--app-2496ED?style=flat-square&logo=docker&logoColor=white)](https://github.com/nodus-it/argos/pkgs/container/argos-app)
[![CI](https://img.shields.io/github/actions/workflow/status/nodus-it/argos/ci.yml?branch=develop&style=flat-square&label=tests)](https://github.com/nodus-it/argos/actions/workflows/ci.yml)

<br>

<img src=".github/screenshots/login.png" alt="Argos — sign in to your control room" width="900">

</div>

<!--
  Screenshot gallery — drop PNGs into .github/screenshots/ and uncomment.
  Suggested shots: dashboard (control room), task-view (phase stepper),
  task-logs-running (live agent stream), task-diff (review).

<div align="center">
<img src=".github/screenshots/dashboard.png" alt="Control room dashboard" width="49%">
&nbsp;
<img src=".github/screenshots/task-view.png" alt="Task phases — concept to PR" width="49%">
</div>
-->

---

Argos accepts a task description, runs it through isolated worker containers
in phases (`concept` → `implement` → `diff` → `push`), and opens a pull
request you can review.

> [!IMPORTANT]
> - **Runs on your Claude subscription, not the API.** Argos uses the Claude
>   Code OAuth token from `claude setup-token` — your existing Pro / Max /
>   Team plan covers it. No per-token API billing.
> - **Currently optimised for PHP / Laravel projects.** The implement phase
>   wires up Composer, npm, Pint, and Pest/PHPUnit as quality gates. Other
>   stacks work, but the gates and prompts are tuned for Laravel today.

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

- ✓ Tasks → automated pull requests on GitHub, GitLab, or Bitbucket
- ✓ Authentication via Personal Access Token, or full OAuth (repo/branch
  pickers + per-user account binding) — OAuth apps are managed in the UI
- ✓ Self-hosted GitLab (set the instance URL when you add the account / OAuth app)
- ✓ Optimised for PHP / Laravel projects out of the box
- ✓ Runs on your Claude Pro / Max / Team subscription
- ✓ Drive Argos from Claude Code via the built-in [MCP server](docs/SETUP-MCP.md),
  or programmatically via the **REST API v1** (Sanctum bearer tokens, `/api/v1`)
- ✓ Import issues from GitHub / GitLab / Linear via [task providers](docs/SETUP-TASK-PROVIDERS.md)
- ✓ Repo-defined worker images — drop a `.argos/worker.dockerfile` to control
  the build environment (BYOI)
- ✓ Ephemeral per-task live demo — preview the implemented branch in a
  throwaway container before merging

### What this does **not** get you out of the box

- ✗ Repository / branch dropdowns + per-user account binding until you connect
  an OAuth app (Configuration → OAuth Apps)
- ✗ Custom domain / TLS (terminate at your reverse proxy)

For any of those: see **[Extended Setup](docs/SETUP.md)**.

## Usage

Once the container is up:

1. **Sign in** — the first visit auto-creates an admin user. Set a real
   password under *Profile* before exposing the instance.
2. **Onboarding** — paste your Claude token (`claude setup-token`).
3. **Create a project** — pick a Git host, paste a PAT, set the default
   branch.
4. **Create a task** — describe what you want done. Argos drafts a concept,
   implements it, opens a pull request.

## Prepare a project for Argos

Most PHP / Laravel repos work out of the box. To check a specific project — and
wire up a custom build environment or live-demo when the defaults don't fit —
point your coding agent at the guide and let it do the work. Paste this into an
agent running **inside the target repository**:

> Prepare this repository for Argos, following
> `https://github.com/nodus-it/argos/blob/master/docs/PREPARE-PROJECT.md`.
> Decide whether it runs on Argos's defaults as-is; if not, show me the two
> options (ship a `.argos/` contract vs. adjust the project) before changing
> anything.

The guide ([docs/PREPARE-PROJECT.md](docs/PREPARE-PROJECT.md)) is written for an
AI agent and covers both the worker execution environment
(`.argos/worker.dockerfile`) and the live-demo contract (`.argos/demo.*`).

## Documentation

| Topic | Where |
|---|---|
| Prepare a repo for Argos (agent guide) | [docs/PREPARE-PROJECT.md](docs/PREPARE-PROJECT.md) |
| Extended setup (production, custom workers, reverse proxy) | [docs/SETUP.md](docs/SETUP.md) |
| All environment variables with defaults | [docs/CONFIGURATION.md](docs/CONFIGURATION.md) |
| What Argos runs (worker & demo commands) | [docs/EXECUTION-COMMANDS.md](docs/EXECUTION-COMMANDS.md) |
| OAuth — when and why | [docs/OAUTH.md](docs/OAUTH.md) |
| GitHub setup (PAT + OAuth) | [docs/SETUP-GITHUB.md](docs/SETUP-GITHUB.md) |
| GitLab setup (incl. self-hosted) | [docs/SETUP-GITLAB.md](docs/SETUP-GITLAB.md) |
| Bitbucket setup | [docs/SETUP-BITBUCKET.md](docs/SETUP-BITBUCKET.md) |
| Task-Provider / Issue-Tracker integration | [docs/SETUP-TASK-PROVIDERS.md](docs/SETUP-TASK-PROVIDERS.md) |
| MCP server (drive Argos from Claude Code) | [docs/SETUP-MCP.md](docs/SETUP-MCP.md) |
| Media library — file / image uploads (optional) | [docs/SETUP-MEDIA-LIBRARY.md](docs/SETUP-MEDIA-LIBRARY.md) |
| Provider contract tests (local, real APIs) | [docs/PROVIDER-TEST-SETUP.md](docs/PROVIDER-TEST-SETUP.md) |
| Local development & tests | [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) |

## Contributing

Pull requests welcome. See [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for
local setup, the test suite, and code conventions.

## License

Released under the [GNU Affero General Public License v3.0 or later](LICENSE).

For commercial use or alternative licensing terms, contact Nodus IT at
[argos@nodus-it.de](mailto:argos@nodus-it.de).
