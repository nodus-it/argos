# OAuth Overview

Argos supports two ways of authenticating against your Git host:

1. **Personal Access Token (PAT)** — paste a token per project. Works
   immediately, no server-side configuration.
2. **OAuth** — register an OAuth App on the provider, set client ID/secret in
   the Argos environment, and let users connect their own accounts.

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

## Required environment variables

For each provider you enable OAuth on, set client ID and secret. The callback
URL is fixed at `${APP_URL}/auth/<provider>/callback` — register exactly that
URL in the provider's OAuth app, no further config needed.

```env
GITHUB_CLIENT_ID=...
GITHUB_CLIENT_SECRET=...

GITLAB_CLIENT_ID=...
GITLAB_CLIENT_SECRET=...
# GITLAB_INSTANCE_URL=https://gitlab.example.com  # optional, for self-hosted

BITBUCKET_CLIENT_ID=...
BITBUCKET_CLIENT_SECRET=...
```

See [Configuration Reference](CONFIGURATION.md) for the full list.

## After OAuth is configured

The **Connected Accounts** page in the Argos navigation shows a "Connect"
button for each configured provider. Once connected, the **Authentication**
field in the project form gains an "OAuth" option that pulls repos and
branches from the connected account.
