# How Argos Works

This page is the big picture: what Argos is, the few concepts you need in
your head, and how a piece of work flows from a sentence you type to a pull
request you can review. Read it once, then follow the links into the detail
docs when you need them.

## What is Argos

Argos is **Claude Code in the browser, for a team**. A **workspace** is one
project — one repository with its stack, services, secrets and preview —
running in one container. Every conversation with Claude in it is a **task**:
it belongs to one person, has its own branch, working copy and database, and
ends in its own pull request. You chat with Claude while it works, approve its
changes, edit files yourself in VS Code, click through the result in a live
preview and ship the pull request. Colleagues can read along or be invited to
work in the same task.

Work does not have to be hands-on: an **automatic task** plans, implements,
checks the project's quality gates, fixes what fails and opens the pull request
on its own — started from the UI, the REST API, MCP, or an issue in your
tracker. You stay in control: you can watch, approve the plan or take over at
any time, and you review the pull request before it is merged.

Two things make Argos different from calling an API in a loop:

- **It runs on your Claude subscription, not the API.** Argos uses the Claude
  Code OAuth token from `claude setup-token`, so your existing Pro / Max /
  Team plan covers the work — there is no per-token API billing. A task always
  runs on the subscription of the person who owns it.
- **Every workspace runs in its own container.** The agent never touches the
  manager host or other workspaces; it gets the repository, the workspace's
  backing services and its preview, and nothing else.

> Argos is tuned for PHP / Laravel projects — the quality gates wire up
> Composer, Pint, Pest/PHPUnit and PHPStan, and Laravel repositories need no
> setup at all. Other stacks work; the gates are skipped there, and the
> [setup task](PREPARE-PROJECT.md#the-setup-task) helps get the repository
> running.

## The mental model: Manager and workspaces

### The Manager — the app you use

The Manager is the web application you log into: your work lives at
`${APP_URL}/w`, administration (users, agents, OAuth apps, credentials, API
clients, settings, docs) at `${APP_URL}/admin`. The Manager holds all your data
— tasks, decisions, settings — and decides *what* may happen. **It never runs
the AI itself**; it has no Claude session of its own.

### The workspace container — where the work happens

Each workspace is a **Docker container** the Manager starts for it. It holds
a main clone of the repository and one git worktree per task under
`/workspace/tasks/<slug>`, the backing services (MySQL / Redis) next to it,
OpenVSCode Server for the editor, and a small **daemon** that runs the Claude
sessions and relays their events to the Manager. A workspace stops when idle
and starts again when you open it or a task needs it; files and databases are
kept.

The container is sandboxed: it gets the repository, a repository credential
through a git helper, and nothing else. It has **no Docker socket** and no
access to the Manager's database. Its image comes from a built-in
[stack](WORKER-STACKS.md) (PHP 8.5, PHP 8.4 or Node 22) or from the
repository's own `.argos/worker.dockerfile`.

Tasks of one workspace share the container. They are kept apart by
guard rails (own worktree, own database, file tools limited to the task's
folder), **not** by a security boundary: the members of a workspace trust each
other, as they would in a shared repository.

## The core flow at a glance

1. **Connect credentials.** Add your Claude agent (the token from
   `claude setup-token`) and link a Git account or a Personal Access Token.
   See [Agents](AGENTS.md), [Access Tokens](CREDENTIALS.md) and
   [OAuth](OAUTH.md).
2. **Create a workspace** (Argos administrators). Pick the repository; Argos
   inspects it, picks a stack and — if something is missing — starts a
   [setup task](PREPARE-PROJECT.md#the-setup-task) that writes `.argos/`. Add
   the people who work in it. See [Workspaces](WORKSPACES.md) and
   [Workspace Settings](PROJECTS.md).
3. **Start a task.** Describe what you want, optionally pick a
   [ticket](TICKETS.md). Argos creates the task's branch, worktree and database
   and runs the setup steps; then Claude starts. See [Tasks](TASKS.md).
4. **Work.** Claude reads, edits and runs commands, asking for approval as the
   task's mode requires. Check the [quality gates](TASKS.md#quality-gates),
   the [review](TASKS.md#the-review) and the [preview](LIVE-DEMOS.md).
5. **Ship.** Claude commits finished parts itself; **Commit** and **Push & PR**
   commit what is left, push the branch and open the pull request; the
   sidebar then follows its [checks, reviews and conflicts](PULL-REQUESTS.md).
   Merged → the task is done; closed → cancelled.

Or let it run on its own: an [automatic task](TASKS.md#automatic-tasks) does
steps 4 and 5 by itself —

```
Plan  →  Implement  →  Quality gates ⇄ Fix  →  Pull request
```

— and an [issue trigger](SETUP-TASK-PROVIDERS.md) starts such a task for every
new matching issue in GitHub, GitLab or Linear.

## Glossary

- **[Workspace](WORKSPACES.md)** — one project: repository, default branch,
  stack, services, secrets, preview, limits, automation, integrations and
  members (admin / member / viewer). One container.
- **[Task](TASKS.md)** — one conversation with Claude inside a workspace,
  owned by one person, with its own branch, worktree, database, preview and
  pull request. Collaborators can be invited.
- **[Automatic task](TASKS.md#automatic-tasks)** — a task that runs on its
  own: plan, implement, fix failing gates, ship.
- **[Setup task](PREPARE-PROJECT.md#the-setup-task)** — a special task in
  which Claude writes the workspace's `.argos/` files (setup steps, preview
  contract, optionally an image); **Test setup** checks them.
- **[Ticket](TICKETS.md)** — an issue in GitHub, GitLab, Linear or your own
  system linked to a task. Argos reports back to it and closes it on merge
  where the integration says so.
- **[Pull request](PULL-REQUESTS.md)** — the task's pull/merge request, with
  checks, reviews, conflicts and auto-merge shown in the task.
- **[Stack](WORKER-STACKS.md)** — the image a workspace runs in: a built-in
  stack or the repository's `.argos/worker.dockerfile`.
- **[Agent](AGENTS.md)** — a Claude Code credential (subscription) a task runs
  on.
- **[Credential](CREDENTIALS.md)** — a stored secret: your Claude token, and a
  Git token — a **Personal Access Token (PAT)** or an **OAuth** account binding
  (which also unlocks repository/branch pickers). See [OAuth](OAUTH.md).
- **[Issue trigger](SETUP-TASK-PROVIDERS.md)** — a connection to an issue
  tracker that starts an automatic task for each new matching issue.
- **[Preview](LIVE-DEMOS.md)** — a throwaway deployment of a task's current
  files under its own subdomain.
- **[MCP / REST API](SETUP-MCP.md)** — drive Argos from outside the web UI:
  from Claude Code via the built-in MCP server, or from scripts via the
  [REST API v1](REST-API.md).

## Where to next

- **New install?** Start with [Setup](SETUP.md).
- **Want repository/branch pickers?** Connect an [OAuth App](OAUTH.md).
- **Getting a repository ready?** See
  [Preparing a Repository](PREPARE-PROJECT.md) — `.argos/`, the setup task and
  the preview contract.
- **Ready to work?** Read [Workspaces](WORKSPACES.md), then [Tasks](TASKS.md).
- **Automating?** Wire up [issue triggers](SETUP-TASK-PROVIDERS.md),
  [tickets of your own system](TICKETS.md#own-ticket-systems), the
  [MCP server](SETUP-MCP.md) or the [REST API](REST-API.md).
