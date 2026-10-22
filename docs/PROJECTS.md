# Workspace Settings

A workspace is the project in Argos: the repository plus everything it needs
— access, default branch, stack, services, secrets, preview, limits,
automation, integrations and members. This page describes every setting. For
what a workspace is and who may do what, see [Workspaces](WORKSPACES.md); for
the repository side (`.argos/`, the setup task) see
[Preparing a Repository](PREPARE-PROJECT.md).

**Where:** workspace admins (and Argos administrators) open **Workspace
settings** from the workspace header, at `/{workspace}/settings`. Each
tab has its own **Save** button. Argos administrators find the same form in
the admin panel under **Workspaces** (with the issue trackers and API tokens
below the form). New workspaces are created by Argos administrators only — see
[Creating a workspace](WORKSPACES.md#creating-a-workspace).

- [Creating: platform, access, repository](#creating-platform-access-repository)
- [General](#general)
- [Environment](#environment)
  - [Worker: image source and stack](#worker-image-source-and-stack)
  - [Backing services](#backing-services)
  - [Live demo](#live-demo)
  - [Environment variables and placeholders](#environment-variables-and-placeholders)
  - [Setup](#setup)
- [Resources](#resources)
- [Integrations](#integrations)
- [Automation](#automation)
- [Members](#members)

## Creating: platform, access, repository

These fields are set when the workspace is created. Platform and repository
are fixed afterwards (changing them would orphan every task branch and pull
request) — on the `/w` settings page and in the admin panel's edit form. The
admin panel's edit form can still renew the access, e.g. replace an expired
token.

- **Platform** — GitHub, GitLab (including self-hosted) or Bitbucket. Until you
  pick one, the rest of the form stays locked. A hint links the platform guide
  ([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
  [Bitbucket](SETUP-BITBUCKET.md)).
- **Authentication** — shown only when you have a connected account for the
  platform:
  - **Personal Access Token (PAT)** — pasted into the workspace, stored
    encrypted. GitHub: `repo` scope; GitLab: `api` and `write_repository`;
    Bitbucket: a Repository Access Token. See [Access Tokens](CREDENTIALS.md).
  - **OAuth (connected account)** — reuses a Git account you connected once;
    tokens are refreshed automatically. See [OAuth](OAUTH.md).
- **Repository** — with OAuth a searchable list of the account's repositories
  and their branches (the default branch is preselected); otherwise the clone
  URL (e.g. `https://github.com/owner/repo`, trailing `/` and `.git` are
  removed) plus the token, after which the branch list loads.
- **Name** — prefilled with the repository's short name when you pick one.

This access is what Argos uses to clone, push task branches and open pull
requests for **every** task of the workspace.

## General

| Field | Meaning |
|---|---|
| Name | Display name of the workspace. |
| Repository | Address and platform, read-only. |
| Default branch | New tasks branch off it and their pull requests target it (unless a task picks another base). |
| Branch pattern | How task branches are named. Placeholders: `{ticket}` (the ticket key, or `argos` without a ticket) and `{title}` (the task title as a slug). Empty: `{ticket}-{title}` with a ticket, `argos/{title}` without. Allowed characters: letters, digits, `. _ / - { }`; max. 60. A taken name gets a number. |

## Environment

### Worker: image source and stack

- **Image source**
  - **Registered stack** — one of the built-in stacks.
  - **Own Dockerfile in the repo (BYOI)** — Argos builds
    `.argos/worker.dockerfile` from the repository (at the setup ref: the
    default branch, or a tested, unmerged setup branch). See
    [Custom Worker Image](BYOI.md).
- **Worker Stack** — *PHP 8.5*, *PHP 8.4* or *Node 22* (hidden for BYOI).
  Empty: the installation default (`ARGOS_DEFAULT_STACK`, `php-8.5`). Argos
  presets it when the workspace is created, from `composer.json` /
  `package.json`. See [Stacks and Images](WORKER-STACKS.md).

A passed setup test that found a `.argos/worker.dockerfile` switches the image
source to BYOI by itself.

### Backing services

*Backing services for tests* boots sidecars next to the workspace container,
on a private network, with data kept until the workspace is deleted:

| Value | Service | Reachable at |
|---|---|---|
| `mysql` | MySQL / MariaDB (`mariadb:11`) | host `db`, port `3306` |
| `redis` | Redis (`redis:7-alpine`) | host `redis`, port `6379` |

Every task gets its own MySQL database (`task_<slug>`) and its own Redis
prefix; Argos writes them into the task's `.env` and environment
(`DB_HOST`, `DB_DATABASE`, …, `REDIS_HOST`, `REDIS_PREFIX`, `CACHE_PREFIX`).
Projects using the standard Laravel names need nothing more.

With MySQL enabled you can set **MySQL database**, **MySQL user** and **MySQL
password** (default `argos` each) — the credentials the sidecar is created
with; they also seed the bundled preview database. Host and port are fixed.
Redis has no credentials.

### Live demo

**Enable live demo** lets tasks of this workspace start a
[preview](LIVE-DEMOS.md) on their own subdomain. It uses the repository's
`.argos/demo.compose.yml` + `.argos/demo.yml`, or the bundled Laravel contract
when the repository has neither. See
[Preparing a Repository → Live preview](PREPARE-PROJECT.md#live-preview).

### Environment variables and placeholders

*Environment & secrets* — stored **encrypted**, injected into the workspace
container (setup steps, Claude's commands, gates) and into previews:

- **Private Composer registries** — **Host** (required, e.g.
  `packages.filamentphp.com`), **Username** (default `token`), **Token**
  (required). Argos builds one `COMPOSER_AUTH` HTTP-basic blob from them.
- **Additional environment variables** — **Name** (required) and **Value**.

Rules:

- A hand-written `COMPOSER_AUTH` **overrides** the generated one.
- Argos-owned variables (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, the agent
  credentials, the daemon wiring, …) are stripped — a secret never overrides
  Argos' own wiring.
- With backing services enabled, values may use placeholders that Argos
  resolves at run time (the form lists the available ones):
  - MySQL: `${mysql.host}`, `${mysql.port}`, `${mysql.database}`,
    `${mysql.username}`, `${mysql.password}`
  - Redis: `${redis.host}`, `${redis.port}`

  Example: `MY_DB_DSN=mysql://${mysql.username}:${mysql.password}@${mysql.host}/${mysql.database}`.

### Setup

Only on the settings page, read-only plus actions:

- **Setup status** (*Needs setting up*, *Being set up*, *Being tested*, *Set
  up*, *Test failed*, *Needs a Claude subscription*) and, while a tested setup
  is not merged, the branch it comes from.
- **Last test** — each step of the last **Test setup** with ✓ / ✗.
- **From the repository** — whether the preview contract (`.argos/demo.yml`)
  and the setup steps (`.argos/setup.sh`) are *in the repository*, come from
  the *Laravel defaults*, are *missing*, or *cannot be read*.
- **Image** — state and age of the current image build, its **Build log**
  and **Rebuild** (the workspace uses the new image from its next start).
- **Revise setup** / **Open the setup task** — see
  [The setup task](PREPARE-PROJECT.md#the-setup-task).

## Resources

| Field | Default | Meaning |
|---|---|---|
| Parallel sessions | `3` | How many tasks may talk to Claude at the same time (1–20). Further messages wait in the [session queue](TASKS.md#session-queue). |
| Parallel previews | `3` | Running previews at once (1–20); starting one more stops the oldest. |
| CPU limit (cores) | `ARGOS_WORKSPACE_CPUS` | For the workspace container, 0.25–64. |
| Memory limit (MB) | `ARGOS_WORKSPACE_MEMORY` | For the workspace container, 512–262144. |
| Clean up finished tasks after (days) | `14` | Preview, worktree and database of a done or cancelled task are removed after this many days (1–365); conversation, remote branch and pull request stay. |

CPU and memory apply from the next container start.

## Integrations

- **Issue trackers** — GitHub, GitLab and Linear projects this workspace
  watches, and own ticket systems. See [Issue Triggers](SETUP-TASK-PROVIDERS.md)
  and [Tickets](TICKETS.md).
- **API tokens** — Sanctum tokens bound to this workspace (e.g. for CI or an
  own ticket system). They reach only this workspace, act as its automation
  user and can carry `workspaces:read`, `tasks:read` and `tasks:write` (never
  `workspaces:write`). The token is shown once. See [REST API](REST-API.md).

## Automation

Defaults for [automatic tasks](TASKS.md#automatic-tasks) started in the UI,
through the [REST API](REST-API.md), [MCP](SETUP-MCP.md) or an
[issue trigger](SETUP-TASK-PROVIDERS.md). A single run can override them;
empty fields use the installation defaults from
[Configuration](CONFIGURATION.md#automatic-tasks).

| Field | Meaning |
|---|---|
| Automation user | The person automatic tasks of this workspace's API tokens and issue triggers act for (unless an issue's assignee agreed to own it): their Claude agent does the work and they own the task. Without one, workspace tokens answer `no_actor` and issue triggers start nothing. |
| Plan | *No plan — implement right away* (`off`), *Plan, then continue automatically* (`auto`), *Plan, then wait for approval* (`approve`). |
| When done | *Pull request* (`pr`), *Draft pull request* (`draft_pr`), *Commit and push only* (`none`). |
| Gate fix rounds | How often Claude may try to fix failing quality gates before shipping anyway (0–20). |
| Max. turns, Max. cost (USD), Max. runtime (minutes) | Budgets that stop a run. A cost of `0` means no limit. |

## Members

The people of the workspace with their role — *Admin*, *Member* or *Viewer*
(see [Roles](WORKSPACES.md#roles)): add a member, change a role, remove a
person. A workspace keeps at least one admin. Adding people needs
`ARGOS_WORKSPACE_COLLABORATION`. Removing someone keeps the tasks they own;
hand them over if someone should continue.
