# Live-Demos

Eine **Live-Demo** ist ein kurzlebiges, laufendes Deployment des implementierten
Branches einer Aufgabe. Nachdem Argos die Implement-Phase abgeschlossen hat, kann
es den Code in einem eigenen Container-Stack hochfahren und unter einer
temporären Subdomain veröffentlichen, sodass du das Ergebnis im Browser
durchklicken kannst, **bevor** der Pull Request gemerged wird.

Diese Seite erklärt, was eine Live-Demo ist, wann sie erscheint, wie du steuerst,
wer sie öffnen darf, und wie ihr Lebenszyklus (build → live → stopped)
funktioniert.

## Inhalt

- [Was eine Live-Demo ist](#what-a-live-demo-is)
- [Wann eine Demo erscheint](#when-a-demo-appears)
- [Wo du die Demo-URL findest](#where-to-find-the-demo-url)
- [Der Demo-Vertrag](#the-demo-contract)
- [Zugriffsmodi](#access-modes)
- [Lebenszyklus](#lifecycle)
- [Neu bauen und stoppen](#rebuild-and-stop)
- [Betreiber-Konfiguration](#operator-configuration)

## Was eine Live-Demo ist

Wenn die Implement-Phase einer Aufgabe abgeschlossen ist, liegt der
implementierte Code bereits im Workspace-Volume der Aufgabe. Argos mountet dieses
Volume in einen kleinen Container-Stack, führt die Build-/Setup-Kommandos aus und
veröffentlicht eine Route über Traefik, sodass das Deployment unter einer eigenen
Subdomain erreichbar ist — typischerweise `demo-<task>.<base-domain>`.

Eine Demo ist **kurzlebig** und **pro Aufgabe**:

- Es gibt genau eine aktuelle Demo pro Aufgabe. Ein neuer Implement-Lauf ersetzt
  die vorherige Demo sauber (alte Container, Volumes und Route werden zuerst
  abgebaut).
- Eine Demo hat eine **Time-to-live (TTL)**. Sobald sie abläuft, wird sie
  automatisch gestoppt.
- Die Demo läuft neben Argos selbst — sie ist für eine schnelle Review gedacht,
  nicht als Produktiv-Deployment.

## Wann eine Demo erscheint

Eine Demo wird automatisch nach einem **erfolgreichen Implement-Lauf** gebaut,
aber nur, wenn **beide** der folgenden Punkte zutreffen:

1. **Live-Demos sind für das Projekt aktiviert.** Jedes Projekt (Repo-Profil) hat
   einen `live_demo_enabled`-Schalter. Siehe [PROJECTS.md](PROJECTS.md), wo du ihn
   setzt.
2. **Previews sind plattformweit aktiviert.** Der Betreiber muss die
   Preview-Infrastruktur eingeschaltet haben (`ARGOS_PREVIEW_ENABLED`,
   standardmäßig an — siehe [Betreiber-Konfiguration](#operator-configuration)).

Ist eines davon aus, wird keine Demo gebaut und die Demo-Aktionen bleiben an der
Aufgabe verborgen.

Sind die Bedingungen erfüllt, stößt der Abschluss der Implement-Phase das
Deployment im Hintergrund an — die Aufgabenseite zeigt **„Building demo…"**,
während es läuft, und wechselt zur Live-URL, sobald es bereit ist.

## Wo du die Demo-URL findest

Das Panel **Live demo** wird auf der Ansichtsseite der Aufgabe angezeigt. Öffne
die Aufgabe in Argos (`${APP_URL}`) und such nach dem Abschnitt „Live demo". Je
nach Zustand zeigt es:

- **Building demo…**, während das Deployment gebaut wird.
- Die Demo-**URL** und einen **Expires**-Zeitstempel, sobald die Demo live ist.
- Einen Hinweis und einen **Show build log**-Link, wenn der Build fehlgeschlagen
  ist.
- Einen Hinweis, dass noch keine Demo existiert (sie wird nach dem nächsten
  erfolgreichen Implement-Lauf gebaut).

## Der Demo-Vertrag

Wie eine Demo gebaut wird und was sie ausführt, wird durch einen **Demo-Vertrag**
definiert — zwei Dateien im Repository unter `.argos/`:

| Datei | Zweck |
| --- | --- |
| `.argos/demo.compose.yml` | Die Docker-Compose-Services, die die Demo ausführt (die App, ihre Datenbank, etwaige Backing-Services). |
| `.argos/demo.yml` | Einstellungen: zu welchem Service/Port geroutet wird, wo der Workspace gemountet wird, die auszuführenden Setup-Kommandos und ein optionaler Health-Check. |

Beide Dateien werden aus dem **Default-Branch** des Projekts gelesen. Sie müssen
**gemeinsam** existieren — ein Repository, das nur eine von beiden mitliefert,
wird als Fehler behandelt, und der Demo-Build schlägt mit einer Fehlermeldung
fehl, statt still auf einen Standard zurückzufallen.

Details zum Schreiben eines Vertrags und den darin ausgeführten Kommandos findest
du unter [PREPARE-PROJECT.md](PREPARE-PROJECT.md) und
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### Standard-Laravel-Demo

Liefert ein Repository **keine** `.argos/demo.*`-Dateien mit, verwendet Argos
einen **eingebauten Standard-Laravel-Vertrag**. Er bootet eine PHP-Laufzeit
(nginx + php-fpm) plus eine MariaDB-Datenbank, mountet den Workspace und führt
ein Standard-Laravel-Bring-up aus — grob:

- `composer install`
- `.env.example` nach `.env` kopieren, falls fehlend
- `php artisan migrate --force --seed`
- `npm ci && npm run build` (wenn eine `package.json` vorhanden ist)
- `php artisan storage:link`

Der Standard-Vertrag verwendet die konfigurierten Backing-Service-Einstellungen
des Projekts wieder: Die Demo-Datenbank nutzt dieselben Zugangsdaten, die das
Projekt für seinen Worker-MySQL-Sidecar konfiguriert hat, und ein Redis-Service
wird automatisch ergänzt, wenn das Projekt Redis aktiviert hat. Die
Standard-Laufzeit routet zum `app`-Service auf Port `80`.

Ein Repository, das seinen eigenen Vertrag mitliefert, behält die volle Kontrolle
und wird von diesen Standards nicht angetastet.

## Zugriffsmodi

Jede Demo ist gemäß einem **Zugriffsmodus** geschützt. Du setzt ihn pro Aufgabe
über die Aktion **Demo access** auf der Aufgabenseite. Änderungen gelten **sofort**
für eine laufende Demo (die Route wird ohne vollständigen Neuaufbau umgeschrieben);
für eine gestoppte Demo werden sie beim nächsten Build wirksam.

| Modus | Bedeutung |
| --- | --- |
| **Inherit** (Standard) | Die stack-weite Standard-Zugriffseinstellung verwenden (`ARGOS_PREVIEW_AUTH`). Die Aktion zeigt, worauf das aktuell aufgelöst wird. |
| **Session** | Ein Argos-Login erforderlich. Traefik prüft deine Argos-Session über ein Forward-Auth-Gate, bevor die Demo ausgeliefert wird. |
| **Basic** | Die Demo mit gemeinsamen HTTP-Basic-Zugangsdaten (Benutzername + Passwort) schützen. |
| **Public** | Kein Schutz — jeder mit der URL kann sie öffnen. |

Hinweise:

- **Inherit** löst gegen den Betreiber-Standard `ARGOS_PREVIEW_AUTH` auf
  (`none`, `session` oder `basic`). Ein Standard von `none` löst zu **Public**
  auf.
- Bei **Basic** ist der **Benutzername** der stack-weite Wert
  `ARGOS_PREVIEW_BASIC_USER` (Standard `demo`). Das **Passwort** ist entweder
  eines, das du im Zugriffsdialog eingibst, oder, wenn du es leer lässt, ein
  16-stelliges Passwort, das Argos generiert — eine basic-geschützte Demo bleibt
  nie ohne Passwort. Nach dem Speichern zeigt dir Argos die resultierenden
  Zugangsdaten in einer Benachrichtigung.

## Lebenszyklus

Eine Demo durchläuft diese Zustände:

| Zustand | Bedeutung |
| --- | --- |
| **Building** | Das Deployment wird gebaut (Container hochfahren, Setup-Kommandos, Health-Check). |
| **Live** | Die Demo ist unter ihrer URL erreichbar. |
| **Failed** | Der Build ist fehlgeschlagen — das Build-Log erklärt, warum; etwaige Teil-Container werden aufgeräumt. |
| **Stopped** | Container, Volumes und Route der Demo wurden entfernt. Sie kann neu gestartet werden. |

Wie eine Demo den Zustand **Live** verlässt:

- **TTL-Ablauf.** Eine Demo lebt `ARGOS_PREVIEW_TTL_HOURS` (Standard 24 Stunden)
  ab dem Zeitpunkt, an dem sie gebaut wurde. Eine geplante Bereinigung läuft
  periodisch und baut jede Demo ab, die ihre TTL überschritten hat.
- **Nach dem Pull Request.** Wenn die Push-Phase der Aufgabe den Pull Request
  öffnet, wird die Demo automatisch abgebaut. Du kannst sie jederzeit von der
  Aufgabenseite aus neu starten.
- **Concurrency-Limit.** Die Plattform begrenzt, wie viele Demos gleichzeitig
  laufen (`ARGOS_PREVIEW_MAX_CONCURRENT`, Standard 10). Würde eine neue Demo das
  Limit überschreiten, werden die **ältesten** laufenden Demos *anderer* Aufgaben
  verdrängt (gestoppt), um Platz zu schaffen. Verdrängungen werden geloggt, nie
  still. Setze das Limit auf `0`, um es zu deaktivieren.
- **Manuelles Stoppen.** Siehe unten.

## Neu bauen und stoppen

Die Aufgabenseite bietet zwei Aktionen (nur sichtbar, wenn Live-Demos für das
Projekt aktiviert sind und die Implement-Phase abgeschlossen ist):

- **Rebuild demo** — ersetzt die aktuelle Demo durch einen frischen Build des
  neuesten implementierten Codes. Eine laufende Demo der Aufgabe wird zuerst
  abgebaut; der Build läuft im Hintergrund. (Für eine zuvor gestoppte Demo wird
  dies als **Restart demo** angezeigt.)
- **Stop demo** — entfernt Container, Volumes und Route der Demo. Verfügbar,
  während die Demo **Building** oder **Live** ist. Eine gestoppte Demo kann später
  neu gestartet werden.

## Betreiber-Konfiguration

Live-Demos werden durch die `preview.*`-Einstellungen in `config/argos.php`
gesteuert, die von Umgebungsvariablen getrieben werden. Die für Benutzer und
Betreiber wichtigsten:

| Variable | Standard | Zweck |
| --- | --- | --- |
| `ARGOS_PREVIEW_ENABLED` | `true` | Plattformweiter Hauptschalter für Demos. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Wie lange eine Demo lebt, bevor sie automatisch abgebaut wird. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Limit gleichzeitig laufender Demos (`0` deaktiviert). |
| `ARGOS_PREVIEW_AUTH` | `none` | Standard-Zugriffsmodus für Aufgaben, die auf *Inherit* gesetzt sind (`none` \| `session` \| `basic`). |
| `ARGOS_PREVIEW_BASIC_USER` | `demo` | HTTP-Basic-Benutzername für basic-geschützte Demos. |
| `ARGOS_PREVIEW_BASIC_PASSWORD` | *(nicht gesetzt)* | Fallback-Basic-Passwort für Aufgaben, die lediglich den Basic-Standard erben. |
| `ARGOS_PREVIEW_BASE_DOMAIN` | aus `APP_URL` abgeleitet | Basis-Domain, unter der Demos ausgeliefert werden (`demo-<task>.<base-domain>`). |

Demos benötigen Preview-Infrastruktur auf dem Host (einen Traefik-Edge mit dem
gemeinsamen `argos_edge`-Netzwerk und einer wildcard-fähigen Basis-Domain).
Betreiber ohne diese Infrastruktur können Demos vollständig mit
`ARGOS_PREVIEW_ENABLED=false` abschalten.

Die vollständige Liste der Einstellungen und wie du Umgebungsvariablen setzt,
findest du unter [CONFIGURATION.md](CONFIGURATION.md).
