# GitHub Setup

Argos supports GitHub with two authentication methods: Personal Access Token
(PAT) and OAuth. Both work with github.com.

See [OAuth Overview](OAUTH.md) for how PAT and OAuth compare and which one to
pick.

---

## Option 1: Personal Access Token (PAT)

Recommended for single-user instances or when you just want to try Argos. PAT
works without any server-side configuration — you only paste the token in the
project form.

### Step 1: Create a token

1. Open <https://github.com/settings/tokens>.
2. Click **Generate new token (classic)**.
3. Give it a descriptive name (e.g. `argos`).
4. Select these scopes:
   - `repo` — full repository access (clone, push, create branches, create
     PRs). This is the scope Argos asks for.
   - `workflow` — only if your repo has GitHub Actions and Argos should touch
     `.github/workflows/*`.
5. Set an expiration (or pick "No expiration" for a long-lived token).
6. Copy the token — it is shown only once.

> **Fine-grained tokens** also work, scoped to the specific repository, with
> read/write access to **Contents**, **Pull requests**, and **Workflows** (if
> applicable).

### Step 2: Configure the project in Argos

Under **Projects → New Project**:

- **Platform**: GitHub
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: full HTTPS URL, e.g. `https://github.com/your-org/your-repo`
- **Token**: paste the token from step 1
- **Default Branch**: e.g. `main`

---

## Option 2: OAuth (optional)

OAuth enables repo and branch dropdowns in the project form and per-user
account binding — no manual URL entry, and each user connects their own GitHub
account.

OAuth apps are managed **in the Argos UI**, not via environment variables.
There is no `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` to set. The full UI
flow is described in
[OAuth Overview → Registering the OAuth app in Argos](OAUTH.md#registering-the-oauth-app-in-argos).

### Step 1: Register an OAuth App on GitHub

1. Open <https://github.com/settings/applications/new> (or your
   organization's developer settings for org-wide apps).
2. Fill in:
   - **Application name**: `Argos` (or any descriptive name).
   - **Homepage URL**: your Argos instance, e.g. `https://argos.example.com`.
   - **Authorization callback URL**:
     `https://argos.example.com/auth/github/callback` — the callback is fixed
     at `${APP_URL}/auth/github/callback`. Register exactly that URL, including
     the scheme.
3. Click **Register application**.
4. Copy the **Client ID** and generate a new **Client secret** — copy it too
   (the secret is shown only once).

> The **Add OAuth App** form in Argos can deep-link you to GitHub's
> registration page with the name, homepage and callback already pre-filled,
> so you do not have to type them by hand.

### Step 2: Add the OAuth App in Argos

1. Open **Configuration → OAuth Apps** in the Argos admin.
2. Add an app for **GitHub**, paste its **Client ID** and **Client secret**,
   and enable it.
3. The **callback URL** shown in the form is read-only and derived from
   `APP_URL` — copy it into the GitHub OAuth app's Authorization callback URL
   if you have not already.

Credentials are stored in the database (`provider_oauth_configs`) and take
effect immediately, without a restart. There are **no** `*_CLIENT_ID` /
`*_CLIENT_SECRET` environment variables — the UI is the only place these are
configured. See [Configuration Reference](CONFIGURATION.md) for the settings
that *are* still ENV-based.

The OAuth App is registered with the `repo` scope, which covers cloning,
pushing, branch and PR creation — the same access a classic PAT with `repo`
provides.

### Step 3: Connect your account

1. Sign in to Argos.
2. Go to **Connected Accounts** in the navigation.
3. Click **Connect GitHub** — you are redirected to GitHub to authorize the
   app. After authorizing, you are returned to the Connected Accounts page.
4. When creating a project, select **GitHub** as the platform and choose
   **OAuth** as the authentication method — the repo and branch dropdowns
   appear, populated from your connected account.

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

For the shared binding setup details, see
[Task Providers Setup](SETUP-TASK-PROVIDERS.md).

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 401 on push / PR creation | PAT missing the `repo` scope, or token expired. |
| 403 when pushing to `.github/workflows/*` | PAT missing the `workflow` scope. |
| OAuth redirect fails with "redirect_uri mismatch" | The Authorization callback URL on the GitHub OAuth App must exactly match `${APP_URL}/auth/github/callback` — including scheme. Verify `APP_URL`. |
| OAuth callback returns 500 | `APP_URL` not set or doesn't match the public URL — Laravel can't generate the correct callback. |
| "Connect GitHub" rejected right after authorizing | The OAuth App in **Configuration → OAuth Apps** is disabled or has the wrong client ID/secret. Re-check and enable it. |
| PR creation returns 422 with "A pull request already exists" | Argos detects this and reports the existing PR URL — no action needed. |
