# V1 — Definition of Done

Dieses Dokument enthält die Akzeptanzkriterien für „v1 ist fertig". Wenn alle 23 Kriterien erfüllt sind, ist v1 abgeschlossen.

## Setup-Kriterien

1. `git clone <repo> && ./agent init` führt durch alle Setup-Schritte (Image-Build, Token-Eingabe für `CLAUDE_CODE_OAUTH_TOKEN`, optional Symlink in `~/.local/bin/agent`).
2. `./agent --help` und `./agent help <command>` geben brauchbare Hilfe für alle Commands aus.
3. Bash-Unit-Tests laufen grün via `./tests/run-tests.sh --bats`.
4. Container-Integrationstests laufen grün via `./tests/run-tests.sh --integration`.

## Funktionale Kriterien (manueller Akzeptanztest gegen echtes GitHub-Repo)

5. `./agent task new task-001` legt Volume an, fragt Token interaktiv (versteckt) ab, schreibt `~/.agent/tasks/task-001/credentials.env` mit mode 600.
6. `./agent concept task-001` klont Repo ins Volume, generiert `concept.md`, gibt Result-JSON aus.
7. `./agent show-concept task-001` zeigt Konzept lesbar an (mit Pager wenn TTY und `$PAGER` gesetzt, sonst plain).
8. `./agent edit-concept task-001` öffnet Konzept in `$EDITOR`, Änderungen landen im Volume zurück.
9. `./agent concept task-001` (zweiter Aufruf) erzeugt verbesserte Version, alte landet in `concept.history/`.
10. `./agent concept task-001 --fresh` ignoriert Vorgängerversion, alte landet trotzdem in `concept.history/`.
11. `./agent implement task-001` führt Code-Phase durch. Claude führt Pint und Tests selbständig aus. Quality-Gates werden nach der Session vom Worker verifiziert. Bei grünem Status: status=`completed`.
12. `./agent diff task-001` zeigt git diff lesbar an (mit Farben wenn TTY).
13. `./agent push task-001` generiert Commit-Message via Claude-Sub-Phase, committed, pusht Branch zur Remote, fragt nach Cleanup.
14. `./agent status task-001` zeigt zu jedem Zeitpunkt sinnvolle Zustandsanzeige mit allen Phase-Iterationen.
15. `./agent abort task-001` löscht Volume + Host-State sauber (auch credentials.env).
16. `./agent prune` findet verwaiste Volumes (Volumes ohne korrespondierenden `~/.agent/tasks/`-Eintrag) und bietet Cleanup an.

## Robustheits-Kriterien

17. Bei laufender Phase wird ein zweiter `agent <phase>`-Aufruf für denselben Task mit Lock-Fehler (Exit 6) abgewiesen, mit klarer Meldung welche Phase wann gestartet wurde.
18. Bei Container-Crash mitten in einer Phase: Lock wird durch trap aufgeräumt, nächster Aufruf läuft normal. Falls trap nicht ausgeführt wurde (z.B. SIGKILL): `--force-unlock`-Flag erlaubt Forcieren mit Warnung.
19. Bei abgelaufenem Repo-Token gibt die push-Phase eine klare Fehlermeldung (kein cryptic git error) — Worker erkennt 401/403 und gibt menschenlesbaren Hinweis.
20. Bei abgelaufenem Claude-OAuth-Token gibt eine Phase die Claude nutzt eine klare Fehlermeldung mit Hinweis auf `claude setup-token` und `./agent init --update-token`.

## Doku-Kriterien

21. `README.md` im Repo erklärt das Setup von 0 auf einsatzbereit in unter 10 Minuten.
22. `prompts/*.system.md` sind ausgeschriebene Files, nicht hardcoded in Bash. Beim Image-Build werden sie ins Image kopiert nach `/usr/local/share/agent/prompts/`.
23. `docs/EXAMPLE.md` enthält einen vollständigen Walkthrough mit einem konkreten Demo-Task von `task new` bis `push`.

---

## End-to-End-Akzeptanztest (manuell, einmal vor v1 ready)

Manueller Durchlauf, der grün sein muss:

1. Setup von 0:
   ```
   npm install -g @anthropic-ai/claude-code
   claude setup-token   # Token notieren
   git clone <agent-repo>
   cd agent
   ./agent init         # Token eingeben
   ```

2. Test-Repo erstellen auf GitHub (privat OK), mit einem Mini-Laravel-Projekt das Pint und Pest hat. PAT mit Repo-Rechten generieren.

3. Task anlegen:
   ```
   ./agent task new demo-helloworld
   # REPO_URL: https://github.com/<user>/<test-repo>.git
   # REPO_TOKEN: ghp_xxx
   # BASE_BRANCH: main
   # Task-Description (im Editor):
   #   Lege eine Klasse App\Demo\HelloWorld an mit einer
   #   Methode greet(string $name): string die "Hello, $name!"
   #   zurückgibt. Schreibe einen Pest-Test der die Methode prüft.
   ```

4. Konzept generieren:
   ```
   ./agent concept demo-helloworld
   ./agent show-concept demo-helloworld
   ```
   **Erwartung:** Konzept beschreibt sinnvoll die zu erstellenden Files (mindestens `app/Demo/HelloWorld.php` und `tests/Feature/Demo/HelloWorldTest.php` oder ähnlich).

5. Implementieren:
   ```
   ./agent implement demo-helloworld
   ```
   **Erwartung:** Im stream-Output sieht man Claude die Files anlegen, Pint laufen lassen, Tests laufen lassen. Endstatus `completed`. Quality-Gates `pint: pass, pest: pass`.

6. Diff sichten:
   ```
   ./agent diff demo-helloworld
   ```
   **Erwartung:** zeigt die zwei neuen Files lesbar an.

7. Push:
   ```
   ./agent push demo-helloworld
   ```
   **Erwartung:** Branch `ai/demo-helloworld-<timestamp>` ist auf der Remote, mit einem Commit der die zwei Files enthält und eine sinnvolle Conventional-Commits-Message hat.

8. Auf der Remote im Browser sichten — der Branch sollte sauber aussehen, der Commit sollte den Standards entsprechen.

9. Cleanup-Frage: mit `y` bestätigen, dann `./agent task list` zeigt den Task nicht mehr.

Wenn alle 9 Schritte ohne manuelles Eingreifen funktionieren: v1 ist erledigt.

---

## Out of Scope für v1

Bewusst nicht enthalten — wird in späteren Iterationen adressiert (siehe `BACKLOG.md`):

- PR-Erstellung (`pr`-Phase)
- PR-Feedback-Loop (`respond`-Phase)
- Polling von Task-Quellen (Issues, Tickets)
- Webhooks
- Datenbank, UI
- Multi-Repo-Tasks
- DB-Sidecar im Container (User wechselt bei Bedarf temporär auf SQLite)
- Multi-Account-Support (mehrere Claude-Subscriptions parallel)
- API-Mode statt Subscription
- Toolchains außer Laravel/PHP (Node, Python etc.)
- VCS-Provider außer GitHub
- Auto-Merge des PR
- Cost-Tracking pro Run (außer was Claude im result-JSON liefert)
- Strukturierte JSON-Logs (stdout-Logs reichen für v1)
- Backup-Strategie für die Volumes
