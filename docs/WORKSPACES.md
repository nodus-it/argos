# Workspaces

A **workspace** is one project in Argos: one repository with everything it
needs to run — default branch, branch pattern, stack, backing services
(MySQL / Redis), environment secrets, Composer registries, live preview,
limits, automation defaults, issue-tracker integrations, API tokens and
members. It runs in **one container** that holds the repository, the editor
and the Claude sessions of all its [tasks](TASKS.md).

Every conversation with Claude in a workspace is a **task** with its own
owner, branch, working copy, database, preview and pull request — see
[Tasks](TASKS.md). This page covers the workspace itself: who may do what, how
a workspace is created, the app's layout and addresses, the container and its limits.

## Contents

- [What you need](#what-you-need)
- [The app and its addresses](#the-app-and-its-addresses)
- [Roles](#roles)
- [Creating a workspace](#creating-a-workspace)
- [Settings](#settings)
- [The workspace page](#the-workspace-page)
- [The container](#the-container)
- [Deleting a workspace](#deleting-a-workspace)
- [Security](#security)
- [Configuration](#configuration)

## What you need

- The regular Argos stack (see [Setup](SETUP.md)), including the `reverb`
  service — it carries the live updates to the browser.
- **Wildcard DNS** for the preview base domain: the editor of a workspace is
  served at `code-<workspace>.<base-domain>`, a task's preview at
  `demo-<workspace>-<task>.<base-domain>` (cut to 63 characters). Locally the
  default `*.127.0.0.1.nip.io` needs a resolver that can reach nip.io.
- An **agent** of your own (your Claude subscription) or a shared one — see
  [Agents](AGENTS.md). Without one you can read tasks but not start any.

## The app and its addresses

The app is full width, with a slim **rail** on the left instead of a top bar.
Every workspace and task has a readable address made from its name:

| Address | Shows |
|---|---|
| `/` | My work |
| `/workspaces` | All workspaces |
| `/new` | Create a workspace |
| `/shop-api` | The workspace "Shop API" (its latest task) |
| `/shop-api/fix-login` | Its task "Fix login" |
| `/shop-api/settings` | Its settings |

A workspace's address comes from its name when it is created; a name already
taken gets a number (`shop-api-2`). Names the app uses itself (`docs`,
`account`, `system`, `api`, `login`, …) get one too. Links from before
(`/w/workspaces/…?task=…`, `/admin/…`) still lead to the right place.

The rail:

- **My work** (`/`, the start page) — your own tasks and the ones you
  were invited to, across all workspaces: *Waiting for you* first, then
  *Running*, *PR open* and *Ready* (the most recently active ready tasks; the
  rest are counted). Each row shows the workspace, owner, ticket and pull
  request.
- **Workspaces** as short names, each with a badge counting the tasks that
  wait for you there; *All workspaces* opens the list.
- **Administration** (`/system`, the gear icon) — only for Argos
  administrators: overview, users, access tokens, OAuth apps, API clients.
- The notification bell and your **avatar** with the user menu: **My account**
  (`/account` — profile with name, avatar, language, notifications, consent to
  automatic tasks and password; your Claude subscriptions; connected
  accounts), **Documentation** (`/docs`), **Administration** (administrators),
  light/dark theme, **Feedback**, source code and version, sign out.

One login (`/login`) covers the whole app. On phones the rail moves to the
bottom.

## Roles

Every workspace has **members**, each with one role:

| Role | Can |
|---|---|
| Admin (`admin`) | everything in the workspace: settings, secrets, integrations, members, the setup task, and every task |
| Member (`member`) | start tasks, open VS Code, start/stop the container |
| Viewer (`viewer`) | read every task; work in tasks they are invited to |

On top of the role, each task has its own people:

- The **owner** — the task runs on their Claude subscription. Only the owner
  (and workspace admins) may invite collaborators, hand the task over, cancel,
  archive or delete it.
- **Collaborators** — workspace members the owner invited. They write into the
  task, decide approvals, send review comments and ship. A viewer who is
  invited becomes a collaborator of that one task (but still gets no editor).

Everybody in a workspace sees all of its tasks, read-only unless they own or
collaborate on them. **Argos administrators** may do everything in every
workspace. Each action checks a named capability (`task.write`,
`task.approve`, `task.ship`, `task.review`, `task.invite`, `task.transfer`,
`task.archive`, `workspace.manage`, `workspace.setup`, `workspace.editor`, …);
today the roles map to fixed sets of them.

Members are managed by workspace admins under **Settings → Members** (add,
change role, remove; a workspace keeps at least one admin). Adding people
besides the creator needs `ARGOS_WORKSPACE_COLLABORATION` (on by default).
Removing a person keeps the tasks they own — hand them over if someone should
continue.

## Creating a workspace

Only **Argos administrators** create workspaces:

- with **New workspace** on the workspace list (`/new`),
- from the setup wizard (`/setup`): its repository step picks access and
  repository, then hands over to the guided creation at `/new`, or
- through the REST API: `POST /api/v1/workspaces` with a `workspaces:write`
  token of an API client that runs as an administrator (see
  [REST API](REST-API.md#create-a-workspace)).

`/new` guides you in five steps: **Repository** (platform, access, repository,
default branch, name); **What Argos detected** (project, stack, database and
Redis, quality gates and where setup steps and live demo come from — read from
the repository before anything is saved, each with a reason, changeable);
**Live demo** (on or off, and which environment values are still missing);
**Optional** (limits and automation, folded; team and tracker follow from the
setup checklist); **Start** (a summary, then "Create and set up" starts the
setup task). **Set up everything yourself** switches to the full form at
`/new/expert` and takes your entries along — see [Workspace Settings](WORKSPACE-SETTINGS.md).
The creator becomes the workspace's first admin; add further members in the
settings afterwards.

Right after creation Argos **inspects the repository** (at its default branch):

| Found | Result |
|---|---|
| `composer.json` | Stack PHP 8.5 — or PHP 8.4 when the PHP constraint pins 8.4 without allowing 8.5 |
| `package.json` without `composer.json` | Stack Node 22 |
| `.argos/worker.dockerfile` | The repository's own image |
| `composer.json` + `artisan` (Laravel) | Setup steps and preview contract fall back to the Laravel defaults — with an own image the preview contract counts as missing, since the default preview runs on Argos' PHP images |

When nothing is missing, the setup status becomes *Set up* and the workspace
is ready. When the stack, the preview contract (`.argos/demo.yml`) or the
setup steps (`.argos/setup.sh`) are missing, Argos starts a **setup task** on
the creating admin's subscription, in which Claude writes them — see
[Preparing a Repository → The setup task](PREPARE-PROJECT.md#the-setup-task).
Without a usable Claude subscription the status is *Needs a Claude
subscription*; add one and choose **Revise setup** in the settings.

A Laravel repository without `.argos/setup.sh` or `.argos/demo.yml` starts no
setup task — the defaults cover it. The creating admin gets a notification in
the bell instead: when the project needs more than the defaults (another
database, Redis, built assets, keys), **Revise setup** lets Claude write it.

## Settings

Workspace admins (and Argos administrators) open **Workspace settings** from
the workspace header or at `/{workspace}/settings`. The tabs:

| Tab | What it holds |
|---|---|
| General | Name, repository (fixed), default branch, branch pattern |
| Environment | Image source and stack, backing services, live preview, Composer registries, environment variables; the setup status, last test, image and build log, **Rebuild** and **Revise setup** |
| Resources | Parallel sessions, parallel previews, CPU and memory limit, cleanup of finished tasks |
| Integrations | Issue trackers (incl. own systems) and API tokens |
| Automation | Automation user, plan mode, ship mode, gate fix rounds, budgets |
| Members | People and their roles |

Every field is described in [Workspace Settings](WORKSPACE-SETTINGS.md).

## The workspace page

A workspace page has three parts:

- A **header** with the workspace, the [task switcher](TASKS.md#the-task-switcher)
  (**⌘J** / **Ctrl+J**; the **+** next to it starts a new task), a chip when another task waits for approval, the
  current task's owner and collaborators (with presence, **+** invites), the
  ticket and the **⋯** menu (hand over, link or create a ticket,
  follow-up task, rebase, cancel, delete, settings, keyboard shortcuts,
  start/stop). **Commit** and **Push & PR** sit in the action bar above the text field.
- The **chat** of the current task as a centred column.
- The task's **sidebar**: VS Code (the task's folder), preview, branch, pull
  request, quality gates and changed files — see
  [Tasks → The sidebar](TASKS.md#the-sidebar-editor-preview-branch).

The page fills the window; only the conversation scrolls. On screens narrower
than about 1100px the sidebar becomes a drawer.

## The container

Each workspace runs in one container with:

- the **main clone** at `/workspace/repo` (detached on the default branch,
  only a base — nobody works in it),
- one **git worktree per task** at `/workspace/tasks/<slug>`,
- shared Composer and npm caches,
- the **backing services** enabled in the settings as sidecars on a private
  network: one MySQL database **per task** (`task_<slug>`) and one Redis key
  prefix per task (`<slug>_`),
- OpenVSCode Server and the workspace daemon.

| State | Meaning |
|---|---|
| Provisioning / Starting | The container is being set up (the first start builds the image and can take a few minutes). |
| Running | Ready to work. |
| Stopping / Stopped | The container is stopped; files, databases and tasks are kept. Opening the workspace or starting a task starts it again. |
| Failed | Start failed — the page shows the reason; **Start** retries. |

- **Starting:** opening the workspace page (as an admin or member),
  preparing a task or an automatic task starts a stopped container.
- **Idle stop:** a running workspace without activity for
  `ARGOS_WORKSPACE_IDLE_MINUTES` is stopped — but never while a task is being
  prepared, waits in the session queue, works, waits for a decision, or runs
  automatically. Messages, decisions, Claude's work, the editor and the
  preview count as activity, and so does an open workspace page in a visible
  browser tab. A stopped workspace closes its sessions; the next message
  resumes the session on its own.
- **Capacity:** at most `ARGOS_WORKSPACE_MAX_ACTIVE` workspaces run at once.
  Starting beyond that is refused; running workspaces are never evicted.
- **Limits per workspace** (Settings → Resources): *Parallel sessions*
  (default 3) — how many tasks talk to Claude at once; further messages wait in
  the [session queue](TASKS.md#session-queue). *Parallel previews* (default 3)
  — starting one more stops the oldest. CPU and memory apply from the next
  container start (empty: `ARGOS_WORKSPACE_CPUS` / `ARGOS_WORKSPACE_MEMORY`).
- **Restarts:** sessions live in the container. When it (or its daemon)
  restarts, Argos closes the sessions within about a minute: a running turn
  ends with a notice, open decisions lapse, and messages Claude had not read
  yet are marked *Not delivered* with **Send again**.
- **Unpushed work:** when a task's working copy is removed (cleanup of
  finished tasks, deleting a task), commits and uncommitted changes that are
  on no remote branch are kept first under `refs/argos/backup/<slug>` in the
  main clone; the task's history says so. Check it out in the editor's
  terminal to continue (`git switch -c <branch> refs/argos/backup/<slug>`).

## Deleting a workspace

Only Argos administrators delete a workspace (**⋯ → Delete workspace**). It
removes all tasks, their worktrees, databases and previews, the container and
its volumes. Remote branches and pull requests stay; commits that were never
pushed are lost.

## Security

- The workspace daemon is reachable only on the internal `argos_ws` network and
  only with the workspace's own token. The database and Redis of Argos are not
  on that network.
- The Claude token of a task's owner is sent per session and kept in memory;
  it never lands on disk or in the container's environment. Claude itself can
  technically read it from inside the container, so treat a workspace like a
  machine you run Claude Code on.
- The repository token reaches git through a credential helper and is never
  written to the repository. Claude is told not to push, but it technically can.
- **Tasks are not isolated from each other.** Claude's file tools work freely
  only inside the task's worktree (and `/tmp`); outside it they ask for
  approval, and in automatic tasks they are refused. Shell commands are not
  checked. All tasks share one container, so a workspace is a space of trust,
  not a security boundary.
- The editor is effectively a shell into the container: only admins and
  members get in (also checked on every request through a short-lived ticket
  traded for a cookie on the editor host); viewers read tasks and open
  previews only.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_WORKSPACE_COLLABORATION` | `true` | Whether workspaces may have members besides the creator, and tasks collaborators. |
| `ARGOS_WORKSPACE_MAX_ACTIVE` | `5` | Workspaces running at the same time. |
| `ARGOS_WORKSPACE_IDLE_MINUTES` | `240` | Idle time before a workspace is stopped (`0` disables). |
| `ARGOS_WORKSPACE_CPUS` / `ARGOS_WORKSPACE_MEMORY` | `2` / `4g` | Default limits per workspace container. |
| `ARGOS_WORKSPACE_PR_SYNC_MINUTES` | `3` | How often open pull requests of tasks are re-read. |
| `ARGOS_WORKSPACE_UPLOAD_MAX_MB` | `5` | Size limit per file attached to a message. |

All settings are listed in [Configuration](CONFIGURATION.md#workspaces).
