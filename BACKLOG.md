# Backlog

Issues und Aufgaben nach v1. Sortiert nach Größe und Risiko. Einige sind manuelle Arbeit, andere können der Worker selber bearbeiten sobald er stabil läuft.

## Direkt nach v1 (Quick Wins)

- [ ] **Cost-Tracking pro Task**: in `state.json` die `total_cost_usd` aus den Claude-Result-JSONs summieren, in `agent status` anzeigen
- [ ] **Konzept-Notes-Workflow** verbessern: nach `agent edit-notes` automatisch `agent concept --apply-notes` anbieten
- [ ] **`agent prompt show <phase>`** zeigt den aktuell aktiven merged System-Prompt für eine Phase, hilfreich beim Debuggen
- [ ] **`agent rebuild`**-Command zum Image-Rebuild nach Änderungen an Prompts/Dockerfile
- [ ] **`.editorconfig`** für das eigene Repo
- [ ] **`agent init --update-token`** für Token-Renewal ohne kompletten Re-Init

## Iteration 2: PR-Workflow

- [x] **Basis-PR**: `push`-Phase erstellt automatisch einen GitHub PR via `gh pr create` (title=commit subject, body=commit body). PR-URL wird geloggt und im result-JSON als `pr_url` zurückgegeben. *(in v1 umgesetzt)*
- [ ] **Phase `pr` (erweitert)**: PR-Body aus `concept.md` befüllen, Labels setzen, Reviewers zuweisen.
- [ ] **Phase `respond`**: PR-Feedback erkennen und einarbeiten. Worker pollt PR-Comments seit letztem Lauf, klassifiziert sie via Claude-Sub-Phase (`revise|clarify|ignore`), führt entsprechend Aktionen aus.
- [ ] **Phase `analyze`** als optionaler Pre-Concept-Schritt: nur Repo-Inspektion, kein LLM. Output: Repo-Map als Datei, die in concept als Input genutzt werden kann.
- [ ] **Phase `revise`**: gezielter Edit ohne kompletten Reset, wenn die Änderung lokal und klein ist.
- [ ] **DB-Sidecar-Service** als optionales Profile in `docker-compose.yml`: MariaDB + Redis als Sidecar wenn Boost-MCP gegen die produktive DB-Konfig laufen muss.

## Iteration 3: Orchestrierung

- [ ] **Orchestrator-Container**: liest Task-Quellen (GitHub Issues, GitLab, eigenes Tool), legt Tasks in Worker an via CLI-Calls. Eigenes Compose-Profile.
- [ ] **Polling-Konfiguration**: pro Task-Quelle Intervall, Filter, Konvention (Label etc.)
- [ ] **Concept-Auto-Mode**: Konzept generieren, Kommentar an Issue posten, X Min warten, dann automatisch implement starten wenn keine Anmerkungen kamen
- [ ] **Approval-Gate**: Reaction 👍 oder Kommentar an Issue als Freigabe
- [ ] **Webhook-Endpoint** als Alternative zum Polling

## Iteration 4: UI & Skalierung

- [ ] **Filament-UI** für Konfiguration und Run-Monitoring
- [ ] **Multi-Account-Support für Claude Subscription**: mehrere `CLAUDE_CODE_OAUTH_TOKEN` rotierend nutzen
- [ ] **API-Mode** als zweiter `AiRunner`: Anthropic-API direkt, ohne Subscription-Limit
- [ ] **Toolchain `Symfony`**, **`PlainPhp`**, **`Node20`** — analog zu Laravel
- [ ] **VCS-Provider GitLab**: `glab` CLI integration
- [ ] **Whitelist-basierte ApprovalGate**: nur autorisierte User-Logins zählen

## Operations & Quality

- [ ] **Sentry-Integration** für Worker-Container
- [ ] **Strukturierte JSON-Logs** statt Plain-Text (für Loki/Elasticsearch)
- [ ] **Performance-Metriken**: durchschnittliche Phase-Dauer, Quality-Gate-Failure-Rate, Iterations pro Task
- [ ] **Backup-Strategie** für die persistenten Volumes (Composer-Cache reicht ein Snapshot, Workspaces sollten nach Push gelöscht sein)
- [ ] **Health-Endpoint** wenn Webhook-Server läuft

## Spec-Wartung

Diese Spec wird parallel weitergeführt. Wenn ein Backlog-Item umgesetzt ist:
- Eintrag von hier nach unten in einen "Done"-Abschnitt verschieben
- Betroffene Spec-Files (`WORKER-CONCEPT.md`, `IMPLEMENTATION.md`) updaten falls nötig
- Bei Vertrags-Änderungen (Phase-Interface, Result-JSON-Format): alte Implementierungen erst migrieren, dann altes Format entfernen

---

## Done

Hier landen erledigte Issues mit Datum und Verweis auf den umsetzenden PR/Run.

*(noch leer — wird nach v1 gefüllt)*
