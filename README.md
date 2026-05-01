# Argos

Web-First Dev-Agent. Nimmt eine Aufgabe entgegen, arbeitet sie phasenweise in einem isolierten Worker-Container ab und erstellt einen Pull Request.

**Phasen:** `concept` → `implement` → `diff` → `push` (PR) → `respond` (Review-Feedback)

Zwei Images, ein User-Container: der Manager-Container spawnt Worker-Container via Docker-Socket. Die KI läuft ausschließlich im Worker — kein AI-Zugriff auf den Socket.

## Architektur

| Image | Zweck |
|---|---|
| `argos` (Manager) | Web-UI, Queue, Docker-Socket → spawnt Worker |
| `argos-worker` | Claude Code, Git, PHP/Node — kein Socket, vollständig isoliert |

## Betrieb

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

## Lokale Entwicklung

**Voraussetzungen:** Docker & Compose v2, PHP 8.4, Composer, Node 22+

```bash
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve        # Web-UI auf http://localhost:8000/admin

# Worker-Image bauen:
docker build -t argos-worker:latest worker/
```

## Nutzung

### Web-UI

Unter `/admin/tasks` Task anlegen (Repo-URL, Token, Branch, Beschreibung), dann Phasen über die Buttons starten. Logs, Konzept und Diff in den Detailseiten einsehen. Review-Feedback über die Respond-Seite einreichen.

### CLI

```bash
docker exec -it argos php artisan agent:concept task-001
docker exec -it argos php artisan agent:implement task-001
docker exec -it argos php artisan agent:diff task-001
docker exec -it argos php artisan agent:push task-001
```

## Tests

```bash
./worker/tests/run-tests.sh                # alles: shellcheck + bats + integration
./worker/tests/run-tests.sh --bats         # Bash-Unit-Tests
./worker/tests/run-tests.sh --integration  # Phase-Lifecycle gegen Mock-Claude
./worker/tests/run-tests.sh --shellcheck   # Lint
php artisan test                           # PHP-Tests
```

## Dokumentation

| Datei | Inhalt |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Konventionen für Weiterentwicklung |
| [`docs/WORKER-CONCEPT.md`](docs/WORKER-CONCEPT.md) | Architektur, Sicherheitsmodell, Phasen |
| [`docs/IMPLEMENTATION.md`](docs/IMPLEMENTATION.md) | Implementierungs-Entscheidungen |
| [`docs/BACKLOG.md`](docs/BACKLOG.md) | Roadmap |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Häufige Probleme |
| [`docs/EXTENDING.md`](docs/EXTENDING.md) | Neue Phase / Lib-Funktion hinzufügen |
