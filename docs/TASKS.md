# Tasks

Every conversation with Claude in a [workspace](WORKSPACES.md) is a **task**.
A task

- belongs to one person, its **owner**, and runs on the owner's Claude
  subscription — also when collaborators write,
- has its **own branch** (from the workspace's branch pattern), its own git
  **worktree** at `/workspace/tasks/<slug>`, its **own database** (and Redis
  prefix) when the workspace has those services, its own **preview** on demand
  and its own **pull request**,
- can be linked to exactly one [ticket](TICKETS.md).

Tasks of a workspace share the container but not their files: what Claude
changes in one task does not show up in another.

## Contents

- [Starting a task](#starting-a-task)
- [Preparing: worktree, database, setup steps](#preparing-worktree-database-setup-steps)
- [Status and the task switcher](#status-and-the-task-switcher)
- [Chatting with Claude](#chatting-with-claude)
- [Images, files and commands](#images-files-and-commands)
- [Approvals, plans and questions](#approvals-plans-and-questions)
- [Working together](#working-together)
- [Session queue](#session-queue)
- [The sidebar: editor, preview, branch](#the-sidebar-editor-preview-branch)
- [The review](#the-review)
- [Quality gates](#quality-gates)
- [Checkpoints and reset](#checkpoints-and-reset)
- [Shipping: commit and pull request](#shipping-commit-and-pull-request)
- [Automatic tasks](#automatic-tasks)
- [Finishing a task](#finishing-a-task)
- [Cleanup](#cleanup)
- [Notifications](#notifications)

## Starting a task

**New task** (header or switcher) asks for:

| Field | Meaning |
|---|---|
| Ticket (optional) | Search the open issues of the workspace's tracker by title or number. The task takes the ticket's title and description and [reports back to it](TICKETS.md). |
| Title | What you work on (default: the ticket title). The branch is derived from it. |
| Base branch | Where the task's branch starts (default: the workspace's default branch). Its pull request targets it. |
| Run automatically | Makes it an [automatic task](#automatic-tasks): describe the work, choose plan and ship mode and the gate fix rounds. |
| Merge automatically | Switch on the provider's auto-merge as soon as the pull request exists — see [Pull Requests](PULL-REQUESTS.md#auto-merge). |
| Agent | Which of your Claude subscriptions (or a shared one) the task runs on. |
| Model, Mode | See [Chatting with Claude](#chatting-with-claude). |

Starting a task needs the role *admin* or *member* and a usable agent.

**Branch names.** With a ticket the branch is `<ticket key>-<title>` (for
example `gitlab-42-login-page` or `eng-118-login-page`), without one
`argos/<title>`. A workspace can set its own **branch pattern** (Settings →
General) with the placeholders `{ticket}` (the ticket key, or `argos` without
a ticket) and `{title}` (the title as a slug). A taken name gets a numeric
suffix.

## Preparing: worktree, database, setup steps

A new task starts in the state **Preparing**; the chat shows the progress:

1. Argos creates the task's database `task_<slug>` on the workspace's MySQL
   sidecar (if MySQL is enabled).
2. The daemon fetches the main clone and adds the worktree: on the remote
   branch if it already exists (a task picked up again), otherwise on the
   freshly fetched base branch.
3. It writes the task's values into the worktree's `.env` — starting from
   `.env.example` when there is no `.env` yet: `DB_*` with the task's database,
   `REDIS_*`, `REDIS_PREFIX` and `CACHE_PREFIX`. The same values are kept in
   `.agent/task-env.json` and override the container's environment for
   everything that runs in the worktree (Claude's tools, the gates, the setup).
4. It runs the **setup steps**: `.argos/setup.sh` from the repository, or —
   without one — the Laravel defaults (`composer install`, an `APP_KEY` when
   `.env` has an empty one, `php artisan migrate`). While a tested workspace
   setup is not merged yet, the steps come from its branch. See
   [Preparing a Repository](PREPARE-PROJECT.md#setup-steps-argossetupsh).

Then the task is ready and Claude starts. If the setup steps fail, the task is
usable anyway (the chat shows the setup log and Claude can look into it). If
preparing itself fails, **Try again** retries. Messages written while a task is
being prepared are refused with a hint.

## Status and the task switcher

| Status | Meaning | Switcher group |
|---|---|---|
| Preparing | Worktree, database and setup steps run | Running |
| Running | Claude is working | Running |
| Waiting for you | An approval, plan or question is open | Waiting for you |
| Ready | The turn ended, nothing is open, no pull request yet | Ready |
| PR open | A pull request (draft or ready) exists | PR open |
| Done | The pull request was merged | Done |
| Cancelled | The pull request was closed without a merge, or the task was cancelled | Done |
| Archived | Taken out of the list, readable in the archive | Archive |

Automatic tasks additionally show their run state (*Planning*, *Fixing gate
failures · round 2/3*, …).

### The task switcher

The button in the header shows the current task; click it or press **⌘J** /
**Ctrl+J**. The switcher groups the tasks into *Waiting for you*, *Running*,
*Ready*, *PR open* and *Done* (folded; the newest three and *Show N more*).
Each row shows the owner, the ticket, the pull request, one line of status
(the open approval, the step Claude is on, how many files the last turn
changed, *Waiting for a free session*) and the time of the last activity.

- The filter switches between **Mine + invited** and **All**.
- Type to search by title; arrow keys and **Enter** switch, **Esc** closes.
- Hover a task to rename or archive it (only on tasks you may change; archiving
  asks first); the footer has the **Archive** with archived tasks to restore.
  The **+** right next to the switcher starts a new task.
- Unread entries show as a counter; the switcher updates live.

When **another** task waits for an approval, a chip *N waits for approval*
appears next to the switcher; a click takes you to that task and its open card.

## Chatting with Claude

Write, and Claude's answer streams in live. Each turn starts with a small head
— duration and cost, or *working for N s* while it runs — followed by its
steps as a **compact timeline**: one row per tool call with its state, a verb
(*Edited*, *Created*, *Ran*, …), the file or command and the changed lines or
a short result. Click a row for the full input and output; more than three
steps fold into *Show N more steps*.

- **Follow-ups while Claude works** are queued (send button or `Enter`) and
  picked up as soon as Claude is ready — they show *queued* until then.
- **Stop** (next to the send button while Claude works) interrupts the current
  turn. Open approvals are cancelled.
- **Mode** (the first pill under the text field) controls how much Claude may
  do without asking:
  - *Ask before changes* (default) — every edit and command needs an approval,
  - *Accept edits* — file edits run without asking, commands still ask,
  - *Plan mode* — Claude researches and proposes a plan before changing
    anything,
  - *Bypass permissions* — Claude runs every tool without asking. Only
    workspace admins (and Argos administrators) can choose it.
- **Model** (the second pill) can be switched at any time — like `/model` in
  Claude Code. *Always the newest version* lists the aliases your subscription
  offers (with the version they stand for today); *Fixed version* pins that
  exact model (e.g. `claude-opus-5-5`), which stays when a newer one ships;
  *Other model ID …* takes any model id by hand. The list appears after the
  agent's first session. The task's cost sits next to the send button.
- If a session ended (container restart, crash), **Resume session** continues
  the same Claude conversation.
- Claude works in the task's worktree. Slash commands, skills and `CLAUDE.md`
  of the repository are loaded like in Claude Code.

An **action bar** above the text field shows what changed — files, added and
removed lines and the state of the [quality gates](#quality-gates), or commits
not pushed yet — with **Check gates**, **Review**, **Commit** and **Push & PR**
(see [Shipping](#shipping-commit-and-pull-request)). Below
it, the to-dos that need someone now, each with its action: red gates
(*Show*), a red pipeline (*Let Claude fix it*), conflicts or a moved base
branch (*Rebase by Claude*), new review comments (*Collect in my review*) and
a draft pull request (*Mark ready*). It only shows while there is something
to do.

## Images, files and commands

- **Images:** paste, drop or attach screenshots (PNG, JPEG, GIF, WebP; up to 5
  per message, `ARGOS_WORKSPACE_UPLOAD_MAX_MB` each). Claude sees them with the
  message; others see them as thumbnails. They are stored privately.
- **`@` mentions:** type `@` and part of a path to pick a file of the task's
  worktree (fuzzy search over tracked and untracked files; ignored ones are
  left out). The path is inserted as text.
- **`/` commands:** type `/` for a list. Argos' own commands run in the browser
  and are not sent to Claude:

  | Command | Does |
  |---|---|
  | `/plan [request]` | Switch to plan mode, optionally send the request. |
  | `/ship` | Open the next shipping step: commit while something is uncommitted, otherwise push & pull request. |
  | `/review` | Open the [review](#the-review). |
  | `/preview` | Show the preview in the sidebar (unfolded and marked; on narrow screens it opens the drawer). |
  | `/gates` | Run the [quality gates](#quality-gates). |
  | `/checkpoint [name]` | Set a named [checkpoint](#checkpoints-and-reset). |

  All other commands — the repository's commands and skills, `/compact` and
  friends — go to Claude Code as typed. `/ship` and `/gates` say so when the
  workspace is not running or you may not use them.

`Enter` sends, `Shift+Enter` adds a line; while the suggestion list is open,
`Enter`/`Tab` pick the highlighted entry. **?** (outside a text field) or
**⋯ → Keyboard shortcuts** lists all shortcuts.

## Approvals, plans and questions

When Claude needs a decision, a card appears in the task for everyone:

| Card | What you can do |
|---|---|
| Tool call (e.g. *Claude wants to run a command*) | **Allow**, **Always allow** or **Deny** (with an optional reason). Commands show in full, edits show the change. *Always allow* applies Claude Code's own rule suggestion for the rest of the session, so the same call no longer asks; it is offered when Claude Code suggests such a rule. |
| Plan (from plan mode) | Accept and choose the mode to continue in, or reject with feedback. |
| Question | Pick an option per question or type your own answer. |

File tools outside the task's worktree (and `/tmp`) always ask, even in
*Accept edits*. The owner, collaborators and workspace admins decide; the
**first decision wins**, everyone sees who decided.

## Working together

- **Invite** (the **+** next to the pictures in the header): the owner picks workspace
  members as collaborators. They write, approve, review and ship in this task;
  it keeps running on the owner's subscription. A click on a collaborator's
  picture in the header removes them again (after a confirmation).
- **Hand over**: the task then belongs to the new owner (a workspace admin or
  member with a usable Claude subscription) and runs on their subscription;
  the open session ends, the previous owner stays on as a collaborator, and the
  ticket's assignee follows where the tracker can (see
  [Tickets](TICKETS.md#hand-over-and-assignee)).
- The header shows the owner and collaborators with presence; the chat shows
  who is typing. Messages of other people carry their name and colour, and
  Claude sees them as `[Name]: …`. Approvals and shipments name who did them.

> Everything written in a task runs on the **owner's** subscription —
> including messages of collaborators.

## Session queue

Sessions are limited per workspace (**Parallel sessions**, default 3) and per
Claude subscription (the agent's *parallel sessions*, default 1). When a
message finds no free session, a resting session (idle, nobody waiting on it)
is closed to make room; if there is none, the message waits in line — the chat
says *Waiting for a free session — position N* — and goes out automatically
once a session frees up, oldest first. Resting tasks hold no session for long.

## The sidebar: editor, preview, branch

The sidebar belongs to the current task:

- **Environment** — VS Code and the preview, each with its state and a button.
  Both open in **their own browser tab** (reused on the next click), so they
  get the whole window and apps that refuse to be embedded still show.
- **Branch** — the task's branch, its base branch, the number of
  **uncommitted** files and **unpushed** commits, and the ticket.
- **Pull request** — state, checks, reviews, conflicts and its actions; see
  [Pull Requests](PULL-REQUESTS.md).
- **Quality gates** — each gate with its state (open, running, ok, failed,
  skipped) and *Check all*; see [Quality gates](#quality-gates).
- **Changed files** — status letter (M/A/D/R), name and changed lines. A click
  opens the [review](#the-review) on that file.

### VS Code

**Open** starts OpenVSCode Server on the task's folder
(`/workspace/tasks/<slug>`). What you change is immediately visible to Claude
and the other way round. Only workspace admins and members get the editor;
access is checked on every request.

### Preview

The preview is a [live demo](LIVE-DEMOS.md) of the task's worktree, under its
own subdomain. **Start** builds it, **Open** opens it, **Rebuild** replaces it,
**Re-run setup commands** runs the contract's commands again (after a
migration or a `composer`/`npm` change), **Stop preview** removes it. Previews
need `ARGOS_PREVIEW_ENABLED` and the workspace's *Live demo* toggle; at most
*Parallel previews* run per workspace.

## The review

**Review** in the action bar, a changed file in the sidebar or `/review`
switches to the **review mode**: the sidebar widens into the review panel and
the chat shrinks to a narrow column. **Maximise** gives the diff the full
width. **Close review** or **Esc** returns; the mode is part of the URL
(`?review=1&file=…`), so a reload or a shared link opens the same file.

The review shows the task's changes against its base branch — commits,
uncommitted edits and new files:

- The **toolbar** shows the added/removed lines, *X / N seen*, a chip per gate,
  **Split** or **Unified** (remembered for you) and maximise.
- The **file tree** groups the files by directory with their status letter and
  badges: findings of the gates, *new* (Claude changed the file while you were
  reviewing) and a check mark for files you marked as seen. Generated files
  (lock files, build output) are folded; their diff loads on demand.
- The **diff** shows old and new line numbers; unchanged lines between the
  changed blocks expand. **Seen** marks a file as reviewed — it resets when the
  file changes again. Large diffs load step by step.
- When Claude changes a file while you review, a hint *Claude just changed X
  (+a −b)* appears; **Show change** reloads the diff.

### Line comments

Hover a line and click **+** to comment on it (Shift-click a second line for a
range). A saved comment is **not sent**: it appears in the diff and as a chip
`file:line ×` in the composer, which offers **Send review (N)**. That sends all
collected comments and your text as **one** message. Comments are stored per
task and person.

When Claude changes lines you commented on, the comment follows lines that
only moved. If the commented lines themselves changed, it becomes
**outdated** and is not sent: **Send anyway**, **Move to the new line** or
**Discard**.

## Quality gates

**Check gates** (action bar, *Check all* in the sidebar or `/gates`) runs the quality gates in the task's worktree: Artisan boot, Pint on
changed files, Pest/PHPUnit, PHPStan, migration syntax, debug calls and test
presence. They are made for PHP/Laravel projects; on other stacks the gates
are skipped.

- **Baseline per base commit:** Argos records once which tests and PHPStan
  errors already fail on the base commit; every task on that commit shares it,
  so only failures the task **introduces** count. Without a baseline (still
  being captured or failed) every failure counts.
- One run at a time per task. A click on any gate — green ones too — opens
  its **gate details**: result, duration, when it ran, the baseline hint, the
  full log with search, and the affected files (a click opens the review on
  that file). **Check again** re-runs just that gate (the other gates keep
  their result); a failed gate also offers **Let Claude fix it**, which posts
  a fix prompt with the log into the task.
- In the [review](#the-review), PHPStan errors appear **at their line** and
  Pint flags the file; **Add to my review** there adds the finding to your
  collected comments.
- The commit and push dialogs mention the last result. Failed gates are a warning, never
  a blocker.

Gate logs live in `.agent/logs/` inside the worktree, which git ignores
locally.

## Checkpoints and reset

Before Claude handles a message, Argos takes a **checkpoint** of the task's
worktree — tracked, staged and untracked files, not ignored ones — stored as a
git ref (`refs/argos/checkpoints/<task>/<n>`). The branch, the index and your
files are not touched. A task keeps its newest 200 checkpoints.

- **Reset to here** on a message (hover it) shows what a reset would undo and
  puts the files back to their state before that message. It is refused while
  Claude works in that task — stop it first. Other tasks are not affected.
- The current state is saved as a checkpoint first, so **Undo** brings it back.
- Commits are never undone: if commits were made since, only the files are
  reset.
- The chat stays; Claude is told with your next message, so it re-reads the
  files.
- `/checkpoint [name]` sets a named checkpoint.

## Shipping: commit and pull request

Shipping has two steps — commit, then push with the pull request (GitLab: merge
request) against the base branch. The action bar always offers the next one as
its main button.

1. **Claude commits on its own.** When a self-contained part is done and its
   tests pass, Claude commits it with a Conventional Commits message
   (`feat: …`, `fix: …`) and a `Co-Authored-By: Claude` trailer. The commit
   carries the **task owner** as author. Claude never pushes and never opens a
   pull request.
2. **Commit** commits whatever is still uncommitted. Claude drafts the message
   from exactly these changes and the conversation, on **your** subscription;
   without one, or if it fails, you write it yourself. The commit carries your
   name and e-mail. *Then push and open the pull request* goes straight on to
   step 3.
3. **Push & PR** pushes the branch. Without a pull request yet, Claude drafts
   its title and description from the whole branch and the conversation —
   *What*, *Why*, *Testing* — and you edit them before it opens. A linked
   ticket is referenced (see [Tickets](TICKETS.md#closing-on-merge)); *Open as
   draft* opens a draft pull request. With a pull request open, **Push** only
   confirms how many commits go out and comments on the pull request.

- The push and the pull request use the workspace's repository access; the
  pull request says who opened it.
- Uncommitted files stay behind when you push — the push dialog says so.
- If the remote branch moved, the push is refused. When the pull request
  reports conflicts or a base branch that moved on, **Ask Claude to rebase**
  (header menu, and *Rebase by Claude* in the sidebar) asks Claude to fetch,
  rebase and resolve conflicts.
- From then on the sidebar follows the pull request — see
  [Pull Requests](PULL-REQUESTS.md).
- [Automatic tasks](#automatic-tasks), the API and MCP do both steps at once.

The owner, collaborators and workspace admins may ship.

## Automatic tasks

An **automatic task** works without a human: it plans (optionally),
implements, checks the [quality gates](#quality-gates), fixes failures and
ships. You can watch it live and take over at any time.

**Starting one**

- In a workspace: **New task → Run automatically**, then describe the work.
- From outside: the [REST API](REST-API.md#start-a-task)
  (`POST /api/v1/workspaces/{workspace}/tasks`), [MCP](SETUP-MCP.md)
  (`task_create`), or an issue of a connected tracker
  ([issue triggers](SETUP-TASK-PROVIDERS.md#issue-to-automatic-task)).

**How it runs**

1. **Plan** (per the *Plan* setting): *off* implements right away; *automatic*
   plans first and continues on its own; *wait for approval* stops at the plan
   until someone approves it — on the plan card, via the API or MCP, or with 👍
   on the issue comment. Rejecting with feedback makes Claude revise the plan.
2. **Implement** in *Bypass permissions* mode — nobody is there to answer
   approvals; file tools outside the worktree are refused. If Claude asks a
   question, it is told to decide itself and state its assumption.
3. **Gates** after each turn. Failing gates go back to Claude with the fix
   prompt, up to *Gate fix rounds* times; then it ships anyway and the pull
   request says which gates still fail.
4. **Ship** per *When done*: pull request, draft pull request, or commit and
   push only. Then the session ends; the container keeps running for the other
   tasks.

**Taking over.** Writing into a running automatic task pauses the automation.
**Continue automatically** (play button in the header) hands it back: it
checks the gates and ships. **Pause** stops it without writing.

**Budgets.** *Max. turns*, *max. cost* and *max. runtime* stop a run
(*Budget exceeded*); the work so far stays. When the subscription hits its
usage limit, the run continues once the limit is lifted.

**Who it runs as.** An automatic task belongs to a person: whoever started it
in the UI, the signed-in MCP user, the API client's **Runs as** user, the
workspace's **automation user** (workspace tokens, issue triggers) — or, for
an issue, its assignee when they agreed in their profile (see
[Tickets](TICKETS.md#automatic-tasks-from-issues)).

Defaults come from **Settings → Automation**; all settings are listed in
[Configuration](CONFIGURATION.md#automatic-tasks).

## Finishing a task

| Action | Who | Effect |
|---|---|---|
| Pull request merged | — | The task is **Done**. |
| Pull request closed without merge | — | The task is **Cancelled**. |
| **Cancel task** | owner, workspace admins | The task ends without a merge; its pull request stays as it is. |
| **Archive** | owner, workspace admins | The task leaves the list (session closed) and stays readable in the archive; **Restore** brings it back. |
| **Delete task** | owner, workspace admins | Conversation, worktree, database and preview are deleted for good. Remote branch and pull request stay. |
| **Follow-up task** | admins, members | Continues a finished task: same ticket, a fresh branch from the current base, a link to the earlier task, and a prefilled first message summarising it. |

Finished tasks refuse new messages — start a follow-up task to continue.

## Cleanup

`cleanup_after_days` (Settings → Resources, default 14) after a task is done
or cancelled, Argos removes its preview, worktree, local branch and database
(daily at 03:30, `argos:tasks:cleanup`). The conversation, the remote branch
and the pull request stay. Tasks of a stopped workspace are cleaned up on a
later run once it is running.

## Notifications

The owner and collaborators of a task are notified (except the person who
caused it), per their profile settings:

| Kind | When |
|---|---|
| Claude waits for a decision | an approval, plan or question appears |
| Claude finished a longer turn | a turn took more than 30 seconds |
| A subscription hit its usage limit | the task's agent reports its limit |
| New comments on the ticket | a comment on the task's ticket arrived |
| Errors | a task fails |
| Automatic runs | a plan waits, a run is done, failed or hit its budget |
| Someone shipped a task | commits were pushed, or the pull request's pipeline, reviews or state changed |
| Invited or handed over | you were invited to a task or it was handed to you |

Everything except finished turns also lands in the notification bell. In the
browser a notification (and a counter in the tab title) only shows when that
task is not open in front of you; allow browser notifications in your
**profile**, where you can also switch each kind off.
