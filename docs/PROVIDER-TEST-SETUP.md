# Provider Contract Tests — Setup

End-to-End-Suite, die `GitHubGitService`, `GitLabGitService` und `BitbucketGitService`
gegen ihre echten APIs ausführt. Repo-Koordinaten (Owner/Slug, Default-Branch, Clone-URL)
sind hard-coded in `tests/External/providers.defaults.php` — du musst lokal nur die PATs
in `.env.testing.external` setzen, in CI nur die drei Repo-Secrets.

```bash
touch .env.testing.external
chmod 600 .env.testing.external
php artisan test --configuration=phpunit.external.xml   # ohne Tokens: alles skipped, 0 Errors
```

## PATs anlegen

Pro Provider brauchst du einen Token, scoped auf das jeweilige Test-Repo. Direktlinks:

### GitHub — Fine-Grained PAT

<https://github.com/settings/personal-access-tokens/new>

- **Resource owner:** dein Account *oder* die Org, in der das Test-Repo liegt
- **Repository access:** *Only select repositories* → das Test-Repo wählen
- **Repository permissions:** Contents *RW*, Pull requests *RW*

Bei Org-Repos zusätzlich: Org-Policy unter `https://github.com/organizations/<ORG>/settings/personal-access-tokens-policy`
muss Fine-Grained PATs erlauben; bei *Require approval* genehmigt der Owner unter
`/organizations/<ORG>/settings/personal-access-tokens/pending_requests`. SSO-Orgs
wollen zusätzlich SSO-Authorization (Banner über dem Token nach dem Erstellen).

### GitLab — Project Access Token

`https://gitlab.com/<NAMESPACE>/<PROJECT>/-/settings/access_tokens`

- **Role:** *Maintainer* (Developer reicht für Push, MR-Close braucht Maintainer)
- **Scopes:** `api`, `write_repository`

User-PATs **nicht** verwenden — die sind nicht projekt-scopebar.

### Bitbucket — Repository Access Token

`https://bitbucket.org/<WORKSPACE>/<REPO_SLUG>/admin/access-tokens`

- **Scopes:** `repository`, `repository:write`, `pullrequest`, `pullrequest:write`
- Token kommt **ohne Doppelpunkt** zurück → nutzt Bearer-Auth, einfach in die `.env`.

App Passwords sind tot (Atlassian-Deprecation, ab 2026-06-09 hart aus). Atlassian-Account-API-Tokens
wären eine Alternative, sind aber user-scoped und damit für die Suite zu breit.

## `.env.testing.external`

```dotenv
GITHUB_PAT=github_pat_xxx...
GITLAB_PAT=glpat-xxx...
BITBUCKET_PAT=ATCTT3xFf...
```

Mehr ist nicht nötig. Optional: `<PROVIDER>_TEST_REPO_OWNER`, `_TEST_REPO`, `_DEFAULT_BRANCH`,
`_TEST_REPO_CLONE_URL`, `_INSTANCE_URL` überschreiben die Defaults — nützlich für
self-hosted GitLab oder eigene Sandbox-Repos.

## Suite ausführen

```bash
php artisan test --configuration=phpunit.external.xml                              # alle drei
php artisan test --configuration=phpunit.external.xml --filter=GitHubContractTest  # nur einer
```

Erwartung: **18 Tests, 0 Errors** (4 Provider-Methoden + 2 Git-Wire-Tests pro Provider).

## OAuth-Pfad: `php artisan test:providers`

Die Suite testet nur den PAT-Pfad — bei GitHub und GitLab ist der OAuth-Code-Pfad bitidentisch
(beide Bearer-Auth), bei Bitbucket weicht er ab und wird über den Helper-Command abgedeckt.

```bash
php artisan test:providers           # vollständiger Flow: seed → connect → seed oauth → run
php artisan test:providers --reset   # alle [contract-test]-Profile + verlinkte Accounts löschen
php artisan test:providers --seed-only
```

Ablauf:

1. **Phase A** seedet drei `[contract-test] <provider>-pat`-RepoProfiles in der lokalen Argos-DB
   mit den Tokens aus `.env.testing.external`.
2. **Phase B** druckt drei OAuth-Connect-URLs (`/auth/{provider}/redirect`) und pollt die
   `connected_accounts`-Tabelle, bis du die Accounts im Browser verbunden hast.
3. **Phase C** seedet drei `[contract-test] <provider>-oauth`-RepoProfiles mit FK auf die
   frischen `ConnectedAccount`-Einträge.
4. **Phase D** fährt `phpunit.external.xml` zweimal — einmal mit den PAT-ENVs, einmal mit den
   DB-resident OAuth-Tokens, die der Command aus `connected_accounts.token` zieht und unter
   den `*_PAT`-ENVs an die Suite reicht (die Suite ist auth-mode-agnostisch).

Die Profile bleiben nach dem Run in der DB liegen — du kannst sie im Argos-UI für manuelle
Smoke-Tests (Task anlegen, Phasen-Run starten) verwenden. `--reset` räumt sie weg.

## CI: GitHub Actions

`.github/workflows/external-provider-tests.yml` läuft wöchentlich (Sonntag 04:00 UTC) und
on-demand per `workflow_dispatch`. Drei Repository-Secrets nötig:

- `EXTERNAL_GITHUB_PAT`
- `EXTERNAL_GITLAB_PAT`
- `EXTERNAL_BITBUCKET_PAT`

(Prefix `EXTERNAL_`, weil GitHub Actions `GITHUB_*`-Secret-Namen reserviert; der Workflow
re-exportiert sie unter `*_PAT`.)

OAuth-Tokens kommen in CI nicht vor — sie sind kurzlebig, und der OAuth-Pfad wird ohnehin
manuell über `test:providers` abgedeckt.

## Troubleshooting

| Symptom | Vermutliche Ursache |
| --- | --- |
| `401 Token is invalid, expired, or not supported` | Bitbucket Repository Access Token abgelaufen oder Scope unzureichend — neu erstellen |
| `git clone` failt mit `Authentication failed` | Token-Scope ungenügend; bei Bitbucket-Atlassian-API-Token: muss `email:api_token`-Form haben |
| `createPullRequest` failt mit `403` | Pull-Request-Write-Scope fehlt |
| `Test-Repo … nicht in listRepositories() gefunden` | Token sieht das Repo nicht — GitHub Org-Approval, GitLab Project-Token am richtigen Projekt, Bitbucket User-Zugriff prüfen |
| Cleanup-Warnings im stderr | Ein PR/Branch konnte nicht aufgeräumt werden — alle Test-Refs haben `argos-test/`-Prefix, manuell wegräumen |

## Token-Hygiene

- 90-Tage-Ablauf, Kalender-Reminder.
- Bei Auslaufen: alten Token revoken, neuen anlegen.
- `.env.testing.external` und `EXTERNAL_*_PAT`-Secrets niemals in Issues/PRs/Logs/Screenshots.
- Bei Verdacht auf Leak: sofort revoken, neuen erzeugen, an allen Stellen ersetzen.
