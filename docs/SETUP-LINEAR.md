# Linear Setup Guide

This guide is for **Argos operators** who want to connect **Linear** as a
task/issue provider: import Linear issues as Argos tasks, post phase results
back onto the issue, gate the implement phase on a 👍 reaction, and optionally
close the issue when the task is done.

It covers the Linear-specific parts only. The shared mechanics — how a binding
is created, how webhook vs. poll mode work, the approve gate, status sync — are
described once in [SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md) and only
summarized here. Read that page alongside this one.

## Contents

- [Choosing a credential: OAuth or API key](#choosing-a-credential-oauth-or-api-key)
- [Option A: OAuth application](#option-a-oauth-application)
- [Option B: Personal API key](#option-b-personal-api-key)
- [Creating the binding](#creating-the-binding)
- [Webhook mode](#webhook-mode)
- [Poll mode](#poll-mode)
- [Issue to task](#issue-to-task)
- [Concept write-back and the approve gate](#concept-write-back-and-the-approve-gate)
- [Status sync on completion](#status-sync-on-completion)
- [Issue identifiers](#issue-identifiers)
- [Troubleshooting](#troubleshooting)

## Choosing a credential: OAuth or API key

Argos can authenticate against Linear's GraphQL API two ways, and both can be
selected as the **Zugang** (credential) of a binding:

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

1. In the admin panel go to **Connected Accounts** (or run the onboarding
   flow) and start the Linear connection.
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
2. In the Argos admin panel open **Access Tokens** (Provider Credentials) →
   create a credential with **Provider** = `Linear` and paste the key. The
   inline guidance lists the suggested scope as `read, write`.

A personal API key inherits the permissions of the user who created it.
Posting comments and reading issues need read/write; **creating a webhook**
needs that user to be an admin on the target team. If the key's owner is not a
team admin, webhook registration during Setup fails — use OAuth (with the
`admin` scope) or poll mode instead.

## Creating the binding

Bindings are created on a project's **Task-Provider** tab. The full,
provider-agnostic procedure is in
[SETUP-TASK-PROVIDERS.md#creating-a-binding](SETUP-TASK-PROVIDERS.md#creating-a-binding).
The Linear-specific field values:

| Field (German label) | Value for Linear |
|---|---|
| **Provider** | `Linear` |
| **Modus** (Mode) | `Webhook (Push)`, `Polling`, or `Deaktiviert` |
| **Zugang** (Credential) | The Linear OAuth account **or** the Linear API key (PAT) |
| **Projekt / Team** | A Linear **team**, picked from a list loaded from the credential (the team key + name, e.g. `ENG — Engineering`). The team key is stored, not an `owner/repo` path |
| **Labels-Filter** | Optional: import only issues carrying at least one of these labels |
| **Issue schließen bei Task-Abschluss** | Optional: close the source issue when the Argos task completes ([status sync](#status-sync-on-completion)) |

The **Projekt / Team** list is populated by querying Linear's `teams` API with
the chosen credential, so pick the **Provider** and **Zugang** first. You can
find the same team key in Linear under **Settings** → **Teams** (the short
uppercase slug next to the team name, e.g. `ENG`).

After saving, run the **Einrichten** (Setup) action on the binding row to
activate it. For poll mode, Setup just marks the binding Active. For webhook
mode, Setup registers the webhook (see below). On failure, the error is shown
in the **Letzter Fehler** (Last error) column and the binding stays Pending.

## Webhook mode

In webhook mode, the **Einrichten** action registers a webhook on the selected
Linear **team** automatically — there is no manual step in the Linear UI. The
webhook is scoped to the team behind the chosen **Projekt / Team** ref and
subscribes to the `Issue` resource type only; comment and project events are
ignored.

The inbound endpoint (shared across all providers) is:

```
POST  ${APP_URL}/webhooks/issues/linear/<binding-id>
```

`APP_URL` must be publicly reachable for Linear to deliver. Argos generates a
webhook secret during Setup and registers it with the webhook. Linear signs
each delivery with **HMAC-SHA256** over the raw request body and sends it as a
**raw hex digest** in the `Linear-Signature` header (no `sha256=` prefix). The
controller verifies it with `hash_equals`; a mismatch or a missing secret
returns `401`.

Deliveries are de-duplicated by the `Linear-Delivery` header for 24 hours, so a
Linear retry does not create a duplicate task.

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
publicly reachable. **Einrichten** marks the binding Active immediately.

The poll runs via the scheduled `argos:poll-issues` command. The interval is
configurable through `ARGOS_POLL_INTERVAL_MINUTES` (default **5**, clamped to
**1–59**); set it to `1` locally for faster feedback. The same interval also
drives the approve-gate check (`argos:check-concept-approvals`). The scheduler
(`php artisan schedule:work`, or a system cron running `schedule:run`) must be
active for poll mode *and* for the approve gate — see
[SETUP-TASK-PROVIDERS.md#poll-mode](SETUP-TASK-PROVIDERS.md#poll-mode).

## Issue to task

When a matching Linear issue is seen for the first time, Argos creates a task
on the binding's project: the task name is the issue title and the description
is the issue body. If the project has auto-concept enabled, the concept phase
starts automatically. Import is once-ever per issue. See
[SETUP-TASK-PROVIDERS.md#issue-to-task](SETUP-TASK-PROVIDERS.md#issue-to-task)
for the full behavior.

## Concept write-back and the approve gate

After the concept phase, Argos posts a comment on the Linear issue with the
concept text. While the task is in Concept Review, the approve-gate check polls
that comment's reactions. A 👍 reaction from an **authorized** user starts the
implement phase.

For Linear, "authorized to approve" means an **active, non-guest organisation
member**: the reacting user must have `active = true` and `guest != true` in
Linear. (Linear has no per-repo permissions, so this org-member check stands in
for the write/admin access used on GitHub/GitLab. Admins are a non-guest
superset and qualify.)

Reactions are never pushed by Linear, so the gate relies on the scheduled
`argos:check-concept-approvals` run even in webhook mode. Full mechanics:
[SETUP-TASK-PROVIDERS.md#concept-write-back-and-the-approve-gate](SETUP-TASK-PROVIDERS.md#concept-write-back-and-the-approve-gate).

## Status sync on completion

Status sync is opt-in per binding via the **Issue schließen bei
Task-Abschluss** toggle (`close_on_complete`). When enabled and the Argos task
completes, Argos closes the Linear issue.

Linear has no generic "closed" flag, so Argos moves the issue to the **first
workflow state of type `completed`** on the issue's team. If the team has no
completed-type state, the close step fails (logged, best-effort — it never
blocks completing the task in Argos). See
[SETUP-TASK-PROVIDERS.md#status-sync-on-completion](SETUP-TASK-PROVIDERS.md#status-sync-on-completion).

## Issue identifiers

Linear identifies issues by **UUID**. Argos stores that UUID as the
`external_id` on the External Issue Link and uses it for all subsequent API
calls (comment write-back, reaction polling, close). This is transparent — no
configuration is needed.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Linear team not found for key: …" during Setup | The team key behind **Projekt / Team** is wrong or the credential can't see that team — pick it again from the loaded list, or check the key under Linear Settings → Teams |
| OAuth redirect fails / "Invalid OAuth state" | The callback URL on the Linear OAuth app must exactly match `${APP_URL}/auth/linear/callback`; mismatched or expired session state also triggers this |
| Setup fails in webhook mode | The credential lacks permission to create a webhook on the team — use an OAuth account with the `admin` scope, or an API-key owner who is a team admin. The exact error is in **Letzter Fehler** |
| Webhook deliveries rejected (401) | Signature/secret mismatch, or no secret stored. Re-run **Einrichten** to regenerate and re-register the secret |
| No tasks appear in poll mode | The scheduler isn't running, the binding isn't **Active**, or the **Labels-Filter** excludes the issues. Check the **Letzter Poll** column |
| 👍 does not start implement | The task must be in Concept Review, the reaction must be 👍, the reacting user must be an active non-guest Linear member, and the scheduler must be running the approval check |
| `400` / `401` on API calls | A `lin_api_…` key sent with a `Bearer` prefix is rejected by Linear — Argos handles this automatically, but a revoked/expired token also fails. Reconnect the OAuth account or recreate the API key |
