# GitHub-Einrichtung

Argos unterstützt GitHub mit zwei Authentifizierungsmethoden: Personal Access
Token (PAT) und OAuth. Beide funktionieren mit github.com.

Siehe [OAuth-Überblick](OAUTH.md) für einen Vergleich von PAT und OAuth und
welche Methode du wählen solltest.

---

## Option 1: Personal Access Token (PAT)

Empfohlen für Einzelnutzer-Instanzen oder wenn du Argos einfach ausprobieren
möchtest. PAT funktioniert ohne serverseitige Konfiguration — du fügst den
Token lediglich im Projektformular ein.

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

### Schritt 2: Projekt in Argos konfigurieren

Unter **Projects → New Project**:

- **Platform**: GitHub
- **Authentication**: Personal Access Token (PAT)
- **Repo URL**: vollständige HTTPS-URL, z. B. `https://github.com/your-org/your-repo`
- **Token**: den Token aus Schritt 1 einfügen
- **Default Branch**: z. B. `main`

---

## Option 2: OAuth (optional)

OAuth aktiviert Repo- und Branch-Dropdowns im Projektformular sowie eine
benutzerbezogene Account-Bindung — keine manuelle URL-Eingabe, und jeder
Benutzer verbindet sein eigenes GitHub-Konto.

OAuth-Apps werden **in der Argos-Oberfläche** verwaltet, nicht über
Umgebungsvariablen. Es gibt keine `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET`,
die du setzen müsstest. Der vollständige UI-Ablauf ist beschrieben unter
[OAuth-Überblick → Registering the OAuth app in Argos](OAUTH.md#registering-the-oauth-app-in-argos).

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

> Das Formular **Add OAuth App** in Argos kann dich per Deep-Link zur
> Registrierungsseite von GitHub leiten, wobei Name, Homepage und Callback
> bereits vorausgefüllt sind, sodass du sie nicht von Hand eingeben musst.

### Schritt 2: OAuth-App in Argos hinzufügen

1. Öffne **Configuration → OAuth Apps** in der Argos-Administration.
2. Füge eine App für **GitHub** hinzu, trage ihre **Client ID** und ihr
   **Client secret** ein und aktiviere sie.
3. Die im Formular angezeigte **callback URL** ist schreibgeschützt und wird aus
   `APP_URL` abgeleitet — kopiere sie in die Authorization callback URL der
   GitHub-OAuth-App, falls noch nicht geschehen.

Die Zugangsdaten werden in der Datenbank gespeichert (`provider_oauth_configs`)
und werden sofort wirksam, ohne Neustart. Es gibt **keine** `*_CLIENT_ID` /
`*_CLIENT_SECRET` Umgebungsvariablen — die Oberfläche ist der einzige Ort, an
dem diese konfiguriert werden. Siehe [Configuration Reference](CONFIGURATION.md)
für die Einstellungen, die *weiterhin* ENV-basiert sind.

Die OAuth-App wird mit dem `repo`-Scope registriert, der Klonen, Pushen sowie
das Erstellen von Branches und PRs abdeckt — denselben Zugriff, den ein
klassischer PAT mit `repo` bietet.

### Schritt 3: Konto verbinden

1. Melde dich bei Argos an.
2. Gehe in der Navigation zu **Connected Accounts**.
3. Klicke auf **Connect GitHub** — du wirst zu GitHub weitergeleitet, um die App
   zu autorisieren. Nach der Autorisierung kehrst du zur Seite Connected
   Accounts zurück.
4. Wähle beim Anlegen eines Projekts **GitHub** als Plattform und **OAuth** als
   Authentifizierungsmethode — die Repo- und Branch-Dropdowns erscheinen,
   befüllt aus deinem verbundenen Konto.

---

## Option 3: Issue-Provider (Pull + Webhook)

Argos kann GitHub Issues als Tasks importieren und sie per Polling oder Webhooks
synchron halten. Jedes Repository benötigt ein **TaskProviderBinding** (eines pro
GitHub-Repo), das über die Filament-Administrationsoberfläche konfiguriert wird.

### Schritt 1: Erforderliche Token-Scopes

| Token-Typ | Benötigte Scopes |
|---|---|
| Classic PAT | `repo` (umfasst die Webhook-Verwaltung) |
| Fine-grained PAT | **Issues**: Read & Write · **Webhooks**: Read & Write |
| OAuth (bestehend) | `repo`-Scope — deckt Webhooks bereits ab |

> **Hinweis**: `admin:repo_hook` ist ein Sub-Scope von `repo` bei klassischen
> PATs. Fine-grained Tokens benötigen "Webhooks: Read & Write" explizit.

### Schritt 2: Binding in Filament anlegen

1. Öffne **Argos Admin → Task Provider Bindings → New**.
2. Setze **Kind** auf `GitHub` und wähle das **Connected Account**, das deinen
   PAT- oder OAuth-Token enthält.
3. Gib die **External Project Ref** im Format `owner/repo` ein, z. B.
   `your-org/your-repo`.
4. Wähle einen **Mode**:
   - **Webhook** — in Echtzeit; erfordert, dass `APP_URL` öffentlich erreichbar ist.
   - **Poll** — periodisches Polling; funktioniert hinter Firewalls oder in der lokalen Entwicklung.
5. Setze optional **Filters** (z. B. `state=open`, Label-Filter), sodass nur
   relevante Issues zu Tasks werden.
6. Klicke auf **Save**. Das Binding startet im Zustand `Pending`.

### Schritt 3: Aktivierung mit "Einrichten"

Klicke auf die Aktion **Einrichten** (Setup) in der Binding-Zeile:

- **Webhook-Modus**: Argos ruft `POST /repos/{owner}/{repo}/hooks` auf GitHub auf
  und registriert die Callback-URL `${APP_URL}/webhooks/issues/github/{binding-id}`
  automatisch. Keine manuelle URL-Eingabe nötig. Das Binding wechselt zu `Active`,
  sobald GitHub den Hook bestätigt.
- **Poll-Modus**: Das Binding wechselt sofort zu `Active`. Der Poll-Scheduler
  ruft die Issues beim nächsten Lauf ab.

Schlägt die Einrichtung fehl (ungültiger Token, fehlende Scopes, `APP_URL` nicht
erreichbar), bleibt das Binding `Pending` und die Fehlermeldung wird in der
Spalte **Last Error** angezeigt. Behebe das Problem und klicke erneut auf
**Einrichten**.

### Webhook vs. Poll

| | Webhook | Poll |
|---|---|---|
| Latenz | Sekunden | Minuten (geplantes Intervall) |
| Erfordert öffentliche `APP_URL` | Ja | Nein |
| GitHub-Rate-Limits | Nicht zutreffend | Zählt auf das API-Kontingent an |

> **Tipp**: Nutze den Poll-Modus während der lokalen Entwicklung oder wenn
> `APP_URL` nicht öffentlich erreichbar ist (z. B. hinter einem VPN oder NAT).
> Wechsle in der Produktion zum Webhook-Modus für Echtzeit-Updates.

### Verhaltenshinweise

- **issue_comment**-Events werden vom Webhook-Endpunkt akzeptiert, aber derzeit
  nicht als Tasks aufgenommen. Nur `issues`-Events (opened, edited usw.)
  erstellen oder aktualisieren Tasks.
- **Issue-Zustand und Task-Zustand sind unabhängig voneinander.** Das Schließen
  eines GitHub-Issues schließt nicht den zugehörigen Task in Argos; Tasks werden
  separat verwaltet.
- **Duplikatsicher**: Der Webhook-Endpunkt ignoriert erneute Zustellungen
  (identifiziert über den Header `X-GitHub-Delivery`). Pull Requests, die in der
  Issues-Liste auftauchen, werden automatisch herausgefiltert.

Für die gemeinsamen Details zur Binding-Einrichtung siehe
[Task Providers Setup](SETUP-TASK-PROVIDERS.md).

---

## Fehlerbehebung

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 401 beim Push / bei der PR-Erstellung | PAT fehlt der `repo`-Scope, oder der Token ist abgelaufen. |
| 403 beim Pushen nach `.github/workflows/*` | PAT fehlt der `workflow`-Scope. |
| OAuth-Redirect schlägt mit "redirect_uri mismatch" fehl | Die Authorization callback URL der GitHub-OAuth-App muss exakt mit `${APP_URL}/auth/github/callback` übereinstimmen — einschließlich Schema. Prüfe `APP_URL`. |
| OAuth-Callback liefert 500 | `APP_URL` nicht gesetzt oder stimmt nicht mit der öffentlichen URL überein — Laravel kann den korrekten Callback nicht erzeugen. |
| "Connect GitHub" direkt nach dem Autorisieren abgelehnt | Die OAuth-App unter **Configuration → OAuth Apps** ist deaktiviert oder hat die falsche Client ID / das falsche Secret. Prüfe sie erneut und aktiviere sie. |
| PR-Erstellung liefert 422 mit "A pull request already exists" | Argos erkennt dies und meldet die URL des bestehenden PRs — keine Aktion erforderlich. |
