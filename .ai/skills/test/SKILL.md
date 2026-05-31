---
name: test
description: Run the full Argos quality suite — Pint (formatting), PHPStan/Larastan (static analysis), Pest (tests including architecture rules). Use before any commit, before opening a PR, or when the user asks to verify code quality.
scope: argos
---

# Test Suite

Run these in order. STOP on first failure and report what failed.

## 1. Code style — Pint

```bash
./vendor/bin/pint --test --format agent
```

If this fails: run `./vendor/bin/pint --format agent` to fix, then re-run
the check. Confirm with the user before auto-fixing.

## 2. Static analysis — PHPStan/Larastan

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

PHPStan failures are real bugs more often than not. Do not assume the
analyzer is wrong — investigate before suppressing.

## 3. Tests — Pest (incl. architecture rules)

```bash
./vendor/bin/pest --compact
```

This includes Pest architecture tests under `tests/Arch/` which enforce:

- No debug functions in `app/`
- `strict_types` declared on all `app/` files
- Worker isolation from Filament

The composer shortcut `composer qa` runs Pint + PHPStan + Pest in one go;
the explicit per-step invocation above is preferred so the failing step is
immediately obvious.

On success, output: `✅ All checks passed (pint, phpstan, pest)`

Track this in session state — other skills (e.g. `/commit`) check whether
tests have already run successfully in this session.
