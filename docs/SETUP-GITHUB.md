# GitHub Setup

Argos supports GitHub with two authentication methods: Personal Access Token
(PAT) and OAuth. Both work with github.com.

See [OAuth Overview](OAUTH.md) for how PAT and OAuth compare and which one to
pick.

---

## Option 1: Personal Access Token (PAT)

Recommended for single-user instances or when you just want to try Argos. PAT
works without any server-side configuration — you only paste the token in the
workspace form.

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

### Step 2: Create the workspace in Argos

As an Argos administrator, choose **New workspace** (in `/w`, or under
**Workspaces** in the admin panel):

- **Platform**: GitHub
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: full HTTPS URL, e.g. `https://github.com/your-org/your-repo`
- **Token**: paste the token from step 1
- **Default Branch**: e.g. `main`

---

## Option 2: OAuth (optional)

OAuth enables repo and branch dropdowns in the workspace form and per-user
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
4. When creating a workspace, select **GitHub** as the platform and choose
   **OAuth** as the authentication method — the repo and branch dropdowns
   appear, populated from your connected account.

---

## Option 3: Issue trigger (poll or webhook)

A workspace can use GitHub Issues as [tickets](TICKETS.md) and start automatic
tasks from new issues, via polling or webhooks. The integration lives on the
workspace (one per GitHub repository).

### Step 1: Required token scopes

| Token type | Scopes needed |
|---|---|
| Classic PAT | `repo` (includes webhook management) |
| Fine-grained PAT | **Issues**: Read & Write · **Webhooks**: Read & Write |
| OAuth (existing) | `repo` scope — already covers webhooks |

> **Note**: `admin:repo_hook` is a sub-scope of `repo` in classic PATs.
> Fine-grained tokens require "Webhooks: Read & Write" explicitly.

### Step 2: Create the integration

1. Open the workspace's **Settings → Integrations** → **Issue trackers** →
   **New** (workspace admins; Argos administrators also find it on the
   workspace in the admin panel).
2. Set **Provider** to `GitHub` and choose the **Access** (OAuth account or
   stored PAT).
3. Pick the **Project / Team** — the repository in `owner/repo` format. Use
   the workspace's own repository if merged pull requests should close the
   issues.
4. Choose a **Mode**:
   - **Webhook (push)** — real-time; requires `APP_URL` to be publicly
     reachable.
   - **Polling** — periodic; works behind firewalls or in local development.
5. Optionally set a **Labels filter** so only relevant issues start automatic
   tasks, and decide on **Close the ticket on merge**.
6. Save. The integration starts in `Pending` state.

### Step 3: Activate with "Set up"

Click **Set up** on the integration's row:

- **Webhook mode**: Argos calls `POST /repos/{owner}/{repo}/hooks` on GitHub and
  registers `${APP_URL}/webhooks/issues/github/{integration-id}`
  automatically. The integration moves to `Active` once GitHub confirms the
  hook.
- **Poll mode**: The integration moves to `Active` immediately.

If setup fails (invalid token, missing scopes, `APP_URL` not reachable), it
stays `Pending` and the error is shown in **Last error**. Fix it and click
**Set up** again.

### Webhook vs. Poll

| | Webhook | Poll |
|---|---|---|
| Latency | Seconds | Minutes (scheduled interval) |
| Requires public `APP_URL` | Yes | No |
| GitHub rate limits | Not applicable | Counts against API quota |

### Behaviour notes

- **issue_comment** events are accepted by the webhook endpoint but ignored.
  Only `issues` events (opened, edited, etc.) can start a task. Comments reach
  the task through the scheduled ticket-comment sync.
- **Issue state and the task are independent.** Closing a GitHub issue does
  not stop or cancel its task; merging the pull request closes the issue
  (`Closes #…`) when *Close the ticket on merge* is on (otherwise the pull
  request says `Refs #…`).
- **Assignee:** a hand-over makes the new owner's GitHub login (their
  connected account) the issue's assignee.
- **Duplicate-safe**: re-deliveries (same `X-GitHub-Delivery`) are ignored.
  Pull requests appearing in the issues list are filtered out.

For the shared details, see [Issue Triggers](SETUP-TASK-PROVIDERS.md) and
[Tickets](TICKETS.md).

---

## Pull requests on GitHub

- Argos reads check runs and commit statuses, reviews and review comments of
  the task's pull request, and the job logs of failed GitHub Actions runs
  (see [Pull Requests](PULL-REQUESTS.md)). A classic PAT with `repo` or the
  OAuth `repo` scope covers this.
- **Merge automatically** needs *Settings → General → Allow auto-merge* in the
  repository.

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
