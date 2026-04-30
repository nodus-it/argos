#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export PHASES_DIR="$TEST_DIR/phases"
    mkdir -p "$PHASES_DIR"

    # Erzeuge ein Stub-Phase-Skript fuer phase_load-Tests.
    cat > "$PHASES_DIR/concept.sh" <<'EOF'
#!/usr/bin/env bash
phase_concept_run() { echo "concept ran"; }
EOF

    # shellcheck source=../../phases/registry.sh
    source phases/registry.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "PHASE_NAMES enthaelt alle vier User-Phasen plus commit-message" {
    [[ " ${PHASE_NAMES[*]} " == *" concept "* ]]
    [[ " ${PHASE_NAMES[*]} " == *" implement "* ]]
    [[ " ${PHASE_NAMES[*]} " == *" diff "* ]]
    [[ " ${PHASE_NAMES[*]} " == *" push "* ]]
    [[ " ${PHASE_NAMES[*]} " == *" commit-message "* ]]
}

@test "PHASE_ORDER_IN_LIFECYCLE haelt die Default-Reihenfolge" {
    [ "${PHASE_ORDER_IN_LIFECYCLE[0]}" = "concept" ]
    [ "${PHASE_ORDER_IN_LIFECYCLE[1]}" = "implement" ]
    [ "${PHASE_ORDER_IN_LIFECYCLE[2]}" = "diff" ]
    [ "${PHASE_ORDER_IN_LIFECYCLE[3]}" = "push" ]
    [ "${#PHASE_ORDER_IN_LIFECYCLE[@]}" -eq 4 ]
}

@test "phase_known akzeptiert bekannte Phasen, lehnt andere ab" {
    phase_known concept
    phase_known commit-message
    run phase_known nonsense
    [ "$status" -eq 1 ]
}

@test "phase_load sourced phases/<name>.sh aus PHASES_DIR" {
    phase_load concept
    out="$(phase_concept_run)"
    [ "$out" = "concept ran" ]
}

@test "phase_load schlaegt fehl bei unbekannter Phase" {
    run --separate-stderr phase_load xyz
    [ "$status" -eq 1 ]
    [[ "$stderr" == *"unknown phase"* ]]
}

@test "phase_load schlaegt fehl wenn Datei fehlt" {
    rm "$PHASES_DIR/concept.sh"
    run --separate-stderr phase_load concept
    [ "$status" -eq 1 ]
    [[ "$stderr" == *"file not found"* ]]
}
