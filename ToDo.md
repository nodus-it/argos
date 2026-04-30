# ToDo — naechste Schritte

Stand: alle 10 Bootstrap-Schritte abgeschlossen, ~33 Commits, 88 Bats-Tests + 1 Integration-Test gruen. Es fehlt nur noch der manuelle End-to-End-Akzeptanztest.

## Sofort: Schritt 11 — End-to-End-Akzeptanztest

### Vorbereitung (auf dem Host, einmalig)

- [ ] Test-Repo auf GitHub anlegen (privat OK)
  - frisches Laravel-Projekt: `composer create-project laravel/laravel my-agent-test`
  - Pint + Pest sicherstellen: `composer require --dev laravel/pint pestphp/pest`
  - initial commit auf `main` pushen
- [ ] Fine-grained PAT erzeugen
  - Scope: nur dieses eine Test-Repo
  - Permissions: `Contents: read and write`, `Workflows: read` (falls Actions im Test-Repo aktiv sind)
  - Token sicher kopieren (wird gleich abgefragt)
- [ ] `agent init` durchgelaufen, Symlink optional

### Akzeptanztest-Lauf (gemaess V1-DONE.md)

- [ ] **Schritt 1 — Setup von 0:** `claude setup-token`, Repo clonen, `./agent init` (falls noch nicht)
- [ ] **Schritt 2 — Test-Repo + PAT** (siehe Vorbereitung)
- [ ] **Schritt 3 — `./agent task new demo-helloworld`**
  - REPO_URL, REPO_TOKEN (versteckt), BASE_BRANCH=main
  - Description-Text aus `docs/EXAMPLE.md` Abschnitt 1 uebernehmen
  - Verifikation: `~/.agent/tasks/demo-helloworld/credentials.env` (mode 600), `description.md` (mode 644), Volume `task_ws_demo-helloworld` existiert
- [ ] **Schritt 4 — `./agent concept demo-helloworld`**
  - Verifikation: `concept.md` enthaelt `app/Demo/HelloWorld.php` + Test-File
  - `./agent show-concept demo-helloworld` zeigt das Konzept
- [ ] **Schritt 5 — `./agent implement demo-helloworld`**
  - Live-Output (stream-json) ist sichtbar, Claude legt Files an, Pint+Pest laufen
  - Endstatus `completed`, `quality_gates: {pint:pass, pest:pass}`
- [ ] **Schritt 6 — `./agent diff demo-helloworld`**
  - zwei neue Files lesbar mit Farben (TTY)
- [ ] **Schritt 7 — `./agent push demo-helloworld`**
  - commit-message Sub-Phase laeuft
  - Branch `ai/demo-helloworld-<timestamp>` erscheint auf der Remote
  - Commit ist Conventional-Commits-Style
- [ ] **Schritt 8 — Auf GitHub im Browser pruefen**
  - Branch sauber, Commit-Subject sinnvoll, Diff enthaelt die zwei Files
- [ ] **Schritt 9 — Cleanup mit `y`**
  - `./agent task list` zeigt Task nicht mehr
  - `~/.agent/tasks/demo-helloworld/` weg, Volume `task_ws_demo-helloworld` weg

Alle 9 gruen → `chore: v1 ready` als finaler Commit.

### Was beim ersten Lauf wahrscheinlich schief gehen wird

- **Punkt 1 / commit-message --json-schema-Format**: defensives Parsing (`.structured_output` zuerst, Fallback `.result | fromjson`). Sobald der reale Pfad sichtbar ist:
  - [ ] In `phases/commit-message.sh::_commit_message_extract` den nicht-genutzten Zweig entfernen (oder explizit dokumentieren falls beide Pfade real auftreten)
  - [ ] `IMPLEMENTATION.md` Abschnitt 1.3 — den "Format-Caveat"-Block durch finale Beschreibung ersetzen
- **Composer post-install hooks**: wenn das Test-Repo einen Post-Install-Hook hat der DB-Migrations triggert, kann `composer install` failen → User entfernt den Hook im Test-Repo oder schaltet auf SQLite (siehe `docs/TROUBLESHOOTING.md`)
- **Pest-Konfiguration im Mini-Repo**: `tests/Pest.php` und `tests/TestCase.php` muessen vorhanden sein, sonst pest fail. Vor dem `agent task new` einmal lokal `vendor/bin/pest` im Test-Repo laufen lassen.

### Was zu tun ist wenn ein Schritt fehlschlaegt

1. Output (~letzte 30 Zeilen) und Fehlermeldung kopieren
2. `./agent shell <task-id>` falls weitere Diagnose im Container noetig
3. `./agent logs <task-id> --phase=<phase> --iteration=<n>` fuer detaillierte Logs
4. Fix in `lib/`, `phases/`, oder Spec
5. Bei API-/Format-Aenderungen: Spec im selben oder Folge-Commit aktualisieren (CLAUDE.md-Konvention)
6. Schritt wiederholen
7. Bei `quality_gate_failed`: `agent implement <task-id> --continue` versuchen statt komplett neu starten

## Polishing waehrend Schritt 11 (kosmetisch, nice-to-have)

- [ ] `changed_files`-Liste in implement-Result enthaelt Verzeichnisse (z.B. `app/Demo/`) statt einzelne Files. `git status --porcelain` listet untracked Verzeichnisse als ein Eintrag. Fix: `git ls-files --others --exclude-standard` oder rekursives Listing.
- [ ] `--continue`-Pfad: Failure-Logs der letzten implement-Iteration explizit als Abschnitt im naechsten User-Prompt mit-einblenden (Implement-Phase-System-Prompt referenziert sie schon).
- [ ] Cost-Tracking: aktuell wird `total_cost_usd` nur pro Iteration in den Logs gespeichert, nicht pro Task summiert. Ist im BACKLOG als "Cost-Tracking pro Task" als Quick-Win nach v1.
- [ ] Bats-Test fuer `cmd_phase` und `_agent_phase_flags_json` im `agent`-CLI (aktuell nur durch den Integration-Test indirekt abgedeckt).

## Nach v1 (siehe BACKLOG.md)

Nicht Teil von Schritt 11. Erst angehen, wenn der Akzeptanztest gruen ist.

- Quick Wins (Cost-Tracking, `agent prompt show`, `agent rebuild`)
- Iteration 2: `pr`-Phase, `respond`-Phase, `analyze`-Phase, `revise`-Phase, DB-Sidecar
- Iteration 3: Orchestrator + Polling
- Iteration 4: UI, Multi-Account, API-Mode, weitere Toolchains/VCS

## Erinnerungen fuer den Akzeptanztest

- v1 erstellt **keinen PR** — du machst den manuell auf GitHub. PR-Erstellung kommt in Iteration 2.
- v1 nutzt nur **Subscription-OAuth** (kein API-Key). Pro `implement`-Iteration ein paar Cents Quota.
- v1 hat **keine Multi-Repo-Tasks** und **keine DB-Sidecars**. Wenn das Test-Projekt eine echte DB braucht: SQLite-Override im `.env` (Implement-Prompt instruiert Claude entsprechend).
- Branch-Strategie waehrend Bootstrap: alles auf `master`. Erst nach v1-Ready-Commit folgen wir Conventional-Branching.
