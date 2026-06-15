# Test-Image: bats + jq (Lib-Tests brauchen jq).
# Wird von tests/run-tests.sh on-the-fly gebaut (klein, ~1 sec).
FROM bats/bats:1.10.0
# sed: GNU sed (BusyBox sed in Alpine kennt z.B. `sed -u` nicht — wir brauchen
#      Parität mit dem Worker-Runtime (Debian) für log_scrub-Tests).
# git: lib/git.sh integration tests drive a real local repo (test_git.bats).
RUN apk add --no-cache jq coreutils sed python3 py3-pip git
# pip --break-system-packages: container-internal venv waere overkill
RUN pip install --break-system-packages --no-cache-dir check-jsonschema
