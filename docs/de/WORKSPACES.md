# Workspaces

Ein **Workspace** ist ein interaktiver Arbeitsplatz für einen Branch eines
Projekts, zusammen mit Claude — so, wie du Claude Code auf deinem eigenen Rechner
nutzt, nur im Browser. Du chattest mit Claude, während es arbeitet, beantwortest
seine Fragen, erlaubst oder verweigerst die Änderungen, die es machen will,
bearbeitest Dateien selbst in VS Code und klickst dich durch das Ergebnis in
einer Live-Preview. Mehrere Personen können gleichzeitig im selben Workspace
arbeiten.

Workspaces stehen neben den [Aufgaben](TASKS.md). Eine Aufgabe ist der
automatische Ablauf (Konzept → Implementierung → Pull Request); ein Workspace
ist der interaktive. Beide nutzen dieselben Projekte, Worker-Stacks und Agenten.

## Inhalt

- [Was du brauchst](#was-du-brauchst)
- [Agenten und Abos](#agenten-und-abos)
- [Einen Workspace anlegen](#einen-workspace-anlegen)
- [Threads](#threads)
- [Freigaben, Pläne und Fragen](#freigaben-plane-und-fragen)
- [Zusammenarbeiten](#zusammenarbeiten)
- [Der Code-Tab (VS Code)](#der-code-tab-vs-code)
- [Der Preview-Tab](#der-preview-tab)
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

## Threads

Die linke Hälfte der Workspace-Seite ist der Chat. Lege einen **Thread** an
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

## Der Code-Tab (VS Code)

Die rechte Hälfte zeigt **VS Code** (OpenVSCode Server) auf dem Workspace-Volume.
Was du änderst, sieht Claude sofort — und umgekehrt.

Der Zugriff auf den Editor-Host wird bei jeder Anfrage gegen die Mitgliedschaft
im Workspace geprüft: Die Workspace-Seite gibt deinem Browser ein kurzlebiges
Ticket, das gegen ein Cookie auf dem Editor-Host getauscht wird. Browser senden
dieses Cookie in einem eingebetteten Frame nur, wenn der Editor-Host zur selben
Site wie Argos gehört (z. B. `argos.example.com` und
`code-x.argos.example.com`). In anderen Setups — etwa dem lokalen Standard
`127.0.0.1` / `nip.io` — bietet der Tab stattdessen **VS Code öffnen** in einem
eigenen Browser-Tab an; der Zugriff funktioniert genauso.

## Der Preview-Tab

Die Preview ist eine [Live-Demo](LIVE-DEMOS.md) des Workspaces: derselbe
Demo-Vertrag (`.argos/demo.*` oder der eingebaute Laravel-Standard), sie mountet
aber das **Workspace-Volume** und zeigt so immer die aktuellen Dateien. Sie ist
nur für Mitglieder zugänglich (Zugriffsmodus `workspace`) und wird bei Bedarf
gestartet.

- **Preview starten** baut sie, **Neu bauen** ersetzt sie.
- **Setup-Befehle erneut ausführen** führt die Befehle des Vertrags in der
  laufenden Preview erneut aus — nach einer Migration, einer
  `composer`-/`npm`-Änderung oder einer geänderten Build-Konfiguration.
  PHP-Änderungen erscheinen auch ohne.
- Die Befehle laufen als Agent-Benutzer des Workspaces, damit die Dateien, die
  sie anlegen (z. B. `vendor/`), bearbeitbar bleiben.
- Die TTL der Previews und das plattformweite Limit gleichzeitiger Live-Demos
  gelten.

Previews brauchen `ARGOS_PREVIEW_ENABLED` und den Schalter *Live-Demo* im
Projekt.

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

Alle Einstellungen stehen unter [Konfiguration](CONFIGURATION.md#workspaces).
