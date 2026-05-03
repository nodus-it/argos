#!/usr/bin/env bash
# lib/quality.sh — quality gate helpers shared by implement and respond phases.
#
# Functions:
#   quality_changed_php_files        NUL-separated changed PHP files in /workspace
#   quality_gates_run  ITERATION     run all gates, print JSON, write logs
#   quality_gate_verdict  GATES_JSON print failing gate name, exit 4 if any fails

# shellcheck shell=bash

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
