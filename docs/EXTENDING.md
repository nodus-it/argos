# EXTENDING — neue Phase oder Lib-Funktion hinzufügen

Eine neue Phase braucht beide Seiten: Bash (Worker-Ausführung) und PHP (Steuerung, CLI).

## Beispiel: Phase `analyze`

Pre-Concept Repo-Inspektion ohne LLM (siehe BACKLOG).

### 1. Bash — Phase-Skript

`worker/phases/analyze.sh`:

```bash
#!/usr/bin/env bash
# Wird gesourced vom worker-entrypoint, nicht direkt ausgeführt.

phase_analyze_help() {
    echo "Analyze-Phase: Repo-Map ohne Claude erzeugen."
}

phase_analyze_preconditions() {
    [[ -d /workspace/.git ]] || { echo "analyze: /workspace nicht initialisiert" >&2; return 2; }
    return 0
}

phase_analyze_run() {
    cd /workspace || return 1
    mkdir -p /workspace/.agent/logs

    local started_at finished_at
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

    find . -name '*.php' | head -50 > /workspace/.agent/repo-map.txt

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    result_emit \
        phase analyze \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status completed \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int exit_code 0
    return 0
}
```

### 2. Bash — Registry erweitern

`worker/phases/registry.sh`:

```bash
PHASE_NAMES=(concept implement diff push commit-message respond analyze)
PHASE_ORDER_IN_LIFECYCLE=(analyze concept implement diff push)
```

### 3. Result-Schema

`worker/schemas/result.analyze.schema.json` anlegen, Pflicht-Felder analog zu den anderen Result-Schemas. Bats-Test in `worker/tests/bats/test_result_schemas.bats` ergänzen.

### 4. State-Schema

Wenn die Phase in `state.json.phases.*` auftaucht: `worker/schemas/state.schema.json` und `worker/lib/state.sh::state_init` erweitern. **`schema_version` hochzählen.**

### 5. PHP — Queue-Job

`RunPhaseJob` deckt automatisch alle Phasen ab — kein neuer Job-Typ nötig. Nur der Phase-Name `'analyze'` wird übergeben.

### 6. PHP — Artisan-Command

`app/Console/Commands/AgentAnalyze.php`:

```php
class AgentAnalyze extends Command
{
    protected $signature = 'agent:analyze {task}';

    public function handle(PhaseRunner $runner): void
    {
        $task = Task::where('name', $this->argument('task'))->firstOrFail();
        $runner->run($task, 'analyze');
    }
}
```

### 7. Web-UI — Action-Button

In `TaskResource` (oder `ViewTask`) einen neuen Action-Button `analyze` hinzufügen, der `RunPhaseJob::dispatch($task->id, 'analyze')` auslöst.

### 8. Tests

- Bash-Unit: `worker/tests/bats/test_phase_analyze.bats` (Hilfsfunktionen als `_analyze_*` aus dem Phase-Skript heraus testbar via source)
- Integration: `worker/tests/integration/test_phase_lifecycle.sh` um `agent analyze`-Aufruf erweitern, oder eigener Test
- PHP: Feature-Test für den Artisan-Command

### 9. Dokumentation

`docs/WORKER-CONCEPT.md` (Phasen-Abschnitt) und `docs/IMPLEMENTATION.md` (Sections 5 + 7) aktualisieren.

---

## Eine neue Lib-Funktion (Bash)

1. Funktion in `worker/lib/<modul>.sh` mit Docstring
2. Bats-Test in `worker/tests/bats/test_<modul>.bats`
3. `shellcheck --severity=warning` muss clean sein
4. In Phase-Skripten oder Entrypoint sourcen wo nötig

## Konventionen-Erinnerung

- `set -euo pipefail; IFS=$'\n\t'` in Top-Level-Skripten — nicht in lib-Files (werden gesourced).
- `[[ ... ]]` statt `[ ... ]`, `(( ... ))` für Arithmetik.
- `local` für alle Variablen in Funktionen.
- Tokens niemals loggen — `set +x` falls debug-trace aktiv.
- State-Mutationen atomic via `.tmp + mv` (wie in `worker/lib/state.sh`).

Mehr Details: [`CLAUDE.md`](../CLAUDE.md).
