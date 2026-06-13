# Task-Provider Integration (Issue Trackers)

Argos can connect a project to an external issue tracker. New issues are
imported as Argos tasks, Argos posts its phase results back onto the source
issue as comments, an authorized 👍 reaction on the concept comment starts the
implement phase, and — if you opt in — the source issue is closed when the task
is done.

This page is for operators wiring an issue tracker to an Argos project. It
assumes the provider connection (OAuth or a Personal Access Token) already
exists; the provider-specific setup is covered in the linked guides below.

## Contents

- [Supported providers](#supported-providers)
- [How the integration works](#how-the-integration-works)
- [Prerequisites](#prerequisites)
- [Creating a binding](#creating-a-binding)
- [Webhook mode](#webhook-mode)
- [Poll mode](#poll-mode)
- [Issue to task](#issue-to-task)
- [Concept write-back and the approve gate](#concept-write-back-and-the-approve-gate)
- [Status sync on completion](#status-sync-on-completion)
- [Filtering](#filtering)
- [Environment variables](#environment-variables)
- [Troubleshooting](#troubleshooting)

## Supported providers

As task providers (issue ingest, write-back, approve gate, status sync):

- **GitHub** — see [SETUP-GITHUB.md](SETUP-GITHUB.md)
- **GitLab** (including self-hosted) — see [SETUP-GITLAB.md](SETUP-GITLAB.md)
- **Linear** — see [SETUP-LINEAR.md](SETUP-LINEAR.md)

Bitbucket can be connected to Argos as a *code* provider
([SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)), but it is **not** wired as a task
provider — there is no Bitbucket option in the binding form, and webhook
registration / signature verification are not implemented for it. Do not expect
issue ingest from Bitbucket.

## How the integration works

End to end, a task provider does four things:

1. **Ingest** — a new issue (received via webhook, or fetched by the poller)
   that matches the binding's filters becomes a new Argos task.
2. **Write-back** — when an Argos phase finishes, Argos posts a comment on the
   source issue with the phase result (concept text, implement summary, or the
   pull/merge request link).
3. **Approve gate** — when the concept phase finishes, the concept comment is
   the review surface. An authorized 👍 reaction on that comment starts the
   implement phase. Reactions are not pushed by the providers, so Argos polls
   for them.
4. **Status sync** (opt-in) — when the Argos task is marked completed, the
   source issue is closed/resolved.

Each binding lives on one project (Repo Profile) and points at one external
project/team. The connection between an imported task and its source issue is
recorded as an *External Issue Link* — that record carries the task id, the
concept comment id, and the last sync timestamp.

## Prerequisites

- A provider connection for the project's provider — either a **Connected
  Account** (OAuth) or a stored **Access Token (PAT)**. See the provider guide:
  [SETUP-GITHUB.md](SETUP-GITHUB.md), [SETUP-GITLAB.md](SETUP-GITLAB.md), or
  [SETUP-LINEAR.md](SETUP-LINEAR.md).
- The project (**Repo Profile**) must already exist in Argos.
- The scheduler must be running for poll mode and for the approve gate — both
  the issue poll and the concept-approval check run on Laravel's schedule (see
  [Poll mode](#poll-mode)).

The OAuth scopes you already grant for GitHub (`repo`) and GitLab (`api`) cover
webhook management, so no extra scope is needed for webhook mode. For Linear,
registering a webhook requires an organisation member with sufficient
permissions; see [SETUP-LINEAR.md](SETUP-LINEAR.md).

## Creating a binding

1. In the admin panel open the project under **Repo Profiles**.
2. Open the **Task-Provider** tab → **Create**.
3. Fill in the fields:

| Field | Description |
|---|---|
| **Provider** | GitHub, GitLab or Linear |
| **Modus** (Mode) | `Webhook (Push)`, `Polling`, or `Deaktiviert` (Disabled) |
| **Zugang** (Credential) | The OAuth account **or** stored Access Token (PAT) for this provider |
| **Projekt / Team** | The external project/team. Loaded automatically from the chosen credential. GitHub/GitLab: a repository (`owner/repo`); Linear: a team |
| **Labels-Filter** | Optional: only issues carrying at least one of these labels are imported |
| **Issue schließen bei Task-Abschluss** | Optional toggle: close the source issue when the Argos task completes ([status sync](#status-sync-on-completion)) |

4. Save → the binding is created in status **Pending**.
5. Run the **Einrichten** (Setup) action on the binding row → the binding is
   activated (status **Active**).

The Setup action behaves differently per mode:

- **Polling** — marks the binding **Active** immediately; no provider API call.
- **Webhook (Push)** — generates a webhook secret, registers the webhook with
  the provider, stores the returned webhook id + secret, and marks the binding
  **Active**. If registration fails, the error is recorded in **Letzter Fehler**
  (Last error) and the binding stays Pending.
- **Deaktiviert** — sets the binding back to Pending and clears any stored
  webhook id/secret.

> Note: the binding form labels in the admin panel are currently in German
> (Provider, Modus, Zugang, Projekt / Team, Labels-Filter). The provider
> values map to GitHub / GitLab / Linear.

## Webhook mode

Webhook mode delivers issues in real time. The inbound endpoint is a single,
session-less route; the request is authenticated by the provider's signature,
verified in the controller:

```
POST  ${APP_URL}/webhooks/issues/{provider}/{binding-id}
```

`{provider}` is `github`, `gitlab`, or `linear`; `{binding-id}` is the binding's
id. `APP_URL` must be publicly reachable for the provider to deliver.

For **GitHub** and **GitLab**, the Setup action registers the webhook for you
via the API — you do not normally add it manually. For **Linear**, Setup
registers an organisation webhook for `Issue` resources automatically; there is
no manual step in the Linear UI.

If you need to register the webhook by hand (e.g. an org policy blocks API
registration), use these values. The secret is the one generated by Setup and
stored on the binding.

**GitHub** — Repository → Settings → Webhooks → Add webhook:

- Payload URL: `${APP_URL}/webhooks/issues/github/<binding-id>`
- Content type: `application/json`
- Secret: the generated secret (verified via the `X-Hub-Signature-256`
  HMAC-SHA256 header)
- Events: **Issues** (Argos also accepts `issue_comment`; other event types are
  acknowledged and ignored so GitHub does not retry)

**GitLab** — Project → Settings → Webhooks:

- URL: `${APP_URL}/webhooks/issues/gitlab/<binding-id>`
- Secret token: the generated secret (sent back in, and matched against, the
  `X-Gitlab-Token` header)
- Trigger: **Issues events**

**Linear** — registered automatically during Setup; no manual step required:

- URL: `${APP_URL}/webhooks/issues/linear/<binding-id>`
- Signature: raw HMAC-SHA256 hex digest in the `Linear-Signature` header

Deliveries are deduplicated by the provider's delivery id
(`X-GitHub-Delivery` / `X-Gitlab-Event-UUID` / `Linear-Delivery`) for 24h, so a
provider retry does not create a duplicate task.

## Poll mode

In poll mode Argos fetches issues on a schedule instead of receiving pushes. No
webhook is registered in the external system, and `APP_URL` does not need to be
publicly reachable.

The scheduler dispatches a poll job for every **Active, Poll-mode** binding via
the `argos:poll-issues` command. The interval is configurable through
`ARGOS_POLL_INTERVAL_MINUTES` (default **5**, clamped to **1–59**). Set it to
`1` locally for fast feedback.

The same interval also drives the concept-approval check
(`argos:check-concept-approvals`), because reactions are never pushed — see
[the approve gate](#concept-write-back-and-the-approve-gate).

Manual triggers:

```bash
php artisan argos:poll-issues
php artisan argos:check-concept-approvals
```

## Issue to task

When a matching issue is seen for the first time, Argos creates a task on the
binding's project:

- The task **name** is the issue title; the **description** is the issue body.
- If the project (Repo Profile) has **auto-concept** enabled, the concept phase
  starts automatically for the new task; otherwise the task waits for a manual
  start.

Import is "once, ever" per issue: the External Issue Link records that the issue
was imported. An issue first seen *not* matching the filters and labelled later
will still import on a subsequent poll/delivery, but an imported task you
*delete* in Argos is **not** silently re-imported.

## Concept write-back and the approve gate

After each phase finishes, Argos posts a comment on the linked issue. The
comment carries a header plus the phase result:

- **concept** — the full concept text (capped under provider comment-size
  limits), for review directly on the issue.
- **implement** — the result summary (non-technical + technical sections).
- **push** — the pull/merge request link.

Every comment ends with a link back into Argos. If posting fails (e.g. an
expired token), the error is logged and the workflow continues.

> The comment text is currently rendered in German, for example:
> `**Argos** — Phase **Concept** abgeschlossen mit Status: **Completed**`.

**Approve gate.** When the concept comment is posted, Argos stores its comment
id. While the task is in **Concept Review**, the concept-approval check polls
that comment for reactions. When it finds a 👍 (`+1` / `thumbsup` / `👍`) from
a user who is **authorized to approve**, it starts the implement phase.

"Authorized" is provider-specific:

- **GitHub / GitLab** — the reacting user has write/admin access on the
  repository.
- **Linear** — an active, non-guest organisation member.

The gate is idempotent: starting implement moves the task out of Concept
Review, so a second 👍 is a no-op. The check runs on the same schedule as the
poll (`ARGOS_POLL_INTERVAL_MINUTES`); it requires the scheduler to be running
even in webhook mode, since providers do not push reaction events.

## Status sync on completion

Status sync is **opt-in per binding** via the **Issue schließen bei
Task-Abschluss** toggle (the `close_on_complete` filter flag). When enabled and
the Argos task is marked completed, Argos:

1. Posts a closing comment with the pull-request link (if any), then
2. Closes/resolves the source issue.

This is best-effort: a provider failure is logged and never blocks completing
the task in Argos. With the toggle off, ingest stays purely inbound and the
source issue is left untouched.

## Filtering

Filtering is applied before ingest:

- **Labels-Filter** — OR semantics: an issue is imported only if it carries at
  least one of the configured labels. With no labels configured, all issues
  pass the label filter.
- A binding may also filter by issue **state** (e.g. only open issues), stored
  alongside the other filters.

An issue that does not pass the filters still gets an External Issue Link (so
its last-seen state is tracked), but no task is created.

## Environment variables

No provider secrets are configured via env for the binding itself — the
credential comes from the selected OAuth account or PAT. The relevant variables
are:

```env
# Base URL — also the base of the inbound webhook URL.
# Must be publicly reachable when webhook mode is used.
APP_URL=https://argos.example.com

# How often (minutes) the scheduler polls issue providers and checks
# concept-comment reactions. Default 5; clamped to 1–59. Set to 1 locally.
ARGOS_POLL_INTERVAL_MINUTES=5
```

The scheduler (`php artisan schedule:work`, or a system cron entry running
`schedule:run`) must be active for poll mode and the approve gate to work.

## Troubleshooting

- **Setup fails / binding stays Pending** — read **Letzter Fehler** (Last
  error) on the binding row. Common causes: the credential lacks webhook
  permission, or `APP_URL` is not reachable for the provider to validate.
- **No tasks appear in poll mode** — confirm the scheduler is running and the
  binding is **Active**; check that the **Labels-Filter** is not excluding the
  issues. The **Letzter Poll** (Last poll) column shows when the binding was
  last fetched.
- **Webhook deliveries rejected (401)** — the signature/secret does not match.
  Re-run **Einrichten** to regenerate and re-register the secret, or, for a
  manual webhook, copy the exact stored secret into the provider.
- **👍 does not start implement** — the task must be in **Concept Review**, the
  reaction must be 👍, the reacting user must be authorized (repo write/admin,
  or non-guest Linear member), and the scheduler must be running the approval
  check.
