# Projects

A **Project** in Argos is a configured Git repository — the unit Argos works on.
Before you can hand Argos any work, you create a Project that tells it *which*
repository, *how* to authenticate, *what* environment the code needs to build
and test, and *which* defaults to use for automatic runs. Every workspace you
open belongs to exactly one Project and inherits these settings.

This guide explains every setting on the Project form — what it means, why it
exists, and how to fill it in. It is written for the person creating and
managing a Project, not for the developer preparing the repository itself; for
the repo-side contract (`.argos/` files, the quality gate) see
[PREPARE-PROJECT.md](PREPARE-PROJECT.md).

Projects live under **Configuration → Projects** in the admin panel
(`${APP_URL}/admin`).

- [What a Project is](#what-a-project-is)
- [Creating and editing a Project](#creating-and-editing-a-project)
- [General tab](#general-tab)
  - [Platform](#platform)
  - [Authentication](#authentication)
  - [General settings](#general-settings)
  - [Repository](#repository)
- [Worker tab](#worker-tab)
  - [Worker (stack & agent)](#worker-stack--agent)
  - [Backing services for tests](#backing-services-for-tests)
  - [Live demo](#live-demo)
  - [Environment & secrets](#environment--secrets)
- [Automation tab](#automation-tab)
- [Required vs optional — at a glance](#required-vs-optional--at-a-glance)

## What a Project is

A Project (internally a `RepoProfile`) bundles everything Argos needs to act on
one repository:

- The **repository address** and the **default branch** new work builds upon.
- The **credentials** Argos uses to clone, push, and open pull/merge requests.
- The **worker configuration** — the base image and the agent the workspace
  image is built from.
- The **environment and secrets** injected into workspaces (and the live
  preview) so dependencies install and tests pass.
- Optional **backing services** (MySQL / Redis) that run next to each
  workspace.
- Whether workspaces may start a **live preview**.
- The **automation** defaults for automatic runs (auto-threads) and the person
  they act for.

A Project has many **workspaces**. A new workspace clones the Project's
repository at its default branch (or a branch you pick), runs in an image built
from the Project's worker configuration, and authenticates with the Project's
credentials. See [WORKSPACES.md](WORKSPACES.md).

A Project also has, on its edit page, two related lists: its **issue-trigger
bindings** (see [SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md)) and its
**API tokens** (project-scoped Sanctum tokens, e.g. for CI). The project list
shows how many workspaces each Project has.

## Creating and editing a Project

Open **Configuration → Projects** and choose **New project**, or click an
existing Project row to edit it. There is no separate read-only detail view —
the row opens straight into the edit form, where the related lists also render.

The form has three tabs, **General**, **Worker** and **Automation**. The very first
choice is the **Platform**; until you pick one, the rest of the form stays
locked. Once a platform is chosen, the remaining sections appear, adapted to
that platform and to which accounts you have connected.

## General tab

### Platform

Select the Git platform the repository lives on:

- **GitHub**
- **GitLab** (including self-hosted instances)
- **Bitbucket**

This is the gate for the whole form. The platform determines which
authentication options you get, how the repository picker behaves, and how the
clone URL is built. A short setup hint with a link to the matching platform
guide appears once you choose ([SETUP-GITHUB.md](SETUP-GITHUB.md),
[SETUP-GITLAB.md](SETUP-GITLAB.md), [SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)).

For **self-hosted GitLab**, the instance is honoured via the connected account
(OAuth) or via `GITLAB_INSTANCE_URL` for manual setups — see
[SETUP-GITLAB.md](SETUP-GITLAB.md).

### Authentication

Argos needs a credential to clone the repository and push branches / open
PRs. There are two methods:

- **Personal Access Token (PAT)** — you paste a token directly into the
  Project. The token is stored encrypted. For GitHub a token with the `repo`
  scope is enough; GitLab needs `api` and `write_repository`; Bitbucket uses a
  scoped **Repository Access Token** (paste it directly, no username prefix).
  See the platform guides above and [CREDENTIALS.md](CREDENTIALS.md).
- **OAuth (connected account)** — Argos uses a Git account you connected once,
  centrally, and reuses it across Projects. OAuth tokens are refreshed
  automatically before they expire. See [OAUTH.md](OAUTH.md).

The **Authentication** section, where you pick the method and (for OAuth) the
connected account, only appears when you have a connected account for the
chosen platform. If you have no connected account, there is nothing to choose —
the Project simply uses a PAT, which you enter in the Repository section below.

Choosing OAuth clears any PAT and binds the Project to the selected account;
switching back to PAT clears the account binding. For GitHub and GitLab the
OAuth account label adapts to the platform; Bitbucket has its own OAuth option.

### General settings

These appear once a platform is chosen:

- **Project Name** (required) — the display name in the panel. When you pick a
  repository via the OAuth picker and leave the name empty, Argos pre-fills it
  with the repository's short name.

### Repository

How you point at the repository depends on whether you are on the **OAuth
(connected)** path or the **manual** path:

**Connected path** (platform + OAuth chosen, with a matching account):

- **Repo URL** — a searchable dropdown of repositories the connected account
  can see. Selecting one fills the clone URL for you (and the name, if empty).
- **Default Branch** — a searchable dropdown of that repository's branches;
  Argos pre-selects the repository's API-reported default branch.

**Manual path** (PAT, or any platform without a connected account):

- **Repo URL** (required) — the full clone URL, e.g.
  `https://github.com/owner/repo`. Trailing slashes and `.git` are normalised
  away on save.
- **Token (PAT)** (required) — the access token described under
  [Authentication](#authentication). Stored encrypted; shown masked.
- **Default Branch** (required) — once a valid URL and token are present, Argos
  queries the platform and offers the branch list as a searchable dropdown.

The **default branch** is the branch workspaces on this Project branch from
(unless you pick another base branch) and target with their pull request.

## Worker tab

### Worker (stack & agent)

This section defines the image the Project's workspaces run in.

- **Image source** — where the worker's base image comes from:
  - **Registered stack** — use one of the worker stacks configured in Argos
    (a base image with a PHP version and tools). See
    [WORKER-STACKS.md](WORKER-STACKS.md).
  - **Own Dockerfile in the repo (BYOI)** — Argos reads
    `.argos/worker.dockerfile` from your repository (at the base branch) as the
    base and layers the agent and the workspace tooling on top. Required tools must come
    in via `FROM`/`RUN`; no `COPY` from the repo (the build context is not the
    repo). See [BYOI.md](BYOI.md).
- **Worker Stack** — which registered stack to use (only shown for the
  *Registered stack* source). Leave empty to use the Argos default stack.
- **Agent** — which coding agent the worker image installs. Claude Code is the
  only agent today; leave it empty. See [AGENTS.md](AGENTS.md).

### Backing services for tests

Some test suites need a database or cache. Enable the services your tests
require and Argos boots them **next to each workspace** — on the workspace's
own private network, with credentials injected. Their data lives in volumes of
the workspace, so a stopped and restarted workspace keeps its database; deleting
the workspace removes it:

- **MySQL / MariaDB** — reachable at host `db`, port `3306`.
- **Redis** — reachable at host `redis`, port `6379`.

Projects that use standard Laravel connection names (`DB_HOST`, `DB_DATABASE`,
`REDIS_HOST`, …) need nothing more — Argos injects those automatically. For
non-standard names, wire them up with the placeholders described under
[Environment & secrets](#environment--secrets).

When MySQL is enabled you can override its credentials so they match what your
project hardcodes:

- **MySQL database** (default `argos`)
- **MySQL user** (default `argos`)
- **MySQL password** (default `argos`)

Leave them blank to use the Argos defaults. The host and port are fixed and not
configurable. Redis has no configurable credentials.

These services back both the workspace (including its quality gates) and the
live preview, so both boot from the same definition.

### Live demo

- **Enable live demo** — when on, workspaces of this Project can start a live
  preview on its own subdomain.

This requires your repository to ship the demo contract at the base branch:
`.argos/demo.compose.yml` (the runtime) and `.argos/demo.yml` (settings such as
the entry service, port, and commands). If they are missing, the demo build
fails with a clear message. See [LIVE-DEMOS.md](LIVE-DEMOS.md) and
[PREPARE-PROJECT.md](PREPARE-PROJECT.md).

### Environment & secrets

Project-specific secrets Argos stores **encrypted** and injects into both the
workspace (dependency install, Claude's commands, quality gates) and the live
preview. Two kinds:

**Private Composer registries** — auth-protected Composer sources (Private
Packagist, Satis, Flux, Scramble, …). For each one you add:

- **Host** (required) — e.g. `packages.filamentphp.com`
- **Username** — optional; defaults to `token` when left blank
- **Token** (required) — the registry password/token

Argos assembles these into a single `COMPOSER_AUTH` HTTP-basic blob so
`composer install` reaches the private registries in both the workspace and
the preview.

**Additional environment variables** — arbitrary name/value pairs (extra API
keys, custom database names, etc.). For each:

- **Name** (required) — e.g. `MEILISEARCH_KEY`
- **Value** — stored encrypted

A few important rules:

- A hand-written `COMPOSER_AUTH` variable here **overrides** the one generated
  from the registries above — that is the deliberate escape hatch.
- Argos-owned variables cannot be overridden. Names it sets itself (e.g.
  `REPO_TOKEN`, `APP_KEY`, `APP_URL`, the agent credentials, the workspace
  daemon's wiring, …) are stripped from your list, so a project secret can never
  clobber Argos's own wiring.

**Backing-service placeholders.** When you have enabled MySQL or Redis above,
you can reference their resolved coordinates inside any value here, and Argos
substitutes the real internal host/credentials at run time. The form shows the
exact placeholders available for the services you enabled. They are:

- MySQL: `${mysql.host}`, `${mysql.port}`, `${mysql.database}`,
  `${mysql.username}`, `${mysql.password}`
- Redis: `${redis.host}`, `${redis.port}`

This lets a project with non-standard env names bridge to the sidecars without
hardcoding internal hosts or credentials — for example set
`MY_DB_DSN=mysql://${mysql.username}:${mysql.password}@${mysql.host}/${mysql.database}`.

## Automation tab

Defaults for [auto-threads](WORKSPACES.md#auto-threads) — automatic runs
started in the UI, through the [REST API](REST-API.md), [MCP](SETUP-MCP.md) or
an [issue trigger](SETUP-TASK-PROVIDERS.md). A single run can override them;
empty fields use the installation defaults from
[Configuration](CONFIGURATION.md#auto-threads).

- **Automation user** — the person automatic runs of this Project's API tokens
  and issue triggers act for: their Claude agent does the work and they own the
  workspace. Without one, issue triggers start nothing (the binding shows the
  error).
- **Plan** — *off* (implement right away), *automatic* (plan, then continue) or
  *wait for approval* (plan, then stop until someone approves).
- **When done** — open a pull request, a draft pull request, or only commit and
  push.
- **Gate fix rounds** — how often Claude may try to fix failing quality gates
  before shipping anyway.
- **Max. turns**, **Max. cost (USD)**, **Max. runtime (minutes)** — budgets
  that stop a run. A cost of `0` means no limit.

## Required vs optional — at a glance

**Required**

- Platform
- Project Name
- Repository: either the OAuth repository + branch, or (manual path) Repo URL +
  Token (PAT) + Default Branch
- Authentication method when the Authentication section is shown (defaults to
  PAT); the connected account when the method is OAuth; a private Composer
  registry's Host and Token when you add one

**Optional (with sensible defaults)**

- Image source (default Registered stack), Worker Stack (default Argos stack),
  Agent (default Claude Code)
- Backing services and their MySQL credential overrides
- Live demo (default off)
- Additional environment variables and Composer registries
- Automation user and the automation defaults (default to the installation
  settings)
