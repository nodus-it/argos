# Bitbucket Setup

This guide covers how to connect Argos to Bitbucket Cloud repositories using
either a Repository Access Token or OAuth.

See [OAuth Overview](OAUTH.md) for how PAT and OAuth compare and which one to
pick.

> **Scope note — Bitbucket is a code provider only.** Argos uses Bitbucket for
> cloning, branches, and pull-request creation. Bitbucket is **not** wired as a
> task/issue provider: there is no Bitbucket option when binding a task source,
> and Argos does not ingest Bitbucket issues into tasks or register issue
> webhooks for them. See
> [Task Providers](SETUP-TASK-PROVIDERS.md) for the providers that do drive
> the issue → task flow (GitHub, GitLab, Linear).

---

## What works with Bitbucket

| Capability | Supported |
|---|---|
| Clone repository | Yes |
| List repositories / branches (for project form dropdowns) | Yes |
| Create / update / comment on pull requests | Yes |
| Read the default branch and source files | Yes |
| Issue → task ingest (a Bitbucket issue becoming an Argos task) | **No** |
| Issue webhooks / 👍-approval flow | **No** |

---

## Option 1: Repository Access Token

Repository Access Tokens are scoped to a single repository and use Bearer
authentication. They are the recommended option and require no server-side
OAuth configuration.

> **Note:** Bitbucket App Passwords are deprecated and will be disabled on
> **2026-06-09**. Use Repository Access Tokens instead.

### Step 1: Create a Repository Access Token

1. Log in to Bitbucket Cloud.
2. Navigate to your repository.
3. Go to **Repository Settings** → **Access tokens** (under "Security").
4. Click **Create Repository Access Token**.
5. Give it a descriptive name (e.g. `argos`) and select the following
   permissions:
   - **Repositories**: Read, Write
   - **Pull requests**: Read, Write
   - **Issues**: Read (optional — only needed if you want Argos to read issue
     content from the repo; Bitbucket is not a task provider, so this does not
     create tasks)
6. Click **Create** and copy the generated token — it will only be shown once.

### Step 2: Configure in Argos

In **Projects → New Project**:

- **Platform**: Bitbucket
- **Repo URL**: `https://bitbucket.org/<workspace>/<repository>`
- **Token**: paste the token directly (no username prefix)

> **Important**: Do **not** prepend your username. Repository Access Tokens are
> used as Bearer tokens — just paste the token as-is. Argos detects the auth
> mode (Basic vs. Bearer) from the token shape.

### Alternative: Atlassian API Token (workspace-wide access)

If you need access across multiple repositories, you can use an Atlassian API
Token with Basic authentication:

- **Token format**: `your-email@example.com:your-api-token`
- Create an API Token at
  [id.atlassian.com/manage-profile/security/api-tokens](https://id.atlassian.com/manage-profile/security/api-tokens)

---

## Option 2: OAuth (optional)

OAuth enables repository and branch dropdowns in the project form and per-user
account binding — each user connects their own Bitbucket account instead of
pasting a token.

OAuth apps are managed **in the Argos UI**, not via environment variables.
There is no `BITBUCKET_CLIENT_ID` / `BITBUCKET_CLIENT_SECRET` to set. The full
UI flow is described in
[OAuth Overview → Registering the OAuth app in Argos](OAUTH.md#registering-the-oauth-app-in-argos).

### Step 1: Create an OAuth Consumer on Bitbucket

1. Log in to Bitbucket Cloud and navigate to the workspace you want to connect.
2. Go to **Workspace Settings** → **OAuth consumers** (under
   "Apps and Features").
3. Click **Add consumer**.
4. Fill in the details:
   - **Name**: e.g. `Argos`
   - **Callback URL**: `${APP_URL}/auth/bitbucket/callback`
     (replace `${APP_URL}` with your Argos instance URL, e.g.
     `https://argos.example.com`). Register exactly that URL, including the
     scheme.
5. Select the following permissions (these map to the OAuth scopes Argos
   requests: `account`, `repository`, `pullrequest`, `issue`):
   - **Account**: Read
   - **Repositories**: Read, Write
   - **Pull requests**: Read, Write
   - **Issues**: Read
6. Click **Save** and note the **Key** (client ID) and **Secret**
   (client secret).

### Step 2: Add the OAuth App in Argos

1. Open **Configuration → OAuth Apps** in the Argos admin.
2. Add an app for **Bitbucket**, paste its **Key** (client ID) and **Secret**
   (client secret), and enable it.
3. The **callback URL** shown in the form is read-only and derived from
   `APP_URL` — it is fixed at `${APP_URL}/auth/bitbucket/callback`. Copy it into
   the Bitbucket OAuth consumer's Callback URL if you have not already.

Credentials are stored in the database (`provider_oauth_configs`) and take
effect immediately, without a restart.

### Step 3: Connect your account

1. Go to **Connected Accounts** in the Argos admin panel.
2. Click **Connect Bitbucket**.
3. Authorize the OAuth consumer in Bitbucket.
4. When creating a project, select **Bitbucket** as the platform and choose
   **OAuth** as the authentication method to pull repositories and branches
   from the connected account.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 403 on issues endpoint | Issue tracker is disabled for the repository (issues are returned as an empty list) |
| 401 on push | Token missing Repositories Write permission, or token pasted with an accidental username prefix |
| PR creation returns 409 | A PR for this branch already exists — Argos will find and report the existing URL |
| OAuth redirect fails | The callback URL registered in the OAuth consumer must exactly match `${APP_URL}/auth/bitbucket/callback` — verify `APP_URL` and that the OAuth app is enabled under **Configuration → OAuth Apps** |
| Expecting a Bitbucket issue to become an Argos task | Not supported — Bitbucket is a code provider only. Use [GitHub/GitLab/Linear](SETUP-TASK-PROVIDERS.md) as a task provider |
