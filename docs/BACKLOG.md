# Backlog

Roadmap sortiert nach Priorität. Architektur-Grundlage: `docs/WORKER-CONCEPT.md`.

## Laufend: Architektur-Umbau (Web First)

Der Prototyp war CLI-first mit Bash-Steuerung. Das Zielbild ist Web First mit PHP als Steuerebene und einem einzigen User-Container. Folgende Umbau-Schritte stehen an:

- [ ] **Manager-Dockerfile** (`docker/Dockerfile`): PHP + Nginx + Supervisor (php-fpm + nginx + queue:work). Basis für den Single-Container-Betrieb.
- [ ] **`PhaseRunner` auf `docker run`** umstellen: statt `docker compose run --rm worker` direkt `docker run ghcr.io/nodus-it/argos-worker:latest` mit Socket-Mount im Manager.
- [ ] **Credentials in DB**: Repo-Token und Claude-Token verschlüsselt in DB (`encrypted` Cast), nicht mehr im Dateisystem.
- [ ] **`RunPhaseJob`**: Laravel Queue-Job der `PhaseRunner::run()` kapselt. Queue-Treiber: `database`.
- [ ] **Artisan-Commands** (`agent:concept`, `agent:implement`, `agent:diff`, `agent:push`): synchroner Einstieg für `docker exec`-Nutzung.
- [ ] **`docker-compose.yml` überarbeiten**: Manager-Service hinzufügen, Worker als Build-only-Target.
- [ ] **GitHub Actions Build** (`.github/workflows/build.yml`): beide Images bauen und auf GHCR pushen.

## Nächste Features

- [ ] **State-Sync nach Phase**: PHP liest `state.json` aus Volume nach Container-Exit, schreibt in DB.
- [ ] **Phase `diff` in Web-UI**: strukturierte Diff-Ansicht in Filament statt nur stdout.
- [ ] **Cost-Tracking**: `total_cost_usd` aus Claude-Result-JSONs in DB summieren, in Task-Detailseite anzeigen.
- [ ] **Worker-Image-Varianten**: `argos-worker-node`, `argos-worker-python` — pro Task konfigurierbar welches Worker-Image genutzt wird.
- [ ] **DB-Sidecar optional**: MariaDB als Compose-Profile wenn Boost-Projekte produktive DB brauchen.

## Mittelfristig

- [ ] **Multi-Source-Orchestrator**: liest GitHub Issues, GitLab, eigene Tools und legt Tasks automatisch an.
- [ ] **Concept-Auto-Mode**: Konzept generieren, Issue kommentieren, X Min warten, dann automatisch implementieren.
- [ ] **Approval-Gate**: 👍-Reaction oder Kommentar als Freigabe für nächste Phase.
- [ ] **Webhook-Endpoint** als Alternative zum Polling.
- [ ] **VCS-Provider GitLab**: `glab` CLI Integration im Worker.

## Operations & Quality

- [ ] **Strukturierte JSON-Logs** im Worker (für Loki/Elasticsearch)
- [ ] **Performance-Metriken**: durchschnittliche Phase-Dauer, Quality-Gate-Failure-Rate
- [ ] **Health-Endpoint** im Manager
- [ ] **Sentry-Integration** für Worker-Container

## Spec-Wartung

Wenn ein Item umgesetzt ist:
- In den „Done"-Abschnitt verschieben mit Datum
- Betroffene Spec-Files updaten
- Bei Interface-Änderungen (Phase-Kontrakt, Result-JSON, DB-Schema): erst migrieren, dann altes Format entfernen

---

## Done

- [x] **Basis-Worker**: Bash-Phasen (concept, implement, diff, push, commit-message) mit Mock-Claude-Tests *(Prototyp-Phase)*
- [x] **Web-UI (Filament)**: Task-Verwaltung, Phase-Steuerung, Logs, Concept-Ansicht *(Mai 2026)*
- [x] **PR-Erstellung**: `push`-Phase erstellt GitHub PR via REST API, concept.md als Body *(Mai 2026)*
- [x] **Respond-Phase**: PR-Feedback über Web-UI einreichen, Worker arbeitet es ein *(Mai 2026)*
- [x] **Laravel Boost**: installiert und konfiguriert, MCP-Server, Skills *(Mai 2026)*
- [x] **Repo-Restrukturierung**: Laravel-App im Root, Worker unter `worker/`, Docs unter `docs/` *(Mai 2026)*
