# GitLab Setup

Argos supports GitLab with two authentication methods: Personal Access Token
(PAT) and OAuth. Both work with gitlab.com and with self-hosted GitLab
(CE/EE) instances.

See [OAuth Overview](OAUTH.md) for how PAT and OAuth compare and which one to
pick.

---

## Option 1: Personal Access Token (PAT)

Recommended for single-user instances or when you just want to try Argos. PAT
works without any server-side configuration — you only paste the token in the
project form.

### Step 1: Create a token

1. Open **GitLab → User Settings → Access Tokens** (on a self-hosted instance
   the path is `https://git.example.com/-/user_settings/personal_access_tokens`).
2. Give it a descriptive name (e.g. `argos`).
3. Select these scopes:
   - `api` — full API access (required for listing repositories and creating
     merge requests).
   - `write_repository` — push access to repositories.
4. Set an expiry date (or leave it empty for a long-lived token).
5. Copy the token — it is shown only once.

### Step 2: Configure the project in Argos

Under **Projects → New Project**:

- **Platform**: GitLab
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: full HTTPS URL, e.g. `https://gitlab.com/your-group/your-project`,
  or `https://git.example.com/your-group/your-project` for a self-hosted
  instance.
- **Token**: paste the token from step 1.
- **Default Branch**: e.g. `main`

---

## Option 2: OAuth (optional)

OAuth enables repo and branch dropdowns in the project form and per-user
account binding — no manual URL entry, and each user connects their own GitLab
account.

OAuth apps are managed **in the Argos UI**, not via environment variables.
There is no `GITLAB_CLIENT_ID` / `GITLAB_CLIENT_SECRET` / `GITLAB_INSTANCE_URL`
to set. The full UI flow is described in
[OAuth Overview → Registering the OAuth app in Argos](OAUTH.md#registering-the-oauth-app-in-argos).

### Step 1: Register an OAuth App on GitLab

#### gitlab.com

1. Open **User Settings → Applications**
   (`https://gitlab.com/-/user_settings/applications`). For org-wide use, ask
   your admin for a Group or Instance application instead.
2. Fill in:
   - **Name**: `Argos` (or any descriptive name).
   - **Redirect URI**: `https://argos.example.com/auth/gitlab/callback` — the
     callback is fixed at `${APP_URL}/auth/gitlab/callback`. Register exactly
     that URL, including the scheme.
   - **Scopes**: `read_user`, `api`.
3. Save and copy the **Application ID** and **Secret**.

#### Self-hosted GitLab

Same steps, but on your own instance:

- **User application**:
  `https://git.example.com/-/user_settings/applications`
- **Admin Area application** (for shared, instance-wide use):
  `https://git.example.com/admin/applications`

The redirect URI is still `${APP_URL}/auth/gitlab/callback`, and the scopes are
still `read_user`, `api`.

### Step 2: Add the OAuth App in Argos

1. Open **Configuration → OAuth Apps** in the Argos admin.
2. Add an app for **GitLab**, paste its **Application ID** (client ID) and
   **Secret** (client secret), and enable it.
3. For a self-hosted instance, set the **Instance URL** field on the app
   (e.g. `https://git.example.com`, no trailing slash). Leave it empty for
   gitlab.com. This is the only place the self-hosted URL is configured —
   there is no `GITLAB_INSTANCE_URL` environment variable.
4. The **callback URL** shown in the form is read-only and derived from
   `APP_URL` — copy it into the GitLab OAuth app's Redirect URI if you have
   not already.

Credentials are stored in the database (`provider_oauth_configs`) and take
effect immediately, without a restart. You can register multiple GitLab apps
side by side — for example one for gitlab.com and one per self-hosted instance.

### Step 3: Connect your account

1. Sign in to Argos.
2. Go to **Connected Accounts** in the navigation.
3. Click **Connect with GitLab** — you are redirected to GitLab to authorize
   the app. After authorizing, you are returned to the Connected Accounts page.
4. When creating a project, select **GitLab** as the platform and choose
   **OAuth** as the authentication method — the repo and branch dropdowns
   appear, populated from your connected account.

> For gitlab.com the account's `instance_url` is stored empty (defaults to
> `https://gitlab.com`); for a self-hosted account it carries the instance URL
> from the OAuth app you connected through.

---

## Option 3: Issue-Provider (Pull + Webhook)

Argos can import GitLab issues as Tasks and automatically leave a comment on the
issue when a phase completes. Each repository needs a **TaskProviderBinding**,
created through the Filament admin interface.

### Step 1: Required token scopes

| Token type | Scopes needed |
|---|---|
| Personal Access Token (PAT) | `api` — full API access including webhook management |
| OAuth | `api` (already set when you registered the OAuth app) |

> **Note**: the `api` scope is required for webhook registration
> (`POST /projects/:id/hooks`). Without it, webhook setup fails with 403 and the
> binding stays in `Pending` state.

### Step 2: Create a binding in Filament

1. In the admin panel, open the target project under **Repo Profiles**.
2. Go to the **Task Provider Bindings** tab → **New Binding**.
3. Fill in the fields:

| Field | Description |
|---|---|
| **Provider** | GitLab |
| **Mode** | `Webhook` (real-time) or `Poll` (every 5 min) |
| **Connected Account** | An OAuth or PAT account with the `api` scope |
| **Project / Team Ref** | Project path in `group/project` format, e.g. `acme/widget` |
| **Filters** | Optional: import only issues with a given state or labels |

4. Click **Save**. The binding starts in `Pending` state.

### Step 3: Activate with "Einrichten"

Click the **Einrichten** (Setup) action on the binding:

- **Webhook mode**: Argos registers a webhook on GitLab via
  `POST /projects/:id/hooks` with `issues_events: true`. The callback URL
  `${APP_URL}/webhooks/issues/gitlab/{binding-id}` and the secret are generated
  automatically — no manual entry in GitLab needed. The binding moves to
  `Active`.
- **Poll mode**: The binding moves to `Active` immediately. The poll scheduler
  fetches issues on its next run.

If setup fails (missing `api` scope, wrong project path, `APP_URL` not
reachable), the binding stays `Pending` and the error appears in the
**Last Error** column. Fix the issue and click **Einrichten** again.

### Webhook vs. Poll

| | Webhook | Poll |
|---|---|---|
| Latency | Seconds | Minutes (scheduler interval) |
| Requires public `APP_URL` | Yes | No |
| GitLab API rate limits | Not applicable | Counts against quota |

### Behaviour notes

- **Signature**: GitLab sends the secret as a plain token in the
  `X-Gitlab-Token` header (not an HMAC). Argos compares it directly with
  `hash_equals`.
- **Idempotency**: duplicate deliveries are detected via the
  `X-Gitlab-Event-UUID` header and discarded.
- **Note and MR hooks**: GitLab may also send `note` and `merge_request` events
  to the same webhook URL. These are recognised (`object_kind ≠ issue`) and
  discarded without creating a Task.
- **Confidential issues**: the webhook is registered with
  `confidential_issues_events: true`, so confidential issues are processed too.
- **Self-hosted GitLab**: the tracker automatically uses the `instance_url` of
  the linked `ConnectedAccount`. For gitlab.com this stays empty (defaults to
  `https://gitlab.com`).
- **Labels**: GitLab issues return labels as a string array in API responses;
  webhook payloads return label objects in the top-level `labels` array. Argos
  normalises both formats automatically.
- **State**: GitLab uses `opened`/`closed` (not `open`). The default state when
  polling is `opened`.

For the shared binding setup details, see
[Task Providers Setup](SETUP-TASK-PROVIDERS.md).

---

## Worker: REPO_PLATFORM

The manager passes `REPO_PLATFORM=gitlab` to the worker container as an
environment variable. The push phase uses this to detect the platform reliably
— even for self-hosted GitLab instances with non-obvious hostnames — and pushes
with `-o merge_request.create` to create the merge request automatically.

---

## Notes

- GitLab API authentication uses `Authorization: Bearer <token>` for both PAT
  and OAuth tokens. The `PRIVATE-TOKEN` header is **not** used — GitLab accepts
  Bearer for both token types.
- For gitlab.com, the `instance_url` in `connected_accounts` is stored empty
  (it defaults to `https://gitlab.com`).
- Self-hosted MR URLs are extracted from the git push output and stored on the
  task record.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 401 on push / MR creation | PAT missing the `api` or `write_repository` scope, or the token expired. |
| 403 during webhook setup | The connected account's token lacks the `api` scope. Reconnect or recreate the token with `api`. |
| OAuth redirect fails with "redirect_uri mismatch" | The Redirect URI on the GitLab OAuth app must exactly match `${APP_URL}/auth/gitlab/callback`, including the scheme. Verify `APP_URL`. |
| OAuth callback returns 500 | `APP_URL` not set or not matching the public URL — Laravel cannot generate the correct callback. |
| Self-hosted OAuth hits gitlab.com instead of your instance | The **Instance URL** field on the OAuth App in Argos is empty or wrong. Set it to your instance URL (no trailing slash) under **Configuration → OAuth Apps**. |
