# Linear Setup Guide

This guide covers how to connect Argos to Linear using OAuth, so you can import issues as tasks and manage webhooks directly from the Argos admin panel.

---

## Step 1: Register an OAuth Application in Linear

1. Log in to Linear and navigate to **Settings** → **API** → **OAuth applications**.
2. Click **New application**.
3. Fill in the details:
   - **Name**: e.g. `Argos`
   - **Callback URL**: `{APP_URL}/auth/linear/callback`
     (replace `{APP_URL}` with your Argos instance URL, e.g. `https://argos.example.com`)
4. Click **Create**. Copy the **Client ID** and **Client Secret** — you'll need them in the next step.

---

## Step 2: Configure Environment Variables

Add these variables to your `.env` file:

```env
LINEAR_CLIENT_ID=<your-client-id>
LINEAR_CLIENT_SECRET=<your-client-secret>
```

The callback URL is fixed at `${APP_URL}/auth/linear/callback` — that is what you registered in step 1, no extra variable needed.

---

## Step 3: Connect Your Account

1. Restart the Argos manager so it picks up the new environment variables.
2. Go to **Connected Accounts** in the Argos admin panel.
3. Click **Connect with Linear**.
4. Authorize the OAuth application in Linear (grant the requested scopes).
5. You are redirected back to Argos — the Linear account now shows as **Connected**.

Scopes requested: `read write issues:create comments:create admin`

The `admin` scope is required for webhook management (creating and deleting webhooks on your team).

---

## Step 4: Create a Task Provider Binding

1. In the admin panel, open **Repo Profiles** → select your project → **Task Provider Bindings** → **New Binding**.
2. Fill in the fields:

| Field | Value |
|---|---|
| **Kind** | Linear |
| **Mode** | `Poll` (every 5 min) or `Webhook` (real-time) |
| **Connected Account** | The Linear account you just connected |
| **External Project Ref** | Your team key, e.g. `ENG` |
| **Labels** | Optional: only import issues with these labels |

3. Save → click **Setup** to activate the binding.

### Notes on External Project Ref

Linear uses a **team key** (a short uppercase slug like `ENG`, `BACK`, `FE`) to identify a team, not an `owner/repo` path. You can find the team key in Linear under **Settings** → **Teams** → the key shown next to the team name.

---

## Webhook Mode

When using Webhook mode, Argos registers a webhook on the Linear team automatically during **Setup**. The webhook is signed with a shared secret using HMAC-SHA256.

Linear sends the signature in the `Linear-Signature` header as a raw hex digest (no `sha256=` prefix).

The webhook listens for `Issue` events only. Comment and project events are silently ignored.

**Requirements for webhook mode:**
- `APP_URL` must be publicly reachable from Linear's servers.
- The `admin` OAuth scope must be granted (included by default in the OAuth flow).

---

## Poll Mode

In Poll mode, Argos fetches open issues from the team every 5 minutes. No webhook configuration in Linear is required. `APP_URL` does not need to be publicly reachable.

---

## Issue ID Format

Linear uses **UUIDs** as issue identifiers internally. Argos stores these UUIDs in `external_id` on the `ExternalIssueLink`. This is handled transparently — no manual configuration is needed.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Linear team not found" during Setup | The team key in **External Project Ref** is wrong — check the exact key in Linear Settings |
| OAuth redirect fails | The callback URL in the Linear OAuth app must exactly match `${APP_URL}/auth/linear/callback` |
| Webhook not receiving events | Ensure `APP_URL` is publicly reachable and the binding mode is set to `Webhook` |
| 401 on API calls | The OAuth token may have been revoked — disconnect and reconnect the account |
