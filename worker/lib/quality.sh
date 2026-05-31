#!/usr/bin/env bash
# lib/quality.sh — quality gate helpers shared by implement and respond phases.
#
# Functions:
#   quality_changed_php_files        NUL-separated changed PHP files in /workspace
#   quality_gates_run  ITERATION     run all gates, print JSON, write logs
#   quality_gate_verdict  GATES_JSON print failing gate name, exit 4 if any fails

# shellcheck shell=bash

# quality_ensure_workspace_dotenv: make sure /workspace/.env exists so
# Laravel's Foundation\Bootstrap\LoadEnvironmentVariables (via vlucas/
# phpdotenv) does not emit a `file_get_contents(/workspace/.env): Failed
# to open` warning every test run. That warning is harmless on its own
# (safeLoad swallows the result) but it has historically poisoned the
# Pest output enough that fix-session agents misinterpret the failure
# mode, and some custom error handlers convert PHP warnings to
# exceptions. We seed the file from `.env.example` (which the target
# Laravel project ships) — every actual value comes from the worker's
# docker -e env vars (APP_KEY etc.) and phpunit.xml `force="true"`
# entries, so the seeded `.env` only needs to exist; contents don't
# matter. Idempotent.
quality_ensure_workspace_dotenv() {
    if [[ -f /workspace/.env ]]; then
        return 0
    fi
    if [[ -f /workspace/.env.example ]]; then
        cp /workspace/.env.example /workspace/.env
        log_info "workspace: seeded .env from .env.example"
        return 0
    fi
    # No example to copy — drop a minimal stub so the file at least exists.
    : > /workspace/.env
    log_info "workspace: created empty .env (no .env.example present)"
    return 0
}

# quality_changed_php_files: list PHP files modified or added in /workspace.
# Output: NUL-separated file paths relative to /workspace root.
quality_changed_php_files() {
    (cd /workspace && {
        git diff -z --name-only --diff-filter=ACMR HEAD -- '*.php'
        git ls-files -z --others --exclude-standard -- '*.php'
    } | sort -uz)
}

# quality_gates_run: run all quality gates and return the gates JSON.
# Args: $1=iteration (used in log filenames)
# Output: JSON object on stdout.
# Side effects: writes logs under /workspace/.agent/logs/
# shellcheck disable=SC2317
quality_gates_run() {
    local iteration="${1:-1}"
    local gates='{"artisan":"skip","pint":"skip","pest":"skip","phpunit":"skip","phpstan":"skip","migrations":"skip","debug_code":"skip","test_presence":"skip"}'

    # ── 1. php artisan smoke test ─────────────────────────────────────────
    # Verifies the app bootstraps: service providers, autoload, config.
    # A fatal error here makes test results meaningless.
    if [[ -f /workspace/artisan ]]; then
        if (cd /workspace && php artisan list --no-ansi) \
                &> "/workspace/.agent/logs/artisan-smoke.${iteration}.log"; then
            gates="$(printf '%s' "$gates" | jq '.artisan = "pass"')"
        else
            gates="$(printf '%s' "$gates" | jq '.artisan = "fail"')"
        fi
    fi

    # ── 2. Pint (code style) ─────────────────────────────────────────────
    # Only lint files Claude actually touched — pre-existing debt is not
    # Claude's to fix and would otherwise block every implement run.
    if [[ -x /workspace/vendor/bin/pint ]]; then
        local -a changed_files=()
        mapfile -d '' -t changed_files < <(quality_changed_php_files)

        if [[ ${#changed_files[@]} -eq 0 ]]; then
            gates="$(printf '%s' "$gates" | jq '.pint = "skip"')"
        else
            if (cd /workspace && vendor/bin/pint --test "${changed_files[@]}") \
                    &> "/workspace/.agent/logs/pint.${iteration}.log"; then
                gates="$(printf '%s' "$gates" | jq '.pint = "pass"')"
            else
                gates="$(printf '%s' "$gates" | jq '.pint = "fail"')"
            fi
        fi
    fi

    # ── 3. Pest / PHPUnit ────────────────────────────────────────────────
    if [[ -x /workspace/vendor/bin/pest ]]; then
        if (cd /workspace && vendor/bin/pest --no-coverage) \
                &> "/workspace/.agent/logs/pest.${iteration}.log"; then
            gates="$(printf '%s' "$gates" | jq '.pest = "pass"')"
        else
            gates="$(printf '%s' "$gates" | jq '.pest = "fail"')"
        fi
    elif [[ -x /workspace/vendor/bin/phpunit ]]; then
        if (cd /workspace && vendor/bin/phpunit) \
                &> "/workspace/.agent/logs/phpunit.${iteration}.log"; then
            gates="$(printf '%s' "$gates" | jq '.phpunit = "pass"')"
        else
            gates="$(printf '%s' "$gates" | jq '.phpunit = "fail"')"
        fi
    fi

    # ── 4. PHPStan ───────────────────────────────────────────────────────
    if [[ -f /workspace/phpstan.neon || -f /workspace/phpstan.neon.dist ]] \
            && [[ -x /workspace/vendor/bin/phpstan ]]; then
        if (cd /workspace && vendor/bin/phpstan analyse --no-progress) \
                &> "/workspace/.agent/logs/phpstan.${iteration}.log"; then
            gates="$(printf '%s' "$gates" | jq '.phpstan = "pass"')"
        else
            gates="$(printf '%s' "$gates" | jq '.phpstan = "fail"')"
        fi
    fi

    # ── 5. Migration syntax check ─────────────────────────────────────────
    # php -l validates PHP syntax without needing a live DB connection.
    # Only checks files Claude added in this session.
    local -a new_migrations=()
    mapfile -d '' -t new_migrations < <(
        cd /workspace && git diff -z --name-only --diff-filter=A HEAD \
            -- 'database/migrations/*.php' 2>/dev/null
    )
    if [[ ${#new_migrations[@]} -gt 0 ]]; then
        local migrations_ok=true
        : > "/workspace/.agent/logs/migrations.${iteration}.log"
        for mf in "${new_migrations[@]}"; do
            if ! php -l "/workspace/$mf" \
                    >> "/workspace/.agent/logs/migrations.${iteration}.log" 2>&1; then
                migrations_ok=false
            fi
        done
        if [[ "$migrations_ok" == "true" ]]; then
            gates="$(printf '%s' "$gates" | jq '.migrations = "pass"')"
        else
            gates="$(printf '%s' "$gates" | jq '.migrations = "fail"')"
        fi
    fi

    # ── 6. Debug code detection ──────────────────────────────────────────
    # dd(), dump(), ray(), var_dump(), ddd() in non-test PHP files are
    # debugging artefacts that must not reach production.
    local -a all_changed=()
    mapfile -d '' -t all_changed < <(quality_changed_php_files)
    local -a nontestphp=()
    for f in "${all_changed[@]}"; do
        if [[ "$f" != tests/* && "$f" != test/* ]]; then
            nontestphp+=("/workspace/$f")
        fi
    done
    if [[ ${#nontestphp[@]} -gt 0 ]]; then
        if grep -lE '\bdd\(|\bdump\(|\bray\(|\bvar_dump\(|\bddd\(' \
                "${nontestphp[@]}" \
                > "/workspace/.agent/logs/debug-code.${iteration}.log" 2>/dev/null; then
            gates="$(printf '%s' "$gates" | jq '.debug_code = "fail"')"
        else
            gates="$(printf '%s' "$gates" | jq '.debug_code = "pass"')"
        fi
    fi

    # ── 7. Test presence (non-blocking) ──────────────────────────────────
    # For every new PHP file under app/, a matching *Test.php should exist.
    # "warn" is logged but never blocks the push.
    local -a new_app_files=()
    mapfile -d '' -t new_app_files < <(
        cd /workspace && git diff -z --name-only --diff-filter=A HEAD \
            -- 'app/*.php' 'app/**/*.php' 2>/dev/null
    )
    if [[ ${#new_app_files[@]} -gt 0 ]]; then
        local -a untested=()
        for f in "${new_app_files[@]}"; do
            local base
            base="$(basename "$f" .php)"
            if ! find /workspace/tests -name "${base}Test.php" 2>/dev/null | grep -q .; then
                untested+=("$f")
            fi
        done
        if [[ ${#untested[@]} -gt 0 ]]; then
            printf '%s\n' "${untested[@]}" \
                > "/workspace/.agent/logs/untested.${iteration}.log"
            gates="$(printf '%s' "$gates" | jq '.test_presence = "warn"')"
        else
            gates="$(printf '%s' "$gates" | jq '.test_presence = "pass"')"
        fi
    fi

    printf '%s' "$gates" > "/workspace/.agent/quality-gates.${iteration}.json"
    printf '%s' "$gates"
}

# quality_gate_log_path: filesystem path of the gate log for one iteration.
# Args: $1=gate slug (artisan|pint|pest|phpunit|phpstan|migrations|debug_code),
#       $2=log suffix (e.g. "2" for the initial run, "2.fix1" for a fix retry).
# Output: absolute path on stdout.
quality_gate_log_path() {
    local gate="$1"
    local suffix="$2"
    local base
    case "$gate" in
        artisan)    base="artisan-smoke" ;;
        debug_code) base="debug-code" ;;
        *)          base="$gate" ;;
    esac
    printf '%s/%s.%s.log' "${QUALITY_LOG_DIR:-/workspace/.agent/logs}" "$base" "$suffix"
}

# quality_gate_log_converged: 0 if the latest gate log is byte-identical to the
# previous attempt, 1 otherwise. Used by implement/respond to bail out of the
# fix loop when Claude is producing the same failure output over and over —
# further fix sessions burn budget without changing the outcome.
#
# Args: $1=gate slug, $2=iteration number, $3=fix retry index (1+)
quality_gate_log_converged() {
    local gate="$1"
    local iter="$2"
    local fix_n="$3"

    local current previous
    current="$(quality_gate_log_path "$gate" "${iter}.fix${fix_n}")"
    if (( fix_n > 1 )); then
        previous="$(quality_gate_log_path "$gate" "${iter}.fix$((fix_n - 1))")"
    else
        previous="$(quality_gate_log_path "$gate" "$iter")"
    fi

    [[ -f "$current" && -f "$previous" ]] || return 1

    local cur_hash prev_hash
    cur_hash="$(sha256sum "$current" 2>/dev/null | awk '{print $1}')"
    prev_hash="$(sha256sum "$previous" 2>/dev/null | awk '{print $1}')"
    [[ -n "$cur_hash" && "$cur_hash" == "$prev_hash" ]]
}

# quality_gate_verdict: determine which blocking gate failed (if any).
# Args: $1=gates_json
# Output: name of first failing gate on stdout, or empty string.
# Return: 0 if all blocking gates pass or skip, 4 if any blocking gate fails.
# Note: test_presence is intentionally non-blocking and never evaluated here.
quality_gate_verdict() {
    local gates="$1"
    local artisan pint pest phpunit phpstan migrations debug_code
    artisan="$(printf '%s'    "$gates" | jq -r '.artisan    // "skip"')"
    pint="$(printf '%s'       "$gates" | jq -r '.pint       // "skip"')"
    pest="$(printf '%s'       "$gates" | jq -r '.pest       // "skip"')"
    phpunit="$(printf '%s'    "$gates" | jq -r '.phpunit    // "skip"')"
    phpstan="$(printf '%s'    "$gates" | jq -r '.phpstan    // "skip"')"
    migrations="$(printf '%s' "$gates" | jq -r '.migrations // "skip"')"
    debug_code="$(printf '%s' "$gates" | jq -r '.debug_code // "skip"')"
    if [[ "$artisan"    == "fail" ]]; then printf '%s' "artisan";    return 4; fi
    if [[ "$pint"       == "fail" ]]; then printf '%s' "pint";       return 4; fi
    if [[ "$pest"       == "fail" ]]; then printf '%s' "pest";       return 4; fi
    if [[ "$phpunit"    == "fail" ]]; then printf '%s' "phpunit";    return 4; fi
    if [[ "$phpstan"    == "fail" ]]; then printf '%s' "phpstan";    return 4; fi
    if [[ "$migrations" == "fail" ]]; then printf '%s' "migrations"; return 4; fi
    if [[ "$debug_code" == "fail" ]]; then printf '%s' "debug_code"; return 4; fi
    printf ''
    return 0
}

# quality_gate_fix_prompt: build a focused prompt to fix a failing gate.
# Args: $1=gate_name, $2=log_file (path to gate output; may be absent)
# Output: prompt text on stdout (pass to claude via stdin or temp file)
#
# The embedded log excerpt uses head+tail (default 50 head / 200 tail lines)
# because pest/phpunit/phpstan emit the failure summary at the END of the
# output. A plain head() truncation hides the actual failures behind hundreds
# of "PASS"/progress lines and tricks the agent into thinking the gate is
# green when it isn't.
quality_gate_fix_prompt() {
    local gate="$1"
    local log_file="${2:-}"
    local head_lines="${QUALITY_GATE_LOG_HEAD_LINES:-50}"
    local tail_lines="${QUALITY_GATE_LOG_TAIL_LINES:-200}"

    printf '# Quality-Gate-Fix: %s\n\n' "$gate"
    printf 'Du befindest dich im Workspace `/workspace`. Nach deiner Implementierung ist das\n'
    printf 'Quality-Gate `%s` fehlgeschlagen.\n\n' "$gate"

    if [[ -n "$log_file" && -f "$log_file" && -s "$log_file" ]]; then
        printf '## Verbindliche Vorgehensweise\n\n'
        printf '1. Lies **zuerst** die vollständige Log-Datei `%s` mit `cat` /\n' "$log_file"
        printf '   `Read`. Der unten eingebettete Auszug ist gekürzt und kann den\n'
        printf '   eigentlichen Fehler verbergen.\n'
        printf '2. Wenn du das Gate selbst nachprüfst, ruf den Test-Command **ohne Pipe**\n'
        printf '   auf — `vendor/bin/pest | tail -N` verbirgt den Exit-Code von `pest` und\n'
        printf '   du hältst einen fehlschlagenden Lauf fälschlich für grün. Wenn du eine\n'
        printf '   Pipe brauchst, setze vorher `set -o pipefail` und prüfe `$?` separat,\n'
        printf '   oder schreibe nach `/tmp/foo.log` und lies die Datei.\n'
        printf '3. Wiederhol das Gate, bis es wirklich grün ist — verlasse dich nicht auf\n'
        printf '   den Eindruck einer Sitzung davor, sondern auf das aktuelle Resultat.\n\n'
    fi

    case "$gate" in
        artisan)
            printf '`php artisan list` schlägt fehl — die App bootet nicht.\n'
            printf 'Typische Ursachen: fehlende Use-Statements, Tippfehler in einem Service Provider,\n'
            printf 'fehlerhafte Config-Datei, fehlende Klasse in der Autoload-Map.\n'
            printf 'Prüfe: `php artisan list --no-ansi` und analysiere den Stack-Trace.\n\n'
            ;;
        pint)
            printf 'Code-Style (Pint) hat Verstösse gefunden.\n'
            printf 'Führe `vendor/bin/pint` (ohne `--test`) aus um alle automatisch zu beheben,\n'
            printf 'dann prüfe mit `vendor/bin/pint --test` ob alles grün ist.\n\n'
            ;;
        pest|phpunit)
            printf 'Tests sind fehlgeschlagen.\n'
            printf 'Analysiere jeden Failure: liegt es am Code oder am Test?\n'
            printf 'Korrigiere und führe die Tests nochmals aus — iteriere bis alle grün sind.\n'
            printf 'Beachte: Pest führt auch die Architecture-Tests in `tests/Arch/` aus —\n'
            printf 'z.B. „strict types in app/", „no debug calls in app/", „workers UI-isolated".\n'
            printf 'Ein Failure dort meldet sich nicht über einen klassischen Test-Stacktrace,\n'
            printf 'sondern als Arch-Regel-Verstoss am Anfang oder Ende der Pest-Ausgabe.\n\n'
            ;;
        phpstan)
            printf 'PHPStan hat Typ-Fehler oder andere statische Probleme gefunden.\n'
            printf 'Behebe alle gemeldeten Probleme. Einträge in der Baseline (`phpstan-baseline.neon`)\n'
            printf 'die nicht durch deine Änderungen entstanden sind, lass unangetastet.\n\n'
            ;;
        migrations)
            printf 'Eine neue Migration enthält einen PHP-Syntaxfehler.\n'
            printf 'Korrigiere den Fehler und prüfe mit `php -l <datei>` nach.\n\n'
            ;;
        debug_code)
            printf 'Debug-Aufrufe (dd, dump, ray, var_dump, ddd) wurden in App-Code gefunden.\n'
            printf 'Entferne alle Debug-Aufrufe aus den unten aufgelisteten Dateien.\n\n'
            ;;
    esac

    if [[ -n "$log_file" && -f "$log_file" && -s "$log_file" ]]; then
        local total_lines
        total_lines="$(wc -l < "$log_file")"
        printf '## Auszug aus dem Gate-Log\n\n'
        printf '_Quelle:_ `%s` (insgesamt %d Zeilen)\n\n' "$log_file" "$total_lines"
        printf '```\n'
        if (( total_lines <= head_lines + tail_lines )); then
            cat "$log_file"
        else
            head -n "$head_lines" "$log_file"
            printf '\n... (%d Zeilen ausgelassen — Datei direkt lesen für vollständigen Inhalt) ...\n\n' \
                "$(( total_lines - head_lines - tail_lines ))"
            tail -n "$tail_lines" "$log_file"
        fi
        printf '```\n\n'
    fi

    printf 'Führe das Gate nach der Korrektur selbst nochmals aus um sicherzustellen\n'
    printf 'dass es grün ist. KEIN git commit, KEIN git push.\n'
}
