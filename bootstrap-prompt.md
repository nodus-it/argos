# Bootstrap-Prompt für Claude Code

> Copy & Paste den **Inhalt unterhalb der Trennlinie** in Claude Code als ersten Prompt.

Der Prompt geht in Schritten vor — Claude Code wird nach jedem Schritt committen und du kannst dazwischen reviewen. Wenn etwas schiefläuft, kannst du direkt mit "Schritt X bitte nochmal mit folgender Anpassung: ..." korrigieren.

Erwarte nicht, dass alle Schritte beim ersten Durchlauf perfekt funktionieren. Docker-Build-Fehler, fehlende Bash-Tools, Compose-Versions-Eigenheiten — alles wird zu mindestens einer Iteration führen.

---

## Aufgabe: Claude Worker v1 bauen

Du baust ein dockerisiertes Worker-Tool, das Dev-Tasks isoliert ausführt. Vor allem anderen: lies bitte in dieser Reihenfolge und merke dir den Inhalt:

1. `README.md`
2. `WORKER-CONCEPT.md`
3. `IMPLEMENTATION.md`
4. `V1-DONE.md`
5. `BACKLOG.md`
6. `CLAUDE.md`
7. `prompts/concept.system.md`
8. `prompts/implement.system.md`
9. `prompts/commit-message.system.md`
10. `prompts/user.global.system.md`
11. `schemas/state.schema.json`
12. `schemas/result.*.schema.json`
13. `schemas/commit-message.schema.json`

Sobald du die gelesen hast, antworte mir kurz mit:

- Einem 3-Sätze-Summary worum es geht
- Welche zwei oder drei Punkte dir beim Lesen besonders unklar oder kritisch erscheinen
- Bestätigung dass du die Detail-Entscheidungen in `IMPLEMENTATION.md` als verbindlich verstehst

**Stoppe dann und warte auf mein "Go".**

Erst nach meinem "Go" beginnst du mit dem Bootstrap. Du arbeitest die folgenden Schritte in Reihenfolge ab und commitest nach jedem Schritt. Vor jedem Commit: `shellcheck` über alle geänderten Bash-Files, und wenn Tests existieren `bats tests/bats/`.

---

### Schritt 1: Repo-Skelett

- `.gitignore` mit Standard-Inhalten (`/tmp`, `*.log`, `.DS_Store`, etc.)
- `.editorconfig` für Bash und Markdown
- Verzeichnisstruktur anlegen wie in `WORKER-CONCEPT.md` „Komponenten-Übersicht" beschrieben (leere Dateien sind OK, nur die Struktur)

Verifizierungs-Schritt: `tree -L 2` oder `find . -type d -not -path '*/.*'` zeigt die erwartete Struktur.

Commit: `chore: initial repo skeleton`.

### Schritt 2: Docker-Image und Compose

- `docker/Dockerfile` mit Stages `base`, `worker` gemäß `WORKER-CONCEPT.md` Abschnitt „Container-Aufbau"
  - PHP 8.3-cli auf Bookworm, alle in `IMPLEMENTATION.md` aufgelisteten Extensions
  - Composer, Node 20, Claude Code via npm
  - Non-root User `agent`
- `docker/worker-entrypoint.sh` als Skelett (nur Argument-Parsing und „nicht implementiert"-Stub für jede Phase)
- `docker-compose.yml` mit dem `worker`-Service (siehe `WORKER-CONCEPT.md` „Compose-Service"). Wichtig: kein `restart`, kein `ports`, das ist ein run-and-exit-Service.
- `.env.example` mit Default-Werten

Verifizierungs-Schritt: `docker compose build worker` läuft ohne Fehler durch. `docker compose run --rm worker echo "hello"` gibt "hello" aus.

Commit: `feat: docker image and compose setup`.

### Schritt 3: lib/ Bash-Library

In dieser Reihenfolge, je ein Commit pro File:

a) `lib/logging.sh` — einheitliches Logging mit Levels (`log_info`, `log_warn`, `log_error`, `log_debug`). Färbung wenn TTY.

b) `lib/error.sh` — `die`-Funktion, Exit-Code-Constants.

c) `lib/result.sh` — `result_emit()` baut JSON über `jq` und gibt es auf stdout. Pflicht-Felder werden geprüft.

d) `lib/state.sh` — `state_init`, `state_read`, `state_write_atomic`, `state_add_iteration`, `state_update_iteration`. Schema-Validierung via `jq` (kein externes JSON-Schema-Validator-Tool nötig — manuelle Field-Checks reichen).

e) `lib/lock.sh` — `lock_acquire`, `lock_release`, Stale-Lock-Detection.

f) `lib/credentials.sh` — Token-Storage auf dem Host (`~/.agent/`), mode 600, atomic writes.

g) `lib/tasks.sh` — `task_create_volume`, `task_delete_volume`, `task_volume_exists`, `task_list`, `task_resolve_path`.

h) `lib/docker.sh` — `docker_run_phase()` Wrapper.

i) `lib/parse_args.sh` — Argument-Parser für CLI.

j) `lib/help.sh` — Hilfe-Texte.

k) `lib/prompts.sh` — `build_system_prompt()` (siehe `IMPLEMENTATION.md` Abschnitt 10).

Pro File: bats-Tests unter `tests/bats/test_<name>.bats` für die nicht-trivialen Funktionen.

Commits: `feat: lib/<file> (description)` pro File.

### Schritt 4: Phase-Skripte

In dieser Reihenfolge, je ein Commit pro Phase:

a) `phases/registry.sh` — Liste aktiver Phasen, Lifecycle-Reihenfolge.

b) `phases/concept.sh` — Vollständige Implementation. Verweise auf `IMPLEMENTATION.md` Abschnitt 1.1 für Claude-Aufruf, Abschnitt 5 für Konventionen.

c) `phases/implement.sh` — inklusive Verifikations-Phase nach Claude-Session (Abschnitt 11).

d) `phases/diff.sh` — read-only, zeigt git diff.

e) `phases/commit-message.sh` — Sub-Phase, wird von push aufgerufen.

f) `phases/push.sh` — ruft commit-message intern auf, dann commit + push.

Pro Phase: minimaler Smoke-Test in `tests/integration/` der die Phase mit Mock-Claude und fake-remote-repo durchlaufen lässt.

### Schritt 5: Worker-Entrypoint vervollständigen

`docker/worker-entrypoint.sh` — die volle Logik aus `IMPLEMENTATION.md` Abschnitt 4. Lädt `lib/`-Files, dispatcht zu Phase, kümmert sich um State und Lock.

Commit: `feat: worker entrypoint phase dispatcher`.

### Schritt 6: agent-CLI

`agent` — der Haupt-Einstiegspunkt. Subcommands gemäß `WORKER-CONCEPT.md` „CLI-Reference":

- `init`, `task new|list|show|delete`, `concept|implement|diff|push`, `show-concept|edit-concept|show-notes|edit-notes`, `logs`, `shell`, `status`, `abort`, `prune`

Editor-Integration für `edit-concept`/`edit-notes` mit Copy-In/Copy-Out via Container.

Commit: `feat: agent CLI`.

### Schritt 7: Prompt-Templates ins Image

- Stelle sicher dass `prompts/*.system.md` im Image unter `/usr/local/share/agent/prompts/` landen
- `lib/prompts.sh::build_system_prompt()` liest von dort

Commit: `feat: prompt template integration`.

### Schritt 8: Test-Infrastruktur

- `tests/run-tests.sh` Wrapper mit `--bats` und `--integration` Flags
- Mock-Claude unter `tests/integration/fixtures/mock-claude/` als Bash-Skript das deterministisch antwortet
- Fake-Remote-Repo unter `tests/integration/fixtures/fake-remote-repo/` als bare git repo
- Integration-Test `test_phase_lifecycle.sh` der einen kompletten Durchlauf macht (concept → implement → diff → push)

Commit: `feat: test infrastructure with bats and integration tests`.

### Schritt 9: CI

- `.github/workflows/test.yml` mit shellcheck + bats + integration tests
- Trigger auf push to main und alle PRs

Commit: `chore: github actions ci`.

### Schritt 10: Dokumentation

- `README.md` mit Quickstart-Anleitung
- `docs/EXAMPLE.md` mit vollständigem Walkthrough (HelloWorld-Demo wie in `V1-DONE.md` End-to-End-Test)
- `docs/TROUBLESHOOTING.md` mit häufigen Problemen (Token expired, Boost MCP failure, etc.)
- `docs/EXTENDING.md` für „neue Phase hinzufügen"

Commit: `docs: complete v1 documentation`.

### Schritt 11: Manueller End-to-End-Akzeptanztest

Lass mich (den User) den Akzeptanztest aus `V1-DONE.md` durchlaufen. Wenn etwas nicht klappt: fixen, Test wiederholen, dokumentieren was gefixt wurde. Wenn alle 9 Schritte des End-to-End-Tests grün sind: `chore: v1 ready` als finalen Commit.

---

## Wichtige Regeln während dem ganzen Bootstrap

1. **Nach jedem Schritt:** `shellcheck` + `bats` + Commit
2. **Tests die scheitern:** erst verstehen, dann beheben. Niemals "skip" oder auskommentieren.
3. **Unklarheiten in der Spec:** nachfragen, nicht raten
4. **Neue Top-Level-Dependencies:** vorher fragen
5. **Architektur-Druck:** wenn eine Entscheidung beim Bauen weh tut, *sag es und schlage Alternative vor*, statt heimlich abzuweichen
6. **Branch-Strategie während Bootstrap:** alles auf `main` ist OK in der Bootstrap-Phase. Erst nach v1-Ready-Commit folgen wir Conventional-Branching.

Los geht's mit dem Lesen der Files. Antworte wie oben beschrieben und warte auf mein "Go".
