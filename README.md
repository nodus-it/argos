# Argos

Dockerisierter Dev-Agent mit Web-UI. Nimmt eine Aufgabe entgegen, arbeitet sie phasenweise in einem isolierten Container ab und erstellt einen Pull Request.

**Phasen:** `concept` → `implement` → `diff` → `push` (PR) → `respond` (Review-Feedback)

Pro Task ein eigenes Docker-Volume — der Worker sieht nichts vom Host-Filesystem außerhalb seines Workspace.

## Komponenten

| Komponente | Ort | Zweck |
| --- | --- | --- |
| Laravel Web-UI | `app/`, `resources/` | Filament-Admin-Panel zur Task-Steuerung |
| Docker Worker | `worker/` | Bash-Phasen-Runner in isoliertem Container |
| Agent CLI | `./agent` | Terminal-Steuerung und Init |

## Setup

**Voraussetzungen:**
- Docker & Docker Compose v2
- PHP 8.3+, Composer, Node 22+
- `claude` CLI ([Claude Code](https://docs.claude.com/en/docs/claude-code/cli-reference))
- `jq`

```bash
# 1. Claude Code installieren und Token erzeugen
npm install -g @anthropic-ai/claude-code
claude setup-token   # Browser-OAuth → sk-ant-oat01-... Token

# 2. Abhängigkeiten installieren + Worker-Image bauen
composer install
npm install && npm run build
./agent init   # baut Worker-Image, speichert Token, optionaler Symlink

# 3. Laravel initialisieren
php artisan key:generate
php artisan migrate
php artisan serve
```

Die Web-UI ist dann unter `http://localhost:8000/admin` erreichbar.

## Nutzung

### Web-UI (empfohlen)

Unter `/admin/tasks` einen Task anlegen (Repo-Profil, Name, Beschreibung), dann
Phasen über die Buttons starten. Logs und Konzept in den Detailseiten einsehen.
Review-Feedback über die Respond-Seite einreichen.

### Agent CLI

```bash
./agent task new demo-task   # interaktiv: REPO_URL, TOKEN, BRANCH, Beschreibung
./agent concept demo-task    # Plan generieren
./agent implement demo-task  # Code + Quality-Gates
./agent diff demo-task       # Änderungen reviewen
./agent push demo-task       # Commit + PR erstellen
```

Voller Walkthrough: [`docs/EXAMPLE.md`](docs/EXAMPLE.md).

## Tests

```bash
./worker/tests/run-tests.sh                # alles: shellcheck + bats + integration
./worker/tests/run-tests.sh --bats         # nur Bash-Unit-Tests
./worker/tests/run-tests.sh --integration  # Phase-Lifecycle gegen Mock-Claude
./worker/tests/run-tests.sh --shellcheck   # nur Lint
```

Alle Tests laufen über Docker, kein Host-Install nötig (außer Docker).

## Dokumentation

| Datei | Inhalt |
| --- | --- |
| [`CLAUDE.md`](CLAUDE.md) | Konventionen für Weiterentwicklung |
| [`docs/WORKER-CONCEPT.md`](docs/WORKER-CONCEPT.md) | Architektur und Phasen-Design |
| [`docs/IMPLEMENTATION.md`](docs/IMPLEMENTATION.md) | Implementierungs-Entscheidungen |
| [`docs/BACKLOG.md`](docs/BACKLOG.md) | Roadmap nach v1 |
| [`docs/EXAMPLE.md`](docs/EXAMPLE.md) | End-to-End Demo-Walkthrough |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Häufige Probleme und Fixes |
| [`docs/EXTENDING.md`](docs/EXTENDING.md) | Neue Phase / Lib-Funktion hinzufügen |
