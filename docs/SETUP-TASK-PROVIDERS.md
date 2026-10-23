# Issue Triggers

A workspace can be connected to an issue tracker. Its issues can then be
picked as [tickets](TICKETS.md) of tasks, and a new matching issue starts an
**automatic task** in the workspace on its own: Claude plans, implements,
fixes failing quality gates and opens a pull request. Argos reports on the
issue as it goes, closes it on merge where the integration says so, and an
authorized 👍 on the plan comment approves the plan.

This page is for workspace admins wiring a tracker to a workspace. It assumes
the provider connection (OAuth or a Personal Access Token) already exists; see
the provider guides below. Own ticket systems (REST + signed webhook) are
described in [Tickets → Own ticket systems](TICKETS.md#own-ticket-systems).

## Contents

- [Supported providers](#supported-providers)
- [How the integration works](#how-the-integration-works)
- [Prerequisites](#prerequisites)
- [Creating an integration](#creating-an-integration)
- [Webhook mode](#webhook-mode)
- [Poll mode](#poll-mode)
- [Issue to automatic task](#issue-to-automatic-task)
- [Comments and plan approval](#comments-and-plan-approval)
- [Filtering](#filtering)
- [Environment variables](#environment-variables)
- [Troubleshooting](#troubleshooting)

## Supported providers

- **GitHub** — see [SETUP-GITHUB.md](SETUP-GITHUB.md)
- **GitLab** (including self-hosted) — see [SETUP-GITLAB.md](SETUP-GITLAB.md)
- **Linear** — see [SETUP-LINEAR.md](SETUP-LINEAR.md)
- **Own system** — tasks come in over the REST API, events go out as a signed
  webhook; see [Tickets](TICKETS.md#own-ticket-systems).

Bitbucket can host a workspace's repository
([SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)), but it is **not** available as an
issue tracker — there is no Bitbucket option in the integration form.

## How the integration works

An integration lives on one workspace and points at one external project
(GitHub/GitLab repository) or team (Linear). It does four things:

1. **Tickets** — the open issues can be picked, linked and created from tasks
   (the workspace's first active integration is used for that).
2. **Ingest** — a new issue (received via webhook, or fetched by the poller)
   that matches the filters starts an automatic task in the workspace, once
   per issue.
3. **Feedback** — Argos comments on the issue: task started, plan waiting, pull
   request, run failed or out of budget, done or cancelled; new comments on the
   issue show up in the task. See [Tickets](TICKETS.md#what-the-ticket-hears-back).
4. **Plan approval** — with the plan setting *wait for approval*, an
   authorized 👍 on the plan comment approves the plan. Reactions are not
   pushed by the providers, so Argos polls for them.

The link between an issue and its task is recorded as an *External Issue
Link*.

## Prerequisites

- A provider connection — a **Connected Account** (OAuth) or a stored
  **Access Token (PAT)**. See [SETUP-GITHUB.md](SETUP-GITHUB.md),
  [SETUP-GITLAB.md](SETUP-GITLAB.md) or [SETUP-LINEAR.md](SETUP-LINEAR.md).
- The workspace, and an **automation user** in its settings (Settings →
  Automation) unless every assignee agreed to own their tasks (see
  [Issue to automatic task](#issue-to-automatic-task)).
- The scheduler running — for poll mode, plan approval and ticket comments.

The OAuth scopes you already grant for GitHub (`repo`) and GitLab (`api`)
cover webhook management. For Linear, registering a webhook requires an
organisation member with sufficient permissions; see
[SETUP-LINEAR.md](SETUP-LINEAR.md).

## Creating an integration

1. Open the workspace's **Settings → Integrations** (workspace admins and
   Argos administrators).
2. Under **Issue trackers** choose **New**.
3. Fill in the fields:

| Field | Description |
|---|---|
| **Provider** | GitHub, GitLab, Linear or Own system |
| **Mode** | *Webhook (push)*, *Polling* or *Disabled* |
| **Access** | The OAuth account **or** stored access token (PAT) for this provider |
| **Project / Team** | Loaded from the chosen access. GitHub/GitLab: a repository (`owner/repo`); Linear: a team |
| **Labels filter** | Optional: only issues carrying at least one of these labels start a task |
| **Close the ticket on merge** | On by default; see [Closing on merge](TICKETS.md#closing-on-merge) |

4. Save → the integration is created in status **Pending**.
5. Run **Set up** on its row → the integration becomes **Active**.

**Set up** per mode:

- **Polling** — marks the integration **Active**; no provider call.
- **Webhook (push)** — generates a webhook secret, registers the webhook with
  the provider, stores its id + secret and marks the integration **Active**.
  If registration fails, the error is shown in **Last error** and it stays
  Pending.
- **Disabled** — sets it back to Pending and clears a stored webhook.

For GitHub and GitLab, bind the integration to the **workspace's own
repository** if pull requests should close its issues (`Closes #` only refers
to issues of the pull request's repository).

## Webhook mode

Webhook mode delivers issues in real time. The inbound endpoint is a single,
session-less route; the request is authenticated by the provider's signature:

```
POST  ${APP_URL}/webhooks/issues/{provider}/{integration-id}
```

`{provider}` is `github`, `gitlab` or `linear`. `APP_URL` must be publicly
reachable for the provider to deliver.

**Set up** registers the webhook for you (GitHub and GitLab per repository,
Linear as an organisation webhook for `Issue` resources). To register it by
hand (e.g. an org policy blocks API registration), use the secret stored by
**Set up**:

**GitHub** — Repository → Settings → Webhooks → Add webhook:

- Payload URL: `${APP_URL}/webhooks/issues/github/<integration-id>`
- Content type: `application/json`
- Secret: the generated secret (verified via `X-Hub-Signature-256`)
- Events: **Issues** (Argos also accepts `issue_comment`; other events are
  acknowledged and ignored)

**GitLab** — Project → Settings → Webhooks:

- URL: `${APP_URL}/webhooks/issues/gitlab/<integration-id>`
- Secret token: the generated secret (matched against `X-Gitlab-Token`)
- Trigger: **Issues events**

**Linear** — registered automatically:

- URL: `${APP_URL}/webhooks/issues/linear/<integration-id>`
- Signature: raw HMAC-SHA256 hex digest in `Linear-Signature`

Deliveries are deduplicated by the provider's delivery id
(`X-GitHub-Delivery` / `X-Gitlab-Event-UUID` / `Linear-Delivery`) for 24h.

## Poll mode

Argos fetches issues on a schedule instead; no webhook is registered and
`APP_URL` need not be public. The scheduler polls every **Active, Poll-mode**
integration (`argos:poll-issues`) every `ARGOS_POLL_INTERVAL_MINUTES`
(default **5**, clamped to **1–59**). The same interval drives the
plan-approval check (`argos:check-plan-approvals`) and the ticket comments
(`argos:tasks:sync-ticket-comments`).

```bash
php artisan argos:poll-issues
php artisan argos:check-plan-approvals
```

## Issue to automatic task

When a matching issue is seen for the first time, Argos starts an automatic
task in the integration's workspace:

- The task is titled after the issue and linked to it as its ticket; its
  branch follows the workspace's branch pattern, by default
  `<ticket key>-<title>` (e.g. `github-42-fix-login`, `eng-118-fix-login`).
- The prompt is the issue's title, text and link, marked as untrusted input.
- Plan mode, ship mode and budgets come from **Settings → Automation**.
- **Owner:** the issue's assignee, when they are a workspace admin or member
  with a usable Claude subscription and agreed in their profile (*Issues
  assigned to me may start automatically on my subscription*); otherwise the
  workspace's **automation user**, with the assignee invited as a
  collaborator. Without either nothing starts and **Last error** says so.

Launching is "once, ever" per issue: an issue first seen *not* matching the
filters and labelled later still starts a task, but a task you *delete* is
**not** re-created. If the task cannot start (no usable agent, too many
workspaces running), the next poll or delivery retries.

## Comments and plan approval

Argos comments on the issue as described in
[Tickets → What the ticket hears back](TICKETS.md#what-the-ticket-hears-back).
If posting fails (e.g. an expired token), the error is logged and the task
continues.

**Plan approval.** With *Plan, then wait for approval*, the plan is posted as a
comment. When the plan-approval check finds a 👍 (`+1` / `thumbsup` / `👍`) on
it from a user who is **authorized to approve**, the run continues. You can
also approve in Argos, via the REST API or MCP.

- **GitHub / GitLab** — the reacting user has write/admin access on the
  repository.
- **Linear** — an active, non-guest organisation member.

The check needs the scheduler even in webhook mode, since providers do not
push reaction events.

## Filtering

- **Labels filter** — OR semantics: an issue starts a task only if it carries
  at least one of the labels. Without labels, all issues pass.
- A filter on the issue **state** (e.g. only open issues) is stored alongside.

An issue that does not pass still gets an External Issue Link (so its state is
tracked), but no task starts. Picking issues as tickets in the UI is not
affected by the filters.

## Environment variables

The credential comes from the selected OAuth account or PAT. Relevant:

```env
# Base of the inbound webhook URL; must be public for webhook mode.
APP_URL=https://argos.example.com

# Poll interval (minutes) for issues, plan reactions and ticket comments. 1–59.
ARGOS_POLL_INTERVAL_MINUTES=5
```

The scheduler (`php artisan schedule:work`, or a cron entry running
`schedule:run`) must be active.

## Troubleshooting

- **Set up fails / integration stays Pending** — read **Last error**. Common
  causes: the credential lacks webhook permission, or `APP_URL` is not
  reachable.
- **No tasks start** — confirm the scheduler is running (poll mode), the
  integration is **Active**, the workspace has an **automation user** with a
  usable agent (or the assignee consented), and the **Labels filter** does not
  exclude the issues. **Last poll** shows when it was last fetched.
- **Webhook deliveries rejected (401)** — the signature/secret does not match.
  Run **Set up** again, or copy the stored secret into the provider.
- **👍 does not approve the plan** — the task must wait for approval, the
  reaction must be 👍 on Argos' plan comment, the reacting user must be
  authorized, and the scheduler must run the approval check.
- **The issue is not closed on merge** — *Close the ticket on merge* is off,
  or (GitHub/GitLab) the issue lives in another repository than the pull
  request.
