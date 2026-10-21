# Workspaces

A **workspace** is an interactive place to work on one branch of a project
together with Claude — the way you would use Claude Code on your own machine,
but in the browser. You chat with Claude while it works, answer its questions,
approve or deny the changes it wants to make, edit files yourself in VS Code,
and click through the result in a live preview. Several people can work in the
same workspace at once.

Workspaces have their own area at **`/w`** (also where `/` leads): full
width, navigation in the top bar, so chat, editor and preview get the whole
screen. **Administration** in the top bar leads to the admin area (`/admin`)
with projects, agents, users and tasks; one login covers both.

Workspaces live next to [tasks](TASKS.md). A task is the hands-off flow (concept
→ implement → pull request); a workspace is the hands-on one. Both use the same
projects, worker stacks and agents.

## Contents

- [What you need](#what-you-need)
- [Agents and subscriptions](#agents-and-subscriptions)
- [Creating a workspace](#creating-a-workspace)
- [Starting from an issue](#starting-from-an-issue)
- [Threads](#threads)
- [Images, files and commands](#images-files-and-commands)
- [Approvals, plans and questions](#approvals-plans-and-questions)
- [Working together](#working-together)
- [Overview and notifications](#overview-and-notifications)
- [The workbench: editor and preview](#the-workbench-editor-and-preview)
- [The review](#the-review)
- [Quality gates](#quality-gates)
- [Checkpoints and reset](#checkpoints-and-reset)
- [Shipping: commit and pull request](#shipping-commit-and-pull-request)
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

## Starting from an issue

If the project has a connected issue tracker (see [Task providers](SETUP-TASK-PROVIDERS.md)),
the form offers **From issue**: search the open issues by title or number and
pick one. The workspace is then named `#<number> <title>`, its branch is
`ws/<number>-<title>` (at most 50 characters; a second workspace for the same
issue gets a suffix), and the header links the issue.

The first thread you create is titled after the issue, and the composer is
**prefilled — not sent** — with the issue's title, text and link. The issue
text is wrapped as untrusted input, so Claude treats it as data, not as
instructions. Review it, add what you know, then send it yourself.

When you ship, the pull request carries `Closes #<number>` (GitHub, GitLab) or
the issue link (Linear), and Argos comments the pull request link on the
issue. The issue is closed by merging, never by Argos.

## Threads

The left part of the workspace page is the chat; drag the divider between
chat and the workbench to change the split (your browser remembers it). The page
fills the window, and only the conversation scrolls. Create a **thread** (title,
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

The **thread list** on the left shows every thread with its status (working,
waiting for a decision, idle) and the number of unread entries. Threads can be
renamed and archived; *Show archived* brings archived ones back. On narrow
screens the list folds away.

## Images, files and commands

- **Images:** paste, drop or attach screenshots (PNG, JPEG, GIF, WebP; up to 5
  per message, `ARGOS_WORKSPACE_UPLOAD_MAX_MB` each). Claude sees them with the
  message; members see them as thumbnails. They are stored privately and
  deleted with the workspace.
- **`@` mentions:** type `@` and part of a path to pick a file of the workspace
  (fuzzy search over tracked and untracked files; ignored ones are left out). The path is inserted as text.
- **`/` commands:** type `/` for a list. Argos' own commands run in the
  browser and are not sent to Claude:

  | Command | Does |
  |---|---|
  | `/plan [request]` | Switch to plan mode, optionally send the request. |
  | `/ship` | Open the commit & pull request dialog. |
  | `/preview`, `/review` | Point at the preview controls or the review. |
  | `/gates` | Run the [quality gates](#quality-gates). |
  | `/checkpoint [name]` | Set a named [checkpoint](#checkpoints-and-reset). |

  All other commands — the project's commands and skills, `/compact` and
  friends — go to Claude Code as typed.

`Enter` sends, `Shift+Enter` adds a line; while the suggestion list is open,
`Enter`/`Tab` pick the highlighted entry (a fully typed command is sent).

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

## Overview and notifications

The dashboard widget **My workspaces** and the workspace list show what needs
you: how many threads are working and which ones wait for a decision (filter
*Waiting for you*). They update live.

Owners and editors are notified when

| Kind | When |
|---|---|
| Claude waits for a decision | an approval, plan or question appears |
| Claude finished a longer turn | a turn took more than 30 seconds |
| A subscription hit its usage limit | the thread's agent reports its limit |
| Errors | a thread fails |
| Someone shipped a workspace | another member committed and opened/updated the pull request |

Everything except finished turns also lands in the notification bell. In the
browser, a notification (and a counter in the tab title) only shows when the
workspace in question is not open in front of you; allow browser notifications
in your **profile**, where you can also switch each kind off.

## The workbench: editor and preview

The right part of the page is the workbench. Its bar opens the editor and the
preview, each in **its own browser tab** — one per workspace, reused on the
next click — so both get the whole window, keyboard shortcuts work and apps
that refuse to be embedded still show. Below the bar sits the
[review](#the-review) of the changes.

### VS Code

**Open VS Code** opens VS Code (OpenVSCode Server) on the workspace volume.
What you change is immediately visible to Claude and the other way round.
Access to the editor host is checked against workspace membership on every
request: Argos hands your browser a short-lived ticket that is traded for a
cookie on the editor host.

### Preview

The preview is a [live demo](LIVE-DEMOS.md) of the workspace: the same demo
contract (`.argos/demo.*` or the built-in Laravel default), but it mounts the
**workspace volume**, so it always shows the current files. It is
members-only (access mode `workspace`) and started on demand.

- **Start preview** builds it, **Open preview** opens it, **Rebuild** replaces
  it. The bar shows its state; a failed build shows the build log.
- **Re-run setup commands** runs the contract's commands again in the running
  preview — after a migration, a `composer`/`npm` change or a build-config edit.
  PHP changes show up without it.
- Commands and PHP run as the workspace's agent user, so files they create
  (e.g. `vendor/`, compiled views) stay editable.
- The preview TTL and the platform-wide concurrency cap of live demos apply.

Previews need `ARGOS_PREVIEW_ENABLED` and the project's *Live demo* toggle.

## The review

The review shows the workspace's changes against its base branch —
commits, uncommitted edits and new files — file by file. After Claude finished
a turn it marks itself *changed since you looked*; **Refresh** reloads.

Click a line to leave a comment for Claude. Comments are collected; **Send to
Claude** posts them as one message (`path:line — comment`) into the thread
you pick.

## Quality gates

**Check gates** (in the review, the header menu or `/gates`) runs the same
quality gates as the implement phase of tasks (`worker/lib/quality.sh`):
Artisan boot, Pint on changed files, Pest/PHPUnit, PHPStan, migration syntax,
debug calls and test presence. They are made for PHP/Laravel projects; on other
stacks the gates are skipped.

- Right after the workspace is cloned, Argos records a **baseline** of the base
  branch: Pest and PHPStan then only count failures that are new. While the
  baseline is still running or if it failed, every failure counts; the panel
  says which applies.
- One run at a time per workspace. Each gate shows pass/fail/skip and the end
  of its log.
- **Send errors to agent** posts the same fix prompt the task flow uses into
  the selected thread.
- The commit dialog mentions the last result (*Gates: 6/7 green*). Failed gates
  are a warning, never a blocker.

Gate logs live in `.agent/logs/` inside the workspace, which git ignores
locally.

## Checkpoints and reset

Before Claude handles a message, Argos takes a **checkpoint**: a snapshot of
all files of the workspace — tracked, staged and untracked, not ignored ones —
stored as a git ref (`refs/argos/checkpoints/<thread>/<n>`). The branch, the
index and your files are not touched. A thread keeps its newest 200
checkpoints.

- **Reset to here** on a message (hover it) shows what a reset would undo and
  puts the files back to their state before that message. It is refused while
  Claude works in that thread — stop it first.
- The current state is saved as a checkpoint first, so **Undo** on the reset
  notice brings it back.
- Commits are never undone: if commits were made since, the dialog warns that
  they stay and only the files are reset.
- The chat history stays. The reset is noted in the thread, and Claude is told
  with your next message, so it re-reads the files.
- `/checkpoint [name]` sets a named checkpoint you can return to later.

## Shipping: commit and pull request

**Commit & PR** (header, or `/ship`) commits everything that changed, pushes the
branch and opens a pull request (GitLab: merge request) against the base
branch.

- Claude drafts the commit message from the diff and the conversation, on
  **your** subscription (your first usable agent). Without one, or if it fails,
  you write it yourself.
- *Open as draft* opens a draft pull request. Shipping again pushes the new
  commits and comments on the existing pull request.
- If the remote branch moved, the push is refused; **Ask Claude to rebase**
  (header menu) asks the agent to fetch, rebase and resolve conflicts.
- The header shows the pull request and its state (open, merged, closed). It
  is refreshed every `ARGOS_WORKSPACE_PR_SYNC_MINUTES` and when you open the
  workspace.
- Every open thread gets a notice with the link; the other members are
  notified.

## Auto-threads

An **auto-thread** works without a human: it plans (optional), implements, checks the [quality gates](#quality-gates), fixes failures and ships a pull request. Each project sets the defaults under **Administration → Projects → Automation** (plan: off / automatic / wait for approval; what to do when done; fix rounds; turn, cost and time budgets). All settings are listed in [Configuration](CONFIGURATION.md#auto-threads).

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
| `ARGOS_WORKSPACE_PR_SYNC_MINUTES` | `15` | How often the state of open workspace pull requests is refreshed. |
| `ARGOS_WORKSPACE_UPLOAD_MAX_MB` | `5` | Size limit per image attached to a message. |

All settings are listed in [Configuration](CONFIGURATION.md#workspaces).
