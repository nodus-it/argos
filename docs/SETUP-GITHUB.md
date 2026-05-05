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
GITHUB_REDIRECT_URI="${APP_URL}/auth/github/callback"
```

Restart the manager so the new vars are picked up.

### Step 3: Connect your account

1. Sign in to Argos.
2. Go to **Connected Accounts** in the navigation.
3. Click **Connect GitHub** and approve the OAuth flow.
4. When creating a project, select **GitHub** as the platform and choose
   **OAuth** as the authentication method — the repo and branch dropdowns
   will appear, populated from your account.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 401 on push / PR creation | PAT missing the `repo` scope, or token expired. |
| 403 when pushing to `.github/workflows/*` | PAT missing the `workflow` scope. |
| OAuth redirect fails with "redirect_uri mismatch" | `GITHUB_REDIRECT_URI` does not match the callback URL registered in the OAuth App. Both must be exact, including scheme and trailing path. |
| OAuth callback returns 500 | `APP_URL` not set or doesn't match the public URL — Laravel can't generate the correct callback. |
| PR creation returns 422 with "A pull request already exists" | Argos detects this and reports the existing PR URL — no action needed. |
