# GitLab-Setup

Argos unterstützt GitLab mit zwei Authentifizierungsmethoden: Personal Access
Token (PAT) und OAuth. Beide funktionieren mit gitlab.com und mit selbst
gehosteten GitLab-Instanzen (CE/EE).

Siehe [OAuth-Überblick](OAUTH.md) für einen Vergleich von PAT und OAuth und die
Frage, welche Methode du wählen solltest.

---

## Option 1: Personal Access Token (PAT)

Empfohlen für Instanzen mit einer einzelnen Person oder wenn du Argos einfach
ausprobieren möchtest. PAT funktioniert ohne jegliche serverseitige
Konfiguration — du fügst den Token lediglich im Workspace-Formular ein.

### Schritt 1: Token erstellen

1. Öffne **GitLab → User Settings → Access Tokens** (auf einer selbst
   gehosteten Instanz lautet der Pfad
   `https://git.example.com/-/user_settings/personal_access_tokens`).
2. Gib ihm einen beschreibenden Namen (z. B. `argos`).
3. Wähle diese Scopes aus:
   - `api` — voller API-Zugriff (erforderlich für das Auflisten von
     Repositories und das Erstellen von Merge Requests).
   - `write_repository` — Push-Zugriff auf Repositories.
4. Lege ein Ablaufdatum fest (oder lass es leer für einen langlebigen Token).
5. Kopiere den Token — er wird nur einmal angezeigt.

### Schritt 2: Workspace in Argos anlegen

Wähle als Argos-Administrator **Neuer Workspace** (in der Workspace-Liste oder
direkt unter `/new`):

- **Plattform**: GitLab
- **Authentifizierung**: Personal Access Token (PAT)
- **Repo-URL**: vollständige HTTPS-URL, z. B. `https://gitlab.com/your-group/your-project`,
  oder `https://git.example.com/your-group/your-project` für eine selbst
  gehostete Instanz.
- **Token**: füge den Token aus Schritt 1 ein.
- **Default Branch**: z. B. `main`

---

## Option 2: OAuth (optional)

OAuth aktiviert Repo- und Branch-Dropdowns im Workspace-Formular sowie eine
Konto-Bindung pro Person — keine manuelle URL-Eingabe, und alle verbinden ihr
eigenes GitLab-Konto.

OAuth-Apps werden **in der Argos-UI** verwaltet, nicht über
Umgebungsvariablen. Es gibt kein `GITLAB_CLIENT_ID` / `GITLAB_CLIENT_SECRET` /
`GITLAB_INSTANCE_URL` zu setzen. Der vollständige UI-Ablauf ist beschrieben
unter
[OAuth-Überblick → Die OAuth-App in Argos registrieren](OAUTH.md#die-oauth-app-in-argos-registrieren).

### Schritt 1: Eine OAuth-App auf GitLab registrieren

#### gitlab.com

1. Öffne **User Settings → Applications**
   (`https://gitlab.com/-/user_settings/applications`). Für eine
   organisationsweite Nutzung bitte deine Admins stattdessen um eine Group-
   oder Instance-Application.
2. Trage ein:
   - **Name**: `Argos` (oder ein beliebiger beschreibender Name).
   - **Redirect URI**: `https://argos.example.com/auth/gitlab/callback` — der
     Callback ist fest auf `${APP_URL}/auth/gitlab/callback` gesetzt.
     Registriere genau diese URL, einschließlich des Schemas.
   - **Scopes**: `read_user`, `api`.
3. Speichere und kopiere die **Application ID** und das **Secret**.

#### Selbst gehostetes GitLab

Dieselben Schritte, aber auf deiner eigenen Instanz:

- **User application**:
  `https://git.example.com/-/user_settings/applications`
- **Admin Area application** (für gemeinsame, instanzweite Nutzung):
  `https://git.example.com/admin/applications`

Die Redirect URI ist weiterhin `${APP_URL}/auth/gitlab/callback`, und die
Scopes sind weiterhin `read_user`, `api`.

### Schritt 2: Die OAuth-App in Argos hinzufügen

1. Öffne **Administration → OAuth-Apps** (`${APP_URL}/system/oauth-apps`).
2. Füge eine App für **GitLab** hinzu, trage ihre **Application ID**
   (Client-ID) und ihr **Secret** (Client-Secret) ein und aktiviere sie.
3. Setze für eine selbst gehostete Instanz das Feld **Instanz-URL** an der App
   (z. B. `https://git.example.com`, ohne abschließenden Schrägstrich). Lass es
   für gitlab.com leer. Dies ist der einzige Ort, an dem die selbst gehostete
   URL konfiguriert wird — es gibt keine `GITLAB_INSTANCE_URL`-Umgebungsvariable.
4. Die im Formular angezeigte **Callback-URL** ist schreibgeschützt und wird
   aus `APP_URL` abgeleitet — kopiere sie in die Redirect URI der
   GitLab-OAuth-App, falls noch nicht geschehen.

Die Zugangsdaten werden in der Datenbank (`provider_oauth_configs`) gespeichert
und werden ohne Neustart sofort wirksam. Du kannst mehrere GitLab-Apps
nebeneinander registrieren — zum Beispiel eine für gitlab.com und eine pro
selbst gehosteter Instanz.

### Schritt 3: Konto verbinden

1. Melde dich bei Argos an.
2. Gehe zu **Mein Konto → Verknüpfte Accounts** (`/account/connections`).
3. Klicke auf **Mit GitLab verbinden** — du wirst zu GitLab weitergeleitet, um
   die App zu autorisieren. Nach der Autorisierung gelangst du zurück zur Seite
   Verknüpfte Accounts.
4. Wähle beim Anlegen eines Workspaces **GitLab** als Plattform und **OAuth**
   als Authentifizierungsmethode — die Repo- und Branch-Dropdowns erscheinen,
   befüllt aus deinem verbundenen Konto.

> Für gitlab.com wird die `instance_url` des Kontos leer gespeichert
> (Standardwert `https://gitlab.com`); für ein selbst gehostetes Konto trägt
> sie die Instanz-URL der OAuth-App, über die du dich verbunden hast.

---

## Option 3: Issue-Trigger (Polling oder Webhook)

Ein Workspace kann GitLab-Issues als [Tickets](TICKETS.md) nutzen, aus neuen
Issues automatische Aufgaben starten und im Verlauf der Aufgabe am Issue
kommentieren. Die Integration liegt am Workspace (eine pro GitLab-Projekt).

### Schritt 1: Erforderliche Token-Scopes

| Token-Typ | Benötigte Scopes |
|---|---|
| Personal Access Token (PAT) | `api` — voller API-Zugriff inklusive Webhook-Verwaltung |
| OAuth | `api` (bereits gesetzt, als du die OAuth-App registriert hast) |

> **Hinweis**: Der `api`-Scope ist für die Webhook-Registrierung erforderlich
> (`POST /projects/:id/hooks`). Ohne ihn schlägt das Webhook-Setup mit 403 fehl
> und die Integration bleibt im Zustand `Ausstehend`.

### Schritt 2: Integration anlegen

1. Öffne im Workspace **Einstellungen → Integrationen** → **Issue-Tracker** →
   **Neu** (Workspace-Admins und Argos-Administratoren).
2. Fülle die Felder aus:

| Feld | Beschreibung |
|---|---|
| **Provider** | GitLab |
| **Modus** | *Webhook (Push)* (Echtzeit) oder *Polling* (alle `ARGOS_POLL_INTERVAL_MINUTES`, Standard 5) |
| **Zugang** | Ein OAuth-Account oder PAT mit dem `api`-Scope |
| **Projekt / Team** | Das Projekt (`group/project`, z. B. `acme/widget`) — nimm das eigene Projekt des Workspaces, wenn gemergte Merge Requests die Issues schließen sollen |
| **Labels-Filter** | Optional: nur Issues mit einem dieser Labels starten eine Aufgabe |
| **Ticket beim Merge schließen** | `Closes #…` (an, Standard) oder `Refs #…` im Merge Request |

3. Klicke auf **Speichern**. Die Integration startet im Zustand `Ausstehend`.

### Schritt 3: Mit "Einrichten" aktivieren

Klicke in der Zeile der Integration auf **Einrichten**:

- **Webhook-Modus**: Argos registriert einen Webhook auf GitLab via
  `POST /projects/:id/hooks` mit `issues_events: true`. Die Callback-URL
  `${APP_URL}/webhooks/issues/gitlab/{integration-id}` und das Secret werden
  automatisch generiert — keine manuelle Eingabe in GitLab nötig. Die
  Integration wechselt zu `Aktiv`.
- **Poll-Modus**: Die Integration wechselt sofort zu `Aktiv`. Der
  Poll-Scheduler ruft die Issues beim nächsten Lauf ab.

Falls das Setup fehlschlägt (fehlender `api`-Scope, falscher Projektpfad,
`APP_URL` nicht erreichbar), bleibt die Integration `Ausstehend` und der Fehler
erscheint unter **Letzter Fehler**. Behebe das Problem und klicke erneut auf
**Einrichten**.

### Webhook vs. Poll

| | Webhook | Poll |
|---|---|---|
| Latenz | Sekunden | Minuten (Scheduler-Intervall) |
| Erfordert öffentliche `APP_URL` | Ja | Nein |
| GitLab-API-Rate-Limits | Nicht zutreffend | Zählt gegen das Kontingent |

### Verhaltenshinweise

- **Signatur**: GitLab sendet das Secret als einfachen Token im Header
  `X-Gitlab-Token` (kein HMAC). Argos vergleicht ihn direkt mit `hash_equals`.
- **Idempotenz**: Doppelte Zustellungen werden über den Header
  `X-Gitlab-Event-UUID` erkannt und verworfen.
- **Note- und MR-Hooks**: GitLab sendet unter Umständen auch `note`- und
  `merge_request`-Events an dieselbe Webhook-URL. Diese werden erkannt
  (`object_kind ≠ issue`) und verworfen, ohne eine Aufgabe zu starten.
- **Vertrauliche Issues**: Der Webhook wird mit
  `confidential_issues_events: true` registriert, sodass auch vertrauliche
  Issues verarbeitet werden.
- **Selbst gehostetes GitLab**: Der Tracker verwendet automatisch die
  `instance_url` des verknüpften `ConnectedAccount`. Für gitlab.com bleibt
  diese leer (Standardwert `https://gitlab.com`).
- **Labels**: GitLab-Issues liefern Labels als String-Array in API-Antworten;
  Webhook-Payloads liefern Label-Objekte im Top-Level-Array `labels`. Argos
  normalisiert beide Formate automatisch.
- **Status**: GitLab verwendet `opened`/`closed` (nicht `open`). Der
  Standardstatus beim Pollen ist `opened`.

- **Zuweisung:** Bei einer Übergabe wird der GitLab-Benutzername der neuen
  Person, der die Aufgabe gehört (ihr verbundenes Konto), dem Issue zugewiesen.

Für die gemeinsamen Details siehe [Issue-Trigger](SETUP-TASK-PROVIDERS.md) und
[Tickets](TICKETS.md).

---

## Hinweise

- Die GitLab-API-Authentifizierung verwendet `Authorization: Bearer <token>`
  sowohl für PAT- als auch für OAuth-Tokens. Der `PRIVATE-TOKEN`-Header wird
  **nicht** verwendet — GitLab akzeptiert Bearer für beide Token-Typen.
- Für gitlab.com wird die `instance_url` in `connected_accounts` leer
  gespeichert (Standardwert `https://gitlab.com`).
- Merge Requests werden über die GitLab-REST-API geöffnet (`POST
  …/api/v4/projects/<id>/merge_requests`), auch bei selbst gehosteten
  Instanzen; ihre URL wird an der Aufgabe gespeichert.
- Der Merge-Request-Bereich der Aufgabe liest die Head-Pipeline (und die Traces
  fehlgeschlagener Jobs für **Claude fixen lassen**), Approvals und
  Diskussionen. **Automatisch mergen** nutzt *Merge when pipeline succeeds* und
  ist nur scharf geschaltet, solange eine Pipeline läuft. Siehe
  [Pull Requests](PULL-REQUESTS.md).

---

## Troubleshooting

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 401 beim Push / bei der MR-Erstellung | PAT fehlt der `api`- oder `write_repository`-Scope, oder der Token ist abgelaufen. |
| 403 während des Webhook-Setups | Dem Token des verbundenen Kontos fehlt der `api`-Scope. Konto erneut verbinden oder Token mit `api` neu erstellen. |
| OAuth-Redirect schlägt mit "redirect_uri mismatch" fehl | Die Redirect URI an der GitLab-OAuth-App muss exakt `${APP_URL}/auth/gitlab/callback` entsprechen, einschließlich des Schemas. Prüfe `APP_URL`. |
| OAuth-Callback liefert 500 | `APP_URL` nicht gesetzt oder stimmt nicht mit der öffentlichen URL überein — Laravel kann den korrekten Callback nicht generieren. |
| Selbst gehostetes OAuth landet bei gitlab.com statt bei deiner Instanz | Das Feld **Instanz-URL** an der OAuth-App in Argos ist leer oder falsch. Setze es unter **Administration → OAuth-Apps** auf deine Instanz-URL (ohne abschließenden Schrägstrich). |
