# Linear Setup Guide

This guide is for **Argos operators** who want to connect **Linear** as an
issue tracker of a workspace: Linear issues become tickets of tasks, matching
issues start automatic tasks, Argos comments on the issue as the task
progresses and closes it on merge, and a 👍 reaction approves the plan.

It covers the Linear-specific parts only. The shared mechanics — how an integration
is created, how webhook vs. poll mode work, plan approval — are
described once in [SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md) and only
summarized here. Read that page alongside this one.

## Contents

- [Choosing a credential: OAuth or API key](#choosing-a-credential-oauth-or-api-key)
- [Option A: OAuth application](#option-a-oauth-application)
- [Option B: Personal API key](#option-b-personal-api-key)
- [Creating the integration](#creating-the-integration)
- [Webhook mode](#webhook-mode)
- [Poll mode](#poll-mode)
- [Issue to automatic task](#issue-to-automatic-task)
- [Plan approval](#plan-approval)
- [Issue identifiers](#issue-identifiers)
- [Troubleshooting](#troubleshooting)

## Choosing a credential: OAuth or API key

Argos can authenticate against Linear's GraphQL API two ways, and both can be
selected as the **Access** of an integration:

- **OAuth account** (a Connected Account) — recommended for a multi-user
  instance. The access token is obtained through Linear's OAuth flow and is
  sent as a `Bearer` token.
- **Personal API key** (a stored Access Token / PAT) — a `lin_api_…` key
  created in Linear. Argos detects the `lin_api_` prefix and sends the key
  **raw** (no `Bearer` prefix), as Linear requires.

Either credential type works for **poll mode**. For **webhook mode**, the
credential must have permission to create a webhook on the target team (see
[Webhook mode](#webhook-mode)).

## Option A: OAuth application

### 1. Register the OAuth application in Linear

1. In Linear go to **Settings** → **API** → **OAuth applications** →
   **New application** (deep link:
   `https://linear.app/settings/api/applications/new`).
2. Fill in:
   - **Name**: e.g. `Argos`
   - **Callback URL**: `${APP_URL}/auth/linear/callback`
     (replace `${APP_URL}` with your Argos instance URL, e.g.
     `https://argos.example.com`)
3. Create the app, then copy the **Client ID** and **Client Secret**.

### 2. Configure environment variables

Add to the Argos manager's `.env`:

```env
LINEAR_CLIENT_ID=<your-client-id>
LINEAR_CLIENT_SECRET=<your-client-secret>
```

The callback path is fixed (`config('services.linear.redirect')` defaults to
`/auth/linear/callback`); there is no separate URL variable. Restart the
manager so it picks up the new variables.

### 3. Connect the account

1. Go to **My account → Connected accounts** (`/account/connections`), or run
   the setup wizard, and start the Linear connection.
2. Argos redirects you to `https://linear.app/oauth/authorize` to authorize the
   application; approve the requested scopes.
3. You are redirected back to Argos and the Linear account shows as connected.

The OAuth flow requests these scopes:

```
read write issues:create comments:create admin
```

The `admin` scope is included because **webhook management** (creating and
deleting a webhook on a team) needs admin-level permission in Linear. If you
only ever use poll mode, the read/write/issues/comments scopes are what get
exercised, but the flow always requests `admin` so webhook mode works without a
reconnect.

## Option B: Personal API key

Use this when you don't want to register an OAuth app, or for a
single-operator instance.

1. In Linear go to **Settings** → **API** → **Personal API keys**
   (`https://linear.app/settings/api`) and create a key. It begins with
   `lin_api_`.
2. In Argos open **Administration → Access tokens** (`/system/tokens`) →
   create a credential with **Provider** = `Linear` and paste the key. The
   inline guidance lists the suggested scope as `read, write`.

A personal API key inherits the permissions of the user who created it.
Posting comments and reading issues need read/write; **creating a webhook**
needs that user to be an admin on the target team. If the key's owner is not a
team admin, webhook registration during Setup fails — use OAuth (with the
`admin` scope) or poll mode instead.

## Creating the integration

Integrations are created in the workspace's **Settings → Integrations →
Issue trackers**. The full, provider-agnostic procedure is in
[SETUP-TASK-PROVIDERS.md#creating-an-integration](SETUP-TASK-PROVIDERS.md#creating-an-integration).
The Linear-specific values:

| Field | Value for Linear |
|---|---|
| **Provider** | `Linear` |
| **Mode** | *Webhook (push)*, *Polling* or *Disabled* |
| **Access** | The Linear OAuth account **or** the Linear API key (PAT) |
| **Project / Team** | A Linear **team**, picked from a list loaded from the access (team key + name, e.g. `ENG — Engineering`). The team key is stored, not an `owner/repo` path |
| **Labels filter** | Optional: only issues carrying at least one of these labels start a task |
| **Close the ticket on merge** | On (default): Argos moves the issue to the team's *completed* workflow state once the task is done |

The **Project / Team** list is populated by querying Linear's `teams` API with
the chosen access, so pick **Provider** and **Access** first. The team key is
also shown in Linear under **Settings** → **Teams** (e.g. `ENG`).

After saving, run **Set up** on the integration's row. For poll mode it just
marks the integration Active; for webhook mode it registers the webhook (see
below). On failure the error is shown in **Last error** and the integration
stays Pending.

## Webhook mode

In webhook mode, **Set up** registers a webhook on the selected
Linear **team** automatically — there is no manual step in the Linear UI. The
webhook is scoped to the team behind the chosen **Project / Team** and
subscribes to the `Issue` resource type only; comment and project events are
ignored.

The inbound endpoint (shared across all providers) is:

```
POST  ${APP_URL}/webhooks/issues/linear/<integration-id>
```

`APP_URL` must be publicly reachable for Linear to deliver. Argos generates a
webhook secret during Setup and registers it with the webhook. Linear signs
each delivery with **HMAC-SHA256** over the raw request body and sends it as a
**raw hex digest** in the `Linear-Signature` header (no `sha256=` prefix). The
controller verifies it with `hash_equals`; a mismatch or a missing secret
returns `401`.

Deliveries are de-duplicated by the `Linear-Delivery` header for 24 hours, so a
Linear retry does not start a second task.

**Requirements for webhook mode:**

- `APP_URL` publicly reachable from Linear's servers.
- A credential allowed to create a webhook on the team: an OAuth account with
  the `admin` scope, or an API key whose owner is a team admin.

If an org policy blocks API-driven webhook registration, there is no supported
manual fallback for Linear (the secret is generated server-side and never
shown); use poll mode instead.

## Poll mode

In poll mode Argos fetches the team's issues on a schedule instead of receiving
pushes. No webhook is registered in Linear and `APP_URL` does not need to be
publicly reachable. **Set up** marks the integration Active immediately.

The poll runs via the scheduled `argos:poll-issues` command. The interval is
configurable through `ARGOS_POLL_INTERVAL_MINUTES` (default **5**, clamped to
**1–59**); set it to `1` locally for faster feedback. The same interval also
drives the plan-approval check (`argos:check-plan-approvals`) and the ticket
comments (`argos:tasks:sync-ticket-comments`). The scheduler
(`php artisan schedule:work`, or a system cron running `schedule:run`) must be
active for poll mode *and* for plan approval — see
[SETUP-TASK-PROVIDERS.md#poll-mode](SETUP-TASK-PROVIDERS.md#poll-mode).

## Issue to automatic task

When a matching Linear issue is seen for the first time, Argos starts an
automatic task in the integration's workspace. It belongs to the issue's
assignee (matched by e-mail) when they agreed in their profile, otherwise to
the workspace's automation user with the assignee invited. The branch carries
the issue key (e.g. `eng-118-…`). Launching is once-ever per issue. See
[SETUP-TASK-PROVIDERS.md#issue-to-automatic-task](SETUP-TASK-PROVIDERS.md#issue-to-automatic-task)
and [Tickets](TICKETS.md).

## Plan approval

With the workspace's plan setting *wait for approval*, Argos posts the plan as a
comment on the Linear issue. A 👍 reaction from an **authorized** user approves
it and the task continues.

For Linear, "authorized to approve" means an **active, non-guest organisation
member**: the reacting user must have `active = true` and `guest != true` in
Linear. (Linear has no per-repo permissions, so this org-member check stands in
for the write/admin access used on GitHub/GitLab. Admins are a non-guest
superset and qualify.)

Reactions are never pushed by Linear, so approval relies on the scheduled
`argos:check-plan-approvals` run even in webhook mode. Full mechanics:
[SETUP-TASK-PROVIDERS.md#comments-and-plan-approval](SETUP-TASK-PROVIDERS.md#comments-and-plan-approval).

The pull request links the Linear issue. When the task is done (pull request
merged) and *Close the ticket on merge* is on, Argos moves the issue to the
team's first *completed* workflow state; a hand-over makes the new owner (by
e-mail) the issue's assignee.

## Issue identifiers

Linear identifies issues by **UUID**. Argos stores that UUID as the
`external_id` on the External Issue Link and uses it for all subsequent API
calls (comment write-back, reaction polling). This is transparent — no
configuration is needed.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Linear team not found for key: …" during Set up | The team key behind **Project / Team** is wrong or the credential can't see that team — pick it again from the loaded list, or check the key under Linear Settings → Teams |
| OAuth redirect fails / "Invalid OAuth state" | The callback URL on the Linear OAuth app must exactly match `${APP_URL}/auth/linear/callback`; mismatched or expired session state also triggers this |
| Setup fails in webhook mode | The credential lacks permission to create a webhook on the team — use an OAuth account with the `admin` scope, or an API-key owner who is a team admin. The exact error is in **Last error** |
| Webhook deliveries rejected (401) | Signature/secret mismatch, or no secret stored. Run **Set up** again to regenerate and re-register the secret |
| No tasks start in poll mode | The scheduler isn't running, the integration isn't **Active**, the workspace has no **automation user** with a usable agent (and the assignee did not consent), or the **Labels filter** excludes the issues. Check the **Last poll** and **Last error** columns |
| 👍 does not approve the plan | The task must be waiting for plan approval, the reaction must be 👍 on Argos' plan comment, the reacting user must be an active non-guest Linear member, and the scheduler must be running the approval check |
| `400` / `401` on API calls | A `lin_api_…` key sent with a `Bearer` prefix is rejected by Linear — Argos handles this automatically, but a revoked/expired token also fails. Reconnect the OAuth account or recreate the API key |
