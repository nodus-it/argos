# Issue Triggers

Argos can connect a project to an external issue tracker. A new matching issue
starts an **automatic run** in a workspace ([auto-threads](WORKSPACES.md#auto-threads)):
Claude plans, implements, fixes failing quality gates and opens a pull request
that closes the issue on merge. Argos reports on the issue as it goes, and an
authorized 👍 on the plan comment approves the plan.

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
- [Issue to automatic run](#issue-to-automatic-run)
- [Comments and plan approval](#comments-and-plan-approval)
- [Filtering](#filtering)
- [Environment variables](#environment-variables)
- [Troubleshooting](#troubleshooting)

## Supported providers

As issue triggers (ingest, comments, plan approval):

- **GitHub** — see [SETUP-GITHUB.md](SETUP-GITHUB.md)
- **GitLab** (including self-hosted) — see [SETUP-GITLAB.md](SETUP-GITLAB.md)
- **Linear** — see [SETUP-LINEAR.md](SETUP-LINEAR.md)

Bitbucket can be connected to Argos as a *code* provider
([SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)), but it is **not** wired as an issue
trigger — there is no Bitbucket option in the binding form, and webhook
registration / signature verification are not implemented for it. Do not expect
issue ingest from Bitbucket.

## How the integration works

End to end, a binding does three things:

1. **Ingest** — a new issue (received via webhook, or fetched by the poller)
   that matches the binding's filters starts an automatic run in a new
   workspace, once per issue.
2. **Comments** — Argos comments on the issue when the run starts, when its
   plan waits for approval, when the pull request is opened, and if the run
   fails or hits its budget.
3. **Plan approval** — with the project's plan setting *wait for approval*, an
   authorized 👍 reaction on the plan comment approves the plan. Reactions are
   not pushed by the providers, so Argos polls for them.

The issue is closed by merging the pull request (`Closes #…` on GitHub/GitLab,
the issue link on Linear) — Argos never closes it on its own.

Each binding lives on one project (Repo Profile) and points at one external
project/team. Runs act for the project's **automation user** (project →
*Automation*): that person's Claude agent does the work and they own the
workspace. Without an automation user nothing starts, and the binding's
**Last error** says so. The connection between an issue and its workspace is
recorded as an *External Issue Link*.

## Prerequisites

- A provider connection for the project's provider — either a **Connected
  Account** (OAuth) or a stored **Access Token (PAT)**. See the provider guide:
  [SETUP-GITHUB.md](SETUP-GITHUB.md), [SETUP-GITLAB.md](SETUP-GITLAB.md), or
  [SETUP-LINEAR.md](SETUP-LINEAR.md).
- The project (**Repo Profile**) must already exist in Argos.
- The scheduler must be running for poll mode and for plan approval — both
  the issue poll and the plan-approval check run on Laravel's schedule (see
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
| **Labels-Filter** | Optional: only issues carrying at least one of these labels start a run |

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
provider retry does not start a second run.

## Poll mode

In poll mode Argos fetches issues on a schedule instead of receiving pushes. No
webhook is registered in the external system, and `APP_URL` does not need to be
publicly reachable.

The scheduler dispatches a poll job for every **Active, Poll-mode** binding via
the `argos:poll-issues` command. The interval is configurable through
`ARGOS_POLL_INTERVAL_MINUTES` (default **5**, clamped to **1–59**). Set it to
`1` locally for fast feedback.

The same interval also drives the plan-approval check
(`argos:check-plan-approvals`), because reactions are never pushed — see
[plan approval](#comments-and-plan-approval).

Manual triggers:

```bash
php artisan argos:poll-issues
php artisan argos:check-plan-approvals
```

## Issue to automatic run

When a matching issue is seen for the first time, Argos creates a workspace on
the binding's project and starts an automatic run in it:

- The workspace is named `#<number> <title>`, its branch is
  `ws/<number>-<title>`.
- The run's prompt is the issue's title, text and link, marked as untrusted input
  (Claude treats it as data, not instructions).
- Plan mode, ship mode and budgets come from the project's **Automation**
  settings.

Launching is "once, ever" per issue: an issue first seen *not* matching the
filters and labelled later still launches on a later poll/delivery, but a
workspace you *delete* is **not** re-created. If the run cannot start (no
usable agent, too many workspaces running), the next poll/delivery retries.

## Comments and plan approval

Argos comments on the issue when the run starts (with a link to follow it
live), when the plan waits for approval, when the pull request is opened, and
when the run fails or uses up its budget. If posting fails (e.g. an expired
token), the error is logged and the run continues.

**Plan approval.** With *Plan, then wait for approval*, the plan is posted as a
comment. When the plan-approval check finds a 👍 (`+1` / `thumbsup` / `👍`) on
it from a user who is **authorized to approve**, the run continues. You can also
approve in Argos, via the REST API or MCP.

"Authorized" is provider-specific:

- **GitHub / GitLab** — the reacting user has write/admin access on the
  repository.
- **Linear** — an active, non-guest organisation member.

The check (`argos:check-plan-approvals`) runs on the poll schedule
(`ARGOS_POLL_INTERVAL_MINUTES`); it requires the scheduler even in webhook mode,
since providers do not push reaction events.

## Filtering

Filtering is applied before ingest:

- **Labels-Filter** — OR semantics: an issue starts a run only if it carries at
  least one of the configured labels. With no labels configured, all issues
  pass the label filter.
- A binding may also filter by issue **state** (e.g. only open issues), stored
  alongside the other filters.

An issue that does not pass the filters still gets an External Issue Link (so
its last-seen state is tracked), but no run starts.

## Environment variables

No provider secrets are configured via env for the binding itself — the
credential comes from the selected OAuth account or PAT. The relevant variables
are:

```env
# Base URL — also the base of the inbound webhook URL.
# Must be publicly reachable when webhook mode is used.
APP_URL=https://argos.example.com

# How often (minutes) the scheduler polls issue providers and checks
# plan-comment reactions. Default 5; clamped to 1–59. Set to 1 locally.
ARGOS_POLL_INTERVAL_MINUTES=5
```

The scheduler (`php artisan schedule:work`, or a system cron entry running
`schedule:run`) must be active for poll mode and plan approval to work.

## Troubleshooting

- **Setup fails / binding stays Pending** — read **Letzter Fehler** (Last
  error) on the binding row. Common causes: the credential lacks webhook
  permission, or `APP_URL` is not reachable for the provider to validate.
- **No runs start in poll mode** — confirm the scheduler is running, the
  binding is **Active** and the project has an **automation user** with a
  usable agent; check that the **Labels-Filter** is not excluding the issues. The **Letzter Poll** (Last poll) column shows when the binding was
  last fetched.
- **Webhook deliveries rejected (401)** — the signature/secret does not match.
  Re-run **Einrichten** to regenerate and re-register the secret, or, for a
  manual webhook, copy the exact stored secret into the provider.
- **👍 does not approve the plan** — the run must wait for approval (project
  plan setting *wait for approval*), the reaction must be 👍 on Argos' plan
  comment, the reacting user must be authorized (repo write/admin, or
  non-guest Linear member), and the scheduler must be running the approval
  check.