# Provider Contract Tests — Setup-Anleitung

Schritt-für-Schritt für **GitHub**, **GitLab** und **Bitbucket**. Am Ende steht ein lauffähiger
`php artisan test --configuration=phpunit.external.xml`, der echte API-Calls gegen
deine privaten Test-Repos absetzt.

## Vorab: einmalig

```bash
touch .env.testing.external
chmod 600 .env.testing.external
```

`.env.testing.external` ist gitignored. Tokens kommen ausschließlich dort hinein, niemals
in Code oder normale `.env`-Dateien. Die unten aufgeführten Keys pro Provider in die Datei
übernehmen, leere Werte sind erlaubt — was nicht gesetzt ist, wird übersprungen.

Zum Verifizieren ohne Credentials:

```bash
php artisan test --configuration=phpunit.external.xml
```

→ alle Tests skipped, keine Fehler. Das ist der grüne Ausgangszustand.

---

## GitHub

### 1. Test-Repo anlegen

- **URL:** <https://github.com/new>
- **Owner:** dein Account oder eine Test-Org
- **Name:** z.B. `argos-provider-contract`
- **Visibility:** *Private*
- **Initialize with README:** ✅ ankreuzen, sonst gibt es keinen `main`-Branch und die Suite scheitert beim `clone`.

Notiere: `OWNER/REPO` → kommt gleich in die `.env`.

### 2. Fine-Grained PAT

- **URL:** <https://github.com/settings/personal-access-tokens/new>
- **Token name:** `argos-contract-tests`
- **Expiration:** 90 days (bewusst kurz)
- **Resource owner:** dein User-Account (oder die Org, falls das Test-Repo dort liegt — siehe Org-Block unten)
- **Repository access:** *Only select repositories* → das eben angelegte Test-Repo wählen
- **Repository permissions:**
  - Contents → **Read and write**
  - Pull requests → **Read and write**
  - Metadata → **Read-only** (wird automatisch gesetzt)
- **Generate token** → einmalig kopieren.

#### Variante: Test-Repo in einer Organisation

Drei zusätzliche Hürden, die der User-Account-Fall nicht hat:

1. **Resource owner umstellen.** Auf der Token-Seite ganz oben das Dropdown
   *Resource owner* von deinem User auf die Org ändern — sonst sind keine Org-Repos auswählbar.

2. **Org-Policy für Fine-Grained PATs.** Wenn die Org Fine-Grained PATs nicht erlaubt, entsteht
   der Token ohne Berechtigungen für Org-Repos (lautlos). Org-Owner stellen das ein unter:
   `https://github.com/organizations/<ORG>/settings/personal-access-tokens-policy`
   → *"Allow access via fine-grained personal access tokens"* (oder *"Require approval"*).

3. **Approval einholen.** Bei Policy *"Require approval"* (Default für sicherheitsbewusste Orgs)
   ist der Token nach dem Generieren im Status *Pending* und schlägt mit `403` fehl. Org-Owner
   genehmigen unter:
   `https://github.com/organizations/<ORG>/settings/personal-access-tokens/pending_requests`

4. **SSO-authorize (nur bei SSO-Orgs).** Nach dem Erstellen erscheint ein Banner
   *"Configure SSO"* über dem Token — dort die Org per SSO autorisieren, sonst antwortet die API
   mit `Resource not accessible by personal access token`.

Verifizieren, ob der Token wirklich Zugriff hat:

```bash
curl -H "Authorization: Bearer $GITHUB_PAT" https://api.github.com/repos/<ORG>/<REPO>
```

→ erwartete Antwort ist das Repo-JSON. Bei `404` oder `Resource not accessible` greift einer der
obigen Punkte.

### 3. OAuth-Token (optional — niedriger Mehrwert, hohes Risiko)

⚠️ Argos nutzt eine klassische GitHub-OAuth-App mit Scope `repo`
(`app/Http/Controllers/Auth/ConnectedAccountController.php`). Klassische OAuth-Apps lassen sich
**nicht** auf einzelne Repos beschränken — der Token sieht *alle* privaten Repos, auf die du
Zugriff hast. Anders als der Fine-Grained PAT, der vom Provider selbst auf das Test-Repo
gesperrt wird, schützt dich beim OAuth-Token nichts mehr außer dem `DestructiveOperationGuard`
in der Suite — und der greift nur bei Code-Bugs, nicht bei Konfigurations-Fehlern (z.B. falsches
Repo in `.env.testing.external`).

**Inhaltlich gewinnst du wenig.** GitHubs PAT und OAuth-Tokens sind beide Bearer-Tokens auf
demselben Endpoint — der `GitHubGitService`-Code-Pfad ist bitidentisch. Der OAuth-Roundtrip
verifiziert nur, dass GitHub den Token akzeptiert, was die PAT-Tests schon belegen.

**Empfehlung:** OAuth-Block leer lassen, nur PAT testen.

Falls du den OAuth-Pfad doch absichern willst (z.B. weil du den Onboarding-Flow änderst):
separater Test-User-Account anlegen, der **nur** das Test-Repo als Collaborator sieht. Dann ist
die Token-Reichweite über die User-Account-Grenze begrenzt — sauber genug.

Token holen: Test-User in Argos einloggen, OAuth-Flow durchlaufen, Token aus
`connected_accounts.token` in der DB ziehen.

### 4. `.env.testing.external` ausfüllen

```dotenv
GITHUB_PAT=github_pat_xxx...
GITHUB_OAUTH_TOKEN=                       # leer oder gho_xxx...
GITHUB_TEST_REPO_OWNER=mein-account
GITHUB_TEST_REPO=argos-provider-contract
GITHUB_DEFAULT_BRANCH=main
GITHUB_TEST_REPO_CLONE_URL=https://github.com/mein-account/argos-provider-contract.git
```

### 5. Test laufen lassen

```bash
php artisan test --configuration=phpunit.external.xml --filter=GitHubContractTest
```

---

## GitLab

### 1. Test-Repo anlegen

- **URL:** <https://gitlab.com/projects/new#blank_project>
- **Project name:** `argos-provider-contract`
- **Visibility:** *Private*
- **Initialize with README:** ✅
- **Default branch name:** `main` (Default ist auch `main`, nur sicherheitshalber prüfen)

Notiere: `NAMESPACE/PROJECT` (das, was hinter `gitlab.com/` in der URL steht).

### 2. Project Access Token (PAT)

Project Access Tokens sind projekt-gescopt — *nicht* User-PATs verwenden.

- **URL:** `https://gitlab.com/<NAMESPACE>/<PROJECT>/-/settings/access_tokens`
- **Token name:** `argos-contract-tests`
- **Expiration:** 90 days
- **Role:** *Maintainer* (Developer reicht für Push, aber MR-Close/Decline braucht Maintainer)
- **Scopes:**
  - `api`
  - `write_repository`
- **Create project access token** → kopieren.

### 3. OAuth-Token (optional)

Eigene OAuth-App im persönlichen Workspace anlegen:

- **URL:** <https://gitlab.com/-/user_settings/applications>
- **Name:** `argos-contract-tests`
- **Redirect URI:** `http://localhost` (irrelevant für unseren Use-Case)
- **Scopes:** `api`
- App speichern, dann den Authorization-Code-Flow gegen `/oauth/token` per `curl` durchspielen
  oder einfach in Argos selbst den OAuth-Login durchführen und den Token aus der DB ziehen.

Wenn dir das zu fummelig ist: leer lassen, nur PAT testen.

### 4. `.env.testing.external` ausfüllen

```dotenv
GITLAB_PAT=glpat-xxx...
GITLAB_OAUTH_TOKEN=                       # leer oder Token
GITLAB_INSTANCE_URL=https://gitlab.com
GITLAB_TEST_REPO_OWNER=mein-namespace
GITLAB_TEST_REPO=argos-provider-contract
GITLAB_DEFAULT_BRANCH=main
GITLAB_TEST_REPO_CLONE_URL=https://gitlab.com/mein-namespace/argos-provider-contract.git
```

Für **self-hosted GitLab**: `GITLAB_INSTANCE_URL=https://gitlab.firma.de` und Clone-URL anpassen.

### 5. Test laufen lassen

```bash
php artisan test --configuration=phpunit.external.xml --filter=GitLabContractTest
```

---

## Bitbucket

### 1. Test-Repo anlegen

- **URL:** <https://bitbucket.org/repo/create>
- **Workspace:** dein persönlicher Workspace
- **Project:** beliebig (oder neuen anlegen)
- **Repository name:** `argos-provider-contract`
- **Access level:** *Private*
- **Include a README?:** *Yes, with a tutorial* — hauptsache initialisiert

Bitbucket erstellt den Default-Branch `main` (nur prüfen, ältere Repos kommen mit `master`).

Notiere: `WORKSPACE/REPO_SLUG` (Slug = lower-case repo-name mit Bindestrichen).

### 2. Repository Access Token (PAT-Äquivalent)

⚠️ **App Passwords sind tot.** Atlassian hat sie zum 2025-09-09 für Neuanlagen geschlossen, ab
2026-06-09 sind alle deaktiviert. Verwende **Repository Access Tokens** — die sind ohnehin die
bessere Wahl, weil sie repo-scoped sind und kein User-Account-Workaround nötig ist.

- **URL:** `https://bitbucket.org/<WORKSPACE>/<REPO_SLUG>/admin/access-tokens`
  (im Repo-Settings-Menü unter *Access tokens*)
- **Token name:** `argos-contract-tests`
- **Expiry:** 90 days
- **Scopes:**
  - `repository` (Read)
  - `repository:write`
  - `pullrequest` (Read)
  - `pullrequest:write`
- **Create** → einmalig kopieren.

**Format für `.env`:** Repository Access Tokens haben **keinen Doppelpunkt** — der `BitbucketGitService`
erkennt das automatisch und nutzt Bearer-Auth (statt Basic). In der `.env` einfach den Token
ohne Prefix eintragen:

```dotenv
BITBUCKET_PAT=ATCTT3xFf...
```

#### Variante: Atlassian-Account API Token (user-scoped)

Falls du keinen Repository-Admin-Zugriff hast, gibt es noch user-scopede API Tokens unter
<https://id.atlassian.com/manage-profile/security/api-tokens>. Diese arbeiten mit Basic Auth
über `email:api_token` — also **mit** Doppelpunkt:

```dotenv
BITBUCKET_PAT=meine.email@firma.de:ATATT3xFfG...
```

Nachteil: user-scoped (sieht alle Repos, auf die du Zugriff hast). Für die Test-Suite ist ein
Repository Access Token klar die sauberere Wahl.

#### Legacy: App Passwords

Falls noch ein App Password existiert (vor 2025-09-09 erzeugt), funktioniert es bis zum
2026-06-09 — Format `username:app_password` (mit Doppelpunkt). Danach hart aus.

### 3. OAuth-Consumer (optional)

- **URL:** `https://bitbucket.org/<WORKSPACE>/workspace/settings/api`
- **Add consumer**
- **Name:** `argos-contract-tests`
- **Callback URL:** `http://localhost` (irrelevant)
- **This is a private consumer:** ✅
- **Permissions:** Repositories Read+Write, Pull requests Read+Write
- Speichern, **Key** und **Secret** notieren.

OAuth-Token einmalig per Client-Credentials-Flow holen:

```bash
curl -X POST -u "<KEY>:<SECRET>" \
  https://bitbucket.org/site/oauth2/access_token \
  -d grant_type=client_credentials
```

→ JSON-Response, `access_token` herausziehen, in `.env` eintragen. Token läuft nach 2h ab —
für gelegentliche manuelle Runs reicht das, ggf. vor jedem Run kurz neu holen.

### 4. `.env.testing.external` ausfüllen

```dotenv
BITBUCKET_PAT=mein-username:ATBBxxx...
BITBUCKET_OAUTH_TOKEN=                    # leer oder kurzlebiger access_token
BITBUCKET_TEST_REPO_OWNER=mein-workspace
BITBUCKET_TEST_REPO=argos-provider-contract
BITBUCKET_DEFAULT_BRANCH=main
BITBUCKET_TEST_REPO_CLONE_URL=https://bitbucket.org/mein-workspace/argos-provider-contract.git
```

### 5. Test laufen lassen

```bash
php artisan test --configuration=phpunit.external.xml --filter=BitbucketContractTest
```

---

## Komplett-Run

Wenn alle drei Provider konfiguriert sind:

```bash
php artisan test --configuration=phpunit.external.xml
```

Erwartung: **24 Tests, 0 errors**. Skips passieren nur, wenn ein Auth-Mode (PAT oder OAuth)
für einen Provider nicht konfiguriert ist — die andere Hälfte läuft trotzdem.

## Troubleshooting

| Symptom | Vermutliche Ursache |
| --- | --- |
| `git clone` failt mit `Authentication failed` | Token-Scope ungenügend (Repo-Read fehlt), oder bei Bitbucket: PAT-Format ohne `:` (es muss `username:app_password` sein) |
| `createPullRequest` failt mit 403 | Token-Scope hat Pull-Request-Write nicht — neu generieren |
| `Test-Repo … nicht in listRepositories() gefunden` | Token sieht das Repo nicht. GitHub: PAT explizit auf das Repo gescoped? GitLab: Project Access Token am richtigen Projekt? Bitbucket: User hat tatsächlich Zugriff auf das Repo? |
| Cleanup-Warnings im stderr | Ein PR/Branch konnte nicht aufgeräumt werden. Manuell im Provider-UI nachschauen, sind alle mit `argos-test/`-Prefix oder `argos-test:`-Titel |
| Nach mehreren Runs viele offene `argos-test/…`-Branches | Test wurde mid-flight abgebrochen, Cleanup hat nicht alles erwischt. Manuell oder per `git push --delete origin argos-test/...` aufräumen |

## Token-Hygiene

- Ablauf konsequent kurz halten (90 Tage), Kalender-Reminder setzen.
- Nach Auslaufen: alten Token sicherheitshalber im Provider explizit revoken, neuen anlegen.
- Niemals den Inhalt von `.env.testing.external` in Issues, PRs, Logs, Screenshots zeigen.
- Bei Verdacht auf Leak: **sofort** im Provider-UI revoken, neuen Token, alten überall ersetzen.
