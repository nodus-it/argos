# Workspaces

Ein **Workspace** ist ein Projekt in Argos: ein Repository mit allem, was es
zum Laufen braucht — Default-Branch, Branch-Muster, Stack, Backing Services
(MySQL / Redis), Umgebungs-Secrets, Composer-Registries, Live-Preview,
Grenzen, Automatik-Standardwerte, Issue-Tracker-Integrationen, API-Tokens und
Mitglieder. Er läuft in **einem Container**, der das Repository, den Editor
und die Claude-Sessions all seiner [Aufgaben](TASKS.md) enthält.

Jede Unterhaltung mit Claude in einem Workspace ist eine **Aufgabe** mit
eigenem Besitz, eigenem Branch, eigener Arbeitskopie, Datenbank, Preview und
eigenem Pull Request — siehe [Aufgaben](TASKS.md). Diese Seite behandelt den
Workspace selbst: wer was darf, wie ein Workspace angelegt wird, den Bereich
Aufbau und Adressen der App, den Container und seine Grenzen.

## Inhalt

- [Was du brauchst](#was-du-brauchst)
- [Die App und ihre Adressen](#die-app-und-ihre-adressen)
- [Rollen](#rollen)
- [Einen Workspace anlegen](#einen-workspace-anlegen)
- [Einstellungen](#einstellungen)
- [Die Workspace-Seite](#die-workspace-seite)
- [Der Container](#der-container)
- [Einen Workspace löschen](#einen-workspace-loschen)
- [Sicherheit](#sicherheit)
- [Konfiguration](#konfiguration)

## Was du brauchst

- Den regulären Argos-Stack (siehe [Einrichtung](SETUP.md)) einschließlich des
  Dienstes `reverb` — er bringt die Live-Updates in den Browser.
- **Wildcard-DNS** für die Preview-Basisdomain: Der Editor eines Workspaces
  liegt unter `code-<workspace>.<base-domain>`, die Preview einer Aufgabe unter
  `demo-<workspace>-<task>.<base-domain>` (auf 63 Zeichen gekürzt). Lokal
  braucht der Standard `*.127.0.0.1.nip.io` einen Resolver, der nip.io
  erreicht.
- Einen eigenen **Agenten** (dein Claude-Abo) oder einen geteilten — siehe
  [Agenten](AGENTS.md). Ohne einen kannst du Aufgaben lesen, aber keine
  starten.

## Die App und ihre Adressen

Die App nutzt die volle Breite, mit einer schmalen **Leiste** links statt einer
Kopfleiste. Jeder Workspace und jede Aufgabe hat eine lesbare Adresse aus
ihrem Namen:

| Adresse | Zeigt |
|---|---|
| `/` | Meine Arbeit |
| `/workspaces` | Alle Workspaces |
| `/new` | Workspace anlegen |
| `/shop-api` | Den Workspace „Shop API" (seine letzte Aufgabe) |
| `/shop-api/fix-login` | Seine Aufgabe „Fix login" |
| `/shop-api/settings` | Seine Einstellungen |

Die Adresse eines Workspaces entsteht beim Anlegen aus seinem Namen; ist der
Name schon vergeben, kommt eine Zahl dazu (`shop-api-2`). Namen, die die App
selbst nutzt (`docs`, `account`, `system`, `api`, `login`, …), bekommen
ebenfalls eine. Links von früher (`/w/workspaces/…?task=…`, `/admin/…`)
führen weiterhin an die richtige Stelle.

Die Leiste:

- **Meine Arbeit** (`/`, die Startseite) — deine eigenen Aufgaben und
  die, zu denen du eingeladen wurdest, über alle Workspaces: zuerst *Wartet auf
  dich*, dann *Läuft*, *PR offen* und *Bereit* (die zuletzt aktiven bereiten
  Aufgaben; der Rest wird gezählt). Jede Zeile zeigt Workspace, Besitz, Ticket
  und Pull Request.
- **Workspaces** als Kurznamen, jeweils mit einem Badge, das die Aufgaben
  zählt, die dort auf dich warten; *Alle Workspaces* öffnet die Liste.
- **Administration** (`/system`, das Zahnrad) — nur für Argos-Admins:
  Übersicht, Benutzer, Access-Tokens, OAuth-Apps, API-Clients.
- Die Benachrichtigungsglocke und dein **Avatar** mit dem Benutzermenü: **Mein
  Konto** (`/account` — Profil mit Name, Avatar, Sprache, Benachrichtigungen,
  Zustimmung zu automatischen Aufgaben und Passwort; deine Claude-Abos;
  verknüpfte Accounts), **Dokumentation** (`/docs`), **Administration**
  (Argos-Admins), helles/dunkles Design, **Feedback**, Quellcode und Version,
  Abmelden.

Ein Login (`/login`) gilt für die ganze App. Auf Smartphones wandert die
Leiste nach unten.

## Rollen

Jeder Workspace hat **Mitglieder**, jedes mit einer Rolle:

| Rolle | Darf |
|---|---|
| Admin (`admin`) | alles im Workspace: Einstellungen, Secrets, Integrationen, Mitglieder, die Einrichtungs-Aufgabe und jede Aufgabe |
| Mitglied (`member`) | Aufgaben starten, VS Code öffnen, den Container starten/stoppen |
| Leser (`viewer`) | jede Aufgabe lesen; in Aufgaben arbeiten, zu denen die Person eingeladen ist |

Zusätzlich zur Rolle hat jede Aufgabe ihre eigenen Personen:

- Die Person, der die Aufgabe **gehört** — die Aufgabe läuft auf ihrem
  Claude-Abo. Nur sie (und Workspace-Admins) darf Mitarbeitende einladen, die
  Aufgabe übergeben, abbrechen, archivieren oder löschen.
- **Mitarbeitende** — Workspace-Mitglieder, die von der Person, der die
  Aufgabe gehört, eingeladen wurden. Sie schreiben in die Aufgabe, entscheiden
  über Freigaben, senden Review-Kommentare und liefern aus. Wer die Rolle Leser
  hat und eingeladen wird, arbeitet in dieser einen Aufgabe mit (bekommt aber
  weiterhin keinen Editor).

Alle in einem Workspace sehen alle seine Aufgaben — nur lesend, sofern ihnen
die Aufgabe nicht gehört oder sie nicht mitarbeiten. **Argos-Admins** dürfen
in jedem Workspace alles. Jede Aktion prüft eine benannte Berechtigung
(`task.write`, `task.approve`, `task.ship`, `task.review`, `task.invite`,
`task.transfer`, `task.archive`, `workspace.manage`, `workspace.setup`,
`workspace.editor`, …); heute sind die Rollen festen Sätzen davon zugeordnet.

Mitglieder verwalten Workspace-Admins unter **Einstellungen → Mitglieder**
(hinzufügen, Rolle ändern, entfernen; ein Workspace behält mindestens einen
Admin). Weitere Personen außer der anlegenden hinzuzufügen erfordert
`ARGOS_WORKSPACE_COLLABORATION` (standardmäßig an). Wird eine Person entfernt,
bleiben die Aufgaben, die ihr gehören — übergib sie, wenn jemand weitermachen
soll.

## Einen Workspace anlegen

Nur **Argos-Admins** legen Workspaces an:

- mit **Neuer Workspace** in der Workspace-Liste (`/new`),
- im Einrichtungs-Assistenten (`/setup`, in seinem Repository-Schritt) oder
- über die REST-API: `POST /api/v1/workspaces` mit einem
  `workspaces:write`-Token eines API-Clients, der als Admin läuft (siehe
  [REST-API](REST-API.md#einen-workspace-anlegen)).

Das Formular beginnt mit der **Plattform** (GitHub, GitLab, Bitbucket), dann
folgen der Zugang (ein verbundenes OAuth-Konto oder ein Personal Access Token),
das Repository und sein Default-Branch sowie der Name. Die übrigen Tabs
(Umgebung, Ressourcen, Automatik) kannst du jetzt oder später ausfüllen —
siehe [Workspace-Einstellungen](WORKSPACE-SETTINGS.md). Wer den Workspace anlegt, wird
sein erster Admin; weitere Mitglieder fügst du danach in den Einstellungen
hinzu.

Direkt nach dem Anlegen **untersucht Argos das Repository** (auf seinem
Default-Branch):

| Gefunden | Ergebnis |
|---|---|
| `composer.json` | Stack PHP 8.5 — oder PHP 8.4, wenn die PHP-Constraint 8.4 festlegt, ohne 8.5 zu erlauben |
| `package.json` ohne `composer.json` | Stack Node 22 |
| `.argos/worker.dockerfile` | Das eigene Image des Repositorys |
| `composer.json` + `artisan` (Laravel) | Einrichtungsschritte und Preview-Vertrag fallen auf die Laravel-Standards zurück — mit eigenem Image gilt der Preview-Vertrag als fehlend, da die Standard-Preview auf den PHP-Images von Argos läuft |

Fehlt nichts, wird der Stand der Einrichtung *Eingerichtet* und der Workspace
ist bereit. Fehlen der Stack, der Preview-Vertrag (`.argos/demo.yml`) oder die
Einrichtungsschritte (`.argos/setup.sh`), startet Argos eine
**Einrichtungs-Aufgabe** auf dem Abo der Person (Admin), die den Workspace
anlegt, in der Claude sie schreibt — siehe
[Ein Repository vorbereiten → Die Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe).
Ohne nutzbares Claude-Abo ist der Stand *Braucht ein Claude-Abo*; hinterlege
eines und wähle in den Einstellungen **Einrichtung überarbeiten**.

## Einstellungen

Workspace-Admins (und Argos-Admins) öffnen die **Workspace-Einstellungen** über
den Kopf des Workspaces oder unter `/{workspace}/settings`. Die Tabs:

| Tab | Was er enthält |
|---|---|
| Allgemein | Name, Repository (fest), Default-Branch, Branch-Muster |
| Umgebung | Image-Quelle und Stack, Backing Services, Live-Preview, Composer-Registries, Environment-Variablen; Stand der Einrichtung, letzter Test, Image und Build-Log, **Neu bauen** und **Einrichtung überarbeiten** |
| Ressourcen | Parallele Sessions, parallele Previews, CPU- und Arbeitsspeicher-Grenze, Aufräumen erledigter Aufgaben |
| Integrationen | Issue-Tracker (inkl. eigener Systeme) und API-Tokens |
| Automatik | Automatik-Nutzer, Plan-Modus, Auslieferungs-Modus, Gate-Fix-Runden, Budgets |
| Mitglieder | Personen und ihre Rollen |

Jedes Feld ist unter [Workspace-Einstellungen](WORKSPACE-SETTINGS.md) beschrieben.

## Die Workspace-Seite

Eine Workspace-Seite hat drei Teile:

- Einen **Kopf** mit dem Workspace, dem
  [Aufgaben-Wechsler](TASKS.md#der-aufgaben-wechsler) (**⌘J** / **Ctrl+J**; das **+** daneben legt eine neue Aufgabe an), einem
  Chip, wenn eine andere Aufgabe auf Freigabe wartet, der Person, der die
  aktuelle Aufgabe gehört, den Mitarbeitenden (mit Anwesenheit, **+** lädt
  ein), dem Ticket und dem **⋯**-Menü (übergeben, Ticket
  verknüpfen oder anlegen, Folge-Aufgabe, Rebase, abbrechen, löschen,
  Einstellungen, Tastenkürzel, starten/stoppen). **Committen** und **Pushen & PR**
  sitzen in der Aktionsleiste über dem Eingabefeld.
- Den **Chat** der aktuellen Aufgabe als zentrierte Spalte.
- Die **Seitenleiste** der Aufgabe: VS Code (der Ordner der Aufgabe), Preview,
  Branch, Pull Request, Quality Gates und geänderte Dateien — siehe
  [Aufgaben → Die Seitenleiste](TASKS.md#die-seitenleiste-editor-preview-branch).

Die Seite füllt das Fenster; nur die Unterhaltung scrollt. Auf Bildschirmen
schmaler als etwa 1100px wird die Seitenleiste zur Schublade.

## Der Container

Jeder Workspace läuft in einem Container mit:

- dem **Haupt-Klon** unter `/workspace/repo` (detached auf dem
  Default-Branch, nur eine Basis — niemand arbeitet darin),
- einem **Git-Worktree pro Aufgabe** unter `/workspace/tasks/<slug>`,
- gemeinsamen Composer- und npm-Caches,
- den in den Einstellungen aktivierten **Backing Services** als Sidecars in
  einem privaten Netz: eine MySQL-Datenbank **pro Aufgabe** (`task_<slug>`)
  und ein Redis-Key-Präfix pro Aufgabe (`<slug>_`),
- OpenVSCode Server und dem Workspace-Daemon.

| Status | Bedeutung |
|---|---|
| Wird eingerichtet / Startet | Der Container wird aufgesetzt (der erste Start baut das Image und kann einige Minuten dauern). |
| Läuft | Bereit zum Arbeiten. |
| Stoppt / Gestoppt | Der Container ist gestoppt; Dateien, Datenbanken und Aufgaben bleiben erhalten. Den Workspace zu öffnen oder eine Aufgabe zu starten startet ihn wieder. |
| Fehlgeschlagen | Der Start ist fehlgeschlagen — die Seite zeigt den Grund; **Starten** versucht es erneut. |

- **Starten:** Die Workspace-Seite zu öffnen (als Admin oder Mitglied), eine
  Aufgabe oder eine automatische Aufgabe vorzubereiten startet einen
  gestoppten Container.
- **Stopp bei Leerlauf:** Ein laufender Workspace ohne Aktivität für
  `ARGOS_WORKSPACE_IDLE_MINUTES` wird gestoppt — aber nie, solange eine
  Aufgabe vorbereitet wird, in der Session-Warteschlange wartet, arbeitet, auf
  eine Entscheidung wartet oder automatisch läuft.
- **Kapazität:** Höchstens `ARGOS_WORKSPACE_MAX_ACTIVE` Workspaces laufen
  gleichzeitig. Ein Start darüber hinaus wird abgelehnt; laufende Workspaces
  werden nie verdrängt.
- **Grenzen pro Workspace** (Einstellungen → Ressourcen): *Parallele Sessions*
  (Standard 3) — wie viele Aufgaben gleichzeitig mit Claude sprechen; weitere
  Nachrichten warten in der
  [Session-Warteschlange](TASKS.md#session-warteschlange). *Parallele Previews*
  (Standard 3) — eine weitere stoppt die älteste. CPU und Arbeitsspeicher
  gelten ab dem nächsten Container-Start (leer: `ARGOS_WORKSPACE_CPUS` /
  `ARGOS_WORKSPACE_MEMORY`).
- **Neustarts:** Sessions leben im Container. Startet er (oder sein Daemon)
  neu, schließt Argos die Sessions innerhalb von etwa einer Minute: Ein
  laufender Turn endet mit einem Hinweis, offene Entscheidungen verfallen, und
  Nachrichten, die Claude noch nicht gelesen hatte, werden als *Nicht
  zugestellt* mit **Erneut senden** markiert.
- **Ungepushte Arbeit:** Wird die Arbeitskopie einer Aufgabe entfernt
  (Aufräumen abgeschlossener Aufgaben, Löschen einer Aufgabe), sichert Argos
  vorher Commits und uncommittete Änderungen, die auf keinem Remote-Branch
  liegen, unter `refs/argos/backup/<slug>` im Haupt-Clone; der Verlauf der
  Aufgabe nennt die Referenz. Zum Weitermachen im Terminal des Editors
  auschecken (`git switch -c <branch> refs/argos/backup/<slug>`).

## Einen Workspace löschen

Nur Argos-Admins löschen einen Workspace (**⋯ → Workspace löschen**). Das
entfernt alle Aufgaben, ihre Worktrees, Datenbanken und Previews, den
Container und seine Volumes. Remote-Branches und Pull Requests bleiben;
Commits, die nie gepusht wurden, gehen verloren.

## Sicherheit

- Der Workspace-Daemon ist nur im internen Netz `argos_ws` und nur mit dem
  eigenen Token des Workspaces erreichbar. Datenbank und Redis von Argos sind
  nicht in diesem Netz.
- Der Claude-Token der Person, der eine Aufgabe gehört, wird pro Session
  übergeben und nur im Speicher gehalten; er landet nie auf der Platte oder in
  der Umgebung des Containers. Claude selbst kann ihn im Container technisch
  auslesen — behandle einen Workspace also wie einen Rechner, auf dem du
  Claude Code ausführst.
- Der Repository-Token erreicht git über einen Credential-Helper und wird nie
  ins Repository geschrieben. Claude ist angewiesen, nicht zu pushen, kann es
  technisch aber.
- **Aufgaben sind nicht voneinander isoliert.** Claudes Datei-Werkzeuge
  arbeiten nur innerhalb des Worktrees der Aufgabe (und `/tmp`) frei;
  außerhalb fragen sie nach Freigabe, in automatischen Aufgaben werden sie
  abgelehnt. Shell-Befehle werden nicht geprüft. Alle Aufgaben teilen sich
  einen Container, ein Workspace ist also ein Vertrauensraum, keine
  Sicherheitsgrenze.
- Der Editor ist faktisch eine Shell in den Container: Nur Admins und
  Mitglieder kommen hinein (auch bei jeder Anfrage geprüft, über ein
  kurzlebiges Ticket, das auf dem Editor-Host gegen ein Cookie getauscht
  wird); wer die Rolle Leser hat, liest nur Aufgaben und öffnet Previews.

## Konfiguration

| Variable | Standard | Zweck |
|---|---|---|
| `ARGOS_WORKSPACE_COLLABORATION` | `true` | Ob Workspaces außer der anlegenden Person weitere Mitglieder und Aufgaben Mitarbeitende haben dürfen. |
| `ARGOS_WORKSPACE_MAX_ACTIVE` | `5` | Gleichzeitig laufende Workspaces. |
| `ARGOS_WORKSPACE_IDLE_MINUTES` | `60` | Leerlaufzeit, bevor ein Workspace gestoppt wird (`0` deaktiviert). |
| `ARGOS_WORKSPACE_CPUS` / `ARGOS_WORKSPACE_MEMORY` | `2` / `4g` | Standard-Grenzen pro Workspace-Container. |
| `ARGOS_WORKSPACE_PR_SYNC_MINUTES` | `3` | Wie oft offene Pull Requests von Aufgaben neu gelesen werden. |
| `ARGOS_WORKSPACE_UPLOAD_MAX_MB` | `5` | Größenlimit pro Bild, das an eine Nachricht angehängt wird. |

Alle Einstellungen stehen unter [Konfiguration](CONFIGURATION.md#workspaces).
