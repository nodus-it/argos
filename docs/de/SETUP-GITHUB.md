# GitHub-Einrichtung

Argos unterstützt GitHub mit zwei Authentifizierungsmethoden: Personal Access
Token (PAT) und OAuth. Beide funktionieren mit github.com.

Siehe [OAuth-Überblick](OAUTH.md) für einen Vergleich von PAT und OAuth und
welche Methode du wählen solltest.

---

## Option 1: Personal Access Token (PAT)

Empfohlen für Instanzen mit einer einzelnen Person oder wenn du Argos einfach
ausprobieren möchtest. PAT funktioniert ohne serverseitige Konfiguration — du
fügst den Token lediglich im Workspace-Formular ein.

### Schritt 1: Token erstellen

1. Öffne <https://github.com/settings/tokens>.
2. Klicke auf **Generate new token (classic)**.
3. Gib ihm einen aussagekräftigen Namen (z. B. `argos`).
4. Wähle diese Scopes:
   - `repo` — voller Repository-Zugriff (klonen, pushen, Branches anlegen, PRs
     erstellen). Das ist der Scope, den Argos anfordert.
   - `workflow` — nur, wenn dein Repo GitHub Actions nutzt und Argos
     `.github/workflows/*` verändern soll.
5. Lege ein Ablaufdatum fest (oder wähle "No expiration" für einen langlebigen
   Token).
6. Kopiere den Token — er wird nur einmal angezeigt.

> **Fine-grained Tokens** funktionieren ebenfalls, beschränkt auf das konkrete
> Repository, mit Lese-/Schreibzugriff auf **Contents**, **Pull requests** und
> **Workflows** (falls zutreffend).

### Schritt 2: Workspace in Argos anlegen

Wähle als Argos-Administrator **Neuer Workspace** (in `/w` oder im Admin-Panel
unter **Workspaces**):

- **Plattform**: GitHub
- **Authentifizierung**: Personal Access Token (PAT)
- **Repo-URL**: vollständige HTTPS-URL, z. B. `https://github.com/your-org/your-repo`
- **Token**: den Token aus Schritt 1 einfügen
- **Default Branch**: z. B. `main`

---

## Option 2: OAuth (optional)

OAuth aktiviert Repo- und Branch-Dropdowns im Workspace-Formular sowie eine
Account-Bindung pro Person — keine manuelle URL-Eingabe, und alle verbinden
ihr eigenes GitHub-Konto.

OAuth-Apps werden **in der Argos-Oberfläche** verwaltet, nicht über
Umgebungsvariablen. Es gibt keine `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET`,
die du setzen müsstest. Der vollständige UI-Ablauf ist beschrieben unter
[OAuth-Überblick → Die OAuth-App in Argos registrieren](OAUTH.md#die-oauth-app-in-argos-registrieren).

### Schritt 1: OAuth-App auf GitHub registrieren

1. Öffne <https://github.com/settings/applications/new> (oder die Developer
   Settings deiner Organisation für organisationsweite Apps).
2. Fülle aus:
   - **Application name**: `Argos` (oder ein beliebiger aussagekräftiger Name).
   - **Homepage URL**: deine Argos-Instanz, z. B. `https://argos.example.com`.
   - **Authorization callback URL**:
     `https://argos.example.com/auth/github/callback` — der Callback ist fest
     auf `${APP_URL}/auth/github/callback` gesetzt. Registriere genau diese
     URL, einschließlich des Schemas.
3. Klicke auf **Register application**.
4. Kopiere die **Client ID** und erzeuge ein neues **Client secret** — kopiere
   es ebenfalls (das Secret wird nur einmal angezeigt).

> Das Formular **OAuth-App anlegen** in Argos kann dich per Deep-Link zur
> Registrierungsseite von GitHub leiten, wobei Name, Homepage und Callback
> bereits vorausgefüllt sind, sodass du sie nicht von Hand eingeben musst.

### Schritt 2: OAuth-App in Argos hinzufügen

1. Öffne **Konfiguration → OAuth-Apps** in der Argos-Administration.
2. Füge eine App für **GitHub** hinzu, trage ihre **Client-ID** und ihr
   **Client-Secret** ein und aktiviere sie.
3. Die im Formular angezeigte **Callback-URL** ist schreibgeschützt und wird
   aus `APP_URL` abgeleitet — kopiere sie in die Authorization callback URL der
   GitHub-OAuth-App, falls noch nicht geschehen.

Die Zugangsdaten werden in der Datenbank gespeichert (`provider_oauth_configs`)
und werden sofort wirksam, ohne Neustart. Es gibt **keine** `*_CLIENT_ID` /
`*_CLIENT_SECRET` Umgebungsvariablen — die Oberfläche ist der einzige Ort, an
dem diese konfiguriert werden. Siehe die
[Konfigurationsreferenz](CONFIGURATION.md) für die Einstellungen, die
*weiterhin* ENV-basiert sind.

Die OAuth-App wird mit dem `repo`-Scope registriert, der Klonen, Pushen sowie
das Erstellen von Branches und PRs abdeckt — denselben Zugriff, den ein
klassischer PAT mit `repo` bietet.

### Schritt 3: Konto verbinden

1. Melde dich bei Argos an.
2. Gehe in der Navigation zu **Verknüpfte Accounts**.
3. Klicke auf **Mit GitHub verbinden** — du wirst zu GitHub weitergeleitet, um
   die App zu autorisieren. Nach der Autorisierung kehrst du zur Seite
   Verknüpfte Accounts zurück.
4. Wähle beim Anlegen eines Workspaces **GitHub** als Plattform und **OAuth**
   als Authentifizierungsmethode — die Repo- und Branch-Dropdowns erscheinen,
   befüllt aus deinem verbundenen Konto.

---

## Option 3: Issue-Trigger (Polling oder Webhook)

Ein Workspace kann GitHub-Issues als [Tickets](TICKETS.md) nutzen und aus neuen
Issues automatische Aufgaben starten, per Polling oder Webhooks. Die
Integration liegt am Workspace (eine pro GitHub-Repository).

### Schritt 1: Erforderliche Token-Scopes

| Token-Typ | Benötigte Scopes |
|---|---|
| Classic PAT | `repo` (umfasst die Webhook-Verwaltung) |
| Fine-grained PAT | **Issues**: Read & Write · **Webhooks**: Read & Write |
| OAuth (bestehend) | `repo`-Scope — deckt Webhooks bereits ab |

> **Hinweis**: `admin:repo_hook` ist ein Sub-Scope von `repo` bei klassischen
> PATs. Fine-grained Tokens benötigen "Webhooks: Read & Write" explizit.

### Schritt 2: Integration anlegen

1. Öffne im Workspace **Einstellungen → Integrationen** → **Issue-Tracker** →
   **Neu** (Workspace-Admins; Argos-Administratoren finden es auch am Workspace
   im Admin-Panel).
2. Setze **Provider** auf `GitHub` und wähle den **Zugang** (OAuth-Account oder
   gespeichertes PAT).
3. Wähle **Projekt / Team** — das Repository im Format `owner/repo`. Nimm das
   eigene Repository des Workspaces, wenn gemergte Pull Requests die Issues
   schließen sollen.
4. Wähle einen **Modus**:
   - **Webhook (Push)** — in Echtzeit; erfordert, dass `APP_URL` öffentlich
     erreichbar ist.
   - **Polling** — periodisch; funktioniert hinter Firewalls oder in der lokalen
     Entwicklung.
5. Setze optional einen **Labels-Filter**, sodass nur relevante Issues
   automatische Aufgaben starten, und entscheide über **Ticket beim Merge
   schließen**.
6. Speichere. Die Integration startet im Zustand `Ausstehend`.

### Schritt 3: Aktivierung mit "Einrichten"

Klicke in der Zeile der Integration auf **Einrichten**:

- **Webhook-Modus**: Argos ruft `POST /repos/{owner}/{repo}/hooks` auf GitHub
  auf und registriert `${APP_URL}/webhooks/issues/github/{integration-id}`
  automatisch. Die Integration wechselt zu `Aktiv`, sobald GitHub den Hook
  bestätigt.
- **Poll-Modus**: Die Integration wechselt sofort zu `Aktiv`.

Schlägt die Einrichtung fehl (ungültiger Token, fehlende Scopes, `APP_URL`
nicht erreichbar), bleibt sie `Ausstehend` und der Fehler wird unter
**Letzter Fehler** angezeigt. Behebe ihn und klicke erneut auf **Einrichten**.

### Webhook vs. Poll

| | Webhook | Poll |
|---|---|---|
| Latenz | Sekunden | Minuten (geplantes Intervall) |
| Erfordert öffentliche `APP_URL` | Ja | Nein |
| GitHub-Rate-Limits | Nicht zutreffend | Zählt auf das API-Kontingent an |

### Verhaltenshinweise

- **issue_comment**-Events werden vom Webhook-Endpunkt akzeptiert, aber
  ignoriert. Nur `issues`-Events (opened, edited usw.) können eine Aufgabe
  starten. Kommentare erreichen die Aufgabe über den geplanten Abgleich der
  Ticket-Kommentare.
- **Issue-Zustand und Aufgabe sind unabhängig voneinander.** Das Schließen
  eines GitHub-Issues stoppt oder bricht seine Aufgabe nicht ab; das Mergen des
  Pull Requests schließt das Issue (`Closes #…`), wenn *Ticket beim Merge
  schließen* aktiv ist (sonst enthält der Pull Request `Refs #…`).
- **Zuweisung:** Bei einer Übergabe wird der GitHub-Login der neuen Person,
  der die Aufgabe gehört (ihr verbundenes Konto), dem Issue zugewiesen.
- **Duplikatsicher**: Erneute Zustellungen (gleicher `X-GitHub-Delivery`)
  werden ignoriert. Pull Requests, die in der Issues-Liste auftauchen, werden
  herausgefiltert.

Für die gemeinsamen Details siehe [Issue-Trigger](SETUP-TASK-PROVIDERS.md) und
[Tickets](TICKETS.md).

---

## Pull Requests auf GitHub

- Argos liest Check-Runs und Commit-Status, Reviews und Review-Kommentare des
  Pull Requests der Aufgabe sowie die Job-Logs fehlgeschlagener
  GitHub-Actions-Läufe (siehe [Pull Requests](PULL-REQUESTS.md)). Ein
  klassischer PAT mit `repo` oder der OAuth-Scope `repo` deckt das ab.
- **Automatisch mergen** setzt im Repository *Settings → General → Allow
  auto-merge* voraus.

---

## Fehlerbehebung

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 401 beim Push / bei der PR-Erstellung | PAT fehlt der `repo`-Scope, oder der Token ist abgelaufen. |
| 403 beim Pushen nach `.github/workflows/*` | PAT fehlt der `workflow`-Scope. |
| OAuth-Redirect schlägt mit "redirect_uri mismatch" fehl | Die Authorization callback URL der GitHub-OAuth-App muss exakt mit `${APP_URL}/auth/github/callback` übereinstimmen — einschließlich Schema. Prüfe `APP_URL`. |
| OAuth-Callback liefert 500 | `APP_URL` nicht gesetzt oder stimmt nicht mit der öffentlichen URL überein — Laravel kann den korrekten Callback nicht erzeugen. |
| "Mit GitHub verbinden" direkt nach dem Autorisieren abgelehnt | Die OAuth-App unter **Konfiguration → OAuth-Apps** ist deaktiviert oder hat die falsche Client-ID / das falsche Secret. Prüfe sie erneut und aktiviere sie. |
| PR-Erstellung liefert 422 mit "A pull request already exists" | Argos erkennt dies und meldet die URL des bestehenden PRs — keine Aktion erforderlich. |
