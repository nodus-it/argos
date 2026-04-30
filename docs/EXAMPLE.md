# EXAMPLE — Vollständiger Walkthrough

Demo-Task: eine `App\Demo\HelloWorld`-Klasse mit einer `greet(string $name): string` Methode anlegen, plus Pest-Test. Identisch zum End-to-End-Akzeptanztest aus [`V1-DONE.md`](../V1-DONE.md).

## Voraussetzungen

- `agent init` einmal gelaufen (siehe [README](../README.md))
- Test-Repo auf GitHub mit Pint und Pest installiert (z.B. ein frisches Laravel-Projekt mit `composer require --dev laravel/pint pestphp/pest`)
- Fine-grained PAT für das Test-Repo: Repo-Rechte `Contents: read+write`, `Workflows: read` falls vorhanden

## 1. Task anlegen

```bash
./agent task new demo-helloworld
```

Interaktive Eingaben:

```
REPO_URL (https://github.com/...): https://github.com/<dein-user>/<test-repo>.git
REPO_TOKEN (versteckt; ghp_... fuer GitHub PAT): ··········
BASE_BRANCH [main]: main
```

Anschließend öffnet sich `$EDITOR` mit einer Vorlage. Schreibe:

```markdown
# Task-Beschreibung fuer demo-helloworld

Lege eine Klasse `App\Demo\HelloWorld` an mit einer Methode
`greet(string $name): string`, die `"Hello, $name!"` zurueckgibt.
Schreibe einen Pest-Test der die Methode prueft.
```

Speichern + schließen.

## 2. Konzept generieren

```bash
./agent concept demo-helloworld
./agent show-concept demo-helloworld
```

Erwartet: Konzept-Markdown mit Abschnitten "Verständnis der Aufgabe", "Geplante Änderungen" (mindestens `app/Demo/HelloWorld.php` und `tests/Feature/Demo/HelloWorldTest.php`), "Vorgehensweise".

**Iteration:** Wenn das Konzept eine Annahme trifft, die du anders willst:

```bash
./agent edit-notes demo-helloworld
# In $EDITOR Anmerkungen eintragen, z.B.:
# "Bitte HelloWorld als final readonly class statt einer normalen Klasse."
./agent concept demo-helloworld
# Liest concept.notes.md mit, ueberarbeitet das Konzept.
```

Mit `./agent concept demo-helloworld --fresh` ignoriert Claude das vorherige Konzept und beginnt von vorne (alte Version landet in `concept.history/`).

## 3. Implementieren

```bash
./agent implement demo-helloworld
```

Du siehst Claudes Tool-Aufrufe live (stream-json wird gerendert). Claude:
- führt `composer install` und `npm ci` (falls vorhanden) selbst aus
- legt die geplanten Files an
- läuft `vendor/bin/pint` und `vendor/bin/pest` selbst, fixt was rot ist
- iteriert, bis grün

Nach Claudes Session läuft der Worker noch einmal Pint/Pest zur Verifikation. Bei grün: `status=completed`. Falls rot:

```bash
./agent implement demo-helloworld --continue
# Liefert die Failure-Logs als zusätzlichen Input an Claude und versucht es nochmal.
```

`--max-turns=80` falls 50 nicht reicht.

## 4. Diff sichten

```bash
./agent diff demo-helloworld
```

Mit Farben (TTY) zeigt git diff `origin/main...HEAD` plus git status. Nur eine Zusammenfassung:

```bash
./agent diff demo-helloworld --stat
```

Nur ein File:

```bash
./agent diff demo-helloworld --file=app/Demo/HelloWorld.php
```

## 5. Push

```bash
./agent push demo-helloworld
```

Im Worker:
- Sub-Phase `commit-message` läuft (kurze Claude-Session, JSON-Output mit `subject` + `body`)
- `git add -A && git commit -m "<subject>" -m "<body>"`
- `git push -u origin ai/demo-helloworld-<timestamp>`

Anschließend fragt das CLI:

```
Push erfolgreich. Workspace+Host-State loeschen? [y/N]
```

Mit `y` werden Volume und `~/.agent/tasks/demo-helloworld/` entfernt. Mit `n` (oder Enter) bleibt alles für eine Folge-Iteration. `--auto-cleanup` und `--keep` überspringen die Frage.

## 6. Ergebnis

Auf der Remote im Browser:

- Branch `ai/demo-helloworld-<timestamp>` existiert
- Ein Commit mit Conventional-Commits-Subject (`feat: add HelloWorld greeter` o.ä.)
- Diff enthält die zwei neuen Files

Daraus dann manuell einen PR aufmachen — Worker erzeugt in v1 keinen PR (kommt in Iteration 2, siehe BACKLOG).

## 7. Status anschauen / weiter iterieren

```bash
./agent status demo-helloworld
# Zeigt state.json mit allen Phasen, Iterationen, Timestamps, Quality-Gate-Ergebnissen.

./agent logs demo-helloworld --phase=implement --iteration=1
# Zeigt die rohen Claude-Logs der ersten implement-Iteration.

./agent shell demo-helloworld
# Bash im Worker mit Volume gemountet. Hier kannst du z.B. manuell `vendor/bin/pest` laufen lassen.
```

## Fehlersuche

Siehe [`docs/TROUBLESHOOTING.md`](TROUBLESHOOTING.md).
