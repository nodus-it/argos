# Claude Worker — v1

Dockerisierter Worker, der eine einzelne Dev-Aufgabe phasenweise und isoliert vom Host ausführt, gesteuert über die `agent`-CLI.

Vier Phasen pro Task: **`concept`** (Plan formulieren) → **`implement`** (Code schreiben + Quality-Gates) → **`diff`** (Änderungen sichten) → **`push`** (Branch zur Remote pushen). Zwischen jeder Phase: menschliche Approval möglich. Pro Task ein eigenes Docker-Volume — Worker sieht nichts vom Host-Filesystem außerhalb seines eigenen Workspace.

## Setup (in unter 10 Minuten)

**Voraussetzungen auf dem Host:**

- Docker & Docker Compose v2
- `claude` CLI ([Anthropic Claude Code](https://docs.claude.com/en/docs/claude-code/cli-reference))
- `git`, `bash`

```bash
# 1. Claude Code installieren und Subscription-Token erzeugen
npm install -g @anthropic-ai/claude-code
claude setup-token   # Browser-OAuth, am Ende kopierst du den sk-ant-oat01-... Token

# 2. Repo clonen und Worker initialisieren
git clone <agent-repo-url> agent
cd agent
./agent init   # baut Worker-Image, fragt nach dem Token, fragt nach Symlink in ~/.local/bin
```

`agent init` macht im Detail:
- `docker compose build worker` (~5 min beim ersten Mal)
- legt persistente Caches an (`composer_cache`, `npm_cache`)
- speichert den Claude-Token unter `~/.agent/claude_oauth_token` (mode 600)
- bietet optional einen Symlink `~/.local/bin/agent → ./agent` an

**Token nur erneuern (kein Image-Rebuild):**

```bash
./agent init --update-token
```

## Erste Task

```bash
./agent task new demo-helloworld
# Interaktiv: REPO_URL, REPO_TOKEN (versteckt), BASE_BRANCH (default main),
# Task-Description in $EDITOR. credentials.env + description.md landen unter
# ~/.agent/tasks/demo-helloworld/, Volume `task_ws_demo-helloworld` wird erstellt.

./agent concept demo-helloworld           # Plan formulieren (claude-Session)
./agent show-concept demo-helloworld      # Plan lesen
./agent edit-concept demo-helloworld      # Plan editieren ($EDITOR), oder
./agent edit-notes demo-helloworld        # Anmerkungen anhaengen, dann
./agent concept demo-helloworld           # erneut, inkrementell

./agent implement demo-helloworld         # Claude schreibt Code + faehrt Pint/Pest aus
./agent diff demo-helloworld              # Aenderungen reviewen
./agent push demo-helloworld              # Commit-Message via Sub-Phase, dann git push
```

Voller End-to-End-Walkthrough: [`docs/EXAMPLE.md`](docs/EXAMPLE.md).

## Weiterführende Doku

| Datei | Zweck |
| --- | --- |
| [`WORKER-CONCEPT.md`](WORKER-CONCEPT.md) | Architektur und Verhalten — was wird gebaut |
| [`IMPLEMENTATION.md`](IMPLEMENTATION.md) | Konkrete Implementierungs-Entscheidungen — wie wird es gebaut |
| [`CLAUDE.md`](CLAUDE.md) | Konventionen für die Erweiterung des Codes |
| [`V1-DONE.md`](V1-DONE.md) | Akzeptanzkriterien für „v1 ist fertig" |
| [`BACKLOG.md`](BACKLOG.md) | Was nach v1 kommt, sortiert |
| [`docs/EXAMPLE.md`](docs/EXAMPLE.md) | Vollständiger Demo-Walkthrough |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Häufige Probleme und Fixes |
| [`docs/EXTENDING.md`](docs/EXTENDING.md) | Neue Phase oder Lib-Funktion hinzufügen |
| [`prompts/*.system.md`](prompts/) | System-Prompt-Templates für Claude-Sessions im Worker |
| [`schemas/*.schema.json`](schemas/) | JSON-Schemas für State und strukturierte Outputs |

## Tests laufen lassen

```bash
./tests/run-tests.sh                 # alles: shellcheck + bats + integration
./tests/run-tests.sh --bats          # nur Bash-Unit-Tests
./tests/run-tests.sh --integration   # nur Phase-Lifecycle-Test (gegen Mock-Claude)
./tests/run-tests.sh --shellcheck    # nur Lint
```

Alle Tests laufen über Docker, kein Host-Install nötig (außer Docker selbst).

## Geltungsbereich v1

- Manuelle Bedienung über `./agent`-CLI
- Ein Task pro CLI-Aufruf, beliebig viele parallele Tasks (eigenes Volume pro Task)
- VCS: GitHub (PAT-basiert)
- Toolchain: PHP/Laravel (Pint, Pest/PHPUnit, optional PHPStan)
- KI-Provider: Anthropic Claude (Subscription-OAuth, kein API-Key)

Spätere Iterationen (PR-Erstellung, Feedback-Loop, Orchestrator, UI) bekommen eigene Spec-Updates — Hooks dafür sind im v1-Design schon angelegt (siehe `BACKLOG.md`).
