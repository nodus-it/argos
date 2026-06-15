# Tasks & the Workflow

A **Task** is the central unit of work in Argos. You describe a change you want
made to a repository, and Argos drives it through a fixed sequence of phases —
**Concept → Implement → Push (Pull Request)** — pausing at each step so you can
review before moving on. After the pull request is open you can keep iterating
with review feedback (**Respond**).

This document explains, from a user's point of view, what a task is, how to
create one, what each phase does, what you get to review, and every status a
task can show along the way — including how to pause, resume, retry, abort, and
give feedback.

For the underlying worker commands and how phases run inside the Docker worker,
see [EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md). For projects/repo profiles
that supply the defaults a task inherits, see [PROJECTS.md](PROJECTS.md). For
the agent/model that does the work, see [AGENTS.md](AGENTS.md).

## Contents

- [What a Task is](#what-a-task-is)
- [Creating a Task](#creating-a-task)
- [The lifecycle at a glance](#the-lifecycle-at-a-glance)
- [The phases](#the-phases)
  - [Concept](#concept)
  - [Implement](#implement)
  - [Push & Pull Request](#push--pull-request)
  - [Respond (review feedback)](#respond-review-feedback)
- [Working on the branch yourself](#working-on-the-branch-yourself)
- [Statuses & stages](#statuses--stages)
- [Pausing & resuming (the turn limit)](#pausing--resuming-the-turn-limit)
- [Retrying a failed phase](#retrying-a-failed-phase)
- [Force-unlock a stuck implement run](#force-unlock-a-stuck-implement-run)
- [Aborting a running task](#aborting-a-running-task)
- [Completing a task](#completing-a-task)
- [The live demo](#the-live-demo)
- [Tasks created from issues](#tasks-created-from-issues)

## What a Task is

A task ties together:

- a **name** — a unique slug that also shows up in URLs, the Docker workspace
  volume name, and the feature-branch prefix;
- a **project** (repo profile) — which repository to work in. The worker
  stack, agent, models, and base branch all inherit from the project default
  unless you override them on the task;
- a **description** — what should change, *why*, and how you'll know it worked.
  Concrete acceptance criteria lead to better results;
- a private **Docker workspace volume** that holds the cloned repository and
  the agent's working state for the life of the task.

Each task carries its own progress (current phase, status), its produced
artifacts (the concept, implementation summaries, the diff, the pull-request
URL), and a per-iteration log you can scroll back through.

## Creating a Task

Open the Tasks list at `${APP_URL}/admin/tasks` and choose **New task**. The
form has two tabs:

**General**

- **Name** — the unique slug (see above).
- **Project** — the repo profile to work in.
- **Description** — the change to make, with acceptance criteria.
- **Start concept immediately** — when on, the Concept phase starts right after
  creation (auto-concept). When off, the task is created as a **Draft** and you
  start the concept yourself.
- **Base branch (override)** — branch to base the work on. Empty uses the
  project default.

**Worker & models** (all optional overrides; empty inherits the project/agent
default)

- **Worker stack**, **Agent**, and **Agent credential** — the runtime and
  identity that executes the task (see [AGENTS.md](AGENTS.md)).
- **Concept model** / **Implement model** — the Claude model used per phase.
  Resolution is task override → project default → Argos default.
- **Max turns for Concept** / **Max turns for Implement** — the upper limit on
  tool calls per run. Empty uses the default. This is the budget that, when
  exhausted, *pauses* a phase rather than failing it (see
  [Pausing & resuming](#pausing--resuming-the-turn-limit)).

When you save, Argos creates the workspace volume and — if auto-concept is on —
immediately queues the Concept phase.

## The lifecycle at a glance

Phases run **asynchronously** in the worker. When you start one, the task moves
to a *queued* state until a worker picks it up, then *running*, then settles
into a *review* state waiting for your decision. The order is strict and
forward-only:

```
Draft
  │  start concept
  ▼
Concept ──► (review the concept) ──► Implement ──► (review the implementation)
  │                                                          │
  │                                                          ▼
  │                                              Push & Pull Request
  │                                                          │
  │                                                          ▼
  │                                              In review (PR is open)
  │                                                  │           ▲
  │                                                  │ feedback  │
  │                                                  ▼           │
  │                                              Respond ────────┘
  │                                                  │
  ▼                                                  ▼ mark complete
                                                  Completed
```

Two important rules:

- **The concept is locked once implementation starts.** You cannot go back to
  the Concept phase after the first Implement run — refine the implementation
  instead.
- **Push runs automatically after a successful Implement** when a pull request
  already exists (i.e. on later iterations). The first time, you trigger
  **Push & PR** yourself from the review dock.

## The phases

You review every phase from the task detail page at
`${APP_URL}/admin/tasks/{id}`. At the bottom of that page is the **review dock**:
a composer that shows the right buttons and hint for the current stage. The
header carries only the few actions the dock doesn't own (resume a paused
phase, release a lock, complete after the PR) plus a `⋯` menu with auxiliary
actions (logs, demo controls, abort).

### Concept

The agent analyses your description against the repository and drafts a plan:
what it intends to change and the next steps. It creates the feature branch
during this phase. No code is changed yet.

**What you review:** the proposed concept, on the **Concept** tab. In the review
dock you can:

- **Update concept** — type what should change or be added and re-run the
  concept with your notes as feedback (this is editing the concept, not just
  leaving a comment); or
- **Start implementation** — accept the concept and move on.

### Implement

The agent applies the actual code changes on the feature branch. By default it
starts from a clean checkout of the base branch; when you **refine** a reviewed
implementation it builds on the previous iteration's working tree instead of
resetting. After the agent finishes, the worker re-runs the project's **quality
gates** (formatter, tests, static analysis where configured) as a blocking
verification step.

**What you review:** the **Implementation** tab (a plain-language summary and a
technical summary) and the **Diff** tab. In the review dock you can:

- **Refine implementation** — type what should change and re-run implement on
  top of the current work; or
- **Create Push & PR** — accept the implementation and open the pull request.

A [live demo](#the-live-demo) is built automatically after a successful
implement run when the project has demos enabled.

### Push & Pull Request

The worker generates a commit message, commits the changes, pushes the feature
branch to the remote, and opens (or updates) the pull request.

**What you review:** the **Pull Request** tab shows the PR link. The task moves
to **In review** — open the PR in your git host and review it there. From here
you either complete the task or send feedback.

### Respond (review feedback)

When a PR is open and you want changes, use **Review feedback** (reachable from
the in-review task). You write your feedback; Argos hands it to the agent, which
incorporates *only the addressed points* into the existing feature branch — no
unrelated refactoring — and the pull request is brought up to date. The task
returns to **In review** so you can read the result and, if needed, send more
feedback. Repeat until you're satisfied, then complete the task.

## Working on the branch yourself

Once the feature branch is on the remote (after a **Push**), you can check it
out, edit it, and push your own commits — just like any branch. When you next
continue the task (a **refine** implement, or **Review feedback**/Respond),
Argos **pulls the branch first** and works on top of your commits, so your
manual changes are kept and built upon. A **rebuild of the live demo** likewise
reflects the pushed remote state.

Two things to know:

- This applies to the **continuing** runs (refine, respond). A deliberate
  **fresh re-implement** rebuilds the change from the base branch and would
  supersede external commits — use refine/respond when you want your manual work
  carried forward.
- If you push to the branch *while* a phase is running, that run will refuse to
  push over your commits and fail with a clear message — just start the task
  again and it picks up your changes.

## Statuses & stages

Internally a task has a persisted workflow status plus a current phase/status.
The UI collapses these into a single **stage** shown in the status banner. This
is what you actually see and act on:

| Stage (banner) | Meaning | Your action |
| --- | --- | --- |
| **Draft** | Created, concept not started yet. | Optionally add guidance, then **Start concept**. |
| **Concept waiting for worker** | Concept queued; waiting for a free worker. | Wait. |
| **Concept running** | The agent is drafting the concept. | Wait (or **Abort**). |
| **Concept paused (turn limit)** | Concept hit its turn budget mid-run. | **Continue concept** with a fresh budget. |
| **Review concept** | Concept ready. | **Update concept** or **Start implementation**. |
| **Concept failed** | The concept run errored. | **Try again** from the dock. |
| **Implementation waiting for worker** | Implement queued. | Wait. |
| **Implementation running** | The agent is changing code. | Wait (or **Abort**). |
| **Implementation paused (turn limit)** | Implement hit its turn budget. | **Continue** with a fresh budget. |
| **Review implementation** | Code + summaries + diff ready. | **Refine implementation** or **Create Push & PR**. |
| **Implementation failed** | Implement errored (or is lock-blocked). | **Try again**, or **Release lock** if blocked. |
| **Push waiting for worker** | Push & PR queued. | Wait. |
| **Push & PR running** | Committing, pushing, opening the PR. | Wait. |
| **Push failed** | The push/PR step errored. | **Try again** from the dock. |
| **Pull request created** | PR is open; in review. | Review the PR, then **Complete** or send feedback (**Respond**). |
| **Completed** | Task finished; workspace removed. | Terminal. |
| **Aborted** | Manually stopped. | Terminal, read-only. |

Notes:

- *Waiting for worker* (queued) and *running* are distinct: queued means the
  job is dispatched but no worker has picked it up yet.
- While a phase is **running or queued**, the review dock and phase controls are
  hidden — only the `⋯` menu (recovery + logs) and **Abort** remain.

## Pausing & resuming (the turn limit)

Each run has a **max-turns** budget (the cap on tool calls). If a Concept or
Implement run reaches that budget before finishing, it **pauses** rather than
fails — the workspace and the agent session are preserved.

To continue, use the header's **Continue** (implement) or **Continue concept**
action. It opens a modal pre-filled with a fresh turn budget; resuming continues
the same Claude session with full context, so no work is lost.

If a phase has hit the turn limit **repeatedly** (the agent isn't converging),
Argos warns you in the resume modal — consider narrowing the task or raising
max-turns substantially instead of just resuming again.

## Retrying a failed phase

When a phase ends in an error, the task shows a *failed* stage and the banner
links to the log. The review dock offers **Try again**, which re-runs the same
phase. (A failed concept retry passes your existing notes as feedback; a failed
implement retry starts from a clean checkout.)

## Force-unlock a stuck implement run

If a worker container crashes, the worker lock can be left set, which shows up
as an **Implementation failed** stage with a "blocked by a lock" hint. Use
**Release lock** (in the header / `⋯` menu) to clear the lock and restart the
implement phase. Only do this when you're sure no worker is still running.

## Aborting a running task

While a phase is running or queued, the `⋯` menu offers **Abort**. This
immediately hard-kills the running worker (and any sidecar containers) so the
phase stops at once, closes the in-flight run, and moves the task to the
**Aborted** terminal state.

The workspace **volume is kept** (so you can still inspect it); it is only
removed when you delete the task. Aborted is read-only — there is no dock and no
phase controls. To act again, create a new task.

## Completing a task

When you're happy with the pull request, use **Complete** (the header primary in
the *Pull request created* stage). This marks the task **Completed**, deletes
its Docker workspace volume, and — if the task came from an external issue —
closes the source issue. Both the volume removal and completion are
irreversible.

## The live demo

If the project has live demos enabled, Argos automatically builds a running
preview of the implemented branch after each successful Implement run. The
**Live demo** panel on the task page shows the URL and expiry, and lets you
rebuild, restart, stop, or change the demo's access protection. The demo is
torn down after the pull request, but you can restart it anytime.

See [LIVE-DEMOS.md](LIVE-DEMOS.md) for how demos are built, exposed, and
protected.

## Tasks created from issues

Tasks can also be created automatically from issues in a connected issue
tracker (GitHub, GitLab, Linear, …). Such a task carries a link back to its
source issue (shown in the task header), and completing the task can close that
issue. See [SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md) for connecting an
issue source and how inbound issues become tasks.
