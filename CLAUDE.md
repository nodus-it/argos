# Instructions for Claude Code

## Was hier passiert

Du baust den **Claude Worker v1** — ein dockerisiertes Tool, das Dev-Tasks isoliert und phasenweise ausführt. Lies `docs/WORKER-CONCEPT.md` für das Big Picture.

## Wo was steht (Quellen der Wahrheit)

| Frage | Datei |
| --- | --- |
| Was bauen wir und wofür? | `docs/WORKER-CONCEPT.md` |
| Wie genau implementieren? | `docs/IMPLEMENTATION.md` |
| Wann ist v1 fertig? | `docs/V1-DONE.md` |
| Was kommt danach? | `docs/BACKLOG.md` |
| System-Prompts für die Claude-Sessions im Worker | `worker/prompts/*.system.md` |
| Schemas für State und Outputs | `worker/schemas/*.schema.json` |

**Diese Dateien sind die Grundwahrheit.** Wenn dein Code-Stand davon abweicht, ist der Code falsch — nicht die Spec. Falls du einen guten Grund siehst, von einer Entscheidung abzuweichen: erst im Chat fragen, dann ggf. die Spec anpassen, *dann* implementieren.

## Konventionen

### Bash-Stil

- `#!/usr/bin/env bash` als Shebang in jeder ausführbaren Datei
- `set -euo pipefail` am Anfang jedes Skripts (außer wenn explizit anders nötig)
- `IFS=$'\n\t'` für sichere Wort-Trennung
- Funktions-Namen: `<modul>_<aktion>` (z.B. `state_init`, `lock_acquire`)
- Alle Funktionen in `worker/lib/` haben Docstring-Kommentar oben:
  ```bash
  # state_init: Erstellt initiales state.json für einen neuen Task.
  # Args: $1=task_id, $2=repo_url, $3=base_branch
  # Returns: 0 bei Erfolg, sonst exit code
  state_init() {
      ...
  }
  ```
- Keine `eval`. Keine `cmd $args` ohne Quoting. Variablen immer mit `"$var"`.
- Lokal-Variablen mit `local` deklarieren.
- `[[ ... ]]` statt `[ ... ]`, `(( ... ))` für Arithmetik.

### Shellcheck

Alle Bash-Files müssen `shellcheck`-clean sein (Severity error/warning). `info` und `style` sind optional.

CI führt `shellcheck` über `agent`, `worker/lib/`, `worker/phases/`, `worker/docker/worker-entrypoint.sh` und `worker/tests/integration/*.sh` aus.

### File-Layout

- Eine Bibliothek pro Datei in `worker/lib/`. Keine Mehrfach-Verantwortung.
- Phase-Skripte in `worker/phases/<name>.sh` enthalten *nur* die Funktionen `phase_<name>_run`, `phase_<name>_preconditions`, `phase_<name>_help`. Helfer-Funktionen kommen in `worker/lib/`.
- `worker/docker/Dockerfile` und `worker/docker/worker-entrypoint.sh` sind die einzigen Files unter `worker/docker/`.
- Die Laravel-Applikation lebt im Repo-Root (artisan, app/, config/, etc.) — `worker/` enthält ausschließlich den Docker-Worker.

### Dokumentation

- Jede neue Funktion in `worker/lib/` braucht einen Docstring.
- Bei Architektur-relevanten Änderungen: `docs/WORKER-CONCEPT.md` oder `docs/IMPLEMENTATION.md` mit aktualisieren.
- Beispiel-Walkthrough in `docs/EXAMPLE.md` mit jedem neuen Feature aktuell halten.

### Tests

- Bash-Unit-Tests mit `bats-core` unter `worker/tests/bats/`. Pro Lib-Datei eine Test-Datei.
- Integration-Tests unter `worker/tests/integration/`. Mock-Claude und fake-remote-repo als Fixtures.
- Laravel-Tests (PHP) unter `tests/` (root-Ebene).
- Bei Bug-Fixes: erst Test schreiben der den Bug reproduziert, dann fixen.
- Tests müssen offline laufen — kein Zugriff auf echtes GitHub, keine echte Claude-API.

### Git

- Conventional-Commits-Style: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`
- Subject ≤ 72 Zeichen, imperativisch ("add foo", nicht "added foo")
- Body falls nötig: was und warum, nicht wie

### Sicherheit

- Tokens werden **niemals** geloggt — auch nicht zur Diagnose.
- `set +x` für Code-Bereiche die mit Tokens hantieren, falls debug-trace aktiv.
- credentials.env immer mode 600 schreiben (`umask 077` ODER `chmod 600` direkt nach create).
- `set -u` würde unset-Variablen detecten — wir nutzen es; wenn eine env-Var optional ist, mit `${VAR:-default}` zugreifen.

## Was du NICHT tust ohne Rücksprache

- Architektur-Entscheidungen aus den Spec-Dokumenten umkehren
- Neue Top-Level-Dependencies (Bash-Tools die nicht in `bookworm` Standard sind, ohne explizite Rechtfertigung)
- Neue Volumes oder Services in `docker-compose.yml`
- Neue Phasen einführen oder bestehende grundlegend ändern (Phase-Erweiterung über `phases/` ist OK, aber concept→implement→diff→push als Default-Flow ist gesetzt)
- Auth-Flow ändern (Claude-OAuth-Token-Pfad, Repo-Token in env)
- Branch-Naming-Schema ändern
- An den State-Schema-Versionen vorbei eine neue Struktur einführen — wenn die Struktur sich ändert, `schema_version` hochzählen und Migrations-Logik bedenken

## Wenn du fertig bist mit einem Schritt

1. `shellcheck` über alle geänderten Bash-Files
2. `bash worker/tests/run-bats.sh` falls vorhanden
3. Commit nach Conventional-Commits-Style
4. Falls eine Annahme aus der Spec sich beim Bauen als falsch erwiesen hat: betroffenes Spec-Dokument anpassen *im selben Commit oder im Folge-Commit*

## Häufige Befehle

```bash
# Image bauen
docker compose build worker

# Tests laufen lassen
./worker/tests/run-tests.sh

# Manuelle Smoke-Tests gegen Test-Repo
./agent task new smoke-test
# (mit Test-Repo-URL und Test-Token)
./agent concept smoke-test
./agent implement smoke-test
./agent diff smoke-test
./agent push smoke-test
```

## Rückfragen sind willkommen

Wenn etwas in der Spec unklar, widersprüchlich oder unterspezifiziert ist: **frag nach, bevor du rätst.** Die Spec hat absichtlich offene Stellen, an denen Implementierungs-Details bewusst dem konkreten Bau überlassen sind — aber wenn du dir nicht sicher bist, ob etwas „bewusst offen" oder „vergessen zu spezifizieren" ist, frag.

Genauso bei Architektur-Druck: wenn du beim Implementieren merkst, dass eine Entscheidung in der Praxis weh tut — sag es, statt heimlich abzuweichen.
