# Bitbucket-Einrichtung

Diese Anleitung beschreibt, wie du Argos mit Bitbucket-Cloud-Repositories
verbindest — entweder über ein Repository Access Token oder über OAuth.

Siehe [OAuth-Überblick](OAUTH.md) für einen Vergleich von PAT und OAuth und die
Frage, welche Variante du wählen solltest.

> **Hinweis zum Umfang — Bitbucket ist ausschließlich ein Code-Provider.** Argos
> nutzt Bitbucket zum Klonen, für Branches und für Pull Requests. Bitbucket ist
> **nicht** als Issue-Tracker angebunden: Unter den Issue-Trackern eines
> Workspaces gibt es keine Bitbucket-Option, und Argos nutzt Bitbucket-Issues
> weder als Tickets, noch startet es Aufgaben daraus oder registriert
> Issue-Webhooks dafür. Siehe [Issue-Trigger](SETUP-TASK-PROVIDERS.md) für die
> Provider, die aus Issues tatsächlich automatische Aufgaben starten (GitHub,
> GitLab, Linear).

---

## Was mit Bitbucket funktioniert

| Funktion | Unterstützt |
|---|---|
| Repository klonen | Ja |
| Repositories / Branches auflisten (für Dropdowns im Workspace-Formular) | Ja |
| Pull Requests erstellen / aktualisieren / kommentieren | Ja |
| Zustand des Pull Requests, Build-Status, Reviews und Kommentare, Konflikte in der Aufgabe | Ja (keine Logs fehlgeschlagener Builds, kein Hinweis „Basis hat sich weiterbewegt“) |
| Auto-Merge | **Nein** (Bitbucket Cloud hat keins) |
| Default-Branch und Quelldateien lesen | Ja |
| Issues als Tickets / Issue-Trigger (ein Bitbucket-Issue startet eine automatische Aufgabe) | **Nein** |
| Issue-Webhooks / 👍-Freigabe-Fluss | **Nein** |

---

## Option 1: Repository Access Token

Repository Access Tokens sind auf ein einzelnes Repository beschränkt und nutzen
Bearer-Authentifizierung. Sie sind die empfohlene Option und erfordern keine
serverseitige OAuth-Konfiguration.

> **Hinweis:** Bitbucket App Passwords sind veraltet und werden am
> **2026-06-09** deaktiviert. Verwende stattdessen Repository Access Tokens.

### Schritt 1: Ein Repository Access Token erstellen

1. Melde dich bei Bitbucket Cloud an.
2. Navigiere zu deinem Repository.
3. Gehe zu **Repository Settings** → **Access tokens** (unter "Security").
4. Klicke auf **Create Repository Access Token**.
5. Vergib einen aussagekräftigen Namen (z. B. `argos`) und wähle die folgenden
   Berechtigungen:
   - **Repositories**: Read, Write
   - **Pull requests**: Read, Write
   - **Issues**: Read (optional — nur nötig, wenn Argos Issue-Inhalte aus dem
     Repo lesen soll; Bitbucket ist kein Issue-Trigger, daher starten daraus
     keine Aufgaben)
6. Klicke auf **Create** und kopiere das generierte Token — es wird nur einmal
   angezeigt.

### Schritt 2: Workspace in Argos anlegen

Wähle als Argos-Administrator **Neuer Workspace** (in `/w` oder im Admin-Panel
unter **Workspaces**):

- **Plattform**: Bitbucket
- **Repo-URL**: `https://bitbucket.org/<workspace>/<repository>`
- **Token**: das Token direkt einfügen (kein Benutzername-Präfix)

> **Wichtig**: Stelle **nicht** deinen Benutzernamen voran. Repository Access
> Tokens werden als Bearer-Tokens verwendet — füge das Token einfach unverändert
> ein. Argos erkennt den Auth-Modus (Basic vs. Bearer) an der Form des Tokens.

### Alternative: Atlassian API Token (workspaceweiter Zugriff)

Wenn du Zugriff über mehrere Repositories hinweg benötigst, kannst du ein
Atlassian API Token mit Basic-Authentifizierung verwenden:

- **Token-Format**: `your-email@example.com:your-api-token`
- Erstelle ein API Token unter
  [id.atlassian.com/manage-profile/security/api-tokens](https://id.atlassian.com/manage-profile/security/api-tokens)

---

## Option 2: OAuth (optional)

OAuth aktiviert Repository- und Branch-Dropdowns im Workspace-Formular sowie
eine Konto-Anbindung pro Person — alle verbinden ihr eigenes Bitbucket-Konto,
statt ein Token einzufügen.

OAuth-Apps werden **in der Argos-UI** verwaltet, nicht über Umgebungsvariablen.
Es gibt kein `BITBUCKET_CLIENT_ID` / `BITBUCKET_CLIENT_SECRET` zu setzen. Der
vollständige UI-Ablauf ist beschrieben unter
[OAuth-Überblick → Die OAuth-App in Argos registrieren](OAUTH.md#die-oauth-app-in-argos-registrieren).

### Schritt 1: Einen OAuth Consumer auf Bitbucket erstellen

1. Melde dich bei Bitbucket Cloud an und navigiere zu dem Workspace, den du
   verbinden möchtest.
2. Gehe zu **Workspace Settings** → **OAuth consumers** (unter
   "Apps and Features").
3. Klicke auf **Add consumer**.
4. Fülle die Details aus:
   - **Name**: z. B. `Argos`
   - **Callback URL**: `${APP_URL}/auth/bitbucket/callback`
     (ersetze `${APP_URL}` durch die URL deiner Argos-Instanz, z. B.
     `https://argos.example.com`). Registriere genau diese URL, inklusive des
     Schemas.
5. Wähle die folgenden Berechtigungen (sie entsprechen den OAuth-Scopes, die
   Argos anfordert: `account`, `repository`, `pullrequest`, `issue`):
   - **Account**: Read
   - **Repositories**: Read, Write
   - **Pull requests**: Read, Write
   - **Issues**: Read
6. Klicke auf **Save** und notiere dir den **Key** (Client-ID) und das **Secret**
   (Client-Secret).

### Schritt 2: Die OAuth-App in Argos hinzufügen

1. Öffne **Konfiguration → OAuth-Apps** in der Argos-Administration.
2. Füge eine App für **Bitbucket** hinzu, trage deren **Key** (Client-ID) und
   **Secret** (Client-Secret) ein und aktiviere sie.
3. Die im Formular angezeigte **Callback-URL** ist schreibgeschützt und wird aus
   `APP_URL` abgeleitet — sie ist fest auf `${APP_URL}/auth/bitbucket/callback`
   gesetzt. Kopiere sie in die Callback URL des Bitbucket-OAuth-Consumers, falls
   noch nicht geschehen.

Die Zugangsdaten werden in der Datenbank (`provider_oauth_configs`) gespeichert
und sind sofort wirksam, ohne Neustart.

### Schritt 3: Dein Konto verbinden

1. Gehe zu **Verknüpfte Accounts** im Argos-Admin-Panel.
2. Klicke auf **Mit Bitbucket verbinden**.
3. Autorisiere den OAuth Consumer in Bitbucket.
4. Wähle beim Anlegen eines Workspaces **Bitbucket** als Plattform und **OAuth**
   als Authentifizierungsmethode, um Repositories und Branches aus dem
   verbundenen Konto abzurufen.

---

## Fehlerbehebung

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 403 am Issues-Endpoint | Der Issue-Tracker ist für das Repository deaktiviert (Issues werden als leere Liste zurückgegeben) |
| 401 beim Push | Dem Token fehlt die Repositories-Write-Berechtigung, oder das Token wurde versehentlich mit einem Benutzername-Präfix eingefügt |
| PR-Erstellung liefert 409 | Ein PR für diesen Branch existiert bereits — Argos findet die bestehende URL und meldet sie |
| OAuth-Redirect schlägt fehl | Die im OAuth Consumer registrierte Callback URL muss exakt mit `${APP_URL}/auth/bitbucket/callback` übereinstimmen — überprüfe `APP_URL` und ob die OAuth-App unter **Konfiguration → OAuth-Apps** aktiviert ist |
| Du erwartest, dass ein Bitbucket-Issue eine automatische Aufgabe startet | Nicht unterstützt — Bitbucket ist ausschließlich ein Code-Provider. Verwende [GitHub/GitLab/Linear](SETUP-TASK-PROVIDERS.md) oder ein [eigenes Ticket-System](TICKETS.md#eigene-ticket-systeme) |
| „Automatisch mergen“ lässt sich nicht einschalten | Bitbucket hat kein natives Auto-Merge; merge in Bitbucket |
