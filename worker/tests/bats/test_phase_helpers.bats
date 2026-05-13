#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    mkdir -p /workspace/.agent/concept.history
    unset REPO_URL BASE_BRANCH ITERATION

    # shellcheck source=../../phases/commit-message.sh
    source worker/phases/commit-message.sh
    # shellcheck source=../../lib/quality.sh
    source worker/lib/quality.sh
    # shellcheck source=../../phases/implement.sh
    source worker/phases/implement.sh
    # shellcheck source=../../phases/push.sh
    source worker/phases/push.sh
    # shellcheck source=../../phases/concept.sh
    source worker/phases/concept.sh
}

teardown() {
    rm -rf /workspace/.agent
}

# --- _commit_message_extract ---

@test "_commit_message_extract: structured_output.subject + body → exit 0, subject erste Zeile" {
    envelope='{"structured_output":{"subject":"feat: add login","body":"Closes #42"},"is_error":false}'
    result="$(echo "$envelope" | _commit_message_extract)"
    [ $? -eq 0 ]
    subject="$(echo "$result" | head -n1)"
    [ "$subject" = "feat: add login" ]
}

@test "_commit_message_extract: structured_output.body in Ausgabe enthalten" {
    envelope='{"structured_output":{"subject":"feat: add login","body":"Closes #42"},"is_error":false}'
    result="$(echo "$envelope" | _commit_message_extract)"
    [[ "$result" == *"Closes #42"* ]]
}

@test "_commit_message_extract: Fallback auf .result als JSON wenn kein structured_output" {
    inner='{"subject":"fix: broken route","body":"Route was missing"}'
    envelope="{\"result\":$(echo "$inner" | jq -Rs .)}"
    result="$(echo "$envelope" | _commit_message_extract)"
    [ $? -eq 0 ]
    subject="$(echo "$result" | head -n1)"
    [ "$subject" = "fix: broken route" ]
}

@test "_commit_message_extract: kein subject und kein .result → exit 1" {
    run _commit_message_extract <<< '{"is_error":false}'
    [ "$status" -eq 1 ]
}

@test "_commit_message_extract: leeres structured_output.subject → Fallback auf .result" {
    inner='{"subject":"chore: cleanup","body":""}'
    envelope="{\"structured_output\":{\"subject\":\"\"},\"result\":$(echo "$inner" | jq -Rs .)}"
    result="$(echo "$envelope" | _commit_message_extract)"
    subject="$(echo "$result" | head -n1)"
    [ "$subject" = "chore: cleanup" ]
}

# --- quality_gate_verdict ---

@test "quality_gate_verdict: alle skip → exit 0, keine Ausgabe" {
    gates='{"artisan":"skip","pint":"skip","pest":"skip","phpunit":"skip","phpstan":"skip","migrations":"skip","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 0 ]
    [ -z "$output" ]
}

@test "quality_gate_verdict: artisan=fail → exit 4, gibt 'artisan' aus" {
    gates='{"artisan":"fail","pint":"skip","pest":"skip","phpunit":"skip","phpstan":"skip","migrations":"skip","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "artisan" ]
}

@test "quality_gate_verdict: pint=fail → exit 4, gibt 'pint' aus" {
    gates='{"artisan":"pass","pint":"fail","pest":"skip","phpunit":"skip","phpstan":"skip","migrations":"skip","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "pint" ]
}

@test "quality_gate_verdict: pest=fail → exit 4, gibt 'pest' aus" {
    gates='{"artisan":"pass","pint":"pass","pest":"fail","phpunit":"skip","phpstan":"skip","migrations":"skip","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "pest" ]
}

@test "quality_gate_verdict: phpunit=fail → exit 4, gibt 'phpunit' aus" {
    gates='{"artisan":"pass","pint":"pass","pest":"skip","phpunit":"fail","phpstan":"skip","migrations":"skip","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "phpunit" ]
}

@test "quality_gate_verdict: phpstan=fail → exit 4, gibt 'phpstan' aus" {
    gates='{"artisan":"pass","pint":"pass","pest":"pass","phpunit":"skip","phpstan":"fail","migrations":"skip","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "phpstan" ]
}

@test "quality_gate_verdict: migrations=fail → exit 4, gibt 'migrations' aus" {
    gates='{"artisan":"pass","pint":"pass","pest":"pass","phpunit":"skip","phpstan":"pass","migrations":"fail","debug_code":"skip","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "migrations" ]
}

@test "quality_gate_verdict: debug_code=fail → exit 4, gibt 'debug_code' aus" {
    gates='{"artisan":"pass","pint":"pass","pest":"pass","phpunit":"skip","phpstan":"pass","migrations":"pass","debug_code":"fail","test_presence":"skip"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 4 ]
    [ "$output" = "debug_code" ]
}

@test "quality_gate_verdict: test_presence=warn ist nicht blockierend" {
    gates='{"artisan":"pass","pint":"pass","pest":"pass","phpunit":"skip","phpstan":"pass","migrations":"pass","debug_code":"pass","test_presence":"warn"}'
    run quality_gate_verdict "$gates"
    [ "$status" -eq 0 ]
    [ -z "$output" ]
}

# --- _push_detect_platform ---

@test "_push_detect_platform: github.com URL → 'github'" {
    export REPO_URL="https://github.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "github" ]
}

@test "_push_detect_platform: gitlab.com URL → 'gitlab'" {
    export REPO_URL="https://gitlab.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "gitlab" ]
}

@test "_push_detect_platform: self-hosted gitlab URL → 'gitlab'" {
    export REPO_URL="https://gitlab.example.com/org/repo.git"
    result="$(_push_detect_platform)"
    [ "$result" = "gitlab" ]
}

@test "_push_detect_platform: unbekannte URL → leere Ausgabe" {
    export REPO_URL="https://codeberg.org/org/repo.git"
    result="$(_push_detect_platform)"
    [ -z "$result" ]
}

# --- _concept_archive_to_history ---

@test "_concept_archive_to_history: move verschiebt concept.md, nicht mehr vorhanden" {
    echo "# Konzept" > /workspace/.agent/concept.md
    _concept_archive_to_history move >/dev/null
    [ ! -f /workspace/.agent/concept.md ]
}

@test "_concept_archive_to_history: move legt History-Datei an" {
    echo "# Konzept" > /workspace/.agent/concept.md
    _concept_archive_to_history move >/dev/null
    count="$(find /workspace/.agent/concept.history -name 'concept.*.md' | wc -l)"
    [ "$count" -eq 1 ]
}

@test "_concept_archive_to_history: copy behaelt concept.md" {
    echo "# Konzept" > /workspace/.agent/concept.md
    _concept_archive_to_history copy >/dev/null
    [ -f /workspace/.agent/concept.md ]
}

@test "_concept_archive_to_history: gibt Anzahl History-Files (1) auf stdout" {
    echo "# Konzept" > /workspace/.agent/concept.md
    count="$(_concept_archive_to_history move)"
    [ "$count" -eq 1 ]
}

@test "_concept_archive_to_history: leere Notes werden nicht archiviert" {
    echo "# Konzept" > /workspace/.agent/concept.md
    touch /workspace/.agent/concept.notes.md  # leer
    _concept_archive_to_history move >/dev/null
    notes_count="$(find /workspace/.agent/concept.history -name 'concept.notes.*' | wc -l)"
    [ "$notes_count" -eq 0 ]
}

@test "_concept_archive_to_history: nicht-leere Notes werden archiviert" {
    echo "# Konzept" > /workspace/.agent/concept.md
    echo "Bitte mehr Tests" > /workspace/.agent/concept.notes.md
    _concept_archive_to_history move >/dev/null
    notes_count="$(find /workspace/.agent/concept.history -name 'concept.notes.*' | wc -l)"
    [ "$notes_count" -eq 1 ]
}

# --- _concept_emit_clone_err ---

@test "_concept_emit_clone_err: gibt Inhalt von clone.err nach stderr aus" {
    mkdir -p /workspace/.agent/logs
    printf "fatal: couldn't find remote ref main\n" > /workspace/.agent/logs/clone.err
    output="$(_concept_emit_clone_err 2>&1 1>/dev/null)"
    [[ "$output" == *"clone.err"* ]]
    [[ "$output" == *"couldn't find remote ref main"* ]]
}

@test "_concept_emit_clone_err: leere clone.err produziert keine Ausgabe" {
    mkdir -p /workspace/.agent/logs
    : > /workspace/.agent/logs/clone.err
    output="$(_concept_emit_clone_err 2>&1 1>/dev/null)"
    [ -z "$output" ]
}

@test "_concept_emit_clone_err: fehlende clone.err produziert keine Ausgabe" {
    mkdir -p /workspace/.agent/logs
    rm -f /workspace/.agent/logs/clone.err
    output="$(_concept_emit_clone_err 2>&1 1>/dev/null)"
    [ -z "$output" ]
}

# --- _concept_classify_fetch_err ---

@test "_concept_classify_fetch_err: branch_not_found bei 'couldn't find remote ref'" {
    mkdir -p /workspace/.agent/logs
    printf "fatal: couldn't find remote ref refs/heads/nope\n" > /workspace/.agent/logs/clone.err
    [ "$(_concept_classify_fetch_err)" = "branch_not_found" ]
}

@test "_concept_classify_fetch_err: branch_not_found bei HTTP 404" {
    mkdir -p /workspace/.agent/logs
    printf "fatal: unable to access ...: The requested URL returned error: HTTP 404\n" > /workspace/.agent/logs/clone.err
    [ "$(_concept_classify_fetch_err)" = "branch_not_found" ]
}

@test "_concept_classify_fetch_err: auth bei HTTP 401" {
    mkdir -p /workspace/.agent/logs
    printf "fatal: Authentication failed for 'https://example.com/repo.git/'\nHTTP 401\n" > /workspace/.agent/logs/clone.err
    [ "$(_concept_classify_fetch_err)" = "auth" ]
}

@test "_concept_classify_fetch_err: network bei GnuTLS recv error" {
    mkdir -p /workspace/.agent/logs
    cat > /workspace/.agent/logs/clone.err <<'EOF'
error: RPC failed; curl 56 GnuTLS recv error (-110): The TLS connection was non-properly terminated.
fetch-pack: unexpected disconnect while reading sideband packet
fatal: early EOF
EOF
    [ "$(_concept_classify_fetch_err)" = "network" ]
}

@test "_concept_classify_fetch_err: network bei Connection refused" {
    mkdir -p /workspace/.agent/logs
    printf "fatal: unable to access ...: Failed to connect to host: Connection refused\n" > /workspace/.agent/logs/clone.err
    [ "$(_concept_classify_fetch_err)" = "network" ]
}

@test "_concept_classify_fetch_err: unknown bei leerem clone.err" {
    mkdir -p /workspace/.agent/logs
    : > /workspace/.agent/logs/clone.err
    [ "$(_concept_classify_fetch_err)" = "unknown" ]
}

@test "_concept_classify_fetch_err: unknown bei nicht klassifizierbarer Meldung" {
    mkdir -p /workspace/.agent/logs
    printf "some weird unparseable git error message\n" > /workspace/.agent/logs/clone.err
    [ "$(_concept_classify_fetch_err)" = "unknown" ]
}

# --- _concept_build_continue_prompt ---

@test "_concept_build_continue_prompt: enthaelt 'fortsetzen' und 'Turn-Limits'" {
    output="$(_concept_build_continue_prompt)"
    [[ "$output" == *"fortsetzen"* ]]
    [[ "$output" == *"Turn-Limits"* ]]
}

@test "_concept_build_continue_prompt: erinnert, dass KEINE Datei geschrieben werden soll" {
    output="$(_concept_build_continue_prompt)"
    [[ "$output" == *"KEINE Datei"* ]]
}
