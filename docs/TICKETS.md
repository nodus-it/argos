# Tickets

A [task](TASKS.md) can be linked to **exactly one ticket** — an issue in
GitHub, GitLab or Linear, or a ticket of your own system. The ticket gives the
task its title and description, names its branch, and hears back what
happens: started, plan waiting, pull request, done or cancelled. The switcher,
the task header and [My work](WORKSPACES.md#the-app-and-its-addresses) always show the
ticket as a link (*GitLab #42*, *ENG-118*).

Tickets come from the workspace's **integrations** (Settings → Integrations →
Issue trackers). How to connect a tracker, and how a new issue starts an
automatic task on its own, is described in
[Issue Triggers](SETUP-TASK-PROVIDERS.md).

## Contents

- [Picking, linking and creating a ticket](#picking-linking-and-creating-a-ticket)
- [What the ticket hears back](#what-the-ticket-hears-back)
- [Closing on merge](#closing-on-merge)
- [Ticket comments in the task](#ticket-comments-in-the-task)
- [Hand-over and assignee](#hand-over-and-assignee)
- [Automatic tasks from issues](#automatic-tasks-from-issues)
- [Own ticket systems](#own-ticket-systems)
- [Provider support](#provider-support)

## Picking, linking and creating a ticket

The ticket functions use the workspace's first **active** GitHub, GitLab or
Linear integration that has a project or team set.

- **When starting a task:** the *Ticket* field of **New task** searches the
  open issues by title or number. The task takes the issue's title, its branch
  gets the ticket key (`gitlab-42-…`, `eng-118-…`), and the composer is
  **prefilled — not sent** — with the issue's title, text and link, wrapped as
  untrusted input so Claude treats it as data, not as instructions.
- **Afterwards:** **⋯ → Link a ticket** links an existing issue to a task that
  has none (the issue gets a comment with the task link).
- **From the task:** **⋯ → Create a ticket** opens a new issue in the tracker
  with your title and description (plus a link back to the task) and links it.
- Through the [REST API](REST-API.md#start-a-task) (`issue`) and
  [MCP](SETUP-MCP.md) (`task_create` with `issue`): the ticket number of the
  workspace's tracker.

Linking and creating need write access to the task (owner, collaborators,
workspace admins). A task's ticket cannot be changed once set; a
[follow-up task](TASKS.md#finishing-a-task) keeps the same ticket.

## What the ticket hears back

Argos comments on the ticket (own systems: a [webhook](#own-ticket-systems))
when

| Moment | Comment |
|---|---|
| The task starts with the ticket | *Argos is working on this issue. Follow it live: …* |
| A ticket is linked afterwards | *This issue is now linked to a task in Argos: …* |
| An automatic task's plan waits for approval | The plan; a 👍 on this comment approves it (see [plan approval](SETUP-TASK-PROVIDERS.md#comments-and-plan-approval)) |
| The pull request is opened | The pull request link |
| An automatic run failed or used up its budget | The reason and the task link |
| The task is done (merged) or cancelled | *Done — the pull request is merged …* / *… cancelled without a merge …* |

Failures to post are logged and never stop the task.

## Closing on merge

Each integration has **Close the ticket on merge** (on by default):

| Tracker | On | Off |
|---|---|---|
| GitHub, GitLab | The pull request says `Closes #<number>`, so the provider closes the issue when the pull request is merged. | The pull request says `Refs #<number>`; the issue stays open. |
| Linear | Argos closes the issue through the API once the task is done. | The issue stays open; the pull request carries the issue link. |
| Own system | Argos sends the `done` event; closing is up to your system. | Same. |

The done/cancelled comment is posted either way. `Closes #` refers to an issue
of the pull request's own repository — bind the GitHub/GitLab integration to
the workspace's repository if Argos should close issues there.

## Ticket comments in the task

New comments on the ticket of an open task (running or with an open pull
request) show up in the task as a hint with author and text — they are
**never sent to Claude** by themselves; pass on what matters. The owner and
collaborators get a notification (*New comments on the ticket of my tasks*).

- GitHub, GitLab, Linear: read every `ARGOS_POLL_INTERVAL_MINUTES` (default 5,
  `argos:tasks:sync-ticket-comments`). The first look only remembers where the
  conversation stands; Argos' own comments are skipped.
- Own systems: post them with
  [`POST /api/v1/tasks/{task}/ticket-comments`](#posting-comments).

## Hand-over and assignee

When a task is [handed over](TASKS.md#working-together), the ticket's
assignee follows where the tracker can:

| Tracker | New assignee |
|---|---|
| GitHub, GitLab | The new owner's login of their connected GitHub/GitLab account (OAuth). Without a connected account nothing changes. |
| Linear | The Linear user with the new owner's e-mail address. |
| Own system | The `owner_changed` event with the new owner's name and e-mail. |

## Automatic tasks from issues

When an [issue trigger](SETUP-TASK-PROVIDERS.md) starts an automatic task, the
task belongs to

- the **issue's assignee**, if that person is a workspace admin or member with
  a usable Claude subscription **and** agreed in their profile: *Issues
  assigned to me may start automatically on my subscription*;
- otherwise the workspace's **automation user** (Settings → Automation) — and
  the assignee, if they are in the workspace, is invited as a collaborator.

Assignees are matched by the login of their connected account (GitHub,
GitLab) or by e-mail (Linear). Without an automation user and without a
consenting assignee nothing starts; the integration's *Last error* says so.

## Own ticket systems

A help desk, an in-house tracker or anything else can drive tasks through the
REST API and hear back through a signed webhook.

### Connecting

In **Settings → Integrations → Issue trackers → New**, pick **Own system**:

| Field | Meaning |
|---|---|
| System name | How the system is called in Argos, e.g. *Support desk*. |
| Webhook URL | Where Argos posts the task events (optional — without one nothing is sent). |
| Signing secret | Generated when the integration is saved; shown (read-only) when you edit it. |
| Close the ticket on merge | Shown for every integration; own systems receive the `done` event either way. |

Create an [API token](REST-API.md#authentication) with `tasks:write` (and
`tasks:read` to poll) for the system — a workspace token (Settings →
Integrations → API tokens) or a token of an API client.

### Starting a task with a ticket

`POST /api/v1/workspaces/{workspace}/tasks` with a `ticket` object starts an
automatic task linked to your ticket (the workspace's first *Own system*
integration):

```json
{
  "prompt": "Fix the export for the customer described in the ticket.",
  "ticket": {
    "key": "SUP-1234",
    "title": "CSV export drops umlauts",
    "url": "https://desk.example.com/tickets/1234",
    "body": "Customer reports …"
  }
}
```

`ticket.key` (max. 64 characters) and `ticket.title` are required, `url` and
`body` optional; `ticket` cannot be combined with `issue`. The key names the
branch (`sup-1234-…`). All other fields of the endpoint apply — see
[REST API](REST-API.md#start-a-task).

### Posting comments

`POST /api/v1/tasks/{task}/ticket-comments` (`tasks:write`) shows a comment of
your ticket as a hint in the task and notifies the task's people:

| Field | Required | Meaning |
|---|---|---|
| `author` | yes | Name shown with the comment (max. 120 characters) |
| `body` | yes | The comment (shown up to 2,000 characters) |
| `url` | no | Link to the comment |

Answers `202` with an empty body.

### Webhook events

For every event Argos sends a `POST` to the webhook URL:

- Headers: `Content-Type: application/json`, `X-Argos-Event: <event>`,
  `X-Argos-Signature: sha256=<hex>`.
- The signature is the HMAC-SHA256 of the **raw request body** with the
  integration's signing secret, hex-encoded, prefixed with `sha256=`. Compare
  it in constant time before trusting the request.
- Timeout 10 seconds; on an error Argos retries up to three more times (after
  10 s, 60 s and 5 min). Failures are logged, never shown to the task.

| `event` | When | Extra fields |
|---|---|---|
| `started` | the task was created with the ticket | — |
| `plan_waiting` | an automatic task's plan waits for approval | `plan` |
| `pull_request` | the pull request was opened | `pull_request_url` |
| `run_failed` | an automatic run stopped with an error | `detail` |
| `run_budget_exceeded` | an automatic run used up its budget | `detail` |
| `done` | the pull request was merged | — |
| `cancelled` | the pull request was closed without merge, or the task was cancelled | — |
| `owner_changed` | the task was handed over | `owner` (`name`, `email`) |

Every payload has the same frame:

```json
{
  "event": "pull_request",
  "ticket": { "key": "SUP-1234", "url": "https://desk.example.com/tickets/1234" },
  "task": {
    "id": "01J…",
    "title": "CSV export drops umlauts",
    "status": "pr_open",
    "url": "https://argos.example.com/shop-api/fix-login",
    "branch": "sup-1234-csv-export-drops-umlauts",
    "pull_request": { "url": "https://github.com/acme/app/pull/7", "number": 7, "state": "open" }
  },
  "pull_request_url": "https://github.com/acme/app/pull/7",
  "sent_at": "2026-10-21T14:00:00+00:00"
}
```

`task.status` is the task's status as in the REST API (`preparing`, `running`,
`waiting`, `ready`, `pr_open`, `done`, `cancelled`, `archived`);
`task.pull_request` is `null` until a pull request exists.

Verifying the signature, e.g. in PHP:

```php
$expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
if (! hash_equals($expected, $request->header('X-Argos-Signature'))) {
    abort(401);
}
```

## Provider support

| | GitHub | GitLab | Linear | Own system | Bitbucket |
|---|---|---|---|---|---|
| Pick / link / create a ticket in the UI | yes | yes | yes | — (REST `ticket`) | no |
| Feedback | comments | comments | comments | webhook | — |
| Close on merge | `Closes #` in the pull request | `Closes #` in the pull request | by Argos | `done` event | — |
| Ticket comments in the task | polled | polled | polled | REST | — |
| Assignee follows the owner | connected account login | connected account login | e-mail | `owner_changed` event | — |
| Issue starts an automatic task | yes | yes | yes | REST | no |

Bitbucket is supported as a code host (pull requests), not as a ticket
system.
