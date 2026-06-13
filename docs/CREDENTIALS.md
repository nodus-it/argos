# Provider Credentials

Argos has to authenticate to your Git hosts (GitHub, GitLab, Bitbucket) and
issue trackers (Linear) on your behalf — to clone repositories, push branches,
open pull requests, and read or update issues. It can get the token it needs in
two ways: a **Provider Credential** (a Personal Access Token you store) or a
**Connected Account** (an OAuth login).

This document is about the first one — **Provider Credentials, i.e. Personal
Access Tokens (PATs)**. The OAuth side is covered in [OAUTH.md](OAUTH.md).

> Provider credentials are *not* the same as agent credentials (the
> Claude/Codex token the worker uses to think). Those are configured
> separately — see [AGENTS.md](AGENTS.md).

## Contents

- [PAT vs OAuth: which do I use?](#pat-vs-oauth-which-do-i-use)
- [What a provider credential is](#what-a-provider-credential-is)
- [Adding a credential](#adding-a-credential)
  - [Required scopes per provider](#required-scopes-per-provider)
  - [Self-hosted instances](#self-hosted-instances)
  - [Test connection](#test-connection)
- [Security](#security)
- [How a project uses a credential](#how-a-project-uses-a-credential)
- [How an issue tracker uses a credential](#how-an-issue-tracker-uses-a-credential)
- [Expiry, rotation, and status](#expiry-rotation-and-status)

## PAT vs OAuth: which do I use?

Both end in the same place: a token string that Argos uses to talk to the
provider API. They differ in how that token is obtained and managed.

| | Provider Credential (PAT) | Connected Account (OAuth) |
| --- | --- | --- |
| What you supply | A token string you mint at the provider | A login through the provider's OAuth flow |
| Prerequisite | None — just the token | An [OAuth App](OAUTH.md) registered first |
| Bound to | Nothing — it is account-level and reusable | The Argos user who connected it |
| Refresh | Static, never refreshed | Refreshed automatically when near expiry |
| Best for | Service/bot accounts, self-hosted instances, quick setup, CI-style usage | Individual users, "log in with GitHub" convenience |

Quick decision pointer:

- **Use a PAT** when you want a stable, account-independent token — for
  example a dedicated bot/service account, a self-hosted GitLab/Bitbucket
  instance, or when you do not want to register an OAuth app.
- **Use OAuth** when you'd rather log in interactively and let Argos handle
  token refresh for you. See [OAUTH.md](OAUTH.md).

You do not have to pick one globally — each project and each issue-tracker
binding chooses its own credential source independently, and a PAT and an
OAuth account can coexist for the same provider.

## What a provider credential is

A provider credential is a **named, reusable Personal Access Token** for one
integration provider. It carries:

- **Label** — a free-form name you choose to recognize it by (e.g.
  `GitHub – acme org`).
- **Platform** — one of GitHub, GitLab, Bitbucket, or Linear.
- **Instance URL** — optional, only for self-hosted instances; empty means the
  public SaaS host.
- **Token** — the Personal Access Token itself (for Bitbucket this is an *App
  password*), stored encrypted.
- **Scopes (note)** — an optional free-text reminder of which scopes the token
  was minted with. This is a note only; it does not restrict the token.
- **Status** — `Active`, `Expired`, or `Revoked`.
- **Last validated** — when Argos last confirmed the token works.

Unlike a Connected Account, a provider credential is not tied to any Argos user
and needs no OAuth app — you create it once and reference it wherever a token
for that provider is needed.

## Adding a credential

Provider credentials are managed in the admin panel under the **Configuration**
navigation group, on the **Access Tokens** page:

```
${APP_URL}/admin/provider-credentials
```

You can also create one during onboarding — in the authorize/repository step,
stored Access Tokens appear alongside OAuth accounts as a token source.

To add a credential:

1. Pick the **Platform** (and, for a self-hosted server, fill in the
   **Instance URL**).
2. A direct, pre-filled link appears — **Create token at &lt;provider&gt;** —
   that opens the provider's token-creation page with the required scopes
   already selected where the provider supports it. Follow it, mint the token,
   and copy it back.
3. Give the credential a **Label**.
4. Paste the **Token**.
5. Optionally note the scopes in **Scopes (note)**.
6. Save, then run **Test connection** (see below).

### Required scopes per provider

These are the scopes Argos requests on the pre-filled creation link. Mint the
token with at least these. See the per-provider setup docs for the full
walkthrough.

| Provider | Scopes | Setup doc |
| --- | --- | --- |
| GitHub | `repo` | [SETUP-GITHUB.md](SETUP-GITHUB.md) |
| GitLab | `api`, `write_repository` | [SETUP-GITLAB.md](SETUP-GITLAB.md) |
| Bitbucket (App password) | Repositories (read/write), Pull requests, Webhooks | [SETUP-BITBUCKET.md](SETUP-BITBUCKET.md) |
| Linear | `read`, `write` | [SETUP-LINEAR.md](SETUP-LINEAR.md) |

Note that GitHub uses a single broad `repo` scope; GitLab needs both `api` and
`write_repository`; Bitbucket has no PAT in the classic sense — you create an
**App password** with the listed permissions; Linear uses an API key with read
and write.

### Self-hosted instances

For a self-hosted GitLab (CE/EE) or Bitbucket Server, fill in the **Instance
URL** (e.g. `https://gitlab.example.com`). Leave it empty for the public
instance. When set, the instance URL is used both to build the token-creation
link and as the API host Argos talks to. The public defaults are
`https://github.com`, `https://gitlab.com`, `https://bitbucket.org`, and
`https://linear.app`.

### Test connection

After saving, use the **Test connection** action in the credentials table.
Argos makes a single cheap, authenticated API call with the stored token:

- On success, the credential is marked **Active** and **Last validated** is
  timestamped.
- A definitive rejection (the provider returns 400/401/403, typically a bad or
  under-scoped token) is reported with the provider's own message, and the
  status is left unchanged.
- If the provider simply could not be reached (network/5xx), Argos says so and
  again leaves the status unchanged — the token was not proven bad.

The provider's error body never contains the token, so its message is safe to
show.

## Security

- The token is **stored encrypted** at rest (Laravel encrypted cast) and is
  decrypted only when a token string is actually needed for an API call.
- Tokens are **never logged** — not in the app, not in the worker, not even for
  diagnostics.
- The token field in the form is masked (revealable on demand) so it isn't
  shown in plain text by default.

## How a project uses a credential

When you create a project (RepoProfile), you choose a **token source** for the
repository — either an OAuth account or a stored Access Token. If you pick a
PAT, its token and platform are used to set up the project's git
authentication, with the project's auth method recorded as `pat`. At run time
the worker resolves the project's token from that PAT (OAuth projects instead
resolve — and refresh — the token from their Connected Account).

For the full project-authentication walkthrough, see
[PROJECTS.md](PROJECTS.md).

## How an issue tracker uses a credential

When you attach an issue tracker to a project (a task-provider binding), the
**Access** picker lets you choose either a connected OAuth account or one of
your stored Access Tokens for that provider, in one unified list. If you choose
a PAT, the binding references that credential directly, and the issue tracker
(reading issues, writing back) authenticates with it.

This is why the set of providers a credential can serve is deliberately broad:
a single credential can apply to both the git role (clone/push/PR) and the
issue-tracker role.

## Expiry, rotation, and status

Provider credentials (PATs) are **static**: Argos stores the token you give it
and uses it as-is. There is no refresh mechanism — that only applies to OAuth
Connected Accounts (handled in [OAUTH.md](OAUTH.md)).

Consequently, **rotation is manual**:

- When a token nears the expiry you set at the provider, mint a new one and
  update the credential's **Token** field, then **Test connection** to
  re-validate.
- The **Status** field (`Active` / `Expired` / `Revoked`) records the
  credential's current standing. **Last validated** tells you when Argos last
  confirmed the token worked — use **Test connection** to refresh it after a
  rotation.

If a token expires or is revoked at the provider, the next run that uses it
will fail to authenticate; re-test or replace the token to recover.
