# Aufgaben

Jede Unterhaltung mit Claude in einem [Workspace](WORKSPACES.md) ist eine
**Aufgabe**. Eine Aufgabe

- gehört einer Person (**Besitz**) und läuft über deren Claude-Abo — auch wenn
  Mitarbeitende schreiben,
- hat ihren **eigenen Branch** (nach dem Branch-Muster des Workspaces), ihren
  eigenen git-**Worktree** unter `/workspace/tasks/<slug>`, ihre **eigene
  Datenbank** (und ihr eigenes Redis-Präfix), wenn der Workspace diese Dienste
  hat, bei Bedarf ihre eigene **Preview** und ihren eigenen **Pull Request**,
- kann mit genau einem [Ticket](TICKETS.md) verknüpft sein.

Die Aufgaben eines Workspaces teilen sich den Container, aber nicht ihre
Dateien: Was Claude in einer Aufgabe ändert, taucht in keiner anderen auf.

## Inhalt

- [Eine Aufgabe starten](#eine-aufgabe-starten)
- [Vorbereitung: Worktree, Datenbank, Setup-Schritte](#vorbereitung-worktree-datenbank-setup-schritte)
- [Status und der Aufgaben-Wechsler](#status-und-der-aufgaben-wechsler)
- [Mit Claude chatten](#mit-claude-chatten)
- [Bilder, Dateien und Befehle](#bilder-dateien-und-befehle)
- [Freigaben, Pläne und Fragen](#freigaben-plane-und-fragen)
- [Zusammenarbeiten](#zusammenarbeiten)
- [Session-Warteschlange](#session-warteschlange)
- [Die Seitenleiste: Editor, Preview, Branch](#die-seitenleiste-editor-preview-branch)
- [Das Review](#das-review)
- [Quality-Gates](#quality-gates)
- [Checkpoints und Zurücksetzen](#checkpoints-und-zurucksetzen)
- [Ausliefern: Commit und Pull Request](#ausliefern-commit-und-pull-request)
- [Automatische Aufgaben](#automatische-aufgaben)
- [Eine Aufgabe abschließen](#eine-aufgabe-abschliessen)
- [Aufräumen](#aufraumen)
- [Benachrichtigungen](#benachrichtigungen)

## Eine Aufgabe starten

**Neue Aufgabe** (Kopfzeile oder Wechsler) fragt nach:

| Feld | Bedeutung |
|---|---|
| Ticket (optional) | Durchsucht die offenen Issues des Trackers des Workspaces nach Titel oder Nummer. Die Aufgabe übernimmt Titel und Beschreibung des Tickets und [meldet sich dort zurück](TICKETS.md). |
| Titel | Woran du arbeitest (Standard: der Titel des Tickets). Der Branch wird daraus abgeleitet. |
| Basis-Branch | Wo der Branch der Aufgabe startet (Standard: der Default-Branch des Workspaces). Ihr Pull Request zielt darauf. |
| Automatisch umsetzen | Macht sie zu einer [automatischen Aufgabe](#automatische-aufgaben): Beschreib die Arbeit, wähle Plan- und Auslieferungsmodus und die Gate-Fix-Runden. |
| Automatisch mergen | Schaltet das Auto-Merge des Providers ein, sobald der Pull Request existiert — siehe [Pull Requests](PULL-REQUESTS.md#auto-merge). |
| Agent | Auf welchem deiner Claude-Abos (oder einem geteilten) die Aufgabe läuft. |
| Modell, Modus | Siehe [Mit Claude chatten](#mit-claude-chatten). |

Eine Aufgabe zu starten braucht die Rolle *Admin* oder *Mitglied* und einen
nutzbaren Agenten.

**Branch-Namen.** Mit Ticket heißt der Branch `<Ticket-Schlüssel>-<Titel>`
(zum Beispiel `gitlab-42-login-page` oder `eng-118-login-page`), ohne
`argos/<Titel>`. Ein Workspace kann ein eigenes **Branch-Muster** festlegen
(Einstellungen → Allgemein) mit den Platzhaltern `{ticket}` (der
Ticket-Schlüssel, ohne Ticket `argos`) und `{title}` (der Titel als Slug). Ein
vergebener Name bekommt ein numerisches Suffix.

## Vorbereitung: Worktree, Datenbank, Setup-Schritte

Eine neue Aufgabe startet im Zustand **Vorbereitung**; der Chat zeigt den
Fortschritt:

1. Argos legt die Datenbank der Aufgabe `task_<slug>` im MySQL-Sidecar des
   Workspaces an (wenn MySQL aktiviert ist).
2. Der Daemon fetcht den Haupt-Klon und legt den Worktree an: auf dem
   Remote-Branch, wenn es ihn schon gibt (eine wieder aufgenommene Aufgabe),
   sonst auf dem frisch gefetchten Basis-Branch.
3. Er schreibt die Werte der Aufgabe in die `.env` des Worktrees — ausgehend
   von `.env.example`, wenn es noch keine `.env` gibt: `DB_*` mit der Datenbank
   der Aufgabe, `REDIS_*`, `REDIS_PREFIX` und `CACHE_PREFIX`. Dieselben Werte
   stehen in `.agent/task-env.json` und überschreiben die Umgebung des
   Containers für alles, was im Worktree läuft (Claudes Tools, die Gates, das
   Setup).
4. Er führt die **Setup-Schritte** aus: `.argos/setup.sh` aus dem Repository
   oder — ohne diese Datei — die Laravel-Standards (`composer install`, ein
   `APP_KEY`, wenn er in der `.env` leer ist, `php artisan migrate`). Solange
   ein getestetes Workspace-Setup noch nicht gemergt ist, kommen die Schritte
   von seinem Branch. Siehe
   [Ein Repository vorbereiten](PREPARE-PROJECT.md#setup-befehle-argossetupsh).

Danach ist die Aufgabe bereit, und Claude startet. Schlagen die Setup-Schritte
fehl, ist die Aufgabe trotzdem nutzbar (der Chat zeigt das Setup-Log, und
Claude kann sich darum kümmern). Schlägt die Vorbereitung selbst fehl,
versucht **Erneut versuchen** es noch einmal. Nachrichten, die während der
Vorbereitung geschrieben werden, werden mit einem Hinweis abgewiesen.

## Status und der Aufgaben-Wechsler

| Status | Bedeutung | Gruppe im Wechsler |
|---|---|---|
| Vorbereitung | Worktree, Datenbank und Setup-Schritte laufen | Läuft |
| Läuft | Claude arbeitet | Läuft |
| Wartet auf dich | Eine Freigabe, ein Plan oder eine Frage ist offen | Wartet auf dich |
| Bereit | Der Turn ist beendet, nichts ist offen, noch kein Pull Request | Bereit |
| PR offen | Ein Pull Request (Entwurf oder bereit) existiert | PR offen |
| Fertig | Der Pull Request wurde gemergt | Fertig |
| Abgebrochen | Der Pull Request wurde ohne Merge geschlossen, oder die Aufgabe wurde abgebrochen | Fertig |
| Archiviert | Aus der Liste genommen, im Archiv lesbar | Archiv |

Automatische Aufgaben zeigen zusätzlich ihren Laufzustand (*Plant*, *Behebt
Gate-Fehler · Runde 2/3*, …).

### Der Aufgaben-Wechsler

Der Knopf in der Kopfzeile zeigt die aktuelle Aufgabe; klick darauf oder drück
**⌘J** / **Strg+J**. Der Wechsler gruppiert die Aufgaben in *Wartet auf dich*,
*Läuft*, *Bereit*, *PR offen* und *Fertig* (eingeklappt; die drei neuesten und
*N weitere anzeigen*). Jede Zeile zeigt, wem die Aufgabe gehört, das Ticket,
den Pull Request, eine Statuszeile (die offene Freigabe, der Schritt, an dem
Claude gerade ist, wie viele Dateien der letzte Turn geändert hat, *Wartet auf
eine freie Session*) und die Zeit der letzten Aktivität.

- Der Filter wechselt zwischen **Meine + eingeladen** und **Alle**.
- Tippen sucht im Titel; mit den Pfeiltasten und **Enter** wechselst du,
  **Esc** schließt.
- Fährst du über eine Aufgabe, kannst du sie umbenennen oder archivieren (nur
  bei Aufgaben, die du ändern darfst; Archivieren fragt vorher nach); unten
  gibt es *Neue Aufgabe* und das **Archiv** mit archivierten Aufgaben zum
  Wiederherstellen.
- Ungelesene Einträge erscheinen als Zähler; der Wechsler aktualisiert sich
  live.

Wartet eine **andere** Aufgabe auf eine Freigabe, erscheint neben dem Wechsler
ein Chip *N wartet auf Freigabe*; ein Klick führt dich zu dieser Aufgabe und
ihrer offenen Karte.

## Mit Claude chatten

Schreib, und Claudes Antwort erscheint live. Jeder Turn beginnt mit einem
kleinen Kopf — Dauer und Kosten, oder *arbeitet seit N s*, solange er läuft —
gefolgt von seinen Schritten als **kompakte Zeitleiste**: eine Zeile pro
Tool-Aufruf mit Zustand, einem Verb (*Bearbeitet*, *Erstellt*, *Ausgeführt*,
…), der Datei oder dem Befehl und den geänderten Zeilen oder einem kurzen
Ergebnis. Ein Klick auf eine Zeile zeigt Ein- und Ausgabe vollständig; mehr als
drei Schritte klappen zu *N weitere Schritte anzeigen* zusammen.

- **Folgenachrichten, während Claude arbeitet,** werden eingereiht
  (Senden-Knopf oder `Enter`) und aufgenommen, sobald Claude bereit ist — bis
  dahin zeigen sie *in Warteschlange*.
- **Stopp** (neben dem Senden-Knopf, solange Claude arbeitet) unterbricht den
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
    Workspace-Admins (und Argos-Admins) können das wählen.
- Das **Modell** (die zweite Pille) lässt sich jederzeit wechseln. Die Kosten
  der Aufgabe stehen neben dem Senden-Knopf.
- Ist eine Session beendet (Container-Neustart, Absturz), setzt **Session
  fortsetzen** dieselbe Claude-Unterhaltung fort.
- Claude arbeitet im Worktree der Aufgabe. Slash-Commands, Skills und die
  `CLAUDE.md` des Repositorys werden wie in Claude Code geladen.

Solange es nicht committete Änderungen gibt, zeigt eine **Änderungsleiste**
über dem Eingabefeld, wie viele Dateien sich geändert haben, die
hinzugefügten/entfernten Zeilen und den Stand der
[Quality-Gates](#quality-gates), mit **Gates prüfen** und **Review**.

## Bilder, Dateien und Befehle

- **Bilder:** Screenshots einfügen, hineinziehen oder anhängen (PNG, JPEG, GIF,
  WebP; bis zu 5 pro Nachricht, je `ARGOS_WORKSPACE_UPLOAD_MAX_MB`). Claude
  sieht sie mit der Nachricht; andere sehen sie als Vorschaubilder. Sie werden
  privat gespeichert.
- **`@`-Erwähnungen:** Tippe `@` und einen Teil eines Pfads, um eine Datei aus
  dem Worktree der Aufgabe auszuwählen (unscharfe Suche über getrackte und
  ungetrackte Dateien; ignorierte bleiben außen vor). Der Pfad wird als Text
  eingefügt.
- **`/`-Befehle:** Tippe `/` für eine Liste. Die eigenen Befehle von Argos
  laufen im Browser und gehen nicht an Claude:

  | Befehl | Wirkung |
  |---|---|
  | `/plan [Anfrage]` | In den Plan-Mode wechseln, optional die Anfrage senden. |
  | `/ship` | Den Dialog für Commit und Pull Request öffnen. |
  | `/review` | Das [Review](#das-review) öffnen. |
  | `/preview` | Die Preview in der Seitenleiste zeigen (aufgeklappt und markiert; auf schmalen Bildschirmen öffnet sich die Schublade). |
  | `/gates` | Die [Quality-Gates](#quality-gates) ausführen. |
  | `/checkpoint [Name]` | Einen benannten [Checkpoint](#checkpoints-und-zurucksetzen) setzen. |

  Alle anderen Befehle — die Commands und Skills des Repositorys, `/compact`
  und Co. — gehen so an Claude Code, wie du sie tippst. `/ship` und `/gates`
  sagen Bescheid, wenn der Workspace nicht läuft oder du sie nicht nutzen
  darfst.

`Enter` sendet, `Shift+Enter` fügt eine Zeile ein; solange die Vorschlagsliste
offen ist, übernehmen `Enter`/`Tab` den markierten Eintrag. **?** (außerhalb
eines Eingabefelds) oder **⋯ → Tastenkürzel** zeigt alle Tastenkürzel.

## Freigaben, Pläne und Fragen

Braucht Claude eine Entscheidung, erscheint für alle eine Karte in der Aufgabe:

| Karte | Was du tun kannst |
|---|---|
| Tool-Aufruf (z. B. *Claude möchte einen Befehl ausführen*) | **Erlauben**, **Immer erlauben** oder **Ablehnen** (mit optionaler Begründung). Befehle stehen vollständig da, Änderungen werden angezeigt. *Immer erlauben* übernimmt Claude Codes eigenen Regelvorschlag für den Rest der Session, sodass derselbe Aufruf nicht mehr fragt; angeboten wird es, wenn Claude Code eine solche Regel vorschlägt. |
| Plan (aus dem Plan-Mode) | Annehmen und den Modus wählen, in dem es weitergeht, oder mit Feedback ablehnen. |
| Frage | Pro Frage eine Option wählen oder eine eigene Antwort eingeben. |

Datei-Tools außerhalb des Worktrees der Aufgabe (und `/tmp`) fragen immer, auch
in *Änderungen annehmen*. Es entscheiden die Person, der die Aufgabe gehört,
die Mitarbeitenden und die Workspace-Admins; die **erste Entscheidung gilt**,
alle sehen, wer entschieden hat.

## Zusammenarbeiten

- **Einladen** (Kopfzeile **⋯ → Zur Aufgabe einladen**): Wem die Aufgabe
  gehört, wählt Mitglieder des Workspaces als Mitarbeitende aus. Sie schreiben,
  geben frei, reviewen und liefern in dieser Aufgabe aus; sie läuft weiter über
  das Abo der Person, der sie gehört. Ein Klick auf das Bild einer
  mitarbeitenden Person in der Kopfzeile nimmt sie (nach einer Rückfrage)
  wieder heraus.
- **Übergeben**: Die Aufgabe gehört dann der neuen Person (Workspace-Admin oder
  Mitglied mit nutzbarem Claude-Abo) und läuft über deren Abo; die offene
  Session endet, wem sie bisher gehörte, arbeitet als mitarbeitende Person
  weiter, und die Zuständigkeit im Ticket folgt, wo der Tracker das kann (siehe
  [Tickets](TICKETS.md#ubergabe-und-zugewiesene-person)).
- Die Kopfzeile zeigt Besitz und Mitarbeitende mit ihrer Anwesenheit; der Chat
  zeigt, wer tippt. Nachrichten anderer Personen tragen deren Namen und Farbe,
  und Claude sieht sie als `[Name]: …`. Freigaben und Auslieferungen nennen,
  wer sie gemacht hat.

> Alles, was in einer Aufgabe geschrieben wird, läuft über das Abo der
> **Person, der sie gehört** — auch Nachrichten von Mitarbeitenden.

## Session-Warteschlange

Sessions sind pro Workspace (**Parallele Sessions**, Standard 3) und pro
Claude-Abo (die *parallelen Sessions* des Agenten, Standard 1) begrenzt. Findet
eine Nachricht keine freie Session, wird eine ruhende Session (untätig,
niemand wartet auf sie) geschlossen, um Platz zu machen; gibt es keine, wartet
die Nachricht in der Schlange — der Chat zeigt *Wartet auf eine freie Session —
Platz N* — und geht automatisch raus, sobald eine Session frei wird, die älteste
zuerst. Ruhende Aufgaben halten eine Session nicht lange.

## Die Seitenleiste: Editor, Preview, Branch

Die Seitenleiste gehört zur aktuellen Aufgabe:

- **Umgebung** — VS Code und die Preview, jeweils mit Zustand und Knopf. Beide
  öffnen sich in **einem eigenen Browser-Tab** (beim nächsten Klick
  wiederverwendet), sodass sie das ganze Fenster bekommen und auch Apps, die
  sich nicht einbetten lassen, angezeigt werden.
- **Branch** — der Branch der Aufgabe, ihr Basis-Branch, die Zahl
  **uncommitteter** Dateien und **ungepushter** Commits und das Ticket.
- **Pull Request** — Zustand, Checks, Reviews, Konflikte und seine Aktionen;
  siehe [Pull Requests](PULL-REQUESTS.md).
- **Quality Gates** — jedes Gate mit seinem Zustand (offen, läuft, ok, Fehler,
  übersprungen) und *Alle prüfen*; siehe [Quality-Gates](#quality-gates).
- **Geänderte Dateien** — Statusbuchstabe (M/A/D/R), Name und geänderte
  Zeilen. Ein Klick öffnet das [Review](#das-review) mit dieser Datei.

### VS Code

**Öffnen** startet OpenVSCode Server auf dem Ordner der Aufgabe
(`/workspace/tasks/<slug>`). Was du änderst, sieht Claude sofort und
umgekehrt. Nur Workspace-Admins und Mitglieder bekommen den Editor; der Zugang
wird bei jeder Anfrage geprüft.

### Preview

Die Preview ist eine [Live-Demo](LIVE-DEMOS.md) des Worktrees der Aufgabe,
unter einer eigenen Subdomain. **Starten** baut sie, **Öffnen** öffnet sie,
**Neu bauen** ersetzt sie, **Setup-Befehle erneut ausführen** führt die Befehle
des Vertrags noch einmal aus (nach einer Migration oder einer
`composer`-/`npm`-Änderung), **Preview stoppen** entfernt sie. Previews
brauchen `ARGOS_PREVIEW_ENABLED` und den Schalter *Live-Demo* des Workspaces;
pro Workspace laufen höchstens *Parallele Previews*.

## Das Review

**Review** in der Änderungsleiste, eine geänderte Datei in der Seitenleiste
oder `/review` schalten in den **Review-Modus**: Die Seitenleiste wird zum
Review-Panel, der Chat schrumpft auf eine schmale Spalte. **Maximieren** gibt
dem Diff die volle Breite. **Review schließen** oder **Esc** kehrt zurück; der
Modus steht in der URL (`?review=1&file=…`), sodass ein Neuladen oder ein
geteilter Link dieselbe Datei öffnet.

Das Review zeigt die Änderungen der Aufgabe gegenüber ihrem Basis-Branch —
Commits, nicht committete Änderungen und neue Dateien:

- Die **Werkzeugleiste** zeigt die hinzugefügten/entfernten Zeilen, *X / N
  gesehen*, einen Chip pro Gate, **Split** oder **Unified** (für dich
  gespeichert) und Maximieren.
- Der **Dateibaum** gruppiert die Dateien nach Verzeichnis, mit
  Statusbuchstabe und Badges: Befunde der Gates, *neu* (Claude hat die Datei
  geändert, während du reviewst) und ein Haken für Dateien, die du als gesehen
  markiert hast. Generierte Dateien (Lockfiles, Build-Ausgabe) sind
  eingeklappt; ihr Diff lädt bei Bedarf.
- Der **Diff** zeigt alte und neue Zeilennummern; unveränderte Zeilen zwischen
  den geänderten Blöcken klappen auf. **Gesehen** markiert eine Datei als
  geprüft — die Markierung verschwindet, sobald sich die Datei wieder ändert.
  Große Diffs laden schrittweise.
- Ändert Claude eine Datei, während du reviewst, erscheint ein Hinweis *Claude
  hat X gerade geändert (+a −b)*; **Änderung zeigen** lädt den Diff neu.

### Zeilen-Kommentare

Fahr über eine Zeile und klick auf **+**, um sie zu kommentieren (Shift-Klick
auf eine zweite Zeile wählt einen Bereich). Ein gespeicherter Kommentar wird
**nicht gesendet**: Er erscheint im Diff und als Chip `Datei:Zeile ×` im
Eingabefeld, das **Review senden (N)** anbietet. Das schickt alle gesammelten
Kommentare und deinen Text als **eine** Nachricht. Kommentare werden pro
Aufgabe und Person gespeichert.

Ändert Claude Zeilen, die du kommentiert hast, wandert der Kommentar mit
Zeilen, die nur verschoben wurden. Haben sich die kommentierten Zeilen selbst
geändert, wird er **veraltet** und nicht gesendet: **Trotzdem senden**, **Auf
neue Zeile verschieben** oder **Verwerfen**.

## Quality-Gates

**Gates prüfen** (Änderungsleiste, *Alle prüfen* in der Seitenleiste oder
`/gates`) führt die Quality-Gates im Worktree der Aufgabe aus:
Artisan-Start, Pint auf geänderten Dateien, Pest/PHPUnit, PHPStan,
Migrations-Syntax, Debug-Aufrufe und vorhandene Tests. Sie sind für
PHP/Laravel-Projekte gemacht; bei anderen Stacks werden die Gates
übersprungen.

- **Baseline pro Basis-Commit:** Argos erfasst einmal, welche Tests und
  PHPStan-Fehler schon auf dem Basis-Commit fehlschlagen; alle Aufgaben auf
  diesem Commit teilen sie, sodass nur Fehler zählen, die die Aufgabe **neu
  einführt**. Ohne Baseline (wird noch erfasst oder ist fehlgeschlagen) zählt
  jeder Fehler.
- Ein Lauf gleichzeitig pro Aufgabe. Ein Klick auf ein Gate — auch ein grünes —
  öffnet seine **Gate-Details**: Ergebnis, Dauer, Zeitpunkt, den
  Baseline-Hinweis, das vollständige Log mit Suche und die betroffenen Dateien
  (ein Klick öffnet das Review auf dieser Datei). **Erneut prüfen** führt nur
  dieses Gate noch einmal aus (die anderen behalten ihr Ergebnis); ein
  fehlgeschlagenes Gate bietet außerdem **Claude fixen lassen**, das einen
  Fix-Prompt mit dem Log in die Aufgabe schickt.
- Im [Review](#das-review) erscheinen PHPStan-Fehler **an ihrer Zeile**, und
  Pint markiert die Datei; **Ins Review übernehmen** legt den Befund dort zu
  deinen gesammelten Kommentaren.
- Der Commit-Dialog nennt das letzte Ergebnis. Fehlgeschlagene Gates sind ein
  Warnhinweis, nie eine Sperre.

Die Gate-Logs liegen in `.agent/logs/` im Worktree, das git lokal ignoriert.

## Checkpoints und Zurücksetzen

Bevor Claude eine Nachricht bearbeitet, legt Argos einen **Checkpoint** des
Worktrees der Aufgabe an — getrackte, gestagte und ungetrackte Dateien, keine
ignorierten — gespeichert als Git-Ref (`refs/argos/checkpoints/<task>/<n>`).
Branch, Index und deine Dateien bleiben unberührt. Eine Aufgabe behält ihre
neuesten 200 Checkpoints.

- **Bis hierhin zurücksetzen** an einer Nachricht (mit der Maus darüberfahren)
  zeigt, was das Zurücksetzen verwirft, und setzt die Dateien auf den Stand vor
  dieser Nachricht zurück. Solange Claude in dieser Aufgabe arbeitet, wird das
  abgelehnt — stoppe es zuerst. Andere Aufgaben sind nicht betroffen.
- Der aktuelle Stand wird vorher als Checkpoint gesichert, sodass
  **Rückgängig** ihn zurückholt.
- Commits werden nie zurückgenommen: Wurden seitdem Commits erstellt, werden
  nur die Dateien zurückgesetzt.
- Der Chat bleibt; Claude erfährt es mit deiner nächsten Nachricht und liest
  die Dateien neu.
- `/checkpoint [Name]` setzt einen benannten Checkpoint.

## Ausliefern: Commit und Pull Request

**Commit & PR** (Kopfzeile oder `/ship`) committet alles, was sich in der
Aufgabe geändert hat, pusht ihren Branch und öffnet einen Pull Request (GitLab:
Merge Request) gegen den Basis-Branch.

- Claude entwirft die Commit-Message aus dem Diff und der Unterhaltung, auf
  **deinem** Abo. Ohne Abo oder wenn das fehlschlägt, schreibst du sie selbst.
- Push und Pull Request nutzen den Repository-Zugang des Workspaces; der Commit
  trägt Namen und E-Mail-Adresse der Person, die ausliefert, und der Pull
  Request nennt, wer ihn ausgeliefert hat. Ein verknüpftes Ticket wird im Pull
  Request referenziert (siehe [Tickets](TICKETS.md#schliessen-beim-merge)).
- *Als Entwurf öffnen* öffnet einen Entwurfs-Pull-Request. Erneutes Ausliefern
  pusht die neuen Commits und kommentiert den bestehenden Pull Request.
- Hat sich der Remote-Branch bewegt, wird der Push abgelehnt. Meldet der Pull
  Request Konflikte oder einen weitergelaufenen Basis-Branch, bittet **Claude
  rebasen lassen** (Menü der Kopfzeile, in der Seitenleiste *Rebase durch
  Claude*) Claude, zu fetchen, zu rebasen und Konflikte zu lösen.
- Ab dann folgt die Seitenleiste dem Pull Request — siehe
  [Pull Requests](PULL-REQUESTS.md).

Ausliefern dürfen die Person, der die Aufgabe gehört, die Mitarbeitenden und
die Workspace-Admins.

## Automatische Aufgaben

Eine **automatische Aufgabe** arbeitet ohne Menschen: Sie plant (optional),
setzt um, prüft die [Quality-Gates](#quality-gates), behebt Fehler und liefert
aus. Du kannst live zusehen und jederzeit übernehmen.

**Eine starten**

- In einem Workspace: **Neue Aufgabe → Automatisch umsetzen**, dann die Arbeit
  beschreiben.
- Von außen: die [REST-API](REST-API.md#eine-aufgabe-starten)
  (`POST /api/v1/workspaces/{workspace}/tasks`), [MCP](SETUP-MCP.md)
  (`task_create`) oder ein Issue eines verbundenen Trackers
  ([Issue-Trigger](SETUP-TASK-PROVIDERS.md#vom-issue-zur-automatischen-aufgabe)).

**Wie sie läuft**

1. **Plan** (je nach Einstellung *Plan*): *aus* setzt sofort um; *automatisch*
   plant zuerst und macht dann selbst weiter; *auf Freigabe warten* hält beim
   Plan an, bis jemand ihn freigibt — auf der Plan-Karte, per API oder MCP oder
   mit 👍 auf dem Issue-Kommentar. Lehnst du mit Feedback ab, überarbeitet
   Claude den Plan.
2. **Umsetzen** im Modus *Freigaben umgehen* — niemand ist da, um Freigaben zu
   beantworten; Datei-Tools außerhalb des Worktrees werden abgelehnt. Stellt
   Claude eine Frage, wird es angewiesen, selbst zu entscheiden und seine
   Annahme zu nennen.
3. **Gates** nach jedem Turn. Fehlgeschlagene Gates gehen mit dem Fix-Prompt
   zurück an Claude, bis zu *Gate-Fix-Runden* Mal; danach wird trotzdem
   ausgeliefert, und der Pull Request nennt die Gates, die weiterhin
   fehlschlagen.
4. **Ausliefern** je nach *Wenn fertig*: Pull Request, Entwurfs-Pull-Request
   oder nur committen und pushen. Danach endet die Session; der Container läuft
   für die anderen Aufgaben weiter.

**Übernehmen.** Schreibst du in eine laufende automatische Aufgabe, pausiert
die Automatik. **Automatisch fortsetzen** (Play-Button in der Kopfzeile) gibt
sie zurück: Sie prüft die Gates und liefert aus. **Automatik pausieren** hält
sie an, ohne etwas zu schreiben.

**Budgets.** *Max. Turns*, *Max. Kosten* und *Max. Laufzeit* stoppen einen
Lauf (*Budget überschritten*); die bisherige Arbeit bleibt. Erreicht das Abo
sein Nutzungslimit, geht der Lauf weiter, sobald das Limit aufgehoben ist.

**Für wen sie läuft.** Eine automatische Aufgabe gehört einer Person: wer sie
in der UI gestartet hat, die angemeldete MCP-Person, die **Handelt als**-Person
des API-Clients, der **Automatik-Nutzer** des Workspaces (Workspace-Tokens,
Issue-Trigger) — oder, bei einem Issue, die zugewiesene Person, wenn sie in
ihrem Profil zugestimmt hat (siehe
[Tickets](TICKETS.md#automatische-aufgaben-aus-issues)).

Die Standards kommen aus **Einstellungen → Automatik**; alle Einstellungen
stehen unter [Konfiguration](CONFIGURATION.md#automatische-aufgaben).

## Eine Aufgabe abschließen

| Aktion | Wer | Wirkung |
|---|---|---|
| Pull Request gemergt | — | Die Aufgabe ist **Fertig**. |
| Pull Request ohne Merge geschlossen | — | Die Aufgabe ist **Abgebrochen**. |
| **Aufgabe abbrechen** | Besitz, Workspace-Admins | Die Aufgabe endet ohne Merge; ihr Pull Request bleibt, wie er ist. |
| **Archivieren** | Besitz, Workspace-Admins | Die Aufgabe verlässt die Liste (Session geschlossen) und bleibt im Archiv lesbar; **Wiederherstellen** holt sie zurück. |
| **Aufgabe löschen** | Besitz, Workspace-Admins | Unterhaltung, Worktree, Datenbank und Preview werden endgültig gelöscht. Remote-Branch und Pull Request bleiben. |
| **Folge-Aufgabe** | Admins, Mitglieder | Setzt eine abgeschlossene Aufgabe fort: gleiches Ticket, ein frischer Branch vom aktuellen Basis-Branch, ein Link zur früheren Aufgabe und eine vorbefüllte erste Nachricht, die sie zusammenfasst. |

Abgeschlossene Aufgaben nehmen keine neuen Nachrichten an — starte eine
Folge-Aufgabe, um weiterzumachen.

## Aufräumen

`cleanup_after_days` (Einstellungen → Ressourcen, Standard 14) nachdem eine
Aufgabe fertig oder abgebrochen ist, entfernt Argos ihre Preview, ihren
Worktree, ihren lokalen Branch und ihre Datenbank (täglich um 03:30,
`argos:tasks:cleanup`). Unterhaltung, Remote-Branch und Pull Request bleiben.
Aufgaben eines gestoppten Workspaces werden bei einem späteren Lauf
aufgeräumt, sobald er läuft.

## Benachrichtigungen

Die Person, der eine Aufgabe gehört, und ihre Mitarbeitenden werden
benachrichtigt (außer der Person, die es ausgelöst hat), je nach ihren
Profileinstellungen:

| Art | Wann |
|---|---|
| Claude wartet auf eine Entscheidung | eine Freigabe, ein Plan oder eine Frage erscheint |
| Claude hat einen längeren Turn beendet | ein Turn länger als 30 Sekunden gedauert hat |
| Ein Abo hat sein Nutzungslimit erreicht | der Agent der Aufgabe sein Limit meldet |
| Neue Kommentare am Ticket | ein Kommentar am Ticket der Aufgabe eingegangen ist |
| Fehler | eine Aufgabe fehlschlägt |
| Automatische Läufe | ein Plan wartet, ein Lauf fertig ist, fehlgeschlagen ist oder sein Budget erreicht hat |
| Jemand hat eine Aufgabe ausgeliefert | Commits gepusht wurden oder sich Pipeline, Reviews oder Zustand des Pull Requests geändert haben |
| Eingeladen oder übergeben | du zu einer Aufgabe eingeladen wurdest oder sie dir übergeben wurde |

Alles außer beendeten Turns landet auch in der Benachrichtigungsglocke. Im
Browser erscheint eine Benachrichtigung (und ein Zähler im Tab-Titel) nur, wenn
die betreffende Aufgabe nicht gerade vor dir offen ist; Browser-Benachrichtigungen
erlaubst du in deinem **Profil**, wo du auch jede Art einzeln abschalten
kannst.
