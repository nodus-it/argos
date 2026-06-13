# GitLab-Setup

Argos unterstützt GitLab mit zwei Authentifizierungsmethoden: Personal Access
Token (PAT) und OAuth. Beide funktionieren mit gitlab.com und mit selbst
gehosteten GitLab-Instanzen (CE/EE).

Siehe [OAuth-Überblick](OAUTH.md) für einen Vergleich von PAT und OAuth und die
Frage, welche Methode man wählen sollte.

---

## Option 1: Personal Access Token (PAT)

Empfohlen für Einzelnutzer-Instanzen oder wenn man Argos einfach ausprobieren
möchte. PAT funktioniert ohne jegliche serverseitige Konfiguration — man fügt
den Token lediglich im Projektformular ein.

### Schritt 1: Token erstellen

1. Öffne **GitLab → User Settings → Access Tokens** (auf einer selbst gehosteten
   Instanz lautet der Pfad
   `https://git.example.com/-/user_settings/personal_access_tokens`).
2. Gib ihm einen beschreibenden Namen (z. B. `argos`).
3. Wähle diese Scopes aus:
   - `api` — voller API-Zugriff (erforderlich für das Auflisten von Repositories
     und das Erstellen von Merge Requests).
   - `write_repository` — Push-Zugriff auf Repositories.
4. Lege ein Ablaufdatum fest (oder lasse es leer für einen langlebigen Token).
5. Kopiere den Token — er wird nur einmal angezeigt.

### Schritt 2: Projekt in Argos konfigurieren

Unter **Projects → New Project**:

- **Platform**: GitLab
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: vollständige HTTPS-URL, z. B. `https://gitlab.com/your-group/your-project`,
  oder `https://git.example.com/your-group/your-project` für eine selbst
  gehostete Instanz.
- **Token**: füge den Token aus Schritt 1 ein.
- **Default Branch**: z. B. `main`

---

## Option 2: OAuth (optional)

OAuth aktiviert Repo- und Branch-Dropdowns im Projektformular sowie eine
benutzerbezogene Konto-Bindung — keine manuelle URL-Eingabe, und jeder Benutzer
verbindet sein eigenes GitLab-Konto.

OAuth-Apps werden **in der Argos-UI** verwaltet, nicht über Umgebungsvariablen.
Es gibt kein `GITLAB_CLIENT_ID` / `GITLAB_CLIENT_SECRET` / `GITLAB_INSTANCE_URL`
zu setzen. Der vollständige UI-Ablauf ist beschrieben unter
[OAuth-Überblick → Registering the OAuth app in Argos](OAUTH.md#registering-the-oauth-app-in-argos).

### Schritt 1: Eine OAuth-App auf GitLab registrieren

#### gitlab.com

1. Öffne **User Settings → Applications**
   (`https://gitlab.com/-/user_settings/applications`). Für eine
   organisationsweite Nutzung bitte deinen Admin um eine Group- oder
   Instance-Application stattdessen.
2. Trage ein:
   - **Name**: `Argos` (oder ein beliebiger beschreibender Name).
   - **Redirect URI**: `https://argos.example.com/auth/gitlab/callback` — der
     Callback ist fest auf `${APP_URL}/auth/gitlab/callback` gesetzt. Registriere
     genau diese URL, einschließlich des Schemas.
   - **Scopes**: `read_user`, `api`.
3. Speichere und kopiere die **Application ID** und das **Secret**.

#### Selbst gehostetes GitLab

Dieselben Schritte, aber auf der eigenen Instanz:

- **User application**:
  `https://git.example.com/-/user_settings/applications`
- **Admin Area application** (für gemeinsame, instanzweite Nutzung):
  `https://git.example.com/admin/applications`

Die Redirect URI ist weiterhin `${APP_URL}/auth/gitlab/callback`, und die Scopes
sind weiterhin `read_user`, `api`.

### Schritt 2: Die OAuth-App in Argos hinzufügen

1. Öffne **Configuration → OAuth Apps** im Argos-Admin.
2. Füge eine App für **GitLab** hinzu, trage ihre **Application ID** (Client-ID)
   und ihr **Secret** (Client-Secret) ein und aktiviere sie.
3. Setze für eine selbst gehostete Instanz das Feld **Instance URL** an der App
   (z. B. `https://git.example.com`, ohne abschließenden Schrägstrich). Lasse es
   für gitlab.com leer. Dies ist der einzige Ort, an dem die selbst gehostete URL
   konfiguriert wird — es gibt keine `GITLAB_INSTANCE_URL`-Umgebungsvariable.
4. Die im Formular angezeigte **Callback-URL** ist schreibgeschützt und wird aus
   `APP_URL` abgeleitet — kopiere sie in die Redirect URI der GitLab-OAuth-App,
   falls noch nicht geschehen.

Die Zugangsdaten werden in der Datenbank (`provider_oauth_configs`) gespeichert
und werden ohne Neustart sofort wirksam. Du kannst mehrere GitLab-Apps
nebeneinander registrieren — zum Beispiel eine für gitlab.com und eine pro selbst
gehosteter Instanz.

### Schritt 3: Konto verbinden

1. Melde dich bei Argos an.
2. Gehe in der Navigation zu **Connected Accounts**.
3. Klicke auf **Connect with GitLab** — du wirst zu GitLab weitergeleitet, um die
   App zu autorisieren. Nach der Autorisierung gelangst du zurück zur Seite
   Connected Accounts.
4. Wähle beim Erstellen eines Projekts **GitLab** als Plattform und **OAuth** als
   Authentifizierungsmethode — die Repo- und Branch-Dropdowns erscheinen, befüllt
   aus deinem verbundenen Konto.

> Für gitlab.com wird die `instance_url` des Kontos leer gespeichert
> (Standardwert `https://gitlab.com`); für ein selbst gehostetes Konto trägt sie
> die Instanz-URL der OAuth-App, über die du dich verbunden hast.

---

## Option 3: Issue-Provider (Pull + Webhook)

Argos kann GitLab-Issues als Tasks importieren und automatisch einen Kommentar am
Issue hinterlassen, wenn eine Phase abgeschlossen ist. Jedes Repository benötigt
ein **TaskProviderBinding**, das über die Filament-Admin-Oberfläche erstellt wird.

### Schritt 1: Erforderliche Token-Scopes

| Token-Typ | Benötigte Scopes |
|---|---|
| Personal Access Token (PAT) | `api` — voller API-Zugriff inklusive Webhook-Verwaltung |
| OAuth | `api` (bereits gesetzt, als du die OAuth-App registriert hast) |

> **Hinweis**: Der `api`-Scope ist für die Webhook-Registrierung erforderlich
> (`POST /projects/:id/hooks`). Ohne ihn schlägt das Webhook-Setup mit 403 fehl
> und das Binding bleibt im Zustand `Pending`.

### Schritt 2: Ein Binding in Filament erstellen

1. Öffne im Admin-Panel das Zielprojekt unter **Repo Profiles**.
2. Gehe zum Tab **Task Provider Bindings** → **New Binding**.
3. Fülle die Felder aus:

| Feld | Beschreibung |
|---|---|
| **Provider** | GitLab |
| **Mode** | `Webhook` (Echtzeit) oder `Poll` (alle 5 Min.) |
| **Connected Account** | Ein OAuth- oder PAT-Konto mit dem `api`-Scope |
| **Project / Team Ref** | Projektpfad im Format `group/project`, z. B. `acme/widget` |
| **Filters** | Optional: nur Issues mit einem bestimmten Status oder bestimmten Labels importieren |

4. Klicke auf **Save**. Das Binding startet im Zustand `Pending`.

### Schritt 3: Mit "Einrichten" aktivieren

Klicke auf die Aktion **Einrichten** (Setup) am Binding:

- **Webhook-Modus**: Argos registriert einen Webhook auf GitLab via
  `POST /projects/:id/hooks` mit `issues_events: true`. Die Callback-URL
  `${APP_URL}/webhooks/issues/gitlab/{binding-id}` und das Secret werden
  automatisch generiert — keine manuelle Eingabe in GitLab nötig. Das Binding
  wechselt zu `Active`.
- **Poll-Modus**: Das Binding wechselt sofort zu `Active`. Der Poll-Scheduler
  ruft die Issues beim nächsten Lauf ab.

Falls das Setup fehlschlägt (fehlender `api`-Scope, falscher Projektpfad,
`APP_URL` nicht erreichbar), bleibt das Binding `Pending` und der Fehler erscheint
in der Spalte **Last Error**. Behebe das Problem und klicke erneut auf
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
  (`object_kind ≠ issue`) und ohne Erstellung eines Tasks verworfen.
- **Vertrauliche Issues**: Der Webhook wird mit
  `confidential_issues_events: true` registriert, sodass auch vertrauliche Issues
  verarbeitet werden.
- **Selbst gehostetes GitLab**: Der Tracker verwendet automatisch die
  `instance_url` des verknüpften `ConnectedAccount`. Für gitlab.com bleibt diese
  leer (Standardwert `https://gitlab.com`).
- **Labels**: GitLab-Issues liefern Labels als String-Array in API-Antworten;
  Webhook-Payloads liefern Label-Objekte im Top-Level-Array `labels`. Argos
  normalisiert beide Formate automatisch.
- **Status**: GitLab verwendet `opened`/`closed` (nicht `open`). Der
  Standardstatus beim Pollen ist `opened`.

Details zum gemeinsamen Binding-Setup siehe
[Task-Provider-Setup](SETUP-TASK-PROVIDERS.md).

---

## Worker: REPO_PLATFORM

Der Manager übergibt `REPO_PLATFORM=gitlab` als Umgebungsvariable an den
Worker-Container. Die Push-Phase nutzt dies, um die Plattform zuverlässig zu
erkennen — auch bei selbst gehosteten GitLab-Instanzen mit unauffälligen
Hostnamen — und pusht mit `-o merge_request.create`, um den Merge Request
automatisch zu erstellen.

---

## Hinweise

- Die GitLab-API-Authentifizierung verwendet `Authorization: Bearer <token>`
  sowohl für PAT- als auch für OAuth-Tokens. Der `PRIVATE-TOKEN`-Header wird
  **nicht** verwendet — GitLab akzeptiert Bearer für beide Token-Typen.
- Für gitlab.com wird die `instance_url` in `connected_accounts` leer gespeichert
  (Standardwert `https://gitlab.com`).
- MR-URLs selbst gehosteter Instanzen werden aus der Ausgabe des Git-Push
  extrahiert und am Task-Datensatz gespeichert.

---

## Troubleshooting

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 401 beim Push / bei der MR-Erstellung | PAT fehlt der `api`- oder `write_repository`-Scope, oder der Token ist abgelaufen. |
| 403 während des Webhook-Setups | Dem Token des verbundenen Kontos fehlt der `api`-Scope. Konto erneut verbinden oder Token mit `api` neu erstellen. |
| OAuth-Redirect schlägt mit "redirect_uri mismatch" fehl | Die Redirect URI an der GitLab-OAuth-App muss exakt `${APP_URL}/auth/gitlab/callback` entsprechen, einschließlich des Schemas. Prüfe `APP_URL`. |
| OAuth-Callback liefert 500 | `APP_URL` nicht gesetzt oder stimmt nicht mit der öffentlichen URL überein — Laravel kann den korrekten Callback nicht generieren. |
| Selbst gehostetes OAuth landet bei gitlab.com statt bei deiner Instanz | Das Feld **Instance URL** an der OAuth-App in Argos ist leer oder falsch. Setze es auf deine Instanz-URL (ohne abschließenden Schrägstrich) unter **Configuration → OAuth Apps**. |
