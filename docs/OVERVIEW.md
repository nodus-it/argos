# How Argos Works

Welcome to Argos. This page is the big picture: what Argos is, the few
concepts you need in your head, and how a piece of work flows from a sentence
you type to a pull request you can review. Read it once, then follow the
links into the detail docs when you need them.

## What is Argos

Argos is **Claude Code in the browser, for a team**. You open a **workspace**
on a repository — one branch, one container — and work with Claude the way you
would on your own machine: chat with it while it works, approve its changes,
edit files yourself in VS Code, click through the result in a live preview, and
ship a pull request. Several people can work in the same workspace at once.

Work does not have to be hands-on: an **automatic run** (auto-thread) plans,
implements, checks the project's quality gates, fixes what fails and opens the
pull request on its own — started from the UI, the REST API, MCP, or an issue in
your tracker. You stay in control: you can watch, approve the plan, or take
over at any time, and you review the pull request before it is merged.

Two things make Argos different from calling an API in a loop:

- **It runs on your Claude subscription, not the API.** Argos uses the Claude
  Code OAuth token from `claude setup-token`, so your existing Pro / Max /
  Team plan covers the work — there's no per-token API billing.
- **Every workspace runs in its own container.** The agent never touches your
  manager host or other workspaces; it gets the repository, its own database
  and preview, and nothing else.

> Argos is currently tuned for PHP / Laravel projects — the quality gates wire
> up Composer, Pint, Pest/PHPUnit and PHPStan. Other stacks work, but the gates
> are skipped there.

## The mental model: Manager and workspaces

Argos is two halves, and it helps to keep them separate in your mind.

### The Manager — the app you use

The Manager is the web application you log into: workspaces live at
`${APP_URL}/w`, administration (projects, agents, users, settings) at
`${APP_URL}/admin`. You connect your accounts here, define projects, open
workspaces, follow Claude live and review the results. The Manager holds all
your data — threads, decisions, settings — and decides *what* may happen.

Importantly, **the Manager itself never runs the AI.** It has no Claude session
of its own.

### The workspace container — where the work happens

Each workspace is a **Docker container** that the Manager starts for it. It
holds the checkout of the repository on its own branch, the project's backing
services (MySQL / Redis) next to it, OpenVSCode Server for the editor, and a
small **daemon** that runs the Claude sessions and relays their events to the
Manager. A workspace stops when idle and starts again when you open it; its
files and database are kept until you delete it.

The container is sandboxed: it gets the repository, a repository credential
through a git helper, and nothing else. It has **no Docker socket** and no
access to the Manager's database. The image it runs in is built from a
[Worker Stack](WORKER-STACKS.md) — think "the toolbox the agent gets" — which
you can customize per project.

This split is the heart of Argos: a stable, stateful Manager you interact
with, and isolated containers that do the risky, expensive work.

## The core flow at a glance

1. **Connect credentials.** Add your Claude agent (the token from
   `claude setup-token`) and link a Git account or a Personal Access Token.
   See [Credentials](CREDENTIALS.md) and [OAuth Apps](OAUTH.md).
2. **Create a Project.** A Project points Argos at one repository and carries
   its defaults — default branch, worker stack, backing services, automation.
   See [Projects](PROJECTS.md).
3. **Open a workspace.** Pick the project and a name; Argos builds the image,
   clones the repository onto a `ws/…` branch and starts the container. See
   [Workspaces](WORKSPACES.md).
4. **Work in threads.** Describe what you want; Claude reads, edits and runs
   commands, asking for approval as the thread's mode requires. Check the
   [quality gates](WORKSPACES.md#quality-gates), look at the
   [review](WORKSPACES.md#the-review) and the [preview](LIVE-DEMOS.md).
5. **Ship.** **Commit & PR** pushes the branch and opens the pull request.

Or let it run on its own: an [auto-thread](WORKSPACES.md#auto-threads) does
steps 4 and 5 by itself —

```
Plan  →  Implement  →  Quality gates ⇄ Fix  →  Pull request
```

— and an [issue trigger](SETUP-TASK-PROVIDERS.md) starts such a run for every
new matching issue in GitHub, GitLab or Linear.

## Glossary

A one-line definition of each core concept. Follow the link for the detail.

- **[Project](PROJECTS.md)** — one repository plus its defaults (default
  branch, worker stack, backing services, automation, auth). Every workspace
  belongs to a project.
- **[Workspace](WORKSPACES.md)** — an interactive place to work on one branch:
  a container with the checkout, its own database and preview, threads and
  members.
- **[Thread](WORKSPACES.md#threads)** — one conversation with Claude inside a
  workspace, on one agent. Threads of a workspace share its files.
- **[Auto-thread](WORKSPACES.md#auto-threads)** — a thread that runs on its
  own: plan, implement, fix failing gates, ship.
- **[Worker Stack](WORKER-STACKS.md)** — the Docker image definition a
  workspace runs in: the base image plus the tools the agent gets. Built on
  demand, customizable per project (drop a `.argos/worker.dockerfile` for full
  control).
- **[Agent](AGENTS.md)** — a Claude Code credential a thread runs on: your
  personal subscription or a shared one.
- **[Credential](CREDENTIALS.md)** — a stored secret Argos needs: your Claude
  token to run the agent, and a Git token to read and write the repository —
  either a **Personal Access Token (PAT)** or a full **OAuth** account binding
  (which also unlocks repo/branch pickers). See [OAuth Apps](OAUTH.md).
- **[Issue trigger](SETUP-TASK-PROVIDERS.md)** — a connection to an issue
  tracker (GitHub, GitLab, Linear) that starts an automatic run for each new
  matching issue and reports back on the issue.
- **[Live Demo / Preview](LIVE-DEMOS.md)** — a throwaway deployment of a
  workspace's current files, so you can click through the change in a browser
  before merging.
- **[MCP / REST API](SETUP-MCP.md)** — drive Argos from outside the web UI:
  from Claude Code via the built-in MCP server, or programmatically via the
  [REST API v1](REST-API.md) with Sanctum bearer tokens.

## Where to next

- **New install?** Start with [Setup](SETUP.md) to finish connecting accounts
  and configuring the stack.
- **Want repo/branch pickers and per-user binding?** Connect an
  [OAuth App](OAUTH.md).
- **Getting a repository ready for Argos?** See
  [Preparing a Project](PREPARE-PROJECT.md) — covers the build environment and
  the live-demo contract.
- **Ready to run something?** Head to [Projects](PROJECTS.md), then
  [Workspaces](WORKSPACES.md).
- **Automating?** Wire up [issue triggers](SETUP-TASK-PROVIDERS.md), the
  [MCP server](SETUP-MCP.md) or the [REST API](REST-API.md).
