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
# GITLAB_INSTANCE_URL=https://gitlab.com  # Override for Self-Hosted
```

The callback URL is fixed at `${APP_URL}/auth/gitlab/callback` — register
that exact URL when creating the GitLab application.

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

## Option 3: Issue-Provider (Pull + Webhook)

Argos kann GitLab-Issues als Tasks importieren und bei Phasenabschluss automatisch einen Kommentar im Issue hinterlassen. Jedes Repository benötigt ein **TaskProviderBinding**, das im Filament-Admin-Interface angelegt wird.

### Schritt 1: Benötigte Token-Scopes

| Token-Typ | Benötigte Scopes |
|---|---|
| Personal Access Token (PAT) | `api` — Vollzugriff auf API inkl. Webhook-Verwaltung |
| OAuth | Scope `api` (bereits bei der OAuth-App-Einrichtung gesetzt) |

> **Hinweis**: Der `api`-Scope ist erforderlich für Webhook-Registrierung (`POST /projects/:id/hooks`). Ohne diesen Scope schlägt die Webhook-Einrichtung mit 403 fehl und das Binding bleibt im Status `Pending`.

### Schritt 2: Binding in Filament anlegen

1. Im Admin-Panel unter **Repo Profiles** das gewünschte Projekt öffnen
2. Tab **Task Provider Bindings** → **New Binding**
3. Felder ausfüllen:

| Feld | Beschreibung |
|---|---|
| **Kind** | GitLab |
| **Mode** | `Webhook` (Echtzeit) oder `Poll` (alle 5 min) |
| **Connected Account** | OAuth- oder PAT-Account mit `api`-Scope |
| **External Project Ref** | Projektpfad im Format `group/projekt`, z. B. `acme/widget` |
| **Filters** | Optional: nur Issues mit bestimmtem State oder Labels importieren |

4. Speichern → Binding ist im Status **Pending**

### Schritt 3: Aktivieren mit „Einrichten"

Klicke auf die **Einrichten**-Aktion beim Binding:

- **Webhook-Modus**: Argos registriert automatisch einen Webhook in GitLab via `POST /projects/:id/hooks` mit `issues_events: true`. Die Callback-URL `${APP_URL}/webhooks/issues/gitlab/{binding-id}` und das Secret werden automatisch generiert — kein manuelles Eintragen in GitLab nötig. Das Binding wechselt auf `Active`.
- **Poll-Modus**: Das Binding wechselt sofort auf `Active`. Der Poll-Scheduler fragt Issues beim nächsten Lauf ab.

Schlägt die Einrichtung fehl (fehlender `api`-Scope, falscher Projektpfad, `APP_URL` nicht erreichbar), bleibt das Binding auf `Pending` und der Fehler steht in der **Last Error**-Spalte. Problem beheben und **Einrichten** erneut klicken.

### Webhook- vs. Poll-Modus

| | Webhook | Poll |
|---|---|---|
| Latenz | Sekunden | Minuten (Scheduler-Intervall) |
| `APP_URL` öffentlich erreichbar | Ja | Nein |
| GitLab API-Rate-Limits | Nicht relevant | Zählt gegen Quota |

### Verhaltenshinweise

- **Signatur**: GitLab sendet das Secret als Plain-Token im `X-Gitlab-Token`-Header (kein HMAC). Argos vergleicht direkt per `hash_equals`.
- **Idempotenz**: Doppelte Deliveries werden anhand des `X-Gitlab-Event-UUID`-Headers erkannt und verworfen.
- **Note- und MR-Hooks**: GitLab kann auch `note`- und `merge_request`-Events an dieselbe Webhook-URL senden. Diese werden vom Job erkannt (`object_kind ≠ issue`) und ohne Task-Erstellung verworfen.
- **Confidential Issues**: Der Webhook ist mit `confidential_issues_events: true` registriert — vertrauliche Issues werden ebenfalls verarbeitet.
- **Self-Hosted GitLab**: Der Tracker verwendet automatisch den `instance_url`-Wert des verknüpften `ConnectedAccount`. Für GitLab.com bleibt dieser leer (Standard `https://gitlab.com`).
- **Labels**: GitLab-Issues liefern Labels als String-Array im API-Response; Webhook-Payloads liefern Label-Objekte im Top-Level-`labels`-Array. Argos normalisiert beide Formate automatisch.
- **State**: GitLab verwendet `opened`/`closed` (nicht `open`). Der Default-State beim Polling ist `opened`.

Weitere Details zum Binding-Setup: [`docs/SETUP-TASK-PROVIDERS.md`](SETUP-TASK-PROVIDERS.md)

---

## Worker: REPO_PLATFORM

The manager passes `REPO_PLATFORM=gitlab` to the worker container as an environment variable. The push phase uses this to detect the platform reliably — even for Self-Hosted GitLab instances with non-obvious hostnames — and pushes with `-o merge_request.create` to create the MR automatically.

---

## Notes

- GitLab API authentication uses `Authorization: Bearer <token>` for both PAT and OAuth tokens.
- The `PRIVATE-TOKEN` header is **not** used — GitLab accepts Bearer for both token types.
- For GitLab.com the `instance_url` in `connected_accounts` is stored as `NULL` (defaults to `https://gitlab.com`).
- Self-Hosted MR URLs are extracted from the git push output and stored in the task record.
