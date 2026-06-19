# Bitbucket-Einrichtung

Diese Anleitung beschreibt, wie du Argos mit Bitbucket-Cloud-Repositories
verbindest — entweder über ein Repository Access Token oder über OAuth.

Siehe [OAuth-Überblick](OAUTH.md) für einen Vergleich von PAT und OAuth und die
Frage, welche Variante du wählen solltest.

> **Hinweis zum Umfang — Bitbucket ist ausschließlich ein Code-Provider.** Argos
> nutzt Bitbucket zum Klonen, für Branches und zum Erstellen von Pull Requests.
> Bitbucket ist **nicht** als Task-/Issue-Provider angebunden: Es gibt keine
> Bitbucket-Option beim Anbinden einer Task-Quelle, und Argos liest weder
> Bitbucket-Issues in Tasks ein noch registriert es Issue-Webhooks dafür. Siehe
> [Task-Provider](SETUP-TASK-PROVIDERS.md) für die Provider, die den
> Issue-→-Task-Fluss tatsächlich antreiben (GitHub, GitLab, Linear).

---

## Was mit Bitbucket funktioniert

| Funktion | Unterstützt |
|---|---|
| Repository klonen | Ja |
| Repositories / Branches auflisten (für Dropdowns im Projektformular) | Ja |
| Pull Requests erstellen / aktualisieren / kommentieren | Ja |
| Default-Branch und Quelldateien lesen | Ja |
| Issue-→-Task-Ingest (ein Bitbucket-Issue wird zu einem Argos-Task) | **Nein** |
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
     Repo lesen soll; Bitbucket ist kein Task-Provider, daher entstehen daraus
     keine Tasks)
6. Klicke auf **Create** und kopiere das generierte Token — es wird nur einmal
   angezeigt.

### Schritt 2: In Argos konfigurieren

Unter **Projects → New Project**:

- **Platform**: Bitbucket
- **Repo URL**: `https://bitbucket.org/<workspace>/<repository>`
- **Token**: das Token direkt einfügen (kein Benutzername-Präfix)

> **Wichtig**: Stelle deinem Benutzernamen **nicht** voran. Repository Access
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

OAuth aktiviert Repository- und Branch-Dropdowns im Projektformular sowie eine
benutzerbezogene Konto-Anbindung — jeder Nutzer verbindet sein eigenes
Bitbucket-Konto, statt ein Token einzufügen.

OAuth-Apps werden **in der Argos-UI** verwaltet, nicht über Umgebungsvariablen.
Es gibt kein `BITBUCKET_CLIENT_ID` / `BITBUCKET_CLIENT_SECRET` zu setzen. Der
vollständige UI-Ablauf ist beschrieben unter
[OAuth-Überblick → Die OAuth-App in Argos registrieren](OAUTH.md#registering-the-oauth-app-in-argos).

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

1. Öffne **Configuration → OAuth Apps** in der Argos-Administration.
2. Füge eine App für **Bitbucket** hinzu, trage deren **Key** (Client-ID) und
   **Secret** (Client-Secret) ein und aktiviere sie.
3. Die im Formular angezeigte **Callback URL** ist schreibgeschützt und wird aus
   `APP_URL` abgeleitet — sie ist fest auf `${APP_URL}/auth/bitbucket/callback`
   gesetzt. Kopiere sie in die Callback URL des Bitbucket-OAuth-Consumers, falls
   noch nicht geschehen.

Die Zugangsdaten werden in der Datenbank (`provider_oauth_configs`) gespeichert
und sind sofort wirksam, ohne Neustart.

### Schritt 3: Dein Konto verbinden

1. Gehe zu **Connected Accounts** im Argos-Admin-Panel.
2. Klicke auf **Connect Bitbucket**.
3. Autorisiere den OAuth Consumer in Bitbucket.
4. Wähle beim Erstellen eines Projekts **Bitbucket** als Plattform und **OAuth**
   als Authentifizierungsmethode, um Repositories und Branches aus dem
   verbundenen Konto abzurufen.

---

## Fehlerbehebung

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 403 am Issues-Endpoint | Der Issue-Tracker ist für das Repository deaktiviert (Issues werden als leere Liste zurückgegeben) |
| 401 beim Push | Dem Token fehlt die Repositories-Write-Berechtigung, oder das Token wurde versehentlich mit einem Benutzername-Präfix eingefügt |
| PR-Erstellung liefert 409 | Ein PR für diesen Branch existiert bereits — Argos findet die bestehende URL und meldet sie |
| OAuth-Redirect schlägt fehl | Die im OAuth Consumer registrierte Callback URL muss exakt mit `${APP_URL}/auth/bitbucket/callback` übereinstimmen — überprüfe `APP_URL` und ob die OAuth-App unter **Configuration → OAuth Apps** aktiviert ist |
| Du erwartest, dass ein Bitbucket-Issue zu einem Argos-Task wird | Nicht unterstützt — Bitbucket ist ausschließlich ein Code-Provider. Verwende [GitHub/GitLab/Linear](SETUP-TASK-PROVIDERS.md) als Task-Provider |
