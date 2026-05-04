# GitLab Setup

Argos supports GitLab with two authentication methods: Personal Access Token (PAT) and OAuth. Both also work with Self-Hosted GitLab instances.

## Personal Access Token (PAT)

PAT works without any additional configuration beyond adding your token in the project form.

### Creating a PAT

1. Go to **GitLab → User Settings → Access Tokens** (or your instance equivalent)
2. Create a new token with the following scopes:
   - `api` — full API access (required for listing repositories, creating MRs)
   - `write_repository` — push access to repositories
3. Set an expiry date (or leave empty for no expiry)
4. Copy the token — it is shown only once

### Configuring a project with PAT

In the Argos project form:
- **Platform**: GitLab
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: full HTTPS URL, e.g. `https://gitlab.com/mygroup/myproject` or `https://git.example.com/mygroup/myproject` for Self-Hosted
- **Token (PAT)**: paste the token you created above
- **Default Branch**: e.g. `main`

---

## OAuth

OAuth enables repo and branch selection via dropdown — no manual URL needed.

### Prerequisites in `.env`

```
GITLAB_CLIENT_ID=your-app-id
GITLAB_CLIENT_SECRET=your-app-secret
GITLAB_REDIRECT_URI=${APP_URL}/auth/gitlab/callback
# GITLAB_INSTANCE_URL=https://gitlab.com  # Override for Self-Hosted
```

`GITLAB_INSTANCE_URL` defaults to `https://gitlab.com`. Set it to your Self-Hosted instance URL (no trailing slash) to use Self-Hosted OAuth.

### Registering the OAuth Application

#### gitlab.com

1. Go to **User Settings → Applications** (or ask your Admin for a Group/Instance app)
2. **Name**: Argos (or any descriptive name)
3. **Redirect URI**: `https://your-argos-app.example.com/auth/gitlab/callback`
4. **Scopes**: `read_user`, `api`
5. Save and copy the **Application ID** and **Secret**

#### Self-Hosted GitLab

Same steps, but in your instance:
- **User Applications**: `https://git.example.com/-/user_settings/applications`
- **Admin Area Applications** (for shared use): `https://git.example.com/admin/applications`

Also set `GITLAB_INSTANCE_URL=https://git.example.com` in `.env`.

### Connecting your account

1. Start Argos and log in
2. Go to **Connected Accounts**
3. Click **Connect with GitLab** — you will be redirected to GitLab for authorization
4. After authorizing, you are returned to the Connected Accounts page

### Configuring a project with OAuth

In the Argos project form:
- **Platform**: GitLab
- **Authentication**: OAuth (GitLab)
- Select your **GitLab account** from the dropdown
- Pick **Repository** and **Default Branch** from the dropdowns

---

## Worker: REPO_PLATFORM

The manager passes `REPO_PLATFORM=gitlab` to the worker container as an environment variable. The push phase uses this to detect the platform reliably — even for Self-Hosted GitLab instances with non-obvious hostnames — and pushes with `-o merge_request.create` to create the MR automatically.

---

## Notes

- GitLab API authentication uses `Authorization: Bearer <token>` for both PAT and OAuth tokens.
- The `PRIVATE-TOKEN` header is **not** used — GitLab accepts Bearer for both token types.
- For GitLab.com the `instance_url` in `connected_accounts` is stored as `NULL` (defaults to `https://gitlab.com`).
- Self-Hosted MR URLs are extracted from the git push output and stored in the task record.
