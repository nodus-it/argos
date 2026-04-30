# Implementation Spec

Dieses Dokument enthält alle konkreten Entscheidungen, die zum Bau von v1 nötig sind. Wenn Konzept und Implementation widersprüchlich aussehen: Konzept ist Architektur (was), Implementation ist Detail (wie).

## Inhalt

1. Claude-Code-Aufrufe
2. State-Schema und Lifecycle
3. Branch-Naming
4. Worker-Entrypoint-Logik
5. Phase-Konventionen
6. Locking
7. Result-JSON-Format
8. CLI-Interna
9. Build- und Test-Mechanik
10. System-Prompt-Komposition

---

## 1. Claude-Code-Aufrufe

### 1.1 Concept-Phase

```bash
claude -p \
  --append-system-prompt "$(cat /workspace/.agent/runtime/concept.system.merged.md)" \
  --output-format json \
  --max-turns 5 \
  --permission-mode bypassPermissions \
  < /workspace/.agent/runtime/concept.user-prompt.md
```

- `--append-system-prompt`: erwartet den merged System-Prompt als String-Argument (Claude Code 2.1.x hat keinen `-file`-Suffix mehr; die Phase-Skripte lesen die merged-Datei via `"$(cat …)"` ein)
- `--output-format json`: liefert ein einzelnes Envelope `{result, session_id, total_cost_usd, ...}`. `result` enthält den Free-Form-Text der letzten Assistant-Message
- `max-turns 5`: Claude darf File-Tools nutzen (Repo-Struktur lesen) bevor Output erscheint
- `bypassPermissions`: Container ist die Sandbox, keine Approval-Prompts
- stdin: User-Prompt-File mit Aufgabe + ggf. vorheriges Konzept + Notes

Output-Verarbeitung: das Phase-Skript greift mit `jq -r '.result' < claude_output.json` an den Konzept-Text. Wenn `is_error: true` im Envelope: Phase fehlschlägt mit Exit 3, Output wird in den Logs gehalten.

Die Datei `/workspace/.agent/concept.md` wird **vom Phase-Skript** geschrieben, nicht von Claude direkt. Begründung: Output deterministisch und parsebar machen, statt auf Claudes File-Write-Tool zu vertrauen. Concept-System-Prompt sagt deshalb explizit "schreibe das Konzept als deine Antwort, NICHT in eine Datei".

### 1.2 Implement-Phase

```bash
claude -p \
  --append-system-prompt "$(cat /workspace/.agent/runtime/implement.system.merged.md)" \
  --output-format stream-json \
  --verbose \
  --include-partial-messages \
  --permission-mode bypassPermissions \
  --max-turns "${MAX_TURNS:-50}" \
  < /workspace/.agent/runtime/implement.user-prompt.md \
  | tee "/workspace/.agent/logs/implement.${ITERATION}.stream.log" \
  | jq -c 'select(.type == "result")' \
  > "/workspace/.agent/logs/implement.${ITERATION}.result.json"
```

- `stream-json` + `tee`: Live-Output für den User (CLI rendert das auf TTY) und vollständige Logs
- `--include-partial-messages`: Token-Level-Streaming für progress feedback
- max-turns 50 default, override via `--max-turns=N` an `agent implement`
- Phase-Skript prüft am Ende ob `result.json` ein erfolgreiches Result enthält

Claude darf in dieser Phase **alle** Files im Workspace lesen/schreiben, alle Bash-Commands ausführen. Pint und Tests führt Claude **selbst** aus (siehe Implement-System-Prompt). Verifikation durch den Worker passiert nach der Session.

### 1.3 Commit-Message-Phase

```bash
claude -p \
  --append-system-prompt "$(cat /workspace/.agent/runtime/commit-message.system.merged.md)" \
  --output-format json \
  --json-schema "$(cat /workspace/.agent/runtime/commit-message.schema.json)" \
  --max-turns 3 \
  --permission-mode bypassPermissions \
  < /workspace/.agent/runtime/commit-message.user-prompt.md
```

- `--json-schema`: erzwingt strukturierte Antwort, sollte parsbar sein via `.structured_output.subject` und `.structured_output.body`
- max-turns 3: Claude darf Diff lesen via Bash-Tool, dann antworten

**Format-Caveat (Stand v1-Bootstrap):** Das genaue Output-Feld bei
`--json-schema` ist noch nicht durch einen erfolgreichen Real-Aufruf
verifiziert (Smoke-Test wurde wegen ~15c Subscription-Cost pro Aufruf
zurückgestellt). Phase-Skript parst defensiv: erst `.structured_output`
direkt, fallback auf `.result | fromjson` (falls Claude die JSON-Antwort
ins `result`-Feld als String legt). Beim End-to-End-Test wird der reale
Pfad sichtbar; danach Spec endgültig aktualisieren.

Schema in `schemas/commit-message.schema.json`:
```json
{
  "type": "object",
  "properties": {
    "subject": {"type": "string", "minLength": 10, "maxLength": 72},
    "body": {"type": "string", "minLength": 0, "maxLength": 1000}
  },
  "required": ["subject", "body"]
}
```

### 1.4 Auth

`CLAUDE_CODE_OAUTH_TOKEN` wird vom CLI in jedes `docker compose run` reingereicht. Das CLI liest den Token aus `~/.agent/claude_oauth_token`. Wenn der Token fehlt oder leer ist: CLI bricht mit klarer Meldung ab, bevor Container startet.

Falls Claude Code mit Authentication-Fehler antwortet (Token expired): Phase exit-code 3, Worker schreibt Hinweis in Result-JSON dass Token erneuert werden muss (`claude setup-token` neu ausführen, dann `./agent init --update-token`).

### 1.5 Tools

Keine `--allowedTools`-Beschränkung. Claude hat im Container vollen Zugriff auf alle Tools, die in der Default-Konfiguration aktiv sind. Der Container ist die Sandbox.

---

## 2. State-Schema und Lifecycle

### 2.1 Schema

Datei: `/workspace/.agent/state.json`. Schema in `schemas/state.schema.json`.

Beispielstand:
```json
{
  "task_id": "task-001",
  "schema_version": 1,
  "created_at": "2026-04-30T10:00:00Z",
  "updated_at": "2026-04-30T10:34:12Z",
  "repo": {
    "url": "https://github.com/example/proj.git",
    "base_branch": "main",
    "feature_branch": "ai/task-001-1714506000"
  },
  "phases": {
    "concept": {
      "iterations": [
        {
          "n": 1,
          "started_at": "2026-04-30T10:01:00Z",
          "finished_at": "2026-04-30T10:02:30Z",
          "status": "completed",
          "exit_code": 0,
          "flags": {"fresh": false}
        }
      ],
      "current_status": "completed"
    },
    "implement": {
      "iterations": [],
      "current_status": "pending"
    },
    "diff": {
      "iterations": [],
      "current_status": "pending"
    },
    "push": {
      "iterations": [],
      "current_status": "pending"
    }
  }
}
```

### 2.2 Status-Werte

`current_status` einer Phase ist einer von:
- `pending` — noch nie gelaufen
- `running` — gerade in Ausführung (Lock aktiv)
- `completed` — letzter Lauf erfolgreich
- `quality_gate_failed` — bei `implement`
- `failed` — sonstiger Fehler
- `no_changes` — bei `implement`/`push` wenn nichts geändert wurde

### 2.3 Atomare Writes

`lib/state.sh` schreibt nie direkt, sondern:
1. `state.json` lesen, in Bash-Variable parsen via `jq`
2. Modifikation in Variable
3. Schreibe in `state.json.tmp`
4. `mv state.json.tmp state.json` (atomar auf POSIX-FS)

So bleibt die Datei bei Crashes konsistent.

### 2.4 Lifecycle-Hooks

Worker-Entrypoint:
- VOR Phase-Skript: status auf `running`, neue Iteration-Entry mit `started_at`, `flags`, `n = previous.n + 1`
- NACH Phase-Skript: status auf Result-Status, `finished_at`, `exit_code`, ggf. `failed_gate`, `error_message`
- `updated_at` immer auf jetzt

---

## 3. Branch-Naming

Format: `ai/<task-id>-<timestamp>`

- `<task-id>`: vom User vergeben, validiert gegen `^[a-z0-9][a-z0-9-]*[a-z0-9]$`, max 40 Zeichen
- `<timestamp>`: Unix-Sekunden vom **ersten** `concept`-Run, gespeichert in `state.json.repo.feature_branch`

Bei `concept --fresh` wird der Branch *nicht* neu generiert — derselbe Branch bleibt erhalten, weil Re-Tries derselben Task auf derselben Remote-Adresse landen sollen.

Bei Konflikt mit existierendem Remote-Branch: git push schlägt fehl, Phase exit-code 1, Hinweis im Result-JSON dass der User den alten Branch löschen oder Force-Push manuell machen muss.

---

## 4. Worker-Entrypoint-Logik

`/usr/local/bin/worker-entrypoint.sh`:

```text
1. Argumente parsen:
   $1 = phase name (concept|implement|diff|push|commit-message|shell)
   $2 = task_id

2. Environment validieren:
   - REPO_URL, REPO_TOKEN, BASE_BRANCH gesetzt? sonst Exit 2
   - CLAUDE_CODE_OAUTH_TOKEN gesetzt für phases die Claude nutzen? sonst Exit 3
   - Volume gemountet auf /workspace? sonst Exit 1

3. State initialisieren:
   - Wenn /workspace/.agent/state.json fehlt: anlegen mit defaults
   - Wenn Phase nicht in state.json bekannt: Eintrag anlegen

4. Lock acquiren (siehe Abschnitt 6)
   - bei Lock-Konflikt: Exit 6

5. Phase-Skript laden via phases/registry.sh

6. Vorbedingungen prüfen:
   - phase_<name>_preconditions aufrufen
   - bei Verletzung: Exit 2 mit der Fehlermeldung des Skripts

7. Trap setzen für Cleanup (Lock-Release, State-Update auf failed bei Crash)

8. State auf "running" setzen, neue iteration entry

9. phase_<name>_run aufrufen, exit code abfangen

10. State final updaten:
    - status nach exit code
    - finished_at = now
    - exit_code, error_message wenn vorhanden

11. Result-JSON auf stdout

12. Lock release, exit mit Phase-Exit-Code
```

Spezialfall `phase shell`: kein State-Update, einfach `bash -i` mit cd nach `/workspace`.

---

## 5. Phase-Konventionen

Jede Phase liefert in `phases/<name>.sh`:

```bash
#!/usr/bin/env bash
# Wird gesourced vom worker-entrypoint, nicht direkt ausgeführt.

phase_concept_help() {
    echo "Konzept-Phase: Aufgabe analysieren und Plan formulieren."
}

phase_concept_preconditions() {
    # Stdout: nichts. Stderr: Fehlermeldung bei Verletzung.
    # Exit 0 = OK, sonst exit code für die ganze Phase.
    [[ -d /workspace/.git ]] || {
        # Erster Run, das ist OK — Repo wird geklont
        return 0
    }
    return 0
}

phase_concept_run() {
    # Hauptlogik der Phase.
    # Hat Zugriff auf:
    # - alle env vars (TASK_ID, REPO_URL, REPO_TOKEN, BASE_BRANCH, PHASE_FLAGS, ...)
    # - lib/-Funktionen (state_*, log_*, result_*, lock_*)
    # Schreibt am Ende Result-JSON über result_emit().
    # Returnt exit code.
    ...
}
```

`phases/registry.sh` enthält:

```bash
PHASE_NAMES=(concept implement diff push commit-message)
PHASE_ORDER_IN_LIFECYCLE=(concept implement diff push)

# Jede Phase muss als Datei phases/<name>.sh existieren.
# Sub-Phasen wie commit-message sind in PHASE_NAMES aber nicht in
# PHASE_ORDER_IN_LIFECYCLE — sie werden von anderen Phasen aufgerufen.
```

---

## 6. Locking

Lock-Datei: `/workspace/.agent/.lock`

Inhalt (JSON):
```json
{"pid": 12345, "phase": "implement", "started_at": "2026-04-30T10:00:00Z"}
```

`lib/lock.sh`:

```bash
lock_acquire() {
    local phase="$1"
    if [[ -f /workspace/.agent/.lock ]]; then
        local existing_phase=$(jq -r '.phase' /workspace/.agent/.lock)
        local existing_started=$(jq -r '.started_at' /workspace/.agent/.lock)
        echo "Lock bereits gesetzt: $existing_phase seit $existing_started" >&2
        return 6
    fi
    jq -n --arg pid "$$" --arg phase "$phase" --arg ts "$(date -u +%FT%TZ)" \
        '{pid: $pid|tonumber, phase: $phase, started_at: $ts}' \
        > /workspace/.agent/.lock.tmp
    mv /workspace/.agent/.lock.tmp /workspace/.agent/.lock
    trap lock_release EXIT INT TERM
}

lock_release() {
    rm -f /workspace/.agent/.lock
}
```

Stale-Lock-Detection: wenn der Lock älter als 4 Stunden ist (Container-Restart hat trap nicht ausgeführt), erlaubt das CLI auf dem Host das Forcieren via `agent <phase> <task-id> --force-unlock` mit ausdrücklicher Warnung.

---

## 7. Result-JSON-Format

Jede Phase emittiert auf stdout (letzte Zeile) ein Result-JSON. Schema-Validierung in den Tests.

Allgemeines Schema (siehe `schemas/result.<phase>.schema.json`):

```json
{
  "phase": "concept|implement|diff|push|commit-message",
  "task_id": "task-001",
  "iteration": 2,
  "status": "completed|failed|quality_gate_failed|no_changes|...",
  "started_at": "ISO8601",
  "finished_at": "ISO8601",
  "duration_ms": 90000,
  "exit_code": 0,
  ...phase-spezifische felder
}
```

Phase-spezifisch:

**concept:** `concept_path`, `concept_history_count`, `claude_session_id`, `claude_total_cost_usd`

**implement:** `changed_files: [...]`, `quality_gates: {pint: pass|fail|skip, pest: ..., phpstan: ...}`, `claude_session_id`, `claude_total_cost_usd`, optional `failed_gate`

**diff:** `files_changed`, `insertions`, `deletions`

**push:** `branch`, `commit_sha`, `remote_url`, `commit_subject`

**commit-message:** `subject`, `body`

---

## 8. CLI-Interna

### 8.1 Argument-Parsing

Eigenes `lib/parse_args.sh` mit POSIX-Bash-Konstrukten, kein externes Tool.

Pattern:
```bash
parse_args "$@"
# Setzt globale Variablen:
# ARG_COMMAND="concept"
# ARG_TASK_ID="task-001"
# ARG_FLAGS_FRESH=true
# ARG_FLAGS_MAX_TURNS=50
# ARG_REMAINING=()
```

### 8.2 Help-System

`./agent help` und `./agent help <command>` zeigen lesbare Hilfe.
Help-Texte stehen pro Command in einer Funktion `help_<command>()` in `lib/help.sh`.

### 8.3 Error-Handling

`lib/error.sh` mit Funktionen wie:
```bash
die() {
    local code="$1"; shift
    echo "Error: $*" >&2
    exit "$code"
}

die_if_no_task() {
    local task_id="$1"
    [[ -d "$HOME/.agent/tasks/$task_id" ]] || die 2 "Task '$task_id' nicht bekannt. Vorhandene Tasks: $(ls "$HOME/.agent/tasks/" 2>/dev/null | tr '\n' ' ')"
}
```

Konsistente Exit-Codes wie in Phase-Skripten.

### 8.4 docker-compose-Wrapper

`lib/docker.sh::docker_run_phase()`:
```bash
# Argumente: phase, task_id
# - Lädt credentials.env aus ~/.agent/tasks/<task-id>/
# - Lädt CLAUDE_CODE_OAUTH_TOKEN aus ~/.agent/claude_oauth_token
# - docker compose run --rm \
#     -v task_ws_<task_id>:/workspace \
#     -v ~/.agent/tasks/<task_id>/description.md:/run/agent/description.md:ro \
#     -e PHASE=<phase> \
#     -e TASK_ID=<task_id> \
#     -e REPO_URL=... -e REPO_TOKEN=... -e BASE_BRANCH=... \
#     -e CLAUDE_CODE_OAUTH_TOKEN=... \
#     -e PHASE_FLAGS=<json> \
#     worker
```

Der `description.md`-Mount ist read-only und wird nur reingegeben wenn die
Datei auf dem Host existiert. Wichtig: der Container-Pfad ist `/run/agent/description.md`
außerhalb von `/workspace`. Ein File-Bind-Mount nach `/workspace/.agent/description.md`
würde Docker dazu zwingen, das parent-Verzeichnis `/workspace/.agent/` als root
anzulegen, bevor das Volume drüber gemountet wird — Resultat: der `agent`-User
(uid 1000) kann nicht in seinen eigenen Workspace schreiben. `/run/agent/...` umgeht das.

### 8.5 Volume-Management

`lib/tasks.sh::task_create_volume()`:
```bash
# Erstellt Volume task_ws_<task-id>
docker volume create "task_ws_$1" >/dev/null
```

`task_delete_volume()`:
```bash
docker volume rm "task_ws_$1" 2>/dev/null
```

`task_volume_exists()`:
```bash
docker volume inspect "task_ws_$1" >/dev/null 2>&1
```

---

## 9. Build- und Test-Mechanik

### 9.1 Test-Wrapper

`tests/run-tests.sh`:
```bash
#!/usr/bin/env bash
# Optionen:
# --bats           nur Bash-Unit-Tests
# --integration    nur Container-Integrationstests
# (default: beide)

# Bats:
docker run --rm -v "$PWD:/code" -w /code bats/bats:latest tests/bats/

# Integration:
bash tests/integration/run-all.sh
```

### 9.2 Bats-Tests

In `tests/bats/`. Jeder File testet eine Lib-Datei. Beispiel:

```bash
# tests/bats/test_state.bats

setup() {
    export TEST_DIR=$(mktemp -d)
    export STATE_FILE="$TEST_DIR/state.json"
    source lib/state.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "state_init creates valid state.json" {
    state_init "task-001" "https://example.com/repo.git" "main"
    [ -f "$STATE_FILE" ]
    run jq -r '.task_id' "$STATE_FILE"
    [ "$status" -eq 0 ]
    [ "$output" = "task-001" ]
}

@test "state_add_iteration appends to phase history" {
    state_init "task-001" "https://example.com/repo.git" "main"
    state_add_iteration "concept" '{"flags":{"fresh":false}}'
    run jq '.phases.concept.iterations | length' "$STATE_FILE"
    [ "$output" = "1" ]
}
```

### 9.3 Integration-Tests

In `tests/integration/`. Nutzen einen lokalen Bare-Git-Repo als „Remote" (`fixtures/fake-remote-repo/`) und ein Mock-Claude-Binary das deterministischen Output zurückgibt.

Mock-Claude (`tests/integration/fixtures/mock-claude/claude`):
```bash
#!/usr/bin/env bash
# Reagiert auf bestimmte Prompts mit vordefinierten Outputs.
# Liest stdin, prüft Marker, schreibt deterministisches JSON.
case "$(cat)" in
    *"create HelloWorld"*)
        cat <<'EOF'
{"type":"result","subtype":"success","result":"# Konzept: HelloWorld\n\nFolgende Klassen anlegen: ...","total_cost_usd":0.001}
EOF
        ;;
    *)
        echo '{"type":"result","subtype":"error","is_error":true,"result":"Mock claude: unknown prompt"}'
        exit 1
        ;;
esac
```

In Tests wird das Image *für den Test* gebaut mit `--build-arg CLAUDE_BIN=/test-mock/claude` o.ä., sodass die echte Claude-CLI durch den Mock ersetzt ist.

### 9.4 CI

`.github/workflows/test.yml`:
- Trigger: push to main, alle PRs
- Steps:
  - Checkout
  - Bash-Lint via `shellcheck` über `lib/`, `phases/`, `agent`, `docker/worker-entrypoint.sh`
  - Bats-Tests
  - Integration-Tests
- Kein Echtsystem-Test (kein Claude-Token in CI)

---

## 10. System-Prompt-Komposition

Ein Phase-Skript baut den finalen System-Prompt aus mehreren Layern zusammen:

```text
1. /workspace/.agent/runtime/<phase>.system.merged.md  <-- finale Datei für claude
   = <phase>.system.md (Worker-eigen, aus prompts/)
   + user.global.system.md (User-globale Konventionen, optional)
   + dynamische Marker (z.B. Task-ID, base-branch)

(CLAUDE.md im Repo wird von Claude Code automatisch zusätzlich geladen — das müssen wir nicht zusammenführen.)
```

Die Merge-Logik in `lib/prompts.sh::build_system_prompt()`:

```bash
build_system_prompt() {
    local phase="$1"
    local out="/workspace/.agent/runtime/${phase}.system.merged.md"

    {
        cat "/usr/local/share/agent/prompts/${phase}.system.md"

        if [[ -f "/usr/local/share/agent/prompts/user.global.system.md" ]]; then
            echo ""
            echo "---"
            echo ""
            cat "/usr/local/share/agent/prompts/user.global.system.md"
        fi

        # Dynamische Marker
        echo ""
        echo "---"
        echo ""
        echo "# Aktueller Kontext"
        echo "- Task-ID: $TASK_ID"
        echo "- Base-Branch: $BASE_BRANCH"
        echo "- Iteration: $ITERATION"
    } > "$out"
}
```

Prompts werden beim Image-Build nach `/usr/local/share/agent/prompts/` kopiert. User-globale Prompt wird *bei Image-Build* gelesen — wenn der User sie ändert, muss `agent rebuild` gemacht werden. Alternative wäre ein Bind-Mount, aber das vermischt Host-Config mit Container-Internals.

---

## 11. Verifikations-Phase nach Implement

Nach Claude-Session in `phase_implement_run()`:

```bash
# Quality-Gate-Verifikation (NICHT Self-Repair-Loop, der ist in Claudes Hand)
local gates_result='{"pint":"skip","pest":"skip","phpstan":"skip"}'

if [[ -x vendor/bin/pint ]]; then
    if vendor/bin/pint --test &> "/workspace/.agent/logs/pint.${ITERATION}.log"; then
        gates_result=$(echo "$gates_result" | jq '.pint = "pass"')
    else
        gates_result=$(echo "$gates_result" | jq '.pint = "fail"')
    fi
fi

if [[ -x vendor/bin/pest ]]; then
    if vendor/bin/pest --no-coverage &> "/workspace/.agent/logs/pest.${ITERATION}.log"; then
        gates_result=$(echo "$gates_result" | jq '.pest = "pass"')
    else
        gates_result=$(echo "$gates_result" | jq '.pest = "fail"')
    fi
elif [[ -x vendor/bin/phpunit ]]; then
    # analog für phpunit
    ...
fi

if [[ -f phpstan.neon ]] && [[ -x vendor/bin/phpstan ]]; then
    if vendor/bin/phpstan analyse --no-progress &> "/workspace/.agent/logs/phpstan.${ITERATION}.log"; then
        gates_result=$(echo "$gates_result" | jq '.phpstan = "pass"')
    else
        gates_result=$(echo "$gates_result" | jq '.phpstan = "advisory_fail"')
    fi
fi

# Speichern
echo "$gates_result" > "/workspace/.agent/quality-gates.${ITERATION}.json"

# Status entscheiden
local pint=$(echo "$gates_result" | jq -r '.pint')
local pest=$(echo "$gates_result" | jq -r '.pest')

if [[ "$pint" == "fail" || "$pest" == "fail" ]]; then
    return 4   # quality_gate_failed
fi
return 0
```

Bei `quality_gate_failed` bleibt der Workspace unangetastet, der User kann via `agent shell` reingucken oder via `agent implement <task-id> --continue` einen weiteren Lauf anstoßen — der Implement-Prompt wird in dem Fall mit den Failure-Logs als zusätzlichem Input gefüttert.

---

## 12. Risiken und bekannte Grenzen

- **Composer post-install scripts mit DB-Anforderung**: kann v1 zum Stolpern bringen. Workaround: User entfernt entsprechende Skripte temporär oder migriert nach SQLite.
- **Sehr große Repos**: clone-time + composer-install können bei initialer concept-Phase mehrere Minuten dauern. Kein Timeout in v1, aber wenn sich das als Problem zeigt: `--depth`-Option am clone überdenken.
- **Quota-Verbrauch bei vielen Iterationen**: jede `implement`-Iteration kostet ein paar Dollar Subscription-Quota. v1 hat keine Limits, nur Logging.
- **Concurrent CLI-Aufrufe für denselben Task**: durch Lock abgedeckt, aber Race-Condition zwischen `agent edit-concept` und parallelem `agent concept` möglich. Dokumentieren, kein Fix in v1.
- **Token-Rotation**: GitHub-PATs und Claude-OAuth-Token haben begrenzte Lebensdauer. v1 zeigt klare Fehlermeldungen, aber kein automatisches Renewal.
