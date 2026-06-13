# Provider Contract Tests — Setup

End-to-end suite that runs `GitHubGitService`, `GitLabGitService`, and `BitbucketGitService`
against their real APIs. The repo coordinates (owner/slug, default branch, clone URL)
are hard-coded in `tests/External/providers.defaults.php` — locally you only need to set the
PATs in `.env.testing.external`, and in CI only the three repo secrets.

```bash
touch .env.testing.external
chmod 600 .env.testing.external
php artisan test --configuration=phpunit.external.xml   # without tokens: everything skipped, 0 errors
```

## Creating PATs

For each provider you need a token scoped to the respective test repo. Direct links:

### GitHub — Fine-Grained PAT

<https://github.com/settings/personal-access-tokens/new>

- **Resource owner:** your account *or* the org that hosts the test repo
- **Repository access:** *Only select repositories* → select the test repo
- **Repository permissions:** Contents *RW*, Pull requests *RW*

For org repos, additionally: the org policy under `https://github.com/organizations/<ORG>/settings/personal-access-tokens-policy`
must allow fine-grained PATs; if *Require approval* is set, the owner approves it under
`/organizations/<ORG>/settings/personal-access-tokens/pending_requests`. SSO orgs
additionally require SSO authorization (banner above the token after creation).

### GitLab — Project Access Token

`https://gitlab.com/<NAMESPACE>/<PROJECT>/-/settings/access_tokens`

- **Role:** *Maintainer* (Developer is enough for push, but closing an MR requires Maintainer)
- **Scopes:** `api`, `write_repository`

Do **not** use user PATs — they cannot be scoped to a project.

### Bitbucket — Repository Access Token

`https://bitbucket.org/<WORKSPACE>/<REPO_SLUG>/admin/access-tokens`

- **Scopes:** `repository`, `repository:write`, `pullrequest`, `pullrequest:write`
- The token comes back **without a colon** → it uses Bearer auth, so just drop it into the `.env`.

App Passwords are dead (Atlassian deprecation, hard cutoff on 2026-06-09). Atlassian account API
tokens would be an alternative, but they are user-scoped and therefore too broad for the suite.

## `.env.testing.external`

```dotenv
GITHUB_PAT=github_pat_xxx...
GITLAB_PAT=glpat-xxx...
BITBUCKET_PAT=ATCTT3xFf...
```

That's all you need. Optional: `<PROVIDER>_TEST_REPO_OWNER`, `_TEST_REPO`, `_DEFAULT_BRANCH`,
`_TEST_REPO_CLONE_URL`, `_INSTANCE_URL` override the defaults — useful for
self-hosted GitLab or your own sandbox repos.

## Running the suite

```bash
php artisan test --configuration=phpunit.external.xml                              # all three
php artisan test --configuration=phpunit.external.xml --filter=GitHubContractTest  # only one
```

Expectation: **18 tests, 0 errors** (4 provider methods + 2 git wire tests per provider).

## OAuth path: `php artisan test:providers`

The suite tests only the PAT path — for GitHub and GitLab the OAuth code path is bit-identical
(both Bearer auth), while Bitbucket differs and is covered via the helper command.

```bash
php artisan test:providers           # full flow: seed → connect → seed oauth → run
php artisan test:providers --reset   # delete all [contract-test] profiles + linked accounts
php artisan test:providers --seed-only
```

Flow:

1. **Phase A** seeds three `[contract-test] <provider>-pat` RepoProfiles into the local Argos DB
   with the tokens from `.env.testing.external`.
2. **Phase B** prints three OAuth connect URLs (`/auth/{provider}/redirect`) and polls the
   `connected_accounts` table until you have connected the accounts in the browser.
3. **Phase C** seeds three `[contract-test] <provider>-oauth` RepoProfiles with an FK to the
   fresh `ConnectedAccount` entries.
4. **Phase D** runs `phpunit.external.xml` twice — once with the PAT ENVs, once with the
   DB-resident OAuth tokens, which the command pulls from `connected_accounts.token` and passes
   to the suite under the `*_PAT` ENVs (the suite is auth-mode-agnostic).

The profiles remain in the DB after the run — you can use them in the Argos UI for manual
smoke tests (create a task, start a phase run). `--reset` clears them out.

## CI: GitHub Actions

`.github/workflows/external-provider-tests.yml` runs weekly (Sunday 04:00 UTC) and
on demand via `workflow_dispatch`. Three repository secrets are required:

- `EXTERNAL_GITHUB_PAT`
- `EXTERNAL_GITLAB_PAT`
- `EXTERNAL_BITBUCKET_PAT`

(Prefix `EXTERNAL_` because GitHub Actions reserves `GITHUB_*` secret names; the workflow
re-exports them under `*_PAT`.)

OAuth tokens do not appear in CI — they are short-lived, and the OAuth path is covered
manually via `test:providers` anyway.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 Token is invalid, expired, or not supported` | Bitbucket Repository Access Token expired or scope insufficient — recreate it |
| `git clone` fails with `Authentication failed` | Insufficient token scope; for a Bitbucket Atlassian API token: it must use the `email:api_token` form |
| `createPullRequest` fails with `403` | Pull-request write scope missing |
| `Test repo … not found in listRepositories()` | The token doesn't see the repo — check GitHub org approval, that the GitLab project token is on the right project, and Bitbucket user access |
| Cleanup warnings on stderr | A PR/branch couldn't be cleaned up — all test refs carry the `argos-test/` prefix, remove them manually |

## Token hygiene

- 90-day expiry, set a calendar reminder.
- On expiry: revoke the old token, create a new one.
- Never put `.env.testing.external` or the `EXTERNAL_*_PAT` secrets in issues/PRs/logs/screenshots.
- If you suspect a leak: revoke immediately, generate a new one, replace it everywhere.
