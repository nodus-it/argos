<div align="center">

<img src=".github/logo.svg" alt="Argos" width="640">

**A web-first dev agent that turns a task description into a pull request.**

[![License: AGPL-3.0](https://img.shields.io/github/license/nodus-it/argos?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/github/v/tag/nodus-it/argos?style=flat-square&label=version&include_prereleases&sort=semver)](https://github.com/nodus-it/argos/releases)
[![GHCR](https://img.shields.io/badge/ghcr.io-argos--app-2496ED?style=flat-square&logo=docker&logoColor=white)](https://github.com/nodus-it/argos/pkgs/container/argos-app)
[![CI](https://img.shields.io/github/actions/workflow/status/nodus-it/argos/ci.yml?branch=master&style=flat-square&label=tests)](https://github.com/nodus-it/argos/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/codecov/c/github/nodus-it/argos?style=flat-square)](https://codecov.io/gh/nodus-it/argos)

</div>

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
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/install.sh | bash
```

That installs Argos into the **current directory** (set `--dir` or
`ARGOS_INSTALL_DIR` to install elsewhere) — drops a `docker-compose.yml`,
generates a fresh `.env` with random secrets, brings up the stack, and prints
the admin password.

Open <http://localhost:8080/admin> — an in-app onboarding wizard walks you
through pasting your Claude token and creating your first project.

To update later, re-run the same command in the same directory: the
installer pulls newer images, merges any new keys from the upstream
`.env.example` into your `.env` without touching existing values, and
restarts the stack. Customisations belong in `docker-compose.override.yml`
next to the compose file — the installer never touches that.

### What this gets you

- ✓ Tasks → automated pull requests on GitHub, GitLab, or Bitbucket
- ✓ Authentication via Personal Access Token (paste a token per project)
- ✓ Optimised for PHP / Laravel projects out of the box
- ✓ Runs on your Claude Pro / Max / Team subscription

### What this does **not** get you

- ✗ Repository / branch dropdowns when creating a project (needs OAuth)
- ✗ Per-user account binding (needs OAuth)
- ✗ Self-hosted GitLab support (needs `GITLAB_INSTANCE_URL`)
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

## Documentation

| Topic | Where |
|---|---|
| Extended setup (production, custom workers, reverse proxy) | [docs/SETUP.md](docs/SETUP.md) |
| All environment variables with defaults | [docs/CONFIGURATION.md](docs/CONFIGURATION.md) |
| OAuth — when and why | [docs/OAUTH.md](docs/OAUTH.md) |
| GitHub setup (PAT + OAuth) | [docs/SETUP-GITHUB.md](docs/SETUP-GITHUB.md) |
| GitLab setup (incl. self-hosted) | [docs/SETUP-GITLAB.md](docs/SETUP-GITLAB.md) |
| Bitbucket setup | [docs/SETUP-BITBUCKET.md](docs/SETUP-BITBUCKET.md) |
| Local development & tests | [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) |

## Contributing

Pull requests welcome. See [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for
local setup, the test suite, and code conventions.

## License

Released under the [GNU Affero General Public License v3.0 or later](LICENSE).

For commercial use or alternative licensing terms, contact Nodus IT at
[argos@nodus-it.de](mailto:argos@nodus-it.de).
