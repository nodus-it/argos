# Test-Image: bats + jq (Lib-Tests brauchen jq).
# Wird von tests/run-tests.sh on-the-fly gebaut (klein, ~1 sec).
FROM bats/bats:1.10.0
RUN apk add --no-cache jq coreutils python3 py3-pip
# pip --break-system-packages: container-internal venv waere overkill
RUN pip install --break-system-packages --no-cache-dir check-jsonschema
