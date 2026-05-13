# Conventional Commits — Argos format

Format:

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

## Types

- `feat` — new functionality visible to users
- `fix` — bug fix
- `chore` — tooling, dependencies, non-functional changes
- `docs` — documentation only
- `refactor` — code change that neither fixes a bug nor adds a feature
- `test` — adding or correcting tests
- `perf` — performance improvement
- `style` — formatting, whitespace (rarely needed with Pint)
- `ci` — CI/CD configuration
- `build` — build system, package manager changes

## Scopes

Use exactly one scope from `@.ai/skills/_shared/argos-scopes.md`. If no
scope fits, omit it rather than invent one — flag for review.

## Subject line

- Imperative mood: "add", not "added" or "adds"
- ≤ 72 characters
- No trailing period
- Lowercase after the colon
- **Always in English** (Argos is an English-language project)

## Body (optional)

- Wrap at 80 columns
- Explain *why*, not *what* (the diff shows *what*)
- Separate from subject by blank line
- Always in English

## Examples

```
feat(worker): add container timeout configuration

Long-running phases could exhaust resources. New ARGOS_PHASE_TIMEOUT_SECONDS
env var caps execution time, defaulting to 300s.
```

```
fix(queue): handle Horizon restart during job dispatch

Race condition caused jobs to silently drop. See #142.
```

```
chore(ai): regenerate Boost guidelines after Larastan upgrade
```
