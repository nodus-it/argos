# Argos Worker

Dockerisierter Worker, der eine einzelne Dev-Aufgabe phasenweise und isoliert vom Host ausführt. Steuerung über die Web-UI (Laravel/Filament) oder das `./agent`-CLI.

## Mission

Das Tool nimmt entgegen: Git-Remote + Repo-Token + Base-Branch + Aufgaben-Beschreibung. Es führt fünf Phasen durch — `concept`, `implement`, `diff`, `push`, `respond` — wobei zwischen den Phasen menschliche Approval-Gates möglich sind. Phasen sind wiederholbar. Ergebnis: ein Feature-Branch mit PR ist auf der Remote, Review-Feedback wird in weiteren Iterationen eingearbeitet.

## Aktueller Umfang

- Task-Steuerung über Web-UI (`/admin/tasks`) und `./agent`-CLI
- PR-Erstellung via GitHub REST API nach Push, concept.md als PR-Body
- Review-Feedback über die UI in den Branch einarbeiten (respond-Phase)
- SQLite-basierte Datenbank für Task- und Phasen-State in der Web-UI
- Kein automatisches Abfragen von Issues/Tickets — Aufgaben werden manuell angelegt
- Keine Multi-Repo-Tasks
- Kein DB-Sidecar — Projekte mit DB-Anforderungen wechseln im Worker auf SQLite (Boost-Strategie)

## Sicherheits-Modell

- **Volume pro Task.** Jeder Task bekommt ein eigenes Docker-Volume `task_ws_<task-id>`. Dynamisch erstellt beim `agent task new`, gelöscht beim `agent abort` oder optional beim `agent push`.
- **Vollständige Trennung vom Host-Filesystem.** Kein Bind-Mount in Code-Verzeichnisse des Users. Worker sieht nur seine eigenen Volumes.
- **Repo-Token mit minimalen Rechten.** Pro Task ein Fine-grained GitHub PAT (oder vergleichbar) mit Rechten nur auf das eine Repo. Token liegt auf dem Host in `~/.agent/tasks/<task-id>/credentials.env` (mode 600), wird pro Phase als Env-Variable reingereicht — niemals im Image, niemals im Volume persistiert.
- **Claude-Auth via OAuth-Token.** User generiert einmal `claude setup-token` auf dem Host, Token landet in `~/.agent/claude_oauth_token` (mode 600), wird pro Phase als `CLAUDE_CODE_OAUTH_TOKEN` reingereicht.
- **Egress offen.** Keine Netzwerk-Restriktionen — Worker muss zu Claude, GitHub, Composer, npm.
- **Path-Hardening.** Worker validiert alle Pfad-Operationen mit `realpath` gegen das Workspace-Root des Tasks.

## Komponenten-Übersicht

```
argos/                              # Repo-Root (= Laravel-Projekt-Root)
├── agent                           # Haupt-CLI (Bash)
├── app/, config/, resources/, ...  # Laravel Web-UI (Filament)
├── artisan, composer.json, ...     # Laravel-Standard-Dateien
├── docker-compose.yml
├── worker/                         # Docker-Worker (Bash)
│   ├── docker/
│   │   ├── Dockerfile              # Worker-Image (multi-stage)
│   │   └── worker-entrypoint.sh    # Phase-Dispatcher im Container
│   ├── lib/                        # Bash-Library
│   │   ├── tasks.sh
│   │   ├── credentials.sh
│   │   ├── docker.sh
│   │   ├── state.sh
│   │   ├── lock.sh
│   │   ├── logging.sh
│   │   └── result.sh
│   ├── phases/                     # Phase-Skripte
│   │   ├── registry.sh
│   │   ├── concept.sh
│   │   ├── implement.sh
│   │   ├── diff.sh
│   │   ├── push.sh
│   │   ├── respond.sh
│   │   └── commit-message.sh
│   ├── prompts/                    # System-Prompt-Templates
│   │   ├── concept.system.md
│   │   ├── implement.system.md
│   │   ├── respond.system.md
│   │   ├── commit-message.system.md
│   │   └── user.global.system.md   # User-globale Konventionen
│   ├── schemas/                    # JSON-Schemas
│   └── tests/                      # Bash/Bats-Tests
└── tests/, docs/, .github/workflows/
```

Auf dem Host wird zusätzlich angelegt:

```
~/.agent/                           # User-State, nicht im Repo
├── claude_oauth_token              # mode 600
├── tasks/
│   └── <task-id>/
│       ├── credentials.env         # mode 600, REPO_URL+REPO_TOKEN+BASE_BRANCH
│       └── description.md          # Task-Beschreibung, mode 644 (kein Secret)
└── config                          # globale Settings (Editor-Präferenz etc.)
```

## Phasen

### Phase `concept`

**Zweck:** Aufgabe analysieren, Plan formulieren.

**Vorbedingungen:** Task existiert, Volume vorhanden, `~/.agent/tasks/<task-id>/description.md` vorhanden.

**Task-Beschreibung:** Liegt auf dem Host unter `~/.agent/tasks/<task-id>/description.md` (vom User in `agent task new` via `$EDITOR` erstellt). Wird bei jedem Phase-Run als read-only Bind-Mount auf `/run/agent/description.md` (NICHT in `/workspace`, sondern bewusst außerhalb — sonst legt Docker das parent-dir als root an und blockiert Schreibzugriff vom `agent`-User auf das Volume) im Container verfügbar. Die Datei kann auf dem Host jederzeit mit dem normalen Editor inspiziert werden — keine Container-Indirektion nötig.

**Verhalten:**
- Erstes Mal: Repo wird in das Task-Volume geklont (`/workspace`), Branch erstellt, Claude-Session startet mit System-Prompt aus den Prompt-Templates plus der Task-Beschreibung. Output: `/workspace/.agent/concept.md`.
- Wiederholung (Default, inkrementell): Vorheriges Konzept + ggf. Anmerkungen werden mitgeladen, Claude überarbeitet. Vorherige Version landet in `/workspace/.agent/concept.history/concept.<timestamp>.md`.
- Wiederholung mit `--fresh`: Vorheriges Konzept wird ins history-Verzeichnis verschoben, neuer Konzept-Lauf von vorne mit nur der Original-Aufgabe.

**Anmerkungen einbringen:**
- `agent edit-concept <task-id>` öffnet `/workspace/.agent/concept.md` in `$EDITOR` (siehe „Editor-Integration").
- Alternativ: User legt parallel eine Datei `/workspace/.agent/concept.notes.md` an. Der nächste `concept`-Lauf liest sie als zusätzlichen Input und verschiebt sie nach Verarbeitung in `concept.history/`.

### Phase `implement`

**Zweck:** Code-Änderungen durchführen, Quality-Gates eigenständig durchlaufen.

**Vorbedingungen:** `concept`-Phase ist mindestens einmal erfolgreich gelaufen.

**Verhalten:**
- Default `--fresh`: `git reset --hard origin/<base-branch>` und `git clean -fd` (ohne `-x`, behält `vendor/` und `node_modules/`), dann Toolchain-Setup (`composer install`, `npm ci` falls vorhanden), dann Claude-Session.
- Mit `--continue`: kein Reset. Aufbau auf bestehenden uncommitted Änderungen.

Claude erhält den Implement-System-Prompt mit der Anweisung, **Pint und Tests selbständig auszuführen und Failures zu beheben bis grün**. Das ist der primäre Quality-Loop.

**Verifikations-Phase nach Claude-Session** (Worker-Entrypoint):
- Pint, Pest/PHPUnit, optional PHPStan werden nochmal ausgeführt
- Bei rotem Status: Phase ist `quality_gate_failed`, Workspace bleibt im aktuellen Stand für `agent implement --continue` mit Failure-Output als Notes
- Bei grünem Status: Phase ist `completed`

**Logs:** vollständiger stdout/stderr von Claude und Quality-Gates landen in `/workspace/.agent/logs/`.

### Phase `diff`

**Zweck:** Änderungen sichten vor dem Push.

**Vorbedingungen:** `implement` ist mit `completed` gelaufen.

**Verhalten:**
- Liest `git diff origin/<base-branch>...HEAD` plus `git status` aus dem Volume
- Gibt strukturiert auf stdout aus, mit Farb-Codierung wenn ein TTY angeschlossen ist
- Optional `agent diff <task-id> --stat` für Kurzfassung
- Optional `agent diff <task-id> --file=<path>` für nur einen File

Read-only, ändert keinen State. Beliebig oft aufrufbar.

### Phase `push`

**Zweck:** Branch zur Remote pushen.

**Vorbedingungen:**
- `implement` ist mit `completed` gelaufen
- Es gibt nicht-leere Änderungen

**Verhalten:**
- Ruft Sub-Phase `commit-message` auf (kurze Claude-Session, JSON-Output mit `subject` und `body`)
- `git add -A && git commit -m "<subject>" -m "<body>"`
- `git push -u origin <branch>`

Nach erfolgreichem Push fragt das CLI: „Workspace löschen? [y/N]". Bei `y` wird das Volume entfernt und Task-State unter `~/.agent/tasks/<task-id>` gelöscht. Default `N`.

## Wiederholbarkeit & State-Tracking

Im Workspace-Volume liegt unter `/workspace/.agent/state.json` ein Status-File mit allen Iterationen pro Phase, ihren Statuses, Timestamps. Schema in `worker/schemas/state.schema.json`.

`agent status <task-id>` liest dieses File und zeigt es schön formatiert. `agent status` (ohne Argument) listet alle Tasks.

## Phase-Erweiterbarkeit

Phasen sind nicht hartcodiert in der CLI, sondern als einzelne Skripte unter `worker/phases/<name>.sh` definiert. Jede Phase liefert:

- Eine Funktion `phase_<name>_run` — der eigentliche Code
- Eine Funktion `phase_<name>_preconditions` — gibt 0 zurück wenn OK, sonst Exit-Code + Fehlermeldung auf stderr
- Eine Funktion `phase_<name>_help` — kurze Beschreibung für `agent help <phase>`

Die `worker/phases/registry.sh` listet alle aktiven Phasen in Reihenfolge. Neue Phase hinzufügen = neues Skript + Eintrag in Registry. Vorhandene Phase ändern = nur das eine Skript.

Beispiele für spätere Phasen:
- `analyze` — pre-concept Repo-Inspektion
- `revise` — gezielter Edit ohne Reset
- `pr` — PR erstellen (Iteration 2)
- `respond` — auf PR-Feedback antworten (Iteration 2)

## CLI-Reference (`agent`)

### Setup

```bash
./agent init
# Baut Worker-Image, erstellt persistente Volumes (composer_cache, npm_cache),
# fragt nach CLAUDE_CODE_OAUTH_TOKEN, fragt nach Symlink in ~/.local/bin/agent.
```

### Task-Lifecycle

```bash
./agent task new <task-id>
# Interaktiv: REPO_URL, REPO_TOKEN (versteckte Eingabe), BASE_BRANCH,
# Task-Beschreibung (Editor-Aufruf für mehrzeilige Texte).
# Erstellt Volume task_ws_<task-id>, schreibt credentials.env (mode 600)
# und description.md (mode 644) unter ~/.agent/tasks/<task-id>/.

./agent task list                    # Listet alle Tasks mit Status.
./agent task show <task-id>          # Zeigt Konfiguration des Tasks (ohne Token).
./agent task delete <task-id>        # Identisch zu `agent abort`.
```

### Phasen

```bash
./agent concept <task-id> [--fresh]
./agent implement <task-id> [--fresh|--continue] [--max-turns=N]
./agent diff <task-id> [--stat] [--file=<path>]
./agent push <task-id> [--auto-cleanup|--keep]
```

### Inspection & Editing

```bash
./agent show-concept <task-id>
./agent edit-concept <task-id>
./agent show-notes <task-id>
./agent edit-notes <task-id>
./agent logs <task-id> [--phase=<phase>] [--iteration=N]
./agent shell <task-id>              # Bash im Worker-Container, Volume gemountet
./agent status [<task-id>]
```

### Aufräumen

```bash
./agent abort <task-id>
./agent prune                        # Verwaiste Volumes finden + cleanen
```

## Editor-Integration

Konzept und Notes liegen im Volume, nicht auf dem Host — direktes Öffnen im Editor geht nicht.

Lösung: `agent edit-concept` macht
1. Container ausführen, der Datei aus Volume nach `/tmp/agent-edit-<random>.md` kopiert
2. `$EDITOR /tmp/agent-edit-<random>.md`
3. Container ausführen, der die Datei ins Volume zurückschreibt — mit Hash-Vergleich der Vorgänger-Version, um konkurrierende Änderungen zu erkennen
4. Tempdatei löschen

## Outputs & Artefakte

Jede Phase produziert:

1. **Files im Volume** unter `/workspace/.agent/`:
   - `concept.md`, `concept.history/`, `concept.notes.md`
   - `quality-gates.<n>.json`
   - `logs/<phase>.<n>.log`, `logs/entrypoint.<n>.log`
   - `state.json`

2. **Result-JSON auf stdout** (eine Zeile, am Ende der Phase, Schema in `worker/schemas/`). CLI pretty-printed das automatisch; mit `--json`-Flag kommt rohes JSON.

3. **Exit-Code:**
   - `0` — Phase erfolgreich
   - `2` — Vorbedingung nicht erfüllt
   - `3` — Auth-Problem (Claude oder Repo-Token)
   - `4` — Quality-Gate-Failure
   - `5` — keine Änderungen
   - `6` — Lock vorhanden (parallele Phase läuft bereits)
   - `1` — sonstiger Fehler

## Container-Aufbau

### Image

Multi-Stage-Dockerfile, finale Stage `worker`:
- Basis: `php:8.3-cli-bookworm`
- Tools: `git`, `gh`, `curl`, `unzip`, `jq`, `bash`, `coreutils`, `vim`, `nano`
- PHP-Extensions: `mbstring`, `intl`, `pdo_sqlite`, `pdo_mysql`, `redis`, `zip`, `bcmath`, `gd`, `xml` (Laravel-Standard)
- `composer` (latest stable)
- `node` 20 + `npm`
- `@anthropic-ai/claude-code` global via npm
- Non-root User `agent` mit Home `/home/agent`
- Entrypoint: `/usr/local/bin/worker-entrypoint.sh`

### Volumes

| Volume | Mount | Zweck | Lebensdauer |
| --- | --- | --- | --- |
| `composer_cache` | `/home/agent/.composer/cache` | Composer-Pakete | Permanent, geteilt |
| `npm_cache` | `/home/agent/.npm` | npm-Pakete | Permanent, geteilt |
| `task_ws_<task-id>` | `/workspace` | Task-Workspace | Pro Task, dynamisch |

Kein `claude_auth`-Volume — Auth läuft über `CLAUDE_CODE_OAUTH_TOKEN`-Env.

### Ports

Keine, der Worker exponiert nichts nach außen.

### Resource Limits

Default in Compose: 4 GB RAM, 2 CPUs. Überschreibbar pro `agent`-Aufruf via `AGENT_MEM_LIMIT`/`AGENT_CPU_LIMIT`.

## Boost-Strategie

Du nutzt Laravel Boost in deinen Projekten. Für v1:

- Worker hat keinen DB-Sidecar.
- Implement-Prompt instruiert Claude, bei Bedarf temporär auf SQLite umzuschalten (`.env` ist gitignored, Änderungen landen nicht im Commit).
- Boost-MCP-Server wird automatisch von Claude Code geladen wenn `.mcp.json` im Repo existiert (Standard bei Boost-Projekten).
- Risiko: wenn `composer install` Post-Install-Hooks hat die DB-Migrations triggern und auf MariaDB hartcodieren, kann das fehlschlagen. Dann müssen wir in v2 doch einen Sidecar einbauen.

## Verwendungs-Walkthrough

```bash
# Einmalig
npm install -g @anthropic-ai/claude-code
claude setup-token   # Token notieren
./agent init         # Token eingeben, Image bauen

# Pro Task
./agent task new task-001
# (interaktive Eingaben)

./agent concept task-001
./agent show-concept task-001
./agent edit-concept task-001    # optional Anmerkungen
./agent concept task-001         # inkrementelle Verfeinerung

./agent implement task-001
./agent diff task-001
./agent implement task-001 --continue   # falls noch was zu fixen ist

./agent push task-001
```

## Erweiterungspfad

- **Iteration 2**: `pr`- und `respond`-Phasen, Worker bleibt gleich, nur neue Phase-Skripte
- **Iteration 3**: Orchestrator-Container der `agent`-CLI selbst aufruft oder die Phase-Skripte direkt importiert; Polling von Task-Quellen
- **Iteration 4**: UI (Filament), Multi-Provider, alles weitere

Die `agent`-CLI bleibt der zentrale Einstieg. Sie wird in späteren Iterationen Subcommands für Konfiguration, Source-Verbindung etc. bekommen, aber ihre Existenz und Form bleiben stabil.
