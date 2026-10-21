# Provider-Zugangsdaten

Argos muss sich gegenüber deinen Git-Hosts (GitHub, GitLab, Bitbucket) und
Issue-Trackern (Linear) in deinem Namen authentifizieren — um Repositories zu
klonen, Branches zu pushen, Pull Requests zu eröffnen sowie Issues zu lesen oder
zu aktualisieren. Den dafür benötigten Token kann Argos auf zwei Wegen erhalten:
über **Provider-Zugangsdaten** (ein Personal Access Token, das du hinterlegst)
oder über ein **verbundenes Konto** (eine OAuth-Anmeldung).

Dieses Dokument behandelt den ersten Weg — **Provider-Zugangsdaten, also
Personal Access Tokens (PATs)**. Die OAuth-Seite ist in [OAUTH.md](OAUTH.md)
beschrieben.

> Provider-Zugangsdaten sind *nicht* dasselbe wie Agent-Zugangsdaten (der
> Claude-Token, auf dem der Agent läuft). Diese werden
> separat konfiguriert — siehe [AGENTS.md](AGENTS.md).

## Inhalt

- [PAT oder OAuth: was nutze ich?](#pat-oder-oauth-was-nutze-ich)
- [Was Provider-Zugangsdaten sind](#was-provider-zugangsdaten-sind)
- [Zugangsdaten hinzufügen](#zugangsdaten-hinzufugen)
  - [Erforderliche Scopes je Provider](#erforderliche-scopes-je-provider)
  - [Selbst gehostete Instanzen](#selbst-gehostete-instanzen)
  - [Verbindung testen](#verbindung-testen)
- [Sicherheit](#sicherheit)
- [Wie ein Workspace Zugangsdaten nutzt](#wie-ein-workspace-zugangsdaten-nutzt)
- [Wie ein Issue-Tracker Zugangsdaten nutzt](#wie-ein-issue-tracker-zugangsdaten-nutzt)
- [Ablauf, Rotation und Status](#ablauf-rotation-und-status)

## PAT oder OAuth: was nutze ich?

Beide enden am selben Punkt: einem Token-String, mit dem Argos mit der
Provider-API kommuniziert. Sie unterscheiden sich darin, wie dieser Token
beschafft und verwaltet wird.

| | Provider-Zugangsdaten (PAT) | Verbundenes Konto (OAuth) |
| --- | --- | --- |
| Was du bereitstellst | Einen Token-String, den du beim Provider erzeugst | Eine Anmeldung über den OAuth-Flow des Providers |
| Voraussetzung | Keine — nur der Token | Eine zuvor registrierte [OAuth-App](OAUTH.md) |
| Gebunden an | Nichts — kontoübergreifend und wiederverwendbar | Das Argos-Benutzerkonto, das es verbunden hat |
| Erneuerung | Statisch, wird nie erneuert | Wird automatisch nahe dem Ablauf erneuert |
| Am besten für | Service-/Bot-Konten, selbst gehostete Instanzen, schnelles Setup, CI-artige Nutzung | Einzelne Personen, Komfort von „Mit GitHub anmelden“ |

Schnelle Entscheidungshilfe:

- **Nutze ein PAT**, wenn du einen stabilen, kontounabhängigen Token möchtest —
  zum Beispiel ein dediziertes Bot-/Service-Konto, eine selbst gehostete
  GitLab-/Bitbucket-Instanz oder wenn du keine OAuth-App registrieren möchtest.
- **Nutze OAuth**, wenn du dich lieber interaktiv anmeldest und Argos die
  Token-Erneuerung für dich übernehmen lässt. Siehe [OAUTH.md](OAUTH.md).

Du musst dich nicht global für eine Variante entscheiden — jeder Workspace und
jede Issue-Tracker-Integration wählt ihre eigene Quelle für Zugangsdaten
unabhängig, und ein PAT und ein OAuth-Konto können für denselben Provider
nebeneinander bestehen.

## Was Provider-Zugangsdaten sind

Provider-Zugangsdaten sind ein **benanntes, wiederverwendbares Personal Access
Token** für einen Integrations-Provider. Sie umfassen:

- **Bezeichnung** — ein frei wählbarer Name, an dem du sie wiedererkennst
  (z. B. `GitHub – acme org`).
- **Plattform** — eine von GitHub, GitLab, Bitbucket oder Linear.
- **Instanz-URL** — optional, nur für selbst gehostete Instanzen; leer bedeutet
  den öffentlichen SaaS-Host.
- **Token** — das Personal Access Token selbst (bei Bitbucket ist dies ein *App
  password*), verschlüsselt gespeichert.
- **Scopes (Notiz)** — eine optionale Freitext-Erinnerung daran, mit welchen
  Scopes der Token erzeugt wurde. Dies ist nur eine Notiz; sie schränkt den
  Token nicht ein.
- **Status** — `Aktiv`, `Abgelaufen` oder `Widerrufen`.
- **Zuletzt geprüft** — wann Argos zuletzt bestätigt hat, dass der Token
  funktioniert.

Anders als ein verbundenes Konto sind Provider-Zugangsdaten an kein
Argos-Benutzerkonto gebunden und benötigen keine OAuth-App — du erstellst sie
einmal und verweist überall dort darauf, wo ein Token für diesen Provider
benötigt wird.

## Zugangsdaten hinzufügen

Provider-Zugangsdaten werden im Admin-Panel unter der Navigationsgruppe
**Konfiguration** auf der Seite **Access-Tokens** verwaltet:

```
${APP_URL}/admin/provider-credentials
```

Du kannst sie auch während des Onboardings anlegen — im
Autorisieren-/Repository-Schritt erscheinen gespeicherte Access-Tokens neben
OAuth-Konten als Token-Quelle.

So fügst du Zugangsdaten hinzu:

1. Wähle die **Plattform** (und trage für einen selbst gehosteten Server die
   **Instanz-URL** ein).
2. Es erscheint ein direkter, vorausgefüllter Link — **Token bei
   &lt;provider&gt; erstellen** — der die Token-Erstellungsseite des Providers
   mit den bereits ausgewählten erforderlichen Scopes öffnet, sofern der
   Provider dies unterstützt. Folge ihm, erzeuge den Token und kopiere ihn
   zurück.
3. Gib den Zugangsdaten eine **Bezeichnung**.
4. Füge den **Token** ein.
5. Notiere optional die Scopes unter **Scopes (Notiz)**.
6. Speichere und führe dann **Verbindung testen** aus (siehe unten).

### Erforderliche Scopes je Provider

Dies sind die Scopes, die Argos im vorausgefüllten Erstellungslink anfordert.
Erzeuge den Token mindestens mit diesen. Den vollständigen Ablauf findest du in
den providerspezifischen Setup-Dokumenten.

| Provider | Scopes | Setup-Dokument |
| --- | --- | --- |
| GitHub | `repo` | [SETUP-GITHUB.md](SETUP-GITHUB.md) |
| GitLab | `api`, `write_repository` | [SETUP-GITLAB.md](SETUP-GITLAB.md) |
| Bitbucket (App password) | Repositories (read/write), Pull requests, Webhooks | [SETUP-BITBUCKET.md](SETUP-BITBUCKET.md) |
| Linear | `read`, `write` | [SETUP-LINEAR.md](SETUP-LINEAR.md) |

Beachte, dass GitHub einen einzigen breiten `repo`-Scope verwendet; GitLab
benötigt sowohl `api` als auch `write_repository`; Bitbucket hat kein PAT im
klassischen Sinne — du erstellst ein **App password** mit den aufgeführten
Berechtigungen; Linear verwendet einen API-Key mit Lese- und Schreibrechten.

### Selbst gehostete Instanzen

Trage für ein selbst gehostetes GitLab (CE/EE) oder einen Bitbucket Server die
**Instanz-URL** ein (z. B. `https://gitlab.example.com`). Lass sie für die
öffentliche Instanz leer. Wenn gesetzt, wird die Instanz-URL sowohl zum Aufbau
des Token-Erstellungslinks als auch als API-Host verwendet, mit dem Argos
kommuniziert. Die öffentlichen Standardwerte sind `https://github.com`,
`https://gitlab.com`, `https://bitbucket.org` und `https://linear.app`.

### Verbindung testen

Verwende nach dem Speichern die Aktion **Verbindung testen** in der Tabelle der
Zugangsdaten. Argos führt einen einzigen günstigen, authentifizierten
API-Aufruf mit dem gespeicherten Token aus:

- Bei Erfolg werden die Zugangsdaten als **Aktiv** markiert und **Zuletzt
  geprüft** mit einem Zeitstempel versehen.
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

## Wie ein Workspace Zugangsdaten nutzt

Der Repository-Zugang eines Workspaces ist entweder ein **OAuth-Konto** oder
ein **PAT** (Auth-Methode `pat`). Im Workspace-Formular fügst du das PAT direkt
ein; der Onboarding-Assistent kann auch eines deiner gespeicherten
Access-Tokens wählen, dessen Token dann in den Workspace kopiert wird. Über
diesen Zugang klont Argos, pusht die Branches der Aufgaben und öffnet und liest
die Pull Requests **aller** Aufgaben im Workspace (OAuth-Workspaces lösen den
Token stattdessen aus ihrem verbundenen Konto auf — und erneuern ihn). Ein
kopierter Token folgt späteren Änderungen der gespeicherten Zugangsdaten nicht
— aktualisiere ihn im Workspace (Admin-Panel → Workspaces).

Zum Workspace-Formular siehe [Workspace-Einstellungen](PROJECTS.md).

## Wie ein Issue-Tracker Zugangsdaten nutzt

Wenn du einem Workspace einen Issue-Tracker hinzufügst (Einstellungen →
Integrationen → Issue-Tracker), lässt dich die Auswahl **Zugang** entweder ein
verbundenes OAuth-Konto oder eines deiner gespeicherten Access-Tokens für
diesen Provider in einer einheitlichen Liste wählen. Wenn du ein PAT wählst,
referenziert die Integration diese Zugangsdaten direkt, und der Issue-Tracker
(Lesen von Issues und Kommentaren, Schließen, Zuweisungen) authentifiziert sich
damit — ein rotierter Token gilt also sofort.

Aus diesem Grund ist die Menge der Provider, die Zugangsdaten bedienen können,
bewusst breit gefasst: Eine einzelne Zugangsdaten-Einheit kann sowohl die
Git-Rolle (clone/push/PR) als auch die Issue-Tracker-Rolle abdecken.

## Ablauf, Rotation und Status

Provider-Zugangsdaten (PATs) sind **statisch**: Argos speichert den Token, den
du ihm gibst, und verwendet ihn unverändert. Es gibt keinen
Erneuerungsmechanismus — dieser gilt nur für verbundene OAuth-Konten (behandelt
in [OAUTH.md](OAUTH.md)).

Folglich ist die **Rotation manuell**:

- Wenn ein Token dem beim Provider gesetzten Ablauf nahekommt, erzeuge einen
  neuen, aktualisiere das Feld **Token** der Zugangsdaten und führe dann
  **Verbindung testen** aus, um erneut zu prüfen.
- Das Feld **Status** (`Aktiv` / `Abgelaufen` / `Widerrufen`) hält den
  aktuellen Zustand der Zugangsdaten fest. **Zuletzt geprüft** sagt dir, wann
  Argos zuletzt bestätigt hat, dass der Token funktioniert hat — verwende
  **Verbindung testen**, um es nach einer Rotation zu aktualisieren.

Wenn ein Token abläuft oder beim Provider widerrufen wird, schlägt der nächste
Aufruf, der ihn verwendet, bei der Authentifizierung fehl; teste erneut oder
ersetze den Token, um die Funktion wiederherzustellen.
