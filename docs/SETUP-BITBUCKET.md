# Bitbucket Setup Guide

This guide covers how to connect Argos to Bitbucket Cloud repositories using either an App Password (PAT) or OAuth.

---

## Option 1: App Password (PAT)

App Passwords are Bitbucket's equivalent of Personal Access Tokens. They are the simpler option and require no server-side OAuth configuration.

### Step 1: Create an App Password

1. Log in to Bitbucket Cloud.
2. Click your avatar → **Personal Settings** → **App passwords** (under "Access Management").
3. Click **Create app password**.
4. Give it a descriptive name (e.g. `argos`).
5. Select the following scopes:
   - **Repositories**: Read, Write
   - **Pull requests**: Read, Write
   - **Issues**: Read, Write
6. Click **Create** and copy the generated password — it will only be shown once.

### Step 2: Configure in Argos

In **Projects → New Project**:
- **Platform**: Bitbucket
- **Repo URL**: `https://bitbucket.org/<workspace>/<repository>`
- **Token**: `<your-bitbucket-username>:<your-app-password>`

> **Important**: The token must be in `username:app_password` format. Do not use your Bitbucket account password here.

---

## Option 2: OAuth (optional)

OAuth enables repository and branch dropdowns in the project form. It requires registering an OAuth consumer in your Bitbucket workspace.

### Step 1: Create an OAuth Consumer

1. Log in to Bitbucket Cloud and navigate to the workspace you want to connect.
2. Go to **Workspace Settings** → **OAuth consumers** (under "Apps and Features").
3. Click **Add consumer**.
4. Fill in the details:
   - **Name**: e.g. `Argos`
   - **Callback URL**: `{APP_URL}/auth/bitbucket/callback`
     (replace `{APP_URL}` with your Argos instance URL, e.g. `https://argos.example.com`)
5. Select the following permissions:
   - **Account**: Read
   - **Repositories**: Read, Write
   - **Pull requests**: Read, Write
   - **Issues**: Read, Write
6. Click **Save** and note the **Key** (Client ID) and **Secret** (Client Secret).

### Step 2: Configure Environment Variables

Add these variables to your `.env` file:

```env
BITBUCKET_CLIENT_ID=<your-oauth-consumer-key>
BITBUCKET_CLIENT_SECRET=<your-oauth-consumer-secret>
```

The callback URL is fixed at `${APP_URL}/auth/bitbucket/callback` — that is
what you registered with the OAuth consumer in step 1, no extra variable
needed.

### Step 3: Connect Your Account

1. Go to **Connected Accounts** in the Argos admin panel.
2. Click **Connect Bitbucket**.
3. Authorize the OAuth consumer in Bitbucket.
4. When creating a project, select **Bitbucket** as the platform and choose **OAuth** as the authentication method.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 403 on issues endpoint | Issue tracker is disabled for the repository (issues will be an empty list) |
| 401 on push | App Password missing Repositories Write scope, or wrong `username:app_password` format |
| PR creation returns 409 | A PR for this branch already exists — Argos will find and report the existing URL |
| OAuth redirect fails | The callback URL registered in the OAuth consumer must exactly match `${APP_URL}/auth/bitbucket/callback` — verify `APP_URL`. |
