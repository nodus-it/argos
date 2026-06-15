# Provider-Zugangsdaten

Argos muss sich gegenüber Ihren Git-Hosts (GitHub, GitLab, Bitbucket) und
Issue-Trackern (Linear) in Ihrem Namen authentifizieren — um Repositories zu
klonen, Branches zu pushen, Pull Requests zu eröffnen sowie Issues zu lesen oder
zu aktualisieren. Den dafür benötigten Token kann Argos auf zwei Wegen erhalten:
über eine **Provider-Zugangsdaten** (ein Personal Access Token, das Sie
hinterlegen) oder über ein **Verbundenes Konto** (eine OAuth-Anmeldung).

Dieses Dokument behandelt den ersten Weg — **Provider-Zugangsdaten, also
Personal Access Tokens (PATs)**. Die OAuth-Seite ist in [OAUTH.md](OAUTH.md)
beschrieben.

> Provider-Zugangsdaten sind *nicht* dasselbe wie Agent-Zugangsdaten (der
> Claude-/Codex-Token, den der Worker zum Denken nutzt). Diese werden
> separat konfiguriert — siehe [AGENTS.md](AGENTS.md).

## Inhalt

- [PAT oder OAuth: was nutze ich?](#pat-vs-oauth-which-do-i-use)
- [Was Provider-Zugangsdaten sind](#what-a-provider-credential-is)
- [Zugangsdaten hinzufügen](#adding-a-credential)
  - [Erforderliche Scopes je Provider](#required-scopes-per-provider)
  - [Selbst gehostete Instanzen](#self-hosted-instances)
  - [Verbindung testen](#test-connection)
- [Sicherheit](#security)
- [Wie ein Projekt Zugangsdaten nutzt](#how-a-project-uses-a-credential)
- [Wie ein Issue-Tracker Zugangsdaten nutzt](#how-an-issue-tracker-uses-a-credential)
- [Ablauf, Rotation und Status](#expiry-rotation-and-status)

## PAT oder OAuth: was nutze ich?

Beide enden am selben Punkt: einem Token-String, mit dem Argos mit der
Provider-API kommuniziert. Sie unterscheiden sich darin, wie dieser Token
beschafft und verwaltet wird.

| | Provider-Zugangsdaten (PAT) | Verbundenes Konto (OAuth) |
| --- | --- | --- |
| Was Sie bereitstellen | Einen Token-String, den Sie beim Provider erzeugen | Eine Anmeldung über den OAuth-Flow des Providers |
| Voraussetzung | Keine — nur der Token | Eine zuvor registrierte [OAuth-App](OAUTH.md) |
| Gebunden an | Nichts — kontoübergreifend und wiederverwendbar | Den Argos-Benutzer, der es verbunden hat |
| Erneuerung | Statisch, wird nie erneuert | Wird automatisch nahe dem Ablauf erneuert |
| Am besten für | Service-/Bot-Konten, selbst gehostete Instanzen, schnelles Setup, CI-artige Nutzung | Einzelne Benutzer, Komfort von „Mit GitHub anmelden" |

Schnelle Entscheidungshilfe:

- **Nutzen Sie ein PAT**, wenn Sie einen stabilen, kontounabhängigen Token
  möchten — zum Beispiel ein dediziertes Bot-/Service-Konto, eine selbst
  gehostete GitLab-/Bitbucket-Instanz oder wenn Sie keine OAuth-App
  registrieren möchten.
- **Nutzen Sie OAuth**, wenn Sie sich lieber interaktiv anmelden und Argos die
  Token-Erneuerung für Sie übernehmen lassen. Siehe [OAUTH.md](OAUTH.md).

Sie müssen sich nicht global für eine Variante entscheiden — jedes Projekt und
jede Issue-Tracker-Bindung wählt ihre eigene Quelle für Zugangsdaten
unabhängig, und ein PAT und ein OAuth-Konto können für denselben Provider
nebeneinander bestehen.

## Was Provider-Zugangsdaten sind

Provider-Zugangsdaten sind ein **benanntes, wiederverwendbares Personal Access
Token** für einen Integrations-Provider. Sie umfassen:

- **Label** — ein frei wählbarer Name, an dem Sie sie wiedererkennen (z. B.
  `GitHub – acme org`).
- **Plattform** — eine von GitHub, GitLab, Bitbucket oder Linear.
- **Instanz-URL** — optional, nur für selbst gehostete Instanzen; leer bedeutet
  den öffentlichen SaaS-Host.
- **Token** — das Personal Access Token selbst (bei Bitbucket ist dies ein *App
  password*), verschlüsselt gespeichert.
- **Scopes (Notiz)** — eine optionale Freitext-Erinnerung daran, mit welchen
  Scopes der Token erzeugt wurde. Dies ist nur eine Notiz; sie schränkt den
  Token nicht ein.
- **Status** — `Active`, `Expired` oder `Revoked`.
- **Zuletzt validiert** — wann Argos zuletzt bestätigt hat, dass der Token
  funktioniert.

Anders als ein Verbundenes Konto sind Provider-Zugangsdaten an keinen
Argos-Benutzer gebunden und benötigen keine OAuth-App — Sie erstellen sie
einmal und verweisen überall dort darauf, wo ein Token für diesen Provider
benötigt wird.

## Zugangsdaten hinzufügen

Provider-Zugangsdaten werden im Admin-Panel unter der Navigationsgruppe
**Configuration** auf der Seite **Access Tokens** verwaltet:

```
${APP_URL}/admin/provider-credentials
```

Sie können sie auch während des Onboardings anlegen — im
Autorisieren-/Repository-Schritt erscheinen gespeicherte Access Tokens neben
OAuth-Konten als Token-Quelle.

So fügen Sie Zugangsdaten hinzu:

1. Wählen Sie die **Plattform** (und tragen Sie für einen selbst gehosteten
   Server die **Instanz-URL** ein).
2. Es erscheint ein direkter, vorausgefüllter Link — **Create token at
   &lt;provider&gt;** — der die Token-Erstellungsseite des Providers mit den
   bereits ausgewählten erforderlichen Scopes öffnet, sofern der Provider dies
   unterstützt. Folgen Sie ihm, erzeugen Sie den Token und kopieren Sie ihn
   zurück.
3. Geben Sie den Zugangsdaten ein **Label**.
4. Fügen Sie den **Token** ein.
5. Notieren Sie optional die Scopes unter **Scopes (Notiz)**.
6. Speichern Sie und führen Sie dann **Test connection** aus (siehe unten).

### Erforderliche Scopes je Provider

Dies sind die Scopes, die Argos im vorausgefüllten Erstellungslink anfordert.
Erzeugen Sie den Token mindestens mit diesen. Den vollständigen Ablauf finden
Sie in den providerspezifischen Setup-Dokumenten.

| Provider | Scopes | Setup-Dokument |
| --- | --- | --- |
| GitHub | `repo` | [SETUP-GITHUB.md](SETUP-GITHUB.md) |
| GitLab | `api`, `write_repository` | [SETUP-GITLAB.md](SETUP-GITLAB.md) |
| Bitbucket (App password) | Repositories (read/write), Pull requests, Webhooks | [SETUP-BITBUCKET.md](SETUP-BITBUCKET.md) |
| Linear | `read`, `write` | [SETUP-LINEAR.md](SETUP-LINEAR.md) |

Beachten Sie, dass GitHub einen einzigen breiten `repo`-Scope verwendet; GitLab
benötigt sowohl `api` als auch `write_repository`; Bitbucket hat kein PAT im
klassischen Sinne — Sie erstellen ein **App password** mit den aufgeführten
Berechtigungen; Linear verwendet einen API-Key mit Lese- und Schreibrechten.

### Selbst gehostete Instanzen

Tragen Sie für ein selbst gehostetes GitLab (CE/EE) oder einen Bitbucket Server
die **Instanz-URL** ein (z. B. `https://gitlab.example.com`). Lassen Sie sie für
die öffentliche Instanz leer. Wenn gesetzt, wird die Instanz-URL sowohl zum
Aufbau des Token-Erstellungslinks als auch als API-Host verwendet, mit dem
Argos kommuniziert. Die öffentlichen Standardwerte sind `https://github.com`,
`https://gitlab.com`, `https://bitbucket.org` und `https://linear.app`.

### Verbindung testen

Verwenden Sie nach dem Speichern die Aktion **Test connection** in der Tabelle
der Zugangsdaten. Argos führt einen einzigen günstigen, authentifizierten
API-Aufruf mit dem gespeicherten Token aus:

- Bei Erfolg werden die Zugangsdaten als **Active** markiert und **Zuletzt
  validiert** mit einem Zeitstempel versehen.
- Eine eindeutige Ablehnung (der Provider liefert 400/401/403 zurück,
  typischerweise ein falscher oder unzureichend berechtigter Token) wird mit der
  providereigenen Meldung gemeldet, und der Status bleibt unverändert.
- Wenn der Provider schlicht nicht erreicht werden konnte (Netzwerk/5xx), teilt
  Argos dies mit und lässt den Status ebenfalls unverändert — der Token wurde
  nicht als ungültig erwiesen.

Der Fehler-Body des Providers enthält nie den Token, sodass seine Meldung
gefahrlos angezeigt werden kann.

## Sicherheit

- Der Token wird im Ruhezustand **verschlüsselt gespeichert** (Laravel
  encrypted cast) und nur dann entschlüsselt, wenn ein Token-String tatsächlich
  für einen API-Aufruf benötigt wird.
- Tokens werden **niemals geloggt** — nicht in der App, nicht im Worker, nicht
  einmal für Diagnosezwecke.
- Das Token-Feld im Formular ist maskiert (auf Wunsch einblendbar), sodass es
  standardmäßig nicht im Klartext angezeigt wird.

## Wie ein Projekt Zugangsdaten nutzt

Wenn Sie ein Projekt (RepoProfile) anlegen, wählen Sie eine **Token-Quelle** für
das Repository — entweder ein OAuth-Konto oder ein gespeichertes Access Token.
Wenn Sie ein PAT wählen, werden dessen Token und Plattform verwendet, um die
Git-Authentifizierung des Projekts einzurichten, wobei die Auth-Methode des
Projekts als `pat` vermerkt wird. Zur Laufzeit löst der Worker den Token des
Projekts aus diesem PAT auf (OAuth-Projekte lösen den Token stattdessen aus
ihrem Verbundenen Konto auf — und erneuern ihn).

Den vollständigen Ablauf der Projekt-Authentifizierung finden Sie in
[PROJECTS.md](PROJECTS.md).

## Wie ein Issue-Tracker Zugangsdaten nutzt

Wenn Sie einen Issue-Tracker an ein Projekt anbinden (eine
Task-Provider-Bindung), lässt Sie der **Access**-Auswähler entweder ein
verbundenes OAuth-Konto oder eines Ihrer gespeicherten Access Tokens für diesen
Provider in einer einheitlichen Liste wählen. Wenn Sie ein PAT wählen,
referenziert die Bindung diese Zugangsdaten direkt, und der Issue-Tracker
(Lesen von Issues, Zurückschreiben) authentifiziert sich damit.

Aus diesem Grund ist die Menge der Provider, die Zugangsdaten bedienen können,
bewusst breit gefasst: Eine einzelne Zugangsdaten-Einheit kann sowohl die
Git-Rolle (clone/push/PR) als auch die Issue-Tracker-Rolle abdecken.

## Ablauf, Rotation und Status

Provider-Zugangsdaten (PATs) sind **statisch**: Argos speichert den Token, den
Sie ihm geben, und verwendet ihn unverändert. Es gibt keinen
Erneuerungsmechanismus — dieser gilt nur für OAuth-Verbundene-Konten (behandelt
in [OAUTH.md](OAUTH.md)).

Folglich ist die **Rotation manuell**:

- Wenn ein Token dem beim Provider gesetzten Ablauf nahekommt, erzeugen Sie
  einen neuen und aktualisieren Sie das **Token**-Feld der Zugangsdaten, und
  führen Sie dann **Test connection** aus, um erneut zu validieren.
- Das Feld **Status** (`Active` / `Expired` / `Revoked`) hält den aktuellen
  Zustand der Zugangsdaten fest. **Zuletzt validiert** sagt Ihnen, wann Argos
  zuletzt bestätigt hat, dass der Token funktioniert hat — verwenden Sie **Test
  connection**, um es nach einer Rotation zu aktualisieren.

Wenn ein Token abläuft oder beim Provider widerrufen wird, schlägt der nächste
Lauf, der ihn verwendet, bei der Authentifizierung fehl; testen Sie erneut oder
ersetzen Sie den Token, um die Funktion wiederherzustellen.
