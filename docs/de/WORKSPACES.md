# Workspaces

Ein **Workspace** ist ein interaktiver Arbeitsplatz für einen Branch eines
Projekts, zusammen mit Claude — so, wie du Claude Code auf deinem eigenen Rechner
nutzt, nur im Browser. Du chattest mit Claude, während es arbeitet, beantwortest
seine Fragen, erlaubst oder verweigerst die Änderungen, die es machen will,
bearbeitest Dateien selbst in VS Code und klickst dich durch das Ergebnis in
einer Live-Preview. Mehrere Personen können gleichzeitig im selben Workspace
arbeiten.

Workspaces haben einen eigenen Bereich unter **`/w`** (dorthin führt auch
`/`): volle Breite, links eine schmale **Icon-Leiste** statt einer oberen
Leiste — Workspaces, **Verwaltung** (der Admin-Bereich unter `/admin` mit
Projekten, Agenten und Benutzern), die Benachrichtigungsglocke und dein
Benutzermenü (Profil, helles/dunkles Design, Abmelden). Ein Login gilt für
beide Bereiche.

Die Arbeit muss nicht interaktiv sein: Ein [Auto-Thread](#auto-threads) plant,
setzt um, prüft die Quality-Gates und öffnet selbstständig einen Pull Request —
gestartet in der Oberfläche, über die [REST-API](REST-API.md), [MCP](SETUP-MCP.md)
oder ein Issue eines verbundenen Trackers.

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
- [Die Kontext-Seitenleiste: Editor, Preview und Branch](#die-kontext-seitenleiste-editor-preview-und-branch)
- [Das Review](#das-review)
- [Quality-Gates](#quality-gates)
- [Checkpoints und Zurücksetzen](#checkpoints-und-zurucksetzen)
- [Ausliefern: Commit und Pull Request](#ausliefern-commit-und-pull-request)
- [Auto-Threads](#auto-threads)
- [Lebenszyklus](#lebenszyklus)
- [Sicherheit](#sicherheit)
- [Konfiguration](#konfiguration)

## Was du brauchst

- Den regulären Argos-Stack (siehe [Setup](SETUP.md)) einschließlich des
  Dienstes `reverb` — er bringt die Live-Updates in den Browser.
- **Wildcard-DNS** für die Preview-Basisdomain: Der Editor eines Workspaces
  liegt unter `code-<workspace>.<basisdomain>` und seine Preview unter
  `demo-ws-<workspace>.<basisdomain>`. Lokal
  braucht der Standard `*.127.0.0.1.nip.io` einen Resolver, der nip.io erreicht.
- Einen eigenen **Agenten** (dein Claude-Abo) oder einen geteilten — siehe unten.

## Agenten und Abos

Jeder Thread läuft auf einem **Agenten**: Zugangsdaten für Claude Code, erzeugt
mit `claude setup-token`. Agenten sind entweder

- **persönlich** — sie gehören einem Benutzer und laufen über dessen
  Claude-Abo. Jeder Benutzer kann unter *Agenten* seinen eigenen anlegen.
  Benutzer sehen immer nur ihre eigenen Agenten.
- **geteilt** — sie haben keinen Besitzer (Administratoren legen sie an); in
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

**Eine Arbeit pro Workspace.** Alle Threads eines Workspaces teilen sich
dieselben Dateien. Für getrennte Arbeiten lege getrennte Workspaces an — jeder
hat seinen eigenen Branch, Container, seine eigene Datenbank und Preview.

## Aus einem Issue starten

Hat das Projekt einen verbundenen Issue-Tracker (siehe
[Issue-Trigger](SETUP-TASK-PROVIDERS.md)), bietet das Formular **Aus Issue** an:
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

Eine Workspace-Seite hat drei Teile: eine **Kopfzeile** (Name und Status des
Workspaces, der Thread-Wechsler, *Neuer Thread*, *Commit & PR* und das
**⋯**-Menü), den **Chat** als zentrierte Spalte und rechts die
[Kontext-Seitenleiste](#die-kontext-seitenleiste-editor-preview-und-branch).
Die Seite füllt das Fenster; nur die Unterhaltung scrollt.

Lege einen **Thread** an (Titel, Agent, Modell, Modus) und schreib los.
Claudes Antwort erscheint live. Jeder Turn von Claude beginnt mit einem kleinen
Kopf — Dauer und Kosten des Turns, oder *arbeitet seit N s*, solange er läuft —
gefolgt von seinen Schritten als **kompakte Zeitleiste**: eine Zeile pro
Tool-Aufruf mit Zustand, einem Verb (*Bearbeitet*, *Erstellt*, *Ausgeführt*,
…), der Datei oder dem Befehl und den geänderten Zeilen oder einem kurzen
Ergebnis. Ein Klick auf eine Zeile zeigt Ein- und Ausgabe vollständig; mehr als
drei Schritte klappen zu *N weitere Schritte anzeigen* zusammen. Aufrufe, die
einfach erlaubt wurden, bekommen keine eigene Karte.

- **Folgenachrichten, während Claude arbeitet,** werden eingereiht und
  aufgenommen, sobald Claude bereit ist — bis dahin zeigen sie *in
  Warteschlange*. Der Platzhalter im Eingabefeld weist darauf hin.
- **Stopp** (ersetzt den Senden-Knopf, solange Claude arbeitet) unterbricht den
  aktuellen Turn. Offene Freigaben werden abgebrochen.
- **Modus** (die erste Pille unter dem Eingabefeld) legt fest, wie viel Claude
  ohne Rückfrage tun darf:
  - *Vor Änderungen fragen* (Standard) — jede Änderung und jeder Befehl braucht
    eine Freigabe,
  - *Änderungen annehmen* — Dateiänderungen laufen ohne Rückfrage, Befehle
    fragen weiterhin,
  - *Plan-Mode* — Claude recherchiert und schlägt einen Plan vor, bevor es
    etwas ändert,
  - *Freigaben umgehen* — Claude führt jedes Tool ohne Rückfrage aus. Nur
    Besitzer des Workspaces können das wählen.
- Das **Modell** (die zweite Pille) lässt sich jederzeit wechseln. Die Kosten
  des Threads stehen neben dem Senden-Knopf.
- Ist eine Session beendet (Container-Neustart, Absturz), setzt **Session
  fortsetzen** dieselbe Claude-Unterhaltung fort.
- Slash-Commands und die `CLAUDE.md` des Projekts werden wie in Claude Code
  geladen.

Solange es nicht committete Änderungen gibt, zeigt eine **Änderungsleiste**
über dem Eingabefeld, wie viele Dateien sich geändert haben, die
hinzugefügten/entfernten Zeilen und den Stand der
[Quality-Gates](#quality-gates), mit **Gates prüfen** und **Review**.

### Der Thread-Wechsler

Der Knopf in der Kopfzeile zeigt den aktuellen Thread und wie viele es gibt;
klick darauf oder drück **⌘J** / **Strg+J**. Der Wechsler gruppiert die Threads
in **Wartet auf dich**, **Läuft** und **Fertig**, jeweils mit einer Statuszeile
(die offene Freigabe, der Schritt, an dem Claude gerade ist, oder wie viele
Dateien der letzte Turn geändert hat) und der Zeit der letzten Aktivität;
*Fertig* zeigt die drei neuesten und *N weitere anzeigen*. Tippen sucht im
Titel, mit den Pfeiltasten und **Enter** wechselst du, **Esc** schließt. Fährst
du über einen Thread, kannst du ihn umbenennen oder archivieren; unten gibt es
*Neuer Thread* und das **Archiv** mit archivierten Threads zum
Wiederherstellen. Ungelesene Einträge erscheinen als Zähler. Der Wechsler
aktualisiert sich live.

Wartet ein **anderer** Thread auf eine Freigabe, erscheint neben dem Wechsler
ein gelber Chip *N wartet auf Freigabe*; ein Klick führt dich in diesen Thread
zu seiner offenen Karte.

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
  | `/review` | Das [Review](#das-review) öffnen. |
  | `/preview` | Die Kontext-Seitenleiste zeigen (auf schmalen Bildschirmen ist sie eine Schublade). |
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
| Tool-Aufruf (z. B. *Claude möchte einen Befehl ausführen*) | **Erlauben**, **Immer erlauben** oder **Ablehnen** (mit optionaler Begründung). Befehle stehen vollständig da, Änderungen werden angezeigt. *Immer erlauben* übernimmt Claude Codes eigenen Regelvorschlag für den Rest der Session (z. B. diesen Befehl), sodass derselbe Aufruf nicht mehr fragt. Angeboten wird es, wenn Claude Code eine solche Regel vorschlägt. |
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

## Die Kontext-Seitenleiste: Editor, Preview und Branch

Die Seitenleiste rechts hat vier Teile. Auf Bildschirmen schmaler als etwa
1100 px wird sie zur Schublade (Knopf rechts in der Kopfzeile); auf dem Handy
wandert die Icon-Leiste nach unten.

- **Umgebung** — VS Code und die Preview, jeweils mit Zustand und Knopf. Beide
  öffnen sich in **einem eigenen Browser-Tab** — einer pro Workspace, beim
  nächsten Klick wiederverwendet. So bekommen sie das ganze Fenster,
  Tastenkürzel funktionieren, und auch Apps, die sich nicht einbetten lassen,
  werden angezeigt.
- **Branch** — der Branch des Workspaces, sein Basis-Branch und das Projekt
  sowie die Zahl **uncommitteter** Dateien und **ungepushter** Commits. Pull
  Request und das Issue, aus dem der Workspace entstand, sind hier verlinkt.
- **Quality Gates** — Pint, Pest/PHPUnit, PHPStan und Artisan mit ihrem
  Zustand (offen, läuft, ok, Fehler) und *Alle prüfen*; siehe
  [Quality-Gates](#quality-gates).
- **Geänderte Dateien** — Statusbuchstabe (M/A/D/R), Name und geänderte
  Zeilen. Ein Klick öffnet das [Review](#das-review) mit dieser Datei.

### VS Code

**Öffnen** startet VS Code (OpenVSCode Server) auf dem Workspace-Volume. Was du
änderst, sieht Claude sofort und umgekehrt. Der Zugang zum Editor-Host wird bei
jeder Anfrage gegen die Mitgliedschaft im Workspace geprüft: Argos gibt deinem
Browser ein kurzlebiges Ticket, das gegen ein Cookie auf dem Editor-Host
getauscht wird.

### Preview

Die Preview ist eine [Live-Demo](LIVE-DEMOS.md) des Workspaces: derselbe
Demo-Vertrag (`.argos/demo.*` oder der eingebaute Laravel-Standard), aber sie
mountet das **Workspace-Volume** und zeigt daher immer die aktuellen Dateien.
Sie ist nur für Mitglieder zugänglich und wird bei Bedarf gestartet.

- **Starten** baut sie, **Öffnen** öffnet sie, **Neu bauen** ersetzt sie. Die
  Zeile zeigt ihren Zustand; das Build-Log ist einen Klick entfernt (nach einem
  fehlgeschlagenen Build schon aufgeklappt).
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

**Review** in der Änderungsleiste, eine geänderte Datei in der Seitenleiste
oder `/review` schalten in den **Review-Modus**: Die Seitenleiste wird zum
Review-Panel, der Chat schrumpft auf eine schmale Spalte (Schritte werden zu
einer Zeile pro Turn). **Maximieren** gibt dem Diff die volle Breite und klappt
den Chat zu einem Streifen ein. **Review schließen** oder **Esc** kehrt zurück;
der Modus steht in der URL (`?review=1&file=…`), sodass ein Neuladen oder ein
geteilter Link dieselbe Datei öffnet.

Das Review zeigt die Änderungen des Workspaces gegenüber dem Basis-Branch —
Commits, nicht committete Änderungen und neue Dateien:

- Die **Werkzeugleiste** zeigt die hinzugefügten/entfernten Zeilen, *X / N
  gesehen*, einen Chip pro Gate (ein fehlgeschlagenes Gate mit Befunden springt
  zum ersten), **Split** oder **Unified** — für dich gespeichert — und
  Maximieren.
- Der **Dateibaum** gruppiert die Dateien nach Verzeichnis, mit Statusbuchstabe
  und Badges: Befunde der Gates (gelb), *neu* (Claude hat die Datei geändert,
  während du reviewst) und ein Haken für Dateien, die du als gesehen markiert
  hast.
- Der **Diff** zeigt alte und neue Zeilennummern; *⋯ N unveränderte Zeilen*
  zwischen den geänderten Blöcken klappt auf. **Gesehen** markiert eine Datei
  als geprüft — die Markierung verschwindet von selbst, sobald sich die Datei
  wieder ändert. *Vorherige* / *Nächste Datei* blättern weiter.
- Claude arbeitet weiter, während du reviewst. Ändert es eine Datei, erscheint
  ein blauer Hinweis *Claude hat X gerade geändert (+a −b)*; der Diff springt
  nicht um — **Änderung zeigen** (oder ein Dateiwechsel) lädt ihn neu.

### Zeilen-Kommentare

Fahr über eine Zeile und klick auf **+**, um sie zu kommentieren (Shift-Klick
auf eine zweite Zeile wählt einen Bereich). Ein gespeicherter Kommentar wird
**nicht gesendet**: Er erscheint im Diff (markiert und nummeriert) und als Chip
`Datei:Zeile ×` im Eingabefeld, das nun *N Kommentare gesammelt* anzeigt und
**Review senden (N)** anbietet. Das schickt alle gesammelten Kommentare und
deinen Text als **eine** Nachricht — pro Kommentar die Stelle, der Code mit
etwas Kontext und deine Anmerkung. Arbeitet Claude gerade, wird die Nachricht
nach dem aktuellen Schritt eingereiht. Kommentare werden pro Thread und Person
gespeichert, überstehen also ein Neuladen und das Schließen des Reviews;
bearbeiten oder verwerfen kannst du sie im Diff, oder du entfernst den Chip.

Ändert Claude Zeilen, die du kommentiert hast, wandert der Kommentar mit
Zeilen, die nur verschoben wurden. Haben sich die kommentierten Zeilen selbst
geändert, wird der Kommentar **veraltet** (gestrichelt, Badge *veraltet*, der
Chip ist durchgestrichen) und nicht gesendet. Wähle **Trotzdem senden** (er geht
mit dem ursprünglichen Code raus, markiert als bezogen auf die vorherige
Version), **Auf neue Zeile verschieben** (er bezieht sich dann auf den
aktuellen Text der Zeilen) oder **Verwerfen**.

## Quality-Gates

**Gates prüfen** (in der Änderungsleiste, *Alle prüfen* in der Seitenleiste, im
**⋯**-Menü oder per `/gates`) führt
dieselben Quality-Gates aus wie automatische Läufe
(`worker/lib/quality.sh`): Artisan-Start, Pint auf geänderten Dateien,
Pest/PHPUnit, PHPStan, Migrations-Syntax, Debug-Aufrufe und vorhandene Tests.
Sie sind für PHP/Laravel-Projekte gemacht; bei anderen Stacks werden die Gates
übersprungen.

- Direkt nach dem Klonen des Workspaces erfasst Argos eine **Baseline** des
  Basis-Branches: Pest und PHPStan zählen dann nur neue Fehler. Solange die
  Baseline noch läuft oder wenn sie fehlgeschlagen ist, zählt jeder Fehler.
- Ein Lauf gleichzeitig pro Workspace. Die Seitenleiste zeigt den Zustand jedes
  Gates; ein fehlgeschlagenes Gate klappt das Ende seines Logs und **Claude
  fixen lassen** auf. Das schickt einen Fix-Prompt mit dem Log in den aktuellen
  Thread — denselben, den automatische Läufe nutzen.
- Im [Review](#das-review) erscheinen PHPStan-Fehler **an ihrer Zeile**, Pint
  markiert die Datei. **Claude fixen lassen** legt den Befund dort (Gate,
  Meldung, Datei und Zeile) zu deinen gesammelten Kommentaren, sodass er mit dem
  nächsten *Review senden* rausgeht.
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

Ein **Auto-Thread** arbeitet ohne Menschen: Er plant (optional), setzt um,
prüft die [Quality-Gates](#quality-gates), behebt Fehler und liefert einen Pull
Request aus. Du kannst live zusehen und jederzeit übernehmen — ansonsten ist er
ein ganz normaler Thread.

**Einen starten**

- In einem Workspace: **Neuer Thread → Automatisch umsetzen**, dann die Arbeit
  beschreiben.
- Mit einem neuen Workspace: **Mit dem Workspace einen automatischen Lauf
  starten** im Formular — der Lauf wartet, bis der Workspace bereit ist.
- Von außen: die [REST-API](REST-API.md#workspaces-und-automatische-laufe)
  (`POST /api/v1/workspaces`), [MCP](SETUP-MCP.md) (`workspace_create`) oder ein
  Issue eines verbundenen Trackers ([Issue-Trigger](SETUP-TASK-PROVIDERS.md#issue-zu-automatischem-lauf)).

**Wie er läuft**

1. **Plan** (je nach Einstellung *Plan*): *aus* setzt sofort um; *automatisch*
   plant zuerst und macht dann selbst weiter; *auf Freigabe warten* hält beim
   Plan an, bis jemand ihn freigibt — auf der Plan-Karte im Thread, per API oder
   MCP oder mit 👍 auf dem Issue-Kommentar. Lehnst du mit Feedback ab, überarbeitet
   Claude den Plan.
2. **Umsetzen** im Modus *Freigaben umgehen* — der Lauf ist im
   Workspace-Container isoliert, und niemand ist da, um Freigaben zu beantworten.
   Stellt Claude eine Frage, wird es angewiesen, selbst zu entscheiden und seine
   Annahme zu nennen.
3. **Gates** nach jedem Turn. Fehlgeschlagene Gates gehen mit demselben
   Fix-Prompt, den das Review anbietet, zurück an Claude, bis zu *Gate-Fix-Runden*
   Mal; danach wird trotzdem ausgeliefert, und der Pull Request nennt die Gates,
   die weiterhin fehlschlagen.
4. **Ausliefern** je nach *Wenn fertig*: Pull Request, Entwurfs-Pull-Request
   oder nur committen und pushen. Danach endet die Session, und ein automatischer
   Workspace (gestartet per API, MCP oder aus einem Issue) wird gestoppt.

Der Thread-Kopf zeigt den Zustand (z. B. *Behebt Gate-Fehler · Runde 2/3*);
automatische Nachrichten sind als *automatisch* markiert.

**Übernehmen.** Schreibst du in einen laufenden Auto-Thread, pausiert die
Automatik — er wird zu einem normalen Thread. **Automatisch fortsetzen**
(Play-Button im Kopf) gibt ihn zurück: Er prüft die Gates und liefert aus.
**Automatik pausieren** hält ihn an, ohne etwas zu schreiben.

**Budgets.** *Max. Turns*, *Max. Kosten* und *Max. Laufzeit* stoppen einen Lauf
(*Budget überschritten*); die bisherige Arbeit bleibt im Workspace. Erreicht der
Agent sein Nutzungslimit, geht der Lauf weiter, sobald das Limit aufgehoben ist.

**Für wen er läuft.** Ein Lauf handelt für eine Person: wer ihn in der UI
gestartet hat, der angemeldete MCP-Nutzer, der **Automatik-Nutzer** des Projekts
(Projekt-Tokens, Issue-Trigger) oder der **Handelt als**-Nutzer des API-Clients.
Ihr Claude-Agent erledigt die Arbeit, und sie besitzt den Workspace.

**Aufräumen.** Automatische Workspaces werden gelöscht, sobald ihr Pull Request
gemergt oder geschlossen ist, oder `ARGOS_AUTO_CLEANUP_DAYS` nach dem Ende ihres
Laufs, wenn niemand darin gearbeitet hat. Von Hand angelegte Workspaces werden
nie automatisch gelöscht.

**Benachrichtigungen** der Art *Automatische Läufe* sagen dir, wenn ein Plan
wartet oder ein Lauf fertig ist, fehlgeschlagen ist oder sein Budget erreicht
hat.

Jedes Projekt legt die Standards unter **Verwaltung → Projekte → Automatik**
fest; alle Einstellungen stehen unter [Konfiguration](CONFIGURATION.md#auto-threads).
Die Gates sind für PHP/Laravel-Projekte gemacht; auf anderen Stacks werden die
Fix-Runden übersprungen.

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
  kann ihn im Container technisch auslesen, behandle einen Workspace also wie einen Rechner, auf dem du Claude
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
