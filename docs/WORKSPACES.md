# Workspaces

A **workspace** is an interactive place to work on one branch of a project
together with Claude — the way you would use Claude Code on your own machine,
but in the browser. You chat with Claude while it works, answer its questions,
approve or deny the changes it wants to make, edit files yourself in VS Code,
and click through the result in a live preview. Several people can work in the
same workspace at once.

Workspaces have their own area at **`/w`** (also where `/` leads): full
width, with a slim **icon rail** on the left instead of a top bar — workspaces,
**Administration** (the admin area at `/admin` with projects, agents and
users), the notification bell and your user menu (profile, light/dark theme,
sign out). One login covers both areas.

Work does not have to be hands-on: an [auto-thread](#auto-threads) plans,
implements, checks the quality gates and opens a pull request on its own —
started in the UI, through the [REST API](REST-API.md), [MCP](SETUP-MCP.md) or
an issue of a connected tracker.

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
- [The context sidebar: editor, preview and branch](#the-context-sidebar-editor-preview-and-branch)
- [The review](#the-review)
- [Quality gates](#quality-gates)
- [Checkpoints and reset](#checkpoints-and-reset)
- [Shipping: commit and pull request](#shipping-commit-and-pull-request)
- [Auto-threads](#auto-threads)
- [Lifecycle](#lifecycle)
- [Security](#security)
- [Configuration](#configuration)

## What you need

- The regular Argos stack (see [Setup](SETUP.md)), including the `reverb`
  service — it carries the live updates to the browser.
- **Wildcard DNS** for the preview base domain: the editor of a workspace is
  served at `code-<workspace>.<base-domain>` and its preview at
  `demo-ws-<workspace>.<base-domain>`. Locally the
  default `*.127.0.0.1.nip.io` needs a resolver that can reach nip.io.
- An **agent** of your own (your Claude subscription) or a shared one — see
  below.

## Agents and subscriptions

Every thread runs on one **agent**: a Claude Code credential created with
`claude setup-token`. Agents are either

- **personal** — they belong to one user and run on that user's Claude
  subscription. Every user can add their own under *Agents*. Users only ever
  see their own agents.
- **shared** — they have no owner (administrators create them); everyone may
  pick them in workspaces.

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

**One piece of work per workspace.** All threads of a workspace share the same files. For
separate pieces of work create separate workspaces — each has its own branch,
container, database and preview.

## Starting from an issue

If the project has a connected issue tracker (see [Issue triggers](SETUP-TASK-PROVIDERS.md)),
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

A workspace page has three parts: a **header** (workspace name and status,
the thread switcher, *New thread*, *Commit & PR* and the **⋯** menu), the
**chat** as a centred column, and the [context sidebar](#the-context-sidebar-editor-preview-and-branch)
on the right. The page fills the window; only the conversation scrolls.

Create a **thread** (title, agent, model, mode), then write. Claude's answer
streams in live. Each turn of Claude starts with a small head — duration and
cost of the turn, or *working for N s* while it runs — followed by its steps
as a **compact timeline**: one row per tool call with its state, a verb
(*Edited*, *Created*, *Ran*, …), the file or command and the changed lines or
a short result. Click a row for the full input and output; more than three
steps fold into *Show N more steps*. Calls that were simply allowed get no card
of their own.

- **Follow-ups while Claude works** are queued and picked up as soon as Claude is
  ready — they show *queued* until then. The composer says so in its
  placeholder.
- **Stop** (it replaces the send button while Claude works) interrupts the
  current turn. Open approvals are cancelled.
- **Mode** (the first pill under the text field) controls how much Claude may
  do without asking:
  - *Ask before changes* (default) — every edit and command needs an approval,
  - *Accept edits* — file edits run without asking, commands still ask,
  - *Plan mode* — Claude researches and proposes a plan before changing anything,
  - *Bypass permissions* — Claude runs every tool without asking. Only workspace
    owners can choose it.
- **Model** (the second pill) can be switched at any time. The thread's cost
  sits next to the send button.
- If a session ended (container restart, crash), **Resume session** continues the
  same Claude conversation.
- Slash commands and `CLAUDE.md` of the project are loaded like in Claude Code.

While there are uncommitted changes, a **change tray** above the text field
shows how many files changed, the added/removed lines and the state of the
[quality gates](#quality-gates), with **Check gates** and **Review**.

### The thread switcher

The button in the header shows the current thread and how many there are;
click it or press **⌘J** / **Ctrl+J**. The switcher groups the threads into
**Waiting for you**, **Running** and **Done**, each with one line of status
(the open approval, the step Claude is on, or how many files the last turn
changed) and the time of the last activity; *Done* shows the newest three
and *Show N more*. Type to search by title, use the arrow keys and **Enter**
to switch, **Esc** to close. Hover a thread to rename or archive it; the
footer has *New thread* and the **Archive** with archived threads to restore.
Unread entries show as a counter. The switcher updates live.

When **another** thread waits for an approval, a yellow chip *N waits for
approval* appears next to the switcher; a click takes you to that thread and
its open card.

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
  | `/review` | Open the [review](#the-review). |
  | `/preview` | Show the context sidebar (on narrow screens it is a drawer). |
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
| Tool call (e.g. *Claude wants to run a command*) | **Allow**, **Always allow** or **Deny** (with an optional reason). Commands show in full, edits show the change. *Always allow* applies Claude Code's own rule suggestion for the rest of the session (e.g. this command), so the same call no longer asks. It is offered when Claude Code suggests such a rule. |
| Plan (from plan mode) | Accept and choose the mode to continue in, or reject with feedback. |
| Question | Pick an option per question or type your own answer. |

The **first decision wins**. Everyone sees who decided; a second click is
rejected with a hint.

## Working together

Every workspace has **members**:

| Role | Can |
|---|---|
| Admin (`admin`) | everything: settings, integrations, members, and every task |
| Member (`member`) | start tasks, use the editor, start/stop the workspace and its preview |
| Viewer (`viewer`) | read all tasks and open the preview |

Everybody in a workspace sees all of its tasks. Writing into a task, stopping
Claude, deciding approvals, review comments and shipping belong to the task's
**owner** and the **collaborators** they invite (and workspace admins); only
the owner may invite, hand the task over or archive it.

Workspace admins add members with **⋯ → Add member**. The workspace header shows who is
online; the chat shows who is typing. Every message carries its author, and
Claude sees it as `[Name]: …`. Argos administrators may do everything in every
workspace.

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

## The context sidebar: editor, preview and branch

The sidebar on the right has four parts. On screens narrower than about
1100px it becomes a drawer (button at the right of the header); on phones the
icon rail moves to the bottom.

- **Environment** — VS Code and the preview, each with its state and a button.
  Both open in **their own browser tab** — one per workspace, reused on the
  next click — so they get the whole window, keyboard shortcuts work and apps
  that refuse to be embedded still show.
- **Branch** — the workspace branch, its base branch and project, and the
  number of **uncommitted** files and **unpushed** commits. The pull request
  and the issue the workspace came from are linked here.
- **Quality gates** — Pint, Pest/PHPUnit, PHPStan and Artisan with their state
  (open, running, ok, failed) and *Check all*; see
  [Quality gates](#quality-gates).
- **Changed files** — status letter (M/A/D/R), name and changed lines. A click
  opens the [review](#the-review) on that file.

### VS Code

**Open** starts VS Code (OpenVSCode Server) on the workspace volume. What you
change is immediately visible to Claude and the other way round. Access to the
editor host is checked against workspace membership on every request: Argos
hands your browser a short-lived ticket that is traded for a cookie on the
editor host.

### Preview

The preview is a [live demo](LIVE-DEMOS.md) of the workspace: the same demo
contract (`.argos/demo.*` or the built-in Laravel default), but it mounts the
**workspace volume**, so it always shows the current files. It is
members-only and started on demand.

- **Start** builds it, **Open** opens it, **Rebuild** replaces it. The row
  shows its state; the build log is one click away (open by default after a
  failed build).
- **Re-run setup commands** runs the contract's commands again in the running
  preview — after a migration, a `composer`/`npm` change or a build-config edit.
  PHP changes show up without it.
- Commands and PHP run as the workspace's agent user, so files they create
  (e.g. `vendor/`, compiled views) stay editable.
- The preview TTL and the platform-wide concurrency cap of live demos apply.

Previews need `ARGOS_PREVIEW_ENABLED` and the project's *Live demo* toggle.

## The review

**Review** in the change tray, a changed file in the sidebar or `/review`
switches to the **review mode**: the sidebar widens into the review panel and
the chat shrinks to a narrow column (steps fold into one line per turn).
**Maximise** gives the diff the full width and folds the chat into a strip.
**Close review** or **Esc** returns; the mode is part of the URL
(`?review=1&file=…`), so a reload or a shared link opens the same file.

The review shows the workspace's changes against its base branch — commits,
uncommitted edits and new files:

- The **toolbar** shows the added/removed lines, *X / N seen*, a chip per gate
  (a failed gate with findings jumps to the first one, the others open the gate
  details), **Split** or
  **Unified** — remembered for you — and maximise.
- The **file tree** groups the files by directory with their status letter and
  badges: findings of the gates (yellow), *new* (Claude changed the file while
  you were reviewing) and a check mark for files you marked as seen.
- The **diff** shows old and new line numbers; *⋯ N unchanged lines* between
  the changed blocks expands. **Seen** marks a file as reviewed — it resets by
  itself when the file changes again. *Previous* / *Next file* step through.
- Claude keeps working while you review. When it changes a file, a blue hint
  *Claude just changed X (+a −b)* appears; the diff does not jump —
  **Show change** (or switching files) reloads it.

### Line comments

Hover a line and click **+** to comment on it (Shift-click a second line for a
range). A saved comment is **not sent**: it appears in the diff (marked and
numbered) and as a chip `file:line ×` in the composer, which now says *N
comments collected* and offers **Send review (N)**. That sends all collected
comments and the text you typed as **one** message — per comment the
location, the code with a little context and your remark. While Claude works,
the message is queued after the current step. Comments are stored per thread
and person, so they survive a reload and closing the review; edit or discard
them in the diff, or remove the chip.

When Claude changes lines you commented on, the comment follows lines that
only moved. If the commented lines themselves changed, the comment becomes
**outdated** (dashed, *outdated* badge, the chip is struck through) and is not
sent. Choose **Send anyway** (it goes out with the original code, marked as
referring to the previous version), **Move to the new line** (it now refers to
what the lines say) or **Discard**.

## Quality gates

**Check gates** (in the change tray, *Check all* in the sidebar, the **⋯** menu
or `/gates`) runs the same
quality gates as automatic runs (`worker/lib/quality.sh`):
Artisan boot, Pint on changed files, Pest/PHPUnit, PHPStan, migration syntax,
debug calls and test presence. They are made for PHP/Laravel projects; on other
stacks the gates are skipped.

- Right after the workspace is cloned, Argos records a **baseline** of the base
  branch: Pest and PHPStan then only count failures that are new. While the
  baseline is still running or if it failed, every failure counts.
- One run at a time per workspace. The sidebar shows each gate's state. A
  click on any gate — green ones too — opens its **gate details**: result,
  duration and when it ran, the baseline hint (*only new errors count* or *all
  errors count* when the base commit has no baseline), the full log with a
  search that filters and highlights matching lines, and the affected files
  (PHPStan errors per file, files Pint flagged) — a click opens the review on
  that file. A failed gate offers **Let Claude fix it**, which posts a fix
  prompt with the log into the current task — the same one automatic runs use.
  When the working copy cannot be reached, the details show the stored end of
  the log.
- In the [review](#the-review), PHPStan errors appear **at their line** and
  Pint flags the file. **Let Claude fix it** there adds the finding (gate,
  message, file and line) to your collected comments, so it goes out with the
  next *Send review*.
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

An **auto-thread** works without a human: it plans (optionally), implements,
checks the [quality gates](#quality-gates), fixes failures and ships a pull
request. You can watch it live and take over at any time — it is an ordinary
thread otherwise.

**Starting one**

- In a workspace: **New thread → Run automatically**, then describe the work.
- From outside: the [REST API](REST-API.md#tasks)
  (`POST /api/v1/workspaces/{workspace}/tasks`), [MCP](SETUP-MCP.md) (`task_create`), or an
  issue of a connected tracker ([issue triggers](SETUP-TASK-PROVIDERS.md#issue-to-automatic-run)).

**How it runs**

1. **Plan** (per the *Plan* setting): *off* implements right away; *automatic*
   plans first and continues on its own; *wait for approval* stops at the plan
   until someone approves it — on the plan card in the thread, via the API or
   MCP, or with 👍 on the issue comment. Rejecting with feedback makes Claude
   revise the plan.
2. **Implement** in *Bypass permissions* mode — the run is isolated in the
   workspace container and nobody is there to answer approvals. If Claude asks
   a question, it is told to decide itself and state its assumption.
3. **Gates** after each turn. Failing gates go back to Claude with the same fix
   prompt the review offers, up to *Gate fix rounds* times; then it ships
   anyway and the pull request says which gates still fail.
4. **Ship** per *When done*: pull request, draft pull request, or commit and
   push only. Then the session ends and an automatic workspace (started by the
   API, MCP or an issue) stops.

The thread header shows the state (e.g. *Fixing gate failures · round 2/3*);
automatic messages are marked *automatic*.

**Taking over.** Writing into a running auto-thread pauses the automation — it
becomes a normal thread. **Continue automatically** (play button in the header)
hands it back: it checks the gates and ships. **Pause** stops it without
writing.

**Budgets.** *Max. turns*, *max. cost* and *max. runtime* stop a run
(*Budget exceeded*); the work so far stays in the workspace. When the agent
hits its usage limit, the run continues once the limit is lifted.

**Who it runs as.** A run acts for a person: whoever started it in the UI, the
signed-in MCP user, the project's **automation user** (project tokens, issue
triggers) or the API client's **Runs as** user. Their Claude agent does the
work and they own the workspace.

**Cleanup.** Automatic workspaces are deleted once their pull request is
merged or closed, or `ARGOS_AUTO_CLEANUP_DAYS` after their run ended without
anyone working in them. Workspaces created by hand are never deleted
automatically.

**Notifications** of kind *Automatic runs* tell you when a plan waits, a run is
done, failed or hit its budget.

Each project sets the defaults under **Administration → Projects →
Automation**; all settings are listed in [Configuration](CONFIGURATION.md#auto-threads).
Gates are made for PHP/Laravel projects; on other stacks the fix rounds are
skipped.

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
  it from inside the container, so treat a
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
