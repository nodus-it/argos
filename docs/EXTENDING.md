# EXTENDING — neue Phase oder Lib-Funktion hinzufügen

## Eine neue Phase anlegen

Beispiel: Phase `analyze` — pre-concept Repo-Inspektion (siehe BACKLOG, Iteration 2).

### 1. Phase-Skript

`phases/analyze.sh`:

```bash
#!/usr/bin/env bash
# phases/analyze.sh — Pre-Concept Repo-Inspektion (kein LLM).
# shellcheck shell=bash

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

    # Hier Logik...
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

### 2. Registry erweitern

`phases/registry.sh`:

```bash
PHASE_NAMES=(concept implement diff push commit-message analyze)
PHASE_ORDER_IN_LIFECYCLE=(analyze concept implement diff push)
```

### 3. Result-Schema

`schemas/result.analyze.schema.json` — Pflicht-Felder analog zu den anderen Result-Schemas. Schema validieren via Bats-Test in `tests/bats/test_result_schemas.bats` ergänzen.

### 4. CLI-Subcommand

`agent` braucht einen Eintrag in `main()`:

```bash
analyze)
    cmd_phase "$ARG_COMMAND"
    ;;
```

Plus optional `cmd_phase` für phase-spezifische Flag-Handhabung erweitern, plus `lib/help.sh::help_show` und `help_analyze`.

### 5. Worker-Entrypoint

In `docker/worker-entrypoint.sh::main()` die case-Liste der bekannten Phasen erweitern:

```bash
concept|implement|diff|push|commit-message|analyze)
    ...
```

`_ep_validate_env` ggf. um phase-spezifische Env-Anforderungen erweitern.

### 6. Bats-Test (optional)

Hilfsfunktionen in `phases/analyze.sh` als eigene Funktionen `_analyze_*` — testbar via Bats wenn man sie sourced (mit Mock-Env).

### 7. Integration-Test ergänzen

`tests/integration/test_phase_lifecycle.sh` um einen `agent analyze`-Aufruf erweitern, falls Teil des Default-Lifecycle. Sonst eigener Test `tests/integration/test_phase_analyze.sh`.

### 8. State-Schema

`schemas/state.schema.json` listet aktuell nur die vier Default-Phasen. Wenn die neue Phase Lifecycle-relevant ist (= taucht in `state.json.phases.*` auf), Schema und `lib/state.sh::state_init` erweitern. **`schema_version` hochzählen** und Migrations-Logik bedenken — siehe CLAUDE.md.

### 9. Dokumentation

`WORKER-CONCEPT.md` und `IMPLEMENTATION.md` aktualisieren. `docs/EXAMPLE.md` erweitern.

## Eine neue Lib-Funktion

1. Funktion in der passenden `lib/<modul>.sh` mit Docstring oben (siehe CLAUDE.md "Bash-Stil")
2. Bats-Test in `tests/bats/test_<modul>.bats`
3. shellcheck (`bash tests/run-tests.sh --shellcheck`) muss clean sein (severity warning)
4. Wenn von außerhalb nutzbar: in den relevanten Phase-Skripten oder im `agent`-CLI sourcen

## Konventionen-Erinnerung

- `set -euo pipefail; IFS=$'\n\t'` in Top-Level-Skripten — nicht in lib-Files (die werden gesourced).
- `[[ ... ]]` statt `[ ... ]`, `(( ... ))` für Arithmetik.
- `local` für alle Variablen in Funktionen.
- Tokens niemals loggen, niemals via `echo` ausgeben — `set +x` falls debug-trace aktiv.
- `state_*`-Operationen sind atomic via `.tmp + mv` — neue State-Mutationen müssen das Pattern halten.

Mehr Details: [`CLAUDE.md`](../CLAUDE.md).
