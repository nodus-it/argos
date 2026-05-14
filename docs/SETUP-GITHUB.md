# GitHub Setup Guide

Argos supports GitHub with two authentication methods: Personal Access Token
(PAT) and OAuth. PAT is the simpler option and works without any server-side
configuration.

---

## Option 1: Personal Access Token (PAT)

Recommended for single-user instances or when you just want to try Argos.

### Step 1: Create a token

1. Open <https://github.com/settings/tokens>.
2. Click **Generate new token (classic)**.
3. Give it a descriptive name (e.g. `argos`).
4. Select these scopes:
   - `repo` — full repository access (clone, push, create branches, create PRs)
   - `workflow` — only if your repo has GitHub Actions and Argos should touch
     `.github/workflows/*`
5. Set an expiration (or pick "No expiration" for a long-lived token).
6. Copy the token — it is shown only once.

### Step 2: Configure the project in Argos

Under **Projects → New Project**:

- **Platform**: GitHub
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: full HTTPS URL, e.g. `https://github.com/your-org/your-repo`
- **Token**: paste the token from step 1
- **Default Branch**: e.g. `main`

> **Fine-grained tokens** also work, scoped to the specific repository, with
> read/write access to **Contents**, **Pull requests**, and **Workflows** (if
> applicable).

---

## Option 2: OAuth (optional)

OAuth enables repo and branch dropdowns in the project form and per-user
account binding — no manual URL entry, and each user connects their own
GitHub account.

### Step 1: Register an OAuth App

1. Open <https://github.com/settings/applications/new> (or your
   organization's developer settings for org-wide apps).
2. Fill in:
   - **Application name**: `Argos` (or any descriptive name)
   - **Homepage URL**: your Argos instance, e.g. `https://argos.example.com`
   - **Authorization callback URL**:
     `https://argos.example.com/auth/github/callback`
3. Click **Register application**.
4. Copy the **Client ID** and generate a new **Client secret** — copy it too.

### Step 2: Configure environment variables

Add to your `.env` (or pass as `-e` flags to `docker run`):

```env
GITHUB_CLIENT_ID=your-client-id
GITHUB_CLIENT_SECRET=your-client-secret
```

The callback URL is fixed at `${APP_URL}/auth/github/callback` and is what
you registered with GitHub in step 1 — no extra variable needed. Restart the
manager so the new vars are picked up.

### Step 3: Connect your account

1. Sign in to Argos.
2. Go to **Connected Accounts** in the navigation.
3. Click **Connect GitHub** and approve the OAuth flow.
4. When creating a project, select **GitHub** as the platform and choose
   **OAuth** as the authentication method — the repo and branch dropdowns
   will appear, populated from your account.

---

## Option 3: Issue-Provider (Pull + Webhook)

Argos can import GitHub Issues as Tasks and keep them in sync via polling or
webhooks. Each repository needs a **TaskProviderBinding** (one per GitHub repo)
configured through the Filament admin interface.

### Step 1: Required token scopes

| Token type | Scopes needed |
|---|---|
| Classic PAT | `repo` (includes webhook management) |
| Fine-grained PAT | **Issues**: Read & Write · **Webhooks**: Read & Write |
| OAuth (existing) | `repo` scope — already covers webhooks |

> **Note**: `admin:repo_hook` is a sub-scope of `repo` in classic PATs.
> Fine-grained tokens require "Webhooks: Read & Write" explicitly.

### Step 2: Create a binding in Filament

1. Open **Argos Admin → Task Provider Bindings → New**.
2. Set **Kind** to `GitHub` and choose the **Connected Account** that holds your
   PAT or OAuth token.
3. Enter the **External Project Ref** in `owner/repo` format, e.g.
   `your-org/your-repo`.
4. Choose a **Mode**:
   - **Webhook** — real-time; requires `APP_URL` to be publicly reachable.
   - **Poll** — periodic polling; works behind firewalls or in local development.
5. Optionally set **Filters** (e.g. `state=open`, label filters) so only
   relevant issues become tasks.
6. Click **Save**. The binding starts in `Pending` state.

### Step 3: Activate with "Einrichten"

Click the **Einrichten** (Setup) action on the binding row:

- **Webhook mode**: Argos calls `POST /repos/{owner}/{repo}/hooks` on GitHub and
  registers the callback URL `${APP_URL}/webhooks/issues/github/{binding-id}`
  automatically. No manual URL entry needed. The binding moves to `Active` once
  GitHub confirms the hook.
- **Poll mode**: The binding moves to `Active` immediately. The poll scheduler
  will fetch issues on the next run.

If setup fails (invalid token, missing scopes, `APP_URL` not reachable), the
binding stays `Pending` and the error message is shown in the **Last Error**
column. Fix the issue and click **Einrichten** again.

### Webhook vs. Poll

| | Webhook | Poll |
|---|---|---|
| Latency | Seconds | Minutes (scheduled interval) |
| Requires public `APP_URL` | Yes | No |
| GitHub rate limits | Not applicable | Counts against API quota |

> **Tip**: Use Poll mode during local development or when `APP_URL` is not
> publicly reachable (e.g. behind a VPN or NAT). Switch to Webhook mode in
> production for real-time updates.

### Behaviour notes

- **issue_comment** events are accepted by the webhook endpoint but not
  currently ingested as tasks. Only `issues` events (opened, edited, etc.)
  create or update Tasks.
- **Issue state and Task state are independent.** Closing a GitHub issue does
  not close the corresponding Task in Argos; Tasks are managed separately.
- **Duplicate-safe**: the webhook endpoint ignores re-deliveries (identified by
  the `X-GitHub-Delivery` header). Pull requests appearing in the issues list
  are filtered out automatically.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 401 on push / PR creation | PAT missing the `repo` scope, or token expired. |
| 403 when pushing to `.github/workflows/*` | PAT missing the `workflow` scope. |
| OAuth redirect fails with "redirect_uri mismatch" | The callback URL registered in the OAuth App must exactly match `${APP_URL}/auth/github/callback` — including scheme. Verify `APP_URL`. |
| OAuth callback returns 500 | `APP_URL` not set or doesn't match the public URL — Laravel can't generate the correct callback. |
| PR creation returns 422 with "A pull request already exists" | Argos detects this and reports the existing PR URL — no action needed. |
