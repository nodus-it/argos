# Workspaces

A **workspace** is an interactive place to work on one branch of a project
together with Claude — the way you would use Claude Code on your own machine,
but in the browser. You chat with Claude while it works, answer its questions,
approve or deny the changes it wants to make, edit files yourself in VS Code,
and click through the result in a live preview. Several people can work in the
same workspace at once.

Workspaces live next to [tasks](TASKS.md). A task is the hands-off flow (concept
→ implement → pull request); a workspace is the hands-on one. Both use the same
projects, worker stacks and agents.

## Contents

- [What you need](#what-you-need)
- [Agents and subscriptions](#agents-and-subscriptions)
- [Creating a workspace](#creating-a-workspace)
- [Threads](#threads)
- [Approvals, plans and questions](#approvals-plans-and-questions)
- [Working together](#working-together)
- [The Code tab (VS Code)](#the-code-tab-vs-code)
- [The Preview tab](#the-preview-tab)
- [Lifecycle](#lifecycle)
- [Security](#security)
- [Configuration](#configuration)

## What you need

- The regular Argos stack (see [Setup](SETUP.md)), including the `reverb`
  service — it carries the live updates to the browser.
- **Wildcard DNS** for the preview base domain: the editor of a workspace is
  served at `code-<workspace>.<base-domain>` and its preview at
  `demo-ws-<workspace>.<base-domain>`, next to the task demos. Locally the
  default `*.127.0.0.1.nip.io` needs a resolver that can reach nip.io.
- An **agent** of your own (your Claude subscription) or a shared one — see
  below.

## Agents and subscriptions

Every thread runs on one **agent**: a Claude Code credential created with
`claude setup-token`. Agents are either

- **personal** — they belong to one user and run on that user's Claude
  subscription. Every user can add their own under *Agents*. Users only ever
  see their own agents.
- **shared** — they have no owner (administrators create them). Tasks use shared
  agents when no agent is chosen explicitly; in workspaces everyone may pick
  them.

An agent runs at most **parallel sessions** threads at the same time (default
`1`, set by an administrator). This protects the subscription's limits. When
Claude reports that the subscription's usage limit is reached, the agent is
marked as limited until the reported reset time and cannot start new sessions
until then.

> **Note:** everything written in a thread runs on the subscription of the
> thread's agent — including messages from other members. Keep that in mind
> when you pair on a personal Pro/Max subscription.

## Creating a workspace

Open **Workspaces → New workspace**, pick the project, give the workspace a name
and optionally a base branch (default: the project's default branch). Argos then

1. builds the workspace image (the project's worker image plus the workspace
   daemon and VS Code) — the first start can take a few minutes,
2. creates the workspace volumes and starts the project's backing services
   (MySQL/Redis) if the project enabled them,
3. starts the workspace container and clones the repository onto the branch
   `ws/<workspace>`.

The workspace page shows the progress and, if something fails, the reason.

**One task per workspace.** All threads of a workspace share the same files. For
separate pieces of work create separate workspaces — each has its own branch,
container, database and preview.

## Threads

The left half of the workspace page is the chat. Create a **thread** (title,
agent, model, mode), then write. Claude's answer streams in live; tool calls
(reading files, running commands, editing) appear as compact rows you can expand.

- **Follow-ups while Claude works** are queued and picked up as soon as Claude is
  ready — they show *queued* until then.
- **Stop** interrupts the current turn. Open approvals are cancelled.
- **Mode** controls how much Claude may do without asking:
  - *Ask before changes* (default) — every edit and command needs an approval,
  - *Accept edits* — file edits run without asking, commands still ask,
  - *Plan mode* — Claude researches and proposes a plan before changing anything,
  - *Bypass permissions* — Claude runs every tool without asking. Only workspace
    owners can choose it.
- **Model** can be switched at any time.
- If a session ended (container restart, crash), **Resume session** continues the
  same Claude conversation.
- Slash commands and `CLAUDE.md` of the project are loaded like in Claude Code.

## Approvals, plans and questions

When Claude needs a decision, a card appears in the thread for everyone:

| Card | What you can do |
|---|---|
| Tool call (e.g. *Edit*, *Bash*) | Allow, or deny with an optional reason. Edits show the change. |
| Plan (from plan mode) | Accept and choose the mode to continue in, or reject with feedback. |
| Question | Pick an option per question or type your own answer. |

The **first decision wins**. Everyone sees who decided; a second click is
rejected with a hint.

## Working together

Every workspace has **members**:

| Role | Can |
|---|---|
| Owner | everything an editor can, plus manage members, delete the workspace and use *Bypass permissions* |
| Editor | write in threads, stop Claude, decide approvals, change mode/model, use the editor, start/stop the workspace and its preview |
| Viewer | read threads and open the preview |

Owners add members with **⋯ → Add member**. The workspace header shows who is
online; the chat shows who is typing. Every message carries its author, and
Claude sees it as `[Name]: …`. Administrators see all workspaces but join as a
member to write.

Administrators manage the people of an installation under **Users**.

## The Code tab (VS Code)

The right half shows **VS Code** (OpenVSCode Server) on the workspace volume.
What you change is immediately visible to Claude and the other way round.

Access to the editor host is checked against workspace membership on every
request: the workspace page hands your browser a short-lived ticket that is
traded for a cookie on the editor host. Browsers only send that cookie inside an
embedded frame when the editor host belongs to the same site as Argos (e.g.
`argos.example.com` and `code-x.argos.example.com`). On other setups — such as
the local `127.0.0.1` / `nip.io` default — the tab offers **Open VS Code** in a
separate browser tab instead; access works the same.

## The Preview tab

The preview is a [live demo](LIVE-DEMOS.md) of the workspace: the same demo
contract (`.argos/demo.*` or the built-in Laravel default), but it mounts the
**workspace volume**, so it always shows the current files. It is
members-only (access mode `workspace`) and started on demand.

- **Start preview** builds it, **Rebuild** replaces it.
- **Re-run setup commands** runs the contract's commands again in the running
  preview — after a migration, a `composer`/`npm` change or a build-config edit.
  PHP changes show up without it.
- Commands run as the workspace's agent user, so files they create (e.g.
  `vendor/`) stay editable.
- The preview TTL and the platform-wide concurrency cap of live demos apply.

Previews need `ARGOS_PREVIEW_ENABLED` and the project's *Live demo* toggle.

## Lifecycle

| State | Meaning |
|---|---|
| Provisioning / Starting | The container is being set up. |
| Running | Ready to work. |
| Stopping / Stopped | The container is stopped; volumes, database and threads are kept. Opening the workspace starts it again. |
| Failed | Start failed — the page shows the reason; **Start** retries. |

- **Idle stop:** a running workspace without activity for
  `ARGOS_WORKSPACE_IDLE_MINUTES` is stopped — but never while a thread is working
  or waiting for a decision.
- **Capacity:** at most `ARGOS_WORKSPACE_MAX_ACTIVE` workspaces run at once.
  Starting beyond that is refused; running workspaces are never evicted.
- **Delete** removes the container, both volumes, the database of the backing
  services and the preview. Commits that were not pushed are lost.

## Security

- The workspace daemon is reachable only on the internal `argos_ws` network and
  only with the workspace's own token. The database and Redis of Argos are not
  on that network.
- Your Claude token is sent per thread and kept in memory; it never lands on
  disk or in the container's environment. Claude itself can technically read
  it from inside the container — the same as in task workers — so treat a
  workspace like a machine you run Claude Code on.
- The repository token reaches git through a credential helper and is never
  written to the repository. Claude is told not to push, but it technically can.
- The editor is effectively a shell into the container: only owners and editors
  get in; viewers see threads and the preview only.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `ARGOS_WORKSPACES_ENABLED` | `true` | Master switch for workspaces. |
| `ARGOS_WORKSPACE_COLLABORATION` | `true` | Whether workspaces may have members besides the owner. |
| `ARGOS_WORKSPACE_MAX_ACTIVE` | `5` | Workspaces running at the same time. |
| `ARGOS_WORKSPACE_IDLE_MINUTES` | `60` | Idle time before a workspace is stopped (`0` disables). |
| `ARGOS_WORKSPACE_CPUS` / `ARGOS_WORKSPACE_MEMORY` | `2` / `4g` | Limits per workspace container. |

All settings are listed in [Configuration](CONFIGURATION.md#workspaces).
