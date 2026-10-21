# Workspaces

Ein **Workspace** ist ein interaktiver Arbeitsplatz für einen Branch eines
Projekts, zusammen mit Claude — so, wie du Claude Code auf deinem eigenen Rechner
nutzt, nur im Browser. Du chattest mit Claude, während es arbeitet, beantwortest
seine Fragen, erlaubst oder verweigerst die Änderungen, die es machen will,
bearbeitest Dateien selbst in VS Code und klickst dich durch das Ergebnis in
einer Live-Preview. Mehrere Personen können gleichzeitig im selben Workspace
arbeiten.

Workspaces haben einen eigenen Bereich unter **`/w`** (dorthin führt auch
`/`): volle Breite, Navigation in der oberen Leiste, damit Chat, Editor und
Preview den ganzen Bildschirm bekommen. **Verwaltung** in der oberen Leiste
führt in den Admin-Bereich (`/admin`) mit Projekten, Agenten, Benutzern und
Aufgaben; ein Login gilt für beide.

Workspaces stehen neben den [Aufgaben](TASKS.md). Eine Aufgabe ist der
automatische Ablauf (Konzept → Implementierung → Pull Request); ein Workspace
ist der interaktive. Beide nutzen dieselben Projekte, Worker-Stacks und Agenten.

## Inhalt

- [Was du brauchst](#was-du-brauchst)
- [Agenten und Abos](#agenten-und-abos)
- [Einen Workspace anlegen](#einen-workspace-anlegen)
- [Aus einem Issue starten](#aus-einem-issue-starten)
- [Threads](#threads)
- [Bilder, Dateien und Befehle](#bilder-dateien-und-befehle)
- [Freigaben, Pläne und Fragen](#freigaben-plane-und-fragen)
- [Zusammenarbeiten](#zusammenarbeiten)
- [Übersicht und Benachrichtigungen](#ubersicht-und-benachrichtigungen)
- [Der Arbeitsbereich: Editor und Preview](#der-arbeitsbereich-editor-und-preview)
- [Das Review](#das-review)
- [Quality-Gates](#quality-gates)
- [Checkpoints und Zurücksetzen](#checkpoints-und-zurucksetzen)
- [Ausliefern: Commit und Pull Request](#ausliefern-commit-und-pull-request)
- [Lebenszyklus](#lebenszyklus)
- [Sicherheit](#sicherheit)
- [Konfiguration](#konfiguration)

## Was du brauchst

- Den regulären Argos-Stack (siehe [Setup](SETUP.md)) einschließlich des
  Dienstes `reverb` — er bringt die Live-Updates in den Browser.
- **Wildcard-DNS** für die Preview-Basisdomain: Der Editor eines Workspaces
  liegt unter `code-<workspace>.<basisdomain>` und seine Preview unter
  `demo-ws-<workspace>.<basisdomain>`, neben den Demos der Aufgaben. Lokal
  braucht der Standard `*.127.0.0.1.nip.io` einen Resolver, der nip.io erreicht.
- Einen eigenen **Agenten** (dein Claude-Abo) oder einen geteilten — siehe unten.

## Agenten und Abos

Jeder Thread läuft auf einem **Agenten**: Zugangsdaten für Claude Code, erzeugt
mit `claude setup-token`. Agenten sind entweder

- **persönlich** — sie gehören einem Benutzer und laufen über dessen
  Claude-Abo. Jeder Benutzer kann unter *Agenten* seinen eigenen anlegen.
  Benutzer sehen immer nur ihre eigenen Agenten.
- **geteilt** — sie haben keinen Besitzer (Administratoren legen sie an).
  Aufgaben nutzen geteilte Agenten, wenn kein Agent ausdrücklich gewählt ist; in
  Workspaces darf sie jeder auswählen.

Ein Agent führt höchstens **parallele Sessions** Threads gleichzeitig aus
(Standard `1`, von einem Administrator einstellbar). Das schützt die Limits des
Abos. Meldet Claude, dass das Nutzungslimit des Abos erreicht ist, wird der
Agent bis zur gemeldeten Rücksetzzeit als limitiert markiert und startet bis
dahin keine neuen Sessions.

> **Hinweis:** Alles, was in einem Thread geschrieben wird, läuft über das Abo
> des Agenten dieses Threads — auch Nachrichten anderer Mitglieder. Denk daran,
> wenn ihr auf einem persönlichen Pro-/Max-Abo zusammenarbeitet.

## Einen Workspace anlegen

Öffne **Workspaces → Neuer Workspace**, wähle das Projekt, gib dem Workspace
einen Namen und optional einen Basis-Branch (Standard: der Standard-Branch des
Projekts). Argos

1. baut das Workspace-Image (das Worker-Image des Projekts plus Workspace-Daemon
   und VS Code) — der erste Start kann einige Minuten dauern,
2. legt die Workspace-Volumes an und startet die Hintergrunddienste des Projekts
   (MySQL/Redis), sofern das Projekt sie aktiviert hat,
3. startet den Workspace-Container und klont das Repository auf den Branch
   `ws/<workspace>`.

Die Workspace-Seite zeigt den Fortschritt und, falls etwas fehlschlägt, den
Grund.

**Eine Aufgabe pro Workspace.** Alle Threads eines Workspaces teilen sich
dieselben Dateien. Für getrennte Arbeiten lege getrennte Workspaces an — jeder
hat seinen eigenen Branch, Container, seine eigene Datenbank und Preview.

## Aus einem Issue starten

Hat das Projekt einen verbundenen Issue-Tracker (siehe
[Task-Provider](SETUP-TASK-PROVIDERS.md)), bietet das Formular **Aus Issue** an:
Durchsuche die offenen Issues nach Titel oder Nummer und wähle eines. Der
Workspace heißt dann `#<Nummer> <Titel>`, sein Branch `ws/<Nummer>-<Titel>`
(höchstens 50 Zeichen; ein zweiter Workspace für dasselbe Issue bekommt ein
Suffix), und die Kopfzeile verlinkt das Issue.

Der erste Thread, den du anlegst, trägt den Titel des Issues, und das
Eingabefeld ist mit Titel, Text und Link des Issues **vorbefüllt — nicht
abgeschickt**. Der Issue-Text ist als nicht vertrauenswürdige Eingabe markiert,
damit Claude ihn als Daten behandelt, nicht als Anweisungen. Prüf ihn, ergänze,
was du weißt, und schick ihn dann selbst ab.

Beim Ausliefern enthält der Pull Request `Closes #<Nummer>` (GitHub, GitLab)
bzw. den Link zum Issue (Linear), und Argos kommentiert den Link zum Pull
Request im Issue. Geschlossen wird das Issue durch den Merge, nie durch Argos.

## Threads

Der linke Teil der Workspace-Seite ist der Chat; zieh den Trenner zwischen
Chat und dem Arbeitsbereich, um die Aufteilung zu ändern (dein Browser merkt sie sich).
Die Seite füllt das Fenster, und nur die Unterhaltung scrollt. Lege einen
**Thread** an
(Titel, Agent, Modell, Modus) und schreib los. Claudes Antwort erscheint live;
Tool-Aufrufe (Dateien lesen, Befehle ausführen, bearbeiten) erscheinen als
kompakte Zeilen, die du aufklappen kannst.

- **Folgenachrichten, während Claude arbeitet,** werden eingereiht und
  aufgenommen, sobald Claude bereit ist — bis dahin zeigen sie *in
  Warteschlange*.
- **Stopp** unterbricht den aktuellen Turn. Offene Freigaben werden abgebrochen.
- **Modus** legt fest, wie viel Claude ohne Rückfrage tun darf:
  - *Vor Änderungen fragen* (Standard) — jede Änderung und jeder Befehl braucht
    eine Freigabe,
  - *Änderungen annehmen* — Dateiänderungen laufen ohne Rückfrage, Befehle
    fragen weiterhin,
  - *Plan-Mode* — Claude recherchiert und schlägt einen Plan vor, bevor es
    etwas ändert,
  - *Freigaben umgehen* — Claude führt jedes Tool ohne Rückfrage aus. Nur
    Besitzer des Workspaces können das wählen.
- Das **Modell** lässt sich jederzeit wechseln.
- Ist eine Session beendet (Container-Neustart, Absturz), setzt **Session
  fortsetzen** dieselbe Claude-Unterhaltung fort.
- Slash-Commands und die `CLAUDE.md` des Projekts werden wie in Claude Code
  geladen.

Die **Thread-Liste** links zeigt jeden Thread mit seinem Status (arbeitet,
wartet auf eine Entscheidung, bereit) und der Zahl ungelesener Einträge.
Threads lassen sich umbenennen und archivieren; *Archivierte anzeigen* holt
archivierte zurück. Auf schmalen Bildschirmen klappt die Liste ein.

## Bilder, Dateien und Befehle

- **Bilder:** Screenshots einfügen, hineinziehen oder anhängen (PNG, JPEG, GIF,
  WebP; bis zu 5 pro Nachricht, je `ARGOS_WORKSPACE_UPLOAD_MAX_MB`). Claude sieht
  sie mit der Nachricht; Mitglieder sehen sie als Vorschaubilder. Sie werden
  privat gespeichert und mit dem Workspace gelöscht.
- **`@`-Erwähnungen:** Tippe `@` und einen Teil eines Pfads, um eine Datei des
  Workspaces auszuwählen (unscharfe Suche über getrackte und ungetrackte
  Dateien; ignorierte bleiben außen vor). Der Pfad wird als Text eingefügt.
- **`/`-Befehle:** Tippe `/` für eine Liste. Die eigenen Befehle von Argos
  laufen im Browser und gehen nicht an Claude:

  | Befehl | Wirkung |
  |---|---|
  | `/plan [Anfrage]` | In den Plan-Mode wechseln, optional die Anfrage senden. |
  | `/ship` | Den Dialog für Commit und Pull Request öffnen. |
  | `/preview`, `/review` | Auf die Preview-Steuerung bzw. das Review zeigen. |
  | `/gates` | Die [Quality-Gates](#quality-gates) ausführen. |
  | `/checkpoint [Name]` | Einen benannten [Checkpoint](#checkpoints-und-zurucksetzen) setzen. |

  Alle anderen Befehle — die Commands und Skills des Projekts, `/compact` und
  Co. — gehen so an Claude Code, wie du sie tippst.

`Enter` sendet, `Shift+Enter` fügt eine Zeile ein; solange die Vorschlagsliste
offen ist, übernehmen `Enter`/`Tab` den markierten Eintrag (ein vollständig
getippter Befehl wird gesendet).

## Freigaben, Pläne und Fragen

Braucht Claude eine Entscheidung, erscheint für alle eine Karte im Thread:

| Karte | Was du tun kannst |
|---|---|
| Tool-Aufruf (z. B. *Edit*, *Bash*) | Erlauben oder mit optionaler Begründung ablehnen. Änderungen werden angezeigt. |
| Plan (aus dem Plan-Mode) | Annehmen und den Modus wählen, in dem es weitergeht, oder mit Feedback ablehnen. |
| Frage | Pro Frage eine Option wählen oder eine eigene Antwort eingeben. |

Die **erste Entscheidung gilt**. Alle sehen, wer entschieden hat; ein zweiter
Klick wird mit einem Hinweis abgewiesen.

## Zusammenarbeiten

Jeder Workspace hat **Mitglieder**:

| Rolle | Darf |
|---|---|
| Besitzer | alles, was ein Bearbeiter darf, dazu Mitglieder verwalten, den Workspace löschen und *Freigaben umgehen* nutzen |
| Bearbeiter | in Threads schreiben, Claude stoppen, Freigaben entscheiden, Modus/Modell wechseln, den Editor nutzen, den Workspace und seine Preview starten/stoppen |
| Leser | Threads lesen und die Preview öffnen |

Besitzer fügen Mitglieder über **⋯ → Mitglied hinzufügen** hinzu. Der Kopf der
Workspace-Seite zeigt, wer gerade da ist; der Chat zeigt, wer tippt. Jede
Nachricht trägt ihren Autor, und Claude sieht sie als `[Name]: …`.
Administratoren sehen alle Workspaces, treten zum Schreiben aber als Mitglied
bei.

Administratoren verwalten die Personen einer Installation unter **Benutzer**.

## Übersicht und Benachrichtigungen

Das Dashboard-Widget **Meine Workspaces** und die Workspace-Liste zeigen, was
dich braucht: wie viele Threads arbeiten und welche auf eine Entscheidung
warten (Filter *Wartet auf dich*). Beides aktualisiert sich live.

Besitzer und Bearbeiter werden benachrichtigt, wenn

| Art | Wann |
|---|---|
| Claude wartet auf eine Entscheidung | eine Freigabe, ein Plan oder eine Frage erscheint |
| Claude hat einen längeren Turn beendet | ein Turn länger als 30 Sekunden gedauert hat |
| Ein Abo hat sein Nutzungslimit erreicht | der Agent des Threads sein Limit meldet |
| Fehler | ein Thread fehlschlägt |
| Jemand hat einen Workspace ausgeliefert | ein anderes Mitglied committet und den Pull Request geöffnet/aktualisiert hat |

Alles außer beendeten Turns landet auch in der Glocke. Im Browser erscheint
eine Benachrichtigung (und ein Zähler im Tab-Titel) nur, wenn der betreffende
Workspace nicht gerade vor dir offen ist; Browser-Benachrichtigungen erlaubst
du in deinem **Profil**, wo du auch jede Art einzeln abschalten kannst.

## Der Arbeitsbereich: Editor und Preview

Der rechte Teil der Seite ist der Arbeitsbereich. Seine Leiste öffnet Editor und
Preview jeweils in **einem eigenen Browser-Tab** — einer pro Workspace, beim
nächsten Klick wiederverwendet. So bekommen beide das ganze Fenster,
Tastenkürzel funktionieren, und auch Apps, die sich nicht einbetten lassen,
werden angezeigt. Unter der Leiste liegt das [Review](#das-review) der
Änderungen.

### VS Code

**VS Code öffnen** öffnet VS Code (OpenVSCode Server) auf dem Workspace-Volume.
Was du änderst, sieht Claude sofort und umgekehrt. Der Zugang zum Editor-Host
wird bei jeder Anfrage gegen die Mitgliedschaft im Workspace geprüft: Argos gibt
deinem Browser ein kurzlebiges Ticket, das gegen ein Cookie auf dem Editor-Host
getauscht wird.

### Preview

Die Preview ist eine [Live-Demo](LIVE-DEMOS.md) des Workspaces: derselbe
Demo-Vertrag (`.argos/demo.*` oder der eingebaute Laravel-Standard), aber sie
mountet das **Workspace-Volume** und zeigt daher immer die aktuellen Dateien.
Sie ist nur für Mitglieder zugänglich (Zugriffsmodus `workspace`) und wird bei
Bedarf gestartet.

- **Preview starten** baut sie, **Preview öffnen** öffnet sie, **Neu bauen**
  ersetzt sie. Die Leiste zeigt ihren Zustand; ein fehlgeschlagener Build zeigt
  das Build-Log.
- **Setup-Befehle erneut ausführen** führt die Befehle des Vertrags in der
  laufenden Preview noch einmal aus — nach einer Migration, einer
  `composer`-/`npm`-Änderung oder einer geänderten Build-Konfiguration.
  PHP-Änderungen erscheinen auch ohne.
- Befehle und PHP laufen als Agent-Benutzer des Workspaces, damit Dateien, die
  sie anlegen (z. B. `vendor/`, kompilierte Views), bearbeitbar bleiben.
- Die TTL der Previews und das plattformweite Limit gleichzeitiger Live-Demos
  gelten.

Previews brauchen `ARGOS_PREVIEW_ENABLED` und den Schalter *Live-Demo* im
Projekt.

## Das Review

Das Review zeigt die Änderungen des Workspaces gegenüber dem Basis-Branch —
Commits, nicht committete Änderungen und neue Dateien — Datei für Datei. Hat
Claude einen Turn beendet, markiert er sich als *seit deinem Blick geändert*;
**Aktualisieren** lädt neu.

Klick auf eine Zeile, um Claude einen Kommentar zu hinterlassen. Kommentare
werden gesammelt; **An Claude senden** schickt sie als eine Nachricht
(`Pfad:Zeile — Kommentar`) in den gewählten Thread.

## Quality-Gates

**Gates prüfen** (im Review, im Menü der Kopfzeile oder per `/gates`) führt
dieselben Quality-Gates aus wie die Implementierungsphase von Aufgaben
(`worker/lib/quality.sh`): Artisan-Start, Pint auf geänderten Dateien,
Pest/PHPUnit, PHPStan, Migrations-Syntax, Debug-Aufrufe und vorhandene Tests.
Sie sind für PHP/Laravel-Projekte gemacht; bei anderen Stacks werden die Gates
übersprungen.

- Direkt nach dem Klonen des Workspaces erfasst Argos eine **Baseline** des
  Basis-Branches: Pest und PHPStan zählen dann nur neue Fehler. Solange die
  Baseline noch läuft oder wenn sie fehlgeschlagen ist, zählt jeder Fehler; das
  Panel zeigt an, was gilt.
- Ein Lauf gleichzeitig pro Workspace. Jedes Gate zeigt grün/rot/übersprungen
  und das Ende seines Logs.
- **Fehler an Agent senden** schickt denselben Fix-Prompt, den der
  Aufgaben-Ablauf nutzt, in den gewählten Thread.
- Der Commit-Dialog nennt das letzte Ergebnis (*Gates: 6/7 grün*).
  Fehlgeschlagene Gates sind ein Warnhinweis, nie eine Sperre.

Die Gate-Logs liegen in `.agent/logs/` im Workspace, das git lokal ignoriert.

## Checkpoints und Zurücksetzen

Bevor Claude eine Nachricht bearbeitet, legt Argos einen **Checkpoint** an:
eine Momentaufnahme aller Dateien des Workspaces — getrackt, gestaged und
ungetrackt, nicht ignoriert — gespeichert als Git-Ref
(`refs/argos/checkpoints/<Thread>/<n>`). Branch, Index und deine Dateien bleiben
unberührt. Ein Thread behält seine neuesten 200 Checkpoints.

- **Bis hierhin zurücksetzen** an einer Nachricht (mit der Maus darüberfahren)
  zeigt, was das Zurücksetzen verwirft, und setzt die Dateien auf den Stand vor
  dieser Nachricht zurück. Solange Claude in diesem Thread arbeitet, wird das
  abgelehnt — stoppe es zuerst.
- Der aktuelle Stand wird vorher als Checkpoint gesichert; **Rückgängig** am
  Hinweis zum Zurücksetzen holt ihn zurück.
- Commits werden nie zurückgenommen: Wurden seitdem Commits erstellt, warnt der
  Dialog, dass sie bestehen bleiben und nur die Dateien zurückgesetzt werden.
- Der Chat-Verlauf bleibt. Das Zurücksetzen wird im Thread vermerkt, und Claude
  erfährt es mit deiner nächsten Nachricht, damit es die Dateien neu liest.
- `/checkpoint [Name]` setzt einen benannten Checkpoint, zu dem du später
  zurückkehren kannst.

## Ausliefern: Commit und Pull Request

**Commit & PR** (Kopfzeile oder `/ship`) committet alles, was sich geändert hat,
pusht den Branch und öffnet einen Pull Request (GitLab: Merge Request) gegen den
Basis-Branch.

- Claude entwirft die Commit-Message aus dem Diff und der Unterhaltung, auf
  **deinem** Abo (dein erster nutzbarer Agent). Ohne Agent oder wenn das
  fehlschlägt, schreibst du sie selbst.
- *Als Entwurf öffnen* öffnet einen Draft-Pull-Request. Erneutes Ausliefern
  pusht die neuen Commits und kommentiert den bestehenden Pull Request.
- Hat sich der Remote-Branch bewegt, wird der Push abgelehnt; **Claude rebasen
  lassen** (Menü der Kopfzeile) bittet den Agenten, zu fetchen, zu rebasen und
  Konflikte zu lösen.
- Die Kopfzeile zeigt den Pull Request und seinen Zustand (offen, gemergt,
  geschlossen). Er wird alle `ARGOS_WORKSPACE_PR_SYNC_MINUTES` und beim Öffnen
  des Workspaces aktualisiert.
- Jeder offene Thread bekommt einen Hinweis mit dem Link; die anderen
  Mitglieder werden benachrichtigt.

## Auto-Threads

Ein **Auto-Thread** arbeitet ohne Menschen: Er plant (optional), setzt um, prüft die [Quality-Gates](#quality-gates), behebt Fehler und liefert einen Pull Request aus. Jedes Projekt legt die Standards unter **Verwaltung → Projekte → Automatik** fest (Plan: aus / automatisch / auf Freigabe warten; was nach dem Ende passiert; Fix-Runden; Budgets für Turns, Kosten und Zeit). Alle Einstellungen stehen unter [Konfiguration](CONFIGURATION.md#auto-threads).

## Lebenszyklus

| Zustand | Bedeutung |
|---|---|
| Wird eingerichtet / Startet | Der Container wird vorbereitet. |
| Läuft | Bereit zum Arbeiten. |
| Stoppt / Gestoppt | Der Container ist gestoppt; Volumes, Datenbank und Threads bleiben erhalten. Öffnest du den Workspace, startet er wieder. |
| Fehlgeschlagen | Der Start ist fehlgeschlagen — die Seite zeigt den Grund; **Starten** versucht es erneut. |

- **Idle-Stopp:** Ein laufender Workspace ohne Aktivität seit
  `ARGOS_WORKSPACE_IDLE_MINUTES` wird gestoppt — aber nie, solange ein Thread
  arbeitet oder auf eine Entscheidung wartet.
- **Kapazität:** Es laufen höchstens `ARGOS_WORKSPACE_MAX_ACTIVE` Workspaces
  gleichzeitig. Ein Start darüber hinaus wird abgelehnt; laufende Workspaces
  werden nie verdrängt.
- **Löschen** entfernt den Container, beide Volumes, die Datenbank der
  Hintergrunddienste und die Preview. Nicht gepushte Commits gehen verloren.

## Sicherheit

- Der Workspace-Daemon ist nur im internen Netz `argos_ws` und nur mit dem
  eigenen Token des Workspaces erreichbar. Datenbank und Redis von Argos sind
  nicht in diesem Netz.
- Dein Claude-Token wird pro Thread übergeben und nur im Speicher gehalten; er
  landet weder auf der Platte noch in der Umgebung des Containers. Claude selbst
  kann ihn im Container technisch auslesen — genau wie in den Workern der
  Aufgaben —, behandle einen Workspace also wie einen Rechner, auf dem du Claude
  Code ausführst.
- Der Repository-Token erreicht git über einen Credential-Helper und wird nie ins
  Repository geschrieben. Claude ist angewiesen, nicht zu pushen, kann es
  technisch aber.
- Der Editor ist faktisch eine Shell im Container: Nur Besitzer und Bearbeiter
  kommen hinein; Leser sehen nur Threads und die Preview.

## Konfiguration

| Variable | Standard | Zweck |
|---|---|---|
| `ARGOS_WORKSPACES_ENABLED` | `true` | Hauptschalter für Workspaces. |
| `ARGOS_WORKSPACE_COLLABORATION` | `true` | Ob Workspaces außer dem Besitzer weitere Mitglieder haben dürfen. |
| `ARGOS_WORKSPACE_MAX_ACTIVE` | `5` | Gleichzeitig laufende Workspaces. |
| `ARGOS_WORKSPACE_IDLE_MINUTES` | `60` | Leerlaufzeit, bevor ein Workspace gestoppt wird (`0` deaktiviert). |
| `ARGOS_WORKSPACE_CPUS` / `ARGOS_WORKSPACE_MEMORY` | `2` / `4g` | Limits pro Workspace-Container. |
| `ARGOS_WORKSPACE_PR_SYNC_MINUTES` | `15` | Wie oft der Zustand offener Workspace-Pull-Requests aktualisiert wird. |
| `ARGOS_WORKSPACE_UPLOAD_MAX_MB` | `5` | Größenlimit pro Bild, das an eine Nachricht angehängt wird. |

Alle Einstellungen stehen unter [Konfiguration](CONFIGURATION.md#workspaces).
