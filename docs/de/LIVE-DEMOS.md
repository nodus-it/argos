# Live-Demos

Eine **Live-Demo** — im Workspace einfach **Preview** genannt — ist ein
laufendes Deployment der aktuellen Dateien eines [Workspaces](WORKSPACES.md).
Argos startet den Code in einem eigenen kleinen Container-Stack und
veröffentlicht ihn unter einer temporären Subdomain, sodass du die Änderung im
Browser durchklicken kannst, **bevor** der Pull Request gemergt wird.

Diese Seite erklärt, was eine Preview ist, wie sie gebaut wird, wer sie öffnen
darf und wie ihr Lebenszyklus (Build → Live → gestoppt) funktioniert.

## Inhalt

- [Was eine Live-Demo ist](#what-a-live-demo-is)
- [Wann eine Preview verfügbar ist](#when-a-preview-is-available)
- [Eine Preview starten und öffnen](#starting-and-opening-a-preview)
- [Der Demo-Vertrag](#the-demo-contract)
- [Zugriff](#access)
- [Lebenszyklus](#lifecycle)
- [Betreiber-Konfiguration](#operator-configuration)

## Was eine Live-Demo ist

Ein Workspace hält seinen Checkout in einem Docker-Volume (`ws_<workspace>`).
Die Preview mountet **dieses Volume** in einen kleinen Container-Stack, führt
die Setup-Befehle des Vertrags aus und veröffentlicht eine Route über Traefik,
sodass das Deployment unter `demo-ws-<workspace>.<basisdomain>` erreichbar ist.
Weil sie das Volume mountet, zeigt die Preview immer die aktuellen Dateien des
Workspaces — PHP-Änderungen erscheinen ohne Neubau.

Eine Preview ist **kurzlebig** und gehört **zu einem Workspace**:

- Pro Workspace gibt es höchstens eine aktuelle Preview. **Neu bauen** ersetzt
  sie sauber (alte Container, Volumes und Route werden zuerst abgebaut).
- Eine Preview hat eine **Lebensdauer (TTL)**. Läuft sie ab, wird die Preview
  automatisch gestoppt.
- Sie läuft neben Argos selbst — gedacht für ein schnelles Review, nicht als
  Produktiv-Deployment.

## Wann eine Preview verfügbar ist

Ein Workspace kann nur dann eine Preview starten, wenn **beides** zutrifft:

1. **Live-Demos sind im Projekt aktiviert.** Jedes Projekt hat einen Schalter
   *Live demo*. Siehe [PROJECTS.md](PROJECTS.md#live-demo).
2. **Previews sind plattformweit aktiviert.** Der Betreiber muss die
   Preview-Infrastruktur eingeschaltet haben (`ARGOS_PREVIEW_ENABLED`,
   standardmäßig an — siehe [Betreiber-Konfiguration](#operator-configuration)).

Ist eines davon aus, nennt die Werkbank den Grund und bietet keine Preview an.

## Eine Preview starten und öffnen

Die Preview-Steuerung sitzt in der Leiste der Werkbank des Workspaces:

- **Starten** baut die Preview im Hintergrund; die Leiste zeigt *Preview wird
  gebaut…*, bis sie live ist.
- **Preview öffnen** öffnet sie in einem eigenen Browser-Tab (einer pro
  Workspace, beim nächsten Klick wiederverwendet). Die Leiste zeigt, wann sie
  abläuft.
- **Neu bauen** ersetzt die laufende Preview durch einen frischen Build.
- **Setup-Befehle erneut ausführen** führt die `commands` des Vertrags in der
  laufenden Preview noch einmal aus — nach einer Migration, einer Änderung an
  `composer`/`npm` oder an der Build-Konfiguration —, ohne neu zu bauen. Die
  Ausgabe wird an das Build-Log angehängt.
- **Preview stoppen** entfernt ihre Container, Volumes und Route.
- Schlägt ein Build fehl, zeigt die Leiste das **Build-Log**.

Die Befehle des Vertrags laufen als Agent-Benutzer des Workspaces, damit die
Dateien, die sie anlegen (z. B. `vendor/`, kompilierte Views), im Workspace
bearbeitbar bleiben.

## Der Demo-Vertrag

Wie eine Preview gebaut wird und was sie ausführt, legt ein **Demo-Vertrag**
fest — zwei Dateien im Repository unter `.argos/`:

| Datei | Zweck |
| --- | --- |
| `.argos/demo.compose.yml` | Die Docker-Compose-Services, die die Preview ausführt (die App, ihre Datenbank, weitere Backing Services). |
| `.argos/demo.yml` | Einstellungen: zu welchem Service/Port geroutet wird, wohin der Workspace gemountet wird, die auszuführenden Setup-Befehle und ein optionaler Health-Check. |

Beide Dateien werden vom **Default-Branch** des Projekts gelesen. Sie müssen
**gemeinsam** vorhanden sein — ein Repository, das nur eine davon mitliefert,
gilt als Fehler, und der Build schlägt mit einer Meldung fehl, statt still auf
den Standard zurückzufallen.

Details zum Schreiben eines Vertrags und zu den Befehlen, die er ausführt,
findest du in [PREPARE-PROJECT.md](PREPARE-PROJECT.md) und
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### Standard-Laravel-Demo

Liefert ein Repository **gar keine** `.argos/demo.*`-Dateien mit, verwendet
Argos einen **eingebauten Standard-Vertrag für Laravel**. Er startet eine
PHP-Laufzeit (nginx + php-fpm) plus eine MariaDB-Datenbank, mountet den
Workspace und führt ein übliches Laravel-Setup aus — ungefähr:

- `composer install`
- `.env.example` nach `.env` kopieren, falls sie fehlt
- `php artisan migrate --force --seed`
- `npm ci && npm run build` (wenn eine `package.json` vorhanden ist)
- `php artisan storage:link`

Der Standard-Vertrag übernimmt die Backing-Service-Einstellungen des Projekts:
Die Datenbank der Preview nutzt dieselben Zugangsdaten, die das Projekt für
seinen MySQL-Service konfiguriert hat, und ein Redis-Service kommt automatisch
hinzu, wenn das Projekt Redis aktiviert hat. Die Standard-Laufzeit routet auf
den Service `app`, Port `80`.

Ein Repository mit eigenem Vertrag behält die volle Kontrolle; diese
Standardwerte greifen dann nicht.

## Zugriff

Eine Preview ist **nur für Mitglieder** zugänglich. Jede Anfrage durchläuft die
Forward-Auth-Prüfung von Traefik gegen das Workspace-Gate, das nur Mitglieder
des Workspaces (Besitzer, Bearbeiter und Betrachter) durchlässt. Einen
öffentlichen oder reinen Passwort-Modus gibt es nicht.

## Lebenszyklus

Eine Preview durchläuft diese Zustände:

| Zustand | Bedeutung |
| --- | --- |
| **Building** | Das Deployment wird gebaut (Container starten, Setup-Befehle, Health-Check). |
| **Live** | Die Preview ist unter ihrer URL erreichbar. |
| **Failed** | Der Build ist fehlgeschlagen — das Build-Log nennt den Grund; halb gestartete Container werden aufgeräumt. |
| **Stopped** | Container, Volumes und Route der Preview wurden entfernt. Sie lässt sich erneut starten. |

So verlässt eine Preview den Zustand **Live**:

- **Ablauf der TTL.** Eine Preview lebt `ARGOS_PREVIEW_TTL_HOURS` (Standard 24
  Stunden) ab ihrem Build. Ein geplanter Cleanup stoppt jede Preview, deren TTL
  abgelaufen ist.
- **Workspace stoppt.** Wird ein Workspace gestoppt (von Hand oder nach
  Leerlauf), stoppt auch seine Preview; beim Löschen des Workspaces wird sie
  entfernt.
- **Obergrenze gleichzeitiger Previews.** Die Plattform begrenzt, wie viele
  Previews gleichzeitig laufen (`ARGOS_PREVIEW_MAX_CONCURRENT`, Standard 10).
  Würde eine neue die Grenze überschreiten, werden die **ältesten** laufenden
  Previews *anderer* Workspaces gestoppt, um Platz zu schaffen. Das wird
  protokolliert, nie stillschweigend. Mit `0` ist die Grenze aus.
- **Manuell** mit **Preview stoppen**.

## Betreiber-Konfiguration

Live-Demos werden über die `preview.*`-Einstellungen in `config/argos.php`
gesteuert, die aus Umgebungsvariablen kommen. Die wichtigsten:

| Variable | Standard | Zweck |
| --- | --- | --- |
| `ARGOS_PREVIEW_ENABLED` | `true` | Plattformweiter Hauptschalter für Previews. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Wie lange eine Preview lebt, bevor sie automatisch abgebaut wird. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Obergrenze gleichzeitig laufender Previews (`0` schaltet sie ab). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | aus `APP_URL` abgeleitet | Basisdomain, unter der Previews liegen (`demo-ws-<workspace>.<basisdomain>`). |

Previews brauchen Preview-Infrastruktur auf dem Host (einen Traefik-Edge mit dem
gemeinsamen Netzwerk `argos_edge` und eine Basisdomain mit Wildcard-DNS).
Betreiber ohne diese Infrastruktur können Previews mit
`ARGOS_PREVIEW_ENABLED=false` ganz abschalten.

Die vollständige Liste der Einstellungen und wie du Umgebungsvariablen setzt,
steht in [CONFIGURATION.md](CONFIGURATION.md).
