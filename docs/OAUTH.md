# OAuth Overview

Argos supports two ways of authenticating against your Git host:

1. **Personal Access Token (PAT)** — paste a token per project. Works
   immediately, no server-side configuration.
2. **OAuth** — register an OAuth App on the provider, add its client ID/secret
   in the Argos UI (Configuration → OAuth Apps), and let users connect their
   own accounts.

## Which one should I pick?

| Situation | Recommended |
|---|---|
| Just trying Argos out, single user | **PAT** |
| Solo developer, one Git host | **PAT** |
| Multiple users, each with their own Git account | **OAuth** |
| You want repo/branch dropdowns in the project form | **OAuth** |
| Self-hosted GitLab with many repos | **OAuth** |
| Air-gapped or no public callback URL reachable from the provider | **PAT** |

You can mix and match: configure OAuth for one provider and use PATs for
another. Each project picks its own auth method at creation time.

## What OAuth gets you

- **Repository dropdown** — pick from a list instead of pasting URLs.
- **Branch dropdown** — see all branches of the selected repo.
- **Per-user accounts** — every user in Argos connects their own provider
  account. Tokens are per-user, not shared.
- **Auto-default-branch** — Argos reads the repo's default branch from the
  provider when you select it.

PAT projects keep working alongside OAuth projects — switching is per-project.

## What OAuth costs you

- One-time provider-side setup (OAuth App / Consumer registration).
- A public callback URL: the provider must be able to redirect users back to
  `${APP_URL}/auth/<provider>/callback`. If your Argos instance is purely
  internal, OAuth won't work — use PAT.

## Provider-specific guides

- [GitHub Setup](SETUP-GITHUB.md)
- [GitLab Setup](SETUP-GITLAB.md) — supports self-hosted instances
- [Bitbucket Setup](SETUP-BITBUCKET.md)

## Registering the OAuth app in Argos

OAuth apps are managed **in the UI** — there are no `*_CLIENT_ID` /
`*_CLIENT_SECRET` environment variables. After creating the OAuth App on the
provider side:

1. Open **Configuration → OAuth Apps** in the Argos admin.
2. Add an app for the provider, paste its **client ID** and **client secret**,
   and enable it. For self-hosted GitLab, set the instance URL on the app
   itself (no `GITLAB_INSTANCE_URL` environment variable needed).
3. The callback URL is fixed at `${APP_URL}/auth/<provider>/callback` —
   register exactly that URL in the provider's OAuth app.

Credentials are stored in the database (`provider_oauth_configs`) and take
effect without a restart. See [Configuration Reference](CONFIGURATION.md) for
environment variables that *are* still ENV-based.

## After OAuth is configured

The **Connected Accounts** page in the Argos navigation shows a "Connect"
button for each configured provider. Once connected, the **Authentication**
field in the project form gains an "OAuth" option that pulls repos and
branches from the connected account.

## Token refresh

Bitbucket and GitLab issue short-lived access tokens (~2h); GitHub OAuth Apps
with token expiration enabled behave the same way (~8h). Argos refreshes the
access token via the stored `refresh_token` whenever a worker job is about to
dispatch and the token has less than 1h of validity left, so a freshly started
job always begins with a token that survives the worker's job timeout.

If a refresh fails (revoked token, provider 4xx, missing `refresh_token`), the
phase fails fast with a "bitte Account neu verbinden" message — reconnect the
account on the **Connected Accounts** page to mint a fresh token + refresh
token pair, then re-run the task.
