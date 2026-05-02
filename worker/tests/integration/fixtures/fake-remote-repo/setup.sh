#!/usr/bin/env bash
# tests/integration/fixtures/fake-remote-repo/setup.sh
#
# Initialisiert ein bare Git-Repo unter $1 (oder ./fake-remote.git relativ zu
# diesem Skript) und seedet einen ersten Commit auf branch `main` mit minimalem
# Laravel-aehnlichen Skelett (composer.json, README, app/, tests/).
#
# Idempotent — wenn das Repo schon existiert, wird es zuerst geloescht.

set -euo pipefail
IFS=$'\n\t'

target="${1:-$(cd "$(dirname "$0")" && pwd)/fake-remote.git}"

# Remove previous repo; files created by docker containers (uid=1000) may
# not be deletable by the CI runner — tolerate failures and re-init on top.
rm -rf "$target" 2>/dev/null || true
mkdir -p "$target"
git init --quiet --bare --initial-branch=main "$target"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
git -C "$work" init --quiet --initial-branch=main
git -C "$work" config user.email "fake-remote@worker.local"
git -C "$work" config user.name "Fake Remote"
git -C "$work" remote add origin "$target"

cat > "$work/composer.json" <<'EOF'
{
    "name": "demo/helloworld",
    "description": "Demo-Test-Repo fuer den agent-Worker",
    "type": "project",
    "require": {
        "php": ">=8.3"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    }
}
EOF

cat > "$work/README.md" <<'EOF'
# Demo HelloWorld

Test-Repo fuer den agent-Worker. Wird vom Lifecycle-Test gefuellt.
EOF

cat > "$work/.gitignore" <<'EOF'
/vendor/
/node_modules/
.env
.phpunit.result.cache
EOF

mkdir -p "$work/app" "$work/tests"
echo "<?php" > "$work/app/.gitkeep"
echo "<?php" > "$work/tests/.gitkeep"

git -C "$work" add -A
git -C "$work" commit --quiet -m "chore: initial demo skeleton"
git -C "$work" push --quiet origin main

# World-writable so the container user (uid 1000) can push back when
# the bare repo is bind-mounted from a different host uid (e.g. CI runner).
chmod -R a+w "$target"

echo "fake-remote-repo initialized at: $target"
