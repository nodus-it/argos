# Argos — Architektur & Konzept

Argos ist ein Web-First Dev-Agent. Er nimmt Aufgaben entgegen, arbeitet sie phasenweise in isolierten Worker-Containern ab und erstellt Pull Requests. Gesteuert wird alles über die Laravel/Filament-Web-UI oder per Artisan-CLI via `docker exec`.

## Mission

Git-Remote + Repo-Token + Base-Branch + Aufgaben-Beschreibung rein, Feature-Branch mit PR raus. Fünf Phasen — `concept`, `implement`, `diff`, `push`, `respond` — mit optionalen menschlichen Approval-Gates dazwischen. Phasen sind wiederholbar. Review-Feedback wird in weiteren Iterationen eingearbeitet.

## Zielarchitektur: Zwei Images, ein User-Container

Der Nutzer startet **einen einzigen Container** (`argos-manager`). Alles läuft darin: Web-UI, Datenbank, Queue-Worker. Wenn eine Phase ausgeführt wird, spawnt der Manager einen kurzlebigen Worker-Container — der Nutzer sieht das nicht.

```
Host
├── Docker Socket (/var/run/docker.sock)  ← in Manager gemountet
├── argos-data Volume (/data)              ← SQLite-DB + persistenter State
│
└── argos-manager Container (langlebig)
    ├── Laravel + Filament (Web-UI, Port 80)
    ├── Queue-Worker (database-Treiber, kein Redis)
    ├── Docker CLI (nur Client)           ← kein Daemon, nur Socket-Zugriff
    └── KEINE KI                          ← bewusst: Socket + AI = Isolation gebrochen
        │
        │ docker run --rm (pro Phase)
        ▼
    argos-worker Container (kurzlebig, pro Phase)
    ├── Claude Code CLI
    ├── PHP CLI + Node + Git + Tools
    ├── task_ws_<id> Volume (/workspace)
    ├── KEIN Docker Socket
    └── Credentials als Env-Vars (niemals persistent)
```

**Warum kein AI im Manager?** Der Manager-Container hat den Docker-Socket gemountet — damit kann er beliebige Container starten. Würde die KI dort laufen, hätte sie via Docker-Socket die Möglichkeit, aus ihrer Isolation auszubrechen. Deshalb läuft Claude Code ausschließlich im Worker, der keinen Socket-Zugriff hat.

**Warum getrennte Images?** Der Worker ist ein eigenständiges, versioniertes Artefakt. Spätere Varianten (`argos-worker-node`, `argos-worker-python`) können denselben Manager nutzen. Die Trennung erlaubt unabhängige Builds und Releases.

## Sicherheits-Modell

- **Socket im Manager, nicht im Worker.** Manager spawnt Worker via Docker-Socket. Worker bekommt den Socket niemals.
- **Volume pro Task.** Jeder Task bekommt ein eigenes Docker-Volume `task_ws_<task-id>`. Worker sieht nur sein eigenes Volume.
- **Credentials ephemer.** Repo-Token und Claude-Token werden pro Phase als Env-Variablen an `docker run -e` übergeben — niemals im Image, niemals im Volume persistiert. Im Manager-Container liegen sie verschlüsselt in der DB.
- **Kein Bind-Mount in Host-Verzeichnisse.** Worker sieht nur seinen Workspace, keine Host-Pfade.
- **Egress offen.** Keine Netzwerk-Restriktionen im Worker — muss Claude API, GitHub, Composer, npm erreichen.
- **Path-Hardening.** Worker validiert alle Pfad-Operationen mit `realpath` gegen `/workspace`.

## Komponenten-Übersicht

```
argos/                              # Repo-Root (= Laravel-Projekt-Root)
├── app/, config/, resources/, ...  # Laravel Web-UI (Filament) + Queue-Jobs
├── artisan, composer.json, ...     # Laravel-Standard
├── docker-compose.yml              # Lokale Entwicklung
├── docker/
│   └── Dockerfile                  # Manager-Image (PHP + Nginx + Supervisor)
└── worker/                         # Worker-Image
    ├── docker/
    │   ├── Dockerfile              # Worker-Image (PHP + Node + Claude Code)
    │   └── worker-entrypoint.sh    # Phase-Dispatcher im Container
    ├── lib/                        # Bash-Library
    │   ├── state.sh
    │   ├── lock.sh
    │   ├── logging.sh
    │   ├── result.sh
    │   └── prompts.sh
    ├── phases/                     # Phase-Skripte (Bash)
    │   ├── registry.sh
    │   ├── concept.sh
    │   ├── implement.sh
    │   ├── diff.sh
    │   ├── push.sh
    │   ├── respond.sh
    │   └── commit-message.sh
    ├── prompts/                    # System-Prompt-Templates
    │   ├── concept.system.md
    │   ├── implement.system.md
    │   ├── respond.system.md
    │   ├── commit-message.system.md
    │   └── user.global.system.md
    ├── schemas/                    # JSON-Schemas
    └── tests/                      # Bash/Bats-Tests
```

## State-Management

Die **Datenbank** (SQLite unter `/data/database.sqlite`) ist die primäre Quelle der Wahrheit für Task- und Phasen-State. Das Volume enthält den eigentlichen Workspace (Git-Repo, Code, Konzept, Logs).

Fluss:
1. PHP-Queue-Job startet Worker-Container via `docker run`
2. Worker schreibt `/workspace/.agent/state.json` (lokal im Volume) während der Phase
3. Nach Container-Exit liest PHP den State aus dem Volume (`docker run alpine cat …`) und schreibt ihn in die DB
4. Web-UI zeigt immer den DB-State

## Phasen

### Phase `concept`

**Zweck:** Aufgabe analysieren, Plan formulieren.

**Verhalten:**
- Erstes Mal: Repo klonen nach `/workspace`, Feature-Branch anlegen, Claude-Session mit System-Prompt + Task-Beschreibung. Output: `/workspace/.agent/concept.md`.
- Wiederholung (inkrementell): Vorheriges Konzept + ggf. Anmerkungen als Input, Claude überarbeitet. Alte Version in `concept.history/`.
- Mit `--fresh`: Vorheriges Konzept ins Archiv, neuer Lauf nur mit Original-Aufgabe.

**Anmerkungen:** Über die Web-UI (Concept-Seite des Tasks) oder via `concept.notes.md` im Workspace.

### Phase `implement`

**Zweck:** Code-Änderungen durchführen, Quality-Gates eigenständig durchlaufen.

**Verhalten:**
- Default (`--fresh`): `git reset --hard origin/<base-branch>` + `git clean -fd`, dann Toolchain-Setup (`composer install`, `npm ci`), dann Claude-Session.
- Mit `--continue`: Kein Reset, Aufbau auf bestehenden Änderungen.

Claude erhält den Implement-System-Prompt mit der Anweisung, **Pint und Tests selbständig auszuführen und Failures zu beheben bis grün**. Danach verifiziert der Worker-Entrypoint nochmals.

Bei rotem Quality-Gate-Status: Phase `quality_gate_failed`, Workspace bleibt erhalten für `--continue`.

### Phase `diff`

**Zweck:** Änderungen sichten vor dem Push. Read-only.

Liest `git diff origin/<base-branch>...HEAD` aus dem Volume. Wird in der Web-UI als Diff-Ansicht dargestellt. Via CLI: `docker exec argos php artisan agent:diff <task-id>`.

### Phase `push`

**Zweck:** Branch zur Remote pushen, PR erstellen.

Ruft Sub-Phase `commit-message` auf (kurze Claude-Session → `subject` + `body`), committed, pusht, erstellt PR via GitHub REST API (Body aus `concept.md`).

### Phase `respond`

**Zweck:** PR-Review-Feedback einarbeiten.

Feedback wird in der Web-UI eingegeben und als `/workspace/.agent/respond.feedback.md` ins Volume geschrieben. Claude-Session mit Feedback als Input, dann automatisch `diff` + `push`.

## Phase-Erweiterbarkeit

Phasen sind Bash-Skripte in `worker/phases/<name>.sh` mit drei Funktionen:
- `phase_<name>_run` — Hauptlogik
- `phase_<name>_preconditions` — Vorbedingungsprüfung (Exit 0 = OK)
- `phase_<name>_help` — Kurzbeschreibung

`worker/phases/registry.sh` listet alle aktiven Phasen. Neue Phase = neues Skript + Eintrag in Registry.

Auf PHP-Seite braucht jede Phase einen entsprechenden Artisan-Command und eine Queue-Job-Klasse (siehe `docs/EXTENDING.md`).

## Outputs & Artefakte

Jede Phase produziert:

1. **Files im Volume** unter `/workspace/.agent/`:
   - `concept.md`, `concept.history/`, `concept.notes.md`
   - `quality-gates.<n>.json`
   - `logs/<phase>.<n>.log`
   - `state.json`

2. **Result-JSON** (letzte Zeile stdout des Workers, Schema in `worker/schemas/`). PHP parst dieses nach Phase-Ende und schreibt relevante Felder in die DB.

3. **Exit-Codes:**
   - `0` — Phase erfolgreich
   - `2` — Vorbedingung nicht erfüllt
   - `3` — Auth-Problem (Claude oder Repo-Token)
   - `4` — Quality-Gate-Failure
   - `5` — keine Änderungen
   - `6` — Lock vorhanden (parallele Phase läuft bereits)
   - `1` — sonstiger Fehler

## Container-Aufbau

### Manager-Image (`argos-manager`)

- Basis: `php:8.4-fpm-bookworm` + Nginx
- Supervisor: php-fpm + nginx + `php artisan queue:work`
- PHP-Extensions: Standard Laravel-Set
- Docker CLI (nur Client, kein Daemon)
- Composer, Node (für Asset-Build), kein Claude Code
- Laravel-App + Filament

### Worker-Image (`argos-worker`)

- Basis: `php:8.4-cli-bookworm`
- Node 20 + npm
- `@anthropic-ai/claude-code` global via npm
- Git, gh, curl, jq, unzip, vim, nano
- PHP-Extensions: mbstring, intl, pdo_sqlite, zip, bcmath, xml
- Composer
- Non-root User `agent` (uid 1000), Home `/home/agent`
- Entrypoint: `/usr/local/bin/worker-entrypoint.sh`
- Kein Docker Socket, kein Netz-Limit

### Volumes

| Volume | Mount | Zweck | Lebensdauer |
|---|---|---|---|
| `argos-data` | `/data` | SQLite-DB, persistente App-Daten | Permanent |
| `composer_cache` | `/home/agent/.composer/cache` | Composer-Pakete | Permanent, geteilt |
| `npm_cache` | `/home/agent/.npm` | npm-Pakete | Permanent, geteilt |
| `task_ws_<id>` | `/workspace` | Task-Workspace | Pro Task, dynamisch |

## Deployment

### Produktion (ein Container)

```bash
docker run -d \
  --name argos \
  -p 8080:80 \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v argos-data:/data \
  -e CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-... \
  -e APP_KEY=base64:... \
  ghcr.io/nodus-it/argos:latest
```

Web-UI: `http://localhost:8080/admin`

CLI via exec:
```bash
docker exec -it argos php artisan agent:concept task-001
```

### Lokale Entwicklung

```bash
docker compose up          # startet manager + worker (build lokal)
php artisan serve          # alternativ: Laravel dev-server
```

## Boost-Strategie

- Worker hat keinen DB-Sidecar.
- Implement-Prompt instruiert Claude, bei Bedarf temporär auf SQLite umzuschalten (`.env` ist gitignored).
- Boost-MCP-Server wird von Claude Code automatisch geladen wenn `.mcp.json` im Repo existiert.
- Risiko: Post-Install-Hooks die DB-Migrations auf MariaDB triggern. Workaround in `docs/TROUBLESHOOTING.md`.

## Erweiterungspfad

- **Nächste Schritte**: Manager-Dockerfile, PhaseRunner auf `docker run` (statt `docker compose run`) umstellen, Credentials in DB verschlüsselt ablegen, Artisan-Commands als CLI-Einstieg
- **Worker-Varianten**: `argos-worker-node`, `argos-worker-python` — gleicher Manager, anderes Worker-Image pro Task konfigurierbar
- **DB-Sidecar optional**: MariaDB + Redis als optionales Compose-Profile wenn Boost-Projekte produktive DB brauchen
- **Multi-Source**: Orchestrator liest GitHub Issues, GitLab, eigene Tools und legt Tasks automatisch an
