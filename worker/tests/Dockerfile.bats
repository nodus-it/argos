# Test-Image: bats + jq (Lib-Tests brauchen jq).
# Wird von tests/run-tests.sh on-the-fly gebaut (klein, ~1 sec).
FROM bats/bats:1.10.0
# sed: GNU sed (BusyBox sed in Alpine kennt z.B. `sed -u` nicht — wir brauchen
#      Parität mit dem Worker-Runtime (Debian) für log_scrub-Tests).
# git: test_run_gates.bats drives a real local repo.
RUN apk add --no-cache jq coreutils sed git
