# Argos Backlog

Geplante Features und offene Verbesserungen. Items sind nach Reifegrad gruppiert: **High Prio** sind angefasste Aufräum-Punkte mit klarer Richtung, **Mittelfristig** sammelt ausgearbeitete Vorhaben und Skizzen, **Operations & Quality** kleinere Infrastruktur-Tickets. Was hier landet, ist noch nicht in Arbeit — laufende Tasks werden in der UI verwaltet.

## Inhalt

- [High Prio](#high-prio)
  - [Aufräumen: `app/Domain/` auflösen, Inhalte nach `app/Services/`](#aufräumen-appdomain-auflösen-inhalte-nach-appservices)
  - [Configs entrümpeln: fixe Defaults statt unnötiger ENV-Schalter](#configs-entrümpeln-fixe-defaults-statt-unnötiger-env-schalter)
- [Mittelfristig](#mittelfristig)
  - [Interaktive User-Rückfragen während laufender Tasks](#interaktive-user-rückfragen-während-laufender-tasks)
  - [Sub-Tasks: KI-getriebene Aufgaben-Aufteilung](#sub-tasks-ki-getriebene-aufgaben-aufteilung-in-review-bare-häppchen)
  - [Test-Deployment: Optionale Test-Instanz nach Implementierung](#test-deployment-optionale-test-instanz-nach-implementierung)
  - [Review-Phase: KI-Triage des eigenen Diffs](#review-phase-ki-triage-des-eigenen-diffs)
  - [Weitere Ideen](#weitere-ideen)
- [Operations & Quality](#operations--quality)

---

## High Prio

### Aufräumen: `app/Domain/` auflösen, Inhalte nach `app/Services/`

Der `app/Domain/`-Tree (`Credentials/CredentialStore`, `Phase/PhaseRunner`, `Phase/StateReader`, `Task/TaskService`, `Worker/WorkerImage`) ist faktisch eine Service-Schicht — kein DDD-Aggregat, keine Value Objects, keine Repositories. Pro Unterordner liegt genau eine Klasse mit Service-Verhalten. Das ist Over-Layering: zwei parallele Verzeichnisse (`Domain/` und `Services/`) für dieselbe Verantwortung.

#### Ansatz

- Klassen 1:1 nach `app/Services/` verschieben — Namen behalten (`CredentialStore`, `PhaseRunner`, `StateReader`, `TaskService`, `WorkerImage`).
- Namespaces (`App\Domain\…` → `App\Services\…`) per Suchen/Ersetzen ziehen, danach `composer dump-autoload`.
- ServiceProvider-Bindings, Filament-Pages/Resources, Jobs, Commands und Tests auf neue Namespaces anpassen.
- `app/Domain/` löschen.
- Tests grün halten (`php artisan test --compact`).

#### Out-of-Scope

- Echtes DDD-Refactoring (Aggregate, Repos, Value Objects). Keine Notwendigkeit für eine geschlossene App in dieser Größe.

## Mittelfristig

### Interaktive User-Rückfragen während laufender Tasks

Worker kann mitten in einer Phase pausieren und über die UI eine Klärungsfrage stellen, ohne den Task abbrechen und neu starten zu müssen.

#### Motivation

Manche Tasks brauchen Mid-Run-Klärung — Claude stößt auf eine Mehrdeutigkeit („welche der beiden Tabellen meinst du?"), oder der User will gezielt eingreifen, bevor eine teure Aktion läuft. Heute ist Interaktion nur am Phasenrand möglich (`respond` nach einem natürlichen Stop). Diese Lücke fühlt sich zunehmend wie der Punkt an, an dem CLI-Claude-Code von Argos abweicht.

#### Ansatz

Strukturierte Pause-und-Frage-Schleife — kein interaktives Terminal.

- Worker stellt Claude ein `ask_user`-Tool bereit (alternativ: Claude Codes eingebauter `--permission-prompt-tool`-Hook). Tool-Aufruf schreibt Frage in die DB und blockiert, bis eine Antwort vorliegt.
- Neuer Task-Status `paused_awaiting_input`, baut auf dem in Arbeit befindlichen `stop_reason`-Flow auf (Migration `add_stop_reason_and_paused_status`).
- UI zeigt im Task-View eine Antwort-Box, sobald eine offene Frage existiert. Antwort des Users → DB-Eintrag → Worker entblockiert und liefert Antwort als Tool-Result an Claude zurück; Phase läuft weiter.
- `state.json` bekommt ein `pending_question`-Feld; `schema_version` hochzählen, Migrations-Logik bedenken.
- Frage + Antwort werden als Log-Einträge persistiert, damit der Verlauf reproduzierbar bleibt.
- Generisches Primitive: deckt „Claude will Klärung" und „User will eingreifen" mit demselben Mechanismus ab.

#### Out-of-Scope (bewusst)

- Vollständiges interaktives Terminal (PTY + xterm.js + WebSocket-Bridge zum Worker). Bricht mit dem state-/phase-basierten Modell (binärer ANSI-Stream, kein sauberer Endzustand, Reconnect-/Scrollback-Komplexität) und sollte, falls überhaupt, als separates Feature neben dem Standardflow gebaut werden — nicht als Default.

#### Offene Fragen

- **Timeout**: wie lange blockiert der Worker auf eine Antwort, bevor der Task auf `paused` fällt und der Container endet? Vorschlag: konfigurierbar, Default z.B. 24h.
- **Mehrfach-Rückfragen** in einem Run — Stream-JSON-Loop sollte das natürlich tragen, aber explizit testen.
- **Berechtigung**: darf jeder UI-User antworten, oder nur der Task-Owner?

---

### Sub-Tasks: KI-getriebene Aufgaben-Aufteilung in review-bare Häppchen

Die Concept-Phase erkennt selbst, wenn ein Task zu groß für ein sinnvolles menschliches Review ist, und schlägt eine Aufteilung in Sub-Tasks vor. Der User entscheidet im Concept-Review-Schritt, ob er die Aufteilung übernimmt. Sub-Tasks laufen als eigenständige Tasks gegen den Feature-Branch des Parent-Tasks; am Ende bleibt ein Parent-PR übrig, der die fertig zusammengeführte Arbeit zur finalen Prüfung trägt.

#### Motivation

Die KI baut Konzepte wie ein Senior — d.h. gerne mal mit 30 geänderten Files quer durchs Repo. Ein Mensch kann das nicht mehr ehrlich reviewen. Statt eine harte Größenobergrenze einzuziehen, soll die KI selbst die Reviewbarkeit einschätzen und einen Vorschlag zur Aufteilung machen. Sub-Tasks geben uns kleine, fokussierte PRs gegen einen integrationsfähigen Feature-Branch — und einen finalen Parent-PR, in dem das Gesamtbild noch einmal gegen `main` (oder den Default-Branch) geprüft wird.

#### Ansatz

**Concept-Phase**

- Concept-Prompt erweitern um zusätzliches Output-Segment „Reviewbarkeit". Claude bewertet weich anhand einer Faustregel — Aufteilung anbieten wenn das Konzept **>10 Files** berührt ODER **>300 LoC geschätzt** ODER **zwei klar trennbare Themen** mischt (z.B. Frontend + Backend-Migration). Format strukturiert (Markdown-Block oder JSON-Anhang), damit der Manager parsen und in der UI rendern kann. Kein Sub-Task-Vorschlag heißt: passt als ein Task.
- Konzept-Prompt-Hinweis ergänzen: „Sub-Tasks so schneiden, dass File-Überlappung minimal ist" (reduziert spätere Merge-Konflikte).
- Manueller Override im UI: User kann jederzeit auch ohne KI-Vorschlag „in Sub-Tasks aufteilen" auslösen, und einen vorgeschlagenen Split ablehnen.

**Schema und Branching**

- `tasks`-Tabelle bekommt `parent_task_id` (nullable FK auf `tasks.id`) und `subtask_order` (int, nullable).
- `feature_branch` des Sub-Tasks wird gegen `parent.feature_branch` gebaut — d.h. der Parent-Branch ist die `base_branch` des Kindes.
- Push-Phase muss beigebracht werden, dass der PR-Target-Branch nicht `repo_profile.default_branch` sondern `parent.feature_branch` ist.
- Branch-Naming: `feat/<parent-slug>__<sub-slug>` damit Parent-Zugehörigkeit am Branch-Namen ablesbar bleibt.
- Repo-Anker: alle Sub-Tasks teilen `repo_profile_id` mit dem Parent. Workspace/Volume bleibt pro Task eigenständig (Sub-Task klont neu vom Parent-Branch).

**Workflow**

- `WorkflowStatus` bekommt entweder einen neuen Zwischenstatus `SubtasksProposed` (zwischen `ConceptReview` und Implement) oder die Auswahl passiert innerhalb des bestehenden `concept_review`-Status über einen UI-Toggle.
- Bei „Aufteilung übernehmen" werden die Sub-Tasks angelegt und der Parent geht in einen Wartezustand `WaitingForSubtasks`.
- Sobald alle Sub-Task-PRs gemerged sind, wird der Parent automatisch auf `in_review` gesetzt (oder optional gleich auf eine finale Implement-Iteration, falls noch Klammer-Code fehlt).

**Merge-Status-Polling**

- Bestehenden PR-Polling-Pfad erweitern, kein separater Watcher-Job.
- Nach jedem Sub-PR-Merge-Event wird `Parent::checkAllSubtasksMerged()` ausgeführt; sobald alle gemerged sind, geht der Parent in den nächsten Status.

**Konflikt-Handling für Sub-PRs**

- Nach jedem Merge eines Sub-PRs wird für die offenen Sub-PRs *einmalig* ein Auto-Rebase auf den aktualisierten Parent-Branch versucht (force-push auf den Sub-Branch).
- Bei Konflikt versucht eine neue Mini-Phase `resolve-conflict` die KI-Auflösung (eigener System-Prompt, User-Prompt enthält Conflict-Markers + 3-Way-Diff, Output ist die aufgelöste Datei).
- Wenn auch die scheitert: Sub-PR auf Status „Konflikt – manuelle Auflösung nötig", User übernimmt. Kein zweiter Auto-Versuch.

**Fehler-Handling für Sub-Tasks**

- Scheitert ein Sub-Task oder lehnt der User ihn ab, bleibt der Parent blockierend auf `WaitingForSubtasks`, andere Sub-Tasks laufen ungestört weiter.
- Am gescheiterten Sub-Task drei Buttons: „Neu starten" (mit oder ohne `--fresh`), „Verwerfen & ohne diesen Sub-Task fortfahren", „Parent abbrechen".
- Beim Verwerfen muss der User den Sub-Task-Scope explizit als „nicht mehr Teil des Parent-Scopes" bestätigen — dieser Abzug wird ins Parent-Konzept zurückgeschrieben, damit das finale Review weiß, was fehlt.

**UI**

- TaskResource bekommt einen Sub-Tasks-Bereich (RelationManager).
- Im Concept-Review wird der Aufteilungs-Vorschlag als auswählbare Liste gerendert (User kann Sub-Tasks weglassen, umbenennen, beschreiben), darunter „Als Sub-Tasks anlegen" vs. „In einem Task implementieren".
- Sub-Task-Cards zeigen Parent-Link; Parent zeigt Fortschritt („3 von 5 Sub-PRs gemerged").

#### Out-of-Scope (bewusst)

- Rekursive Aufteilung (Sub-Sub-Tasks). Eine Ebene reicht für den Reviewbarkeits-Pain.
- Automatisches Merging der Sub-PRs in den Parent-Branch — der User merged manuell pro PR und behält damit die Review-Hoheit. Argos beobachtet nur und triggert den Folge-Schritt.
- Abhängigkeiten/Reihenfolge zwischen Sub-Tasks erzwingen. Wenn die KI eine Reihenfolge im Konzept vorschlägt, wird sie als Hinweis im UI angezeigt, aber nicht hart durchgesetzt.
- Auto-Retry für gescheiterte Phasen — die scheitern aus inhaltlichen, nicht aus transienten Gründen.

---

### Test-Deployment: Optionale Test-Instanz nach Implementierung

Nach erfolgreichem Implement kann Argos die geänderte Anwendung als laufende Test-Instanz bereitstellen, basierend auf einem vom Projekt mitgegebenen `docker-compose.test.yml` und optionalen Setup-Befehlen. Der User bekommt eine URL und kann die Änderung interaktiv ausprobieren, bevor er den PR merged.

#### Motivation

„Sieht im Diff gut aus" ≠ „funktioniert in der laufenden App". Heute muss der Reviewer den Branch lokal auschecken, hochfahren, durchklicken — was die Schwelle für inhaltliches Testen hoch hält. Eine on-demand Test-Instanz pro Task macht das niederschwellig: Link klicken, Feature in der echten UI ausprobieren.

#### Architektur-Entscheidung

Die Spec sagt explizit: Worker bekommt kein Docker-Socket. Test-Deployments brauchen aber Docker-Zugriff. **Entschieden: Manager bekommt Docker-Socket** und steuert Test-Stacks direkt über einen neuen Service `TestDeploymentManager`. Klare Service-Grenze (Test-Stack-Lifecycle ist Manager-Verantwortung, nicht Phase-Verantwortung), bewusste Erweiterung der ursprünglichen Spec — die Annahme „kein AI im Manager" bleibt unangetastet.

#### Ansatz

**Projekt-Konfiguration**

- `repo_profiles`-Tabelle bekommt `test_compose_path` (string, default `docker-compose.test.yml`) und `test_setup_commands` (text, mehrzeilig — z.B. `composer install`, `php artisan migrate --seed`, `npm run build`).
- Beides optional; ohne `test_compose_path` ist das Feature für das Profile aus.

**Task-Status**

- `tasks` bekommt `test_deployment_url` (nullable), `test_deployment_status` (enum: `none|pending|running|ready|failed|stopped`), `test_deployment_started_at` / `_stopped_at`.
- Optional eigene Tabelle `test_deployments` für Historie/Logs, wenn wir Re-Deployments versionieren wollen.

**Trigger**

- Automatisch nach `implement_running → in_review` (oder als manueller Button im UI: „Test-Instanz starten").
- Argos checkt `test_compose_path` im aktuellen Feature-Branch aus, führt Setup-Commands aus, fährt Compose-Stack hoch unter einem eindeutigen Project-Name (`argos-test-<task-id>`).

**Routing**

- Reverse-Proxy via **Traefik, im Manager-Container mit eingebaut** (statt separater Compose-Service).
- Manager-Image braucht damit einen Process-Manager (s6-overlay oder supervisord), der Traefik + PHP-FPM/Webserver + Queue-Worker nebeneinander startet.
- Traefik liest den Docker-Socket (haben wir wegen Test-Deployments eh schon im Manager) und entdeckt Test-Stack-Container über Compose-Labels.
- **TLS-Terminierung erstmal nicht** (HTTP only).
- Konkretes Routing-Mapping (Subdomain vs. Pfad vs. Port pro Test-Stack, inkl. Wildcard-DNS-Voraussetzung) bleibt explizit offen — siehe „Offene Punkte" unten.

**Resource-Cap**

- Konfigurierbar via Env-Var `ARGOS_TEST_DEPLOYMENT_MAX_PARALLEL` (Default 5).
- Bei Limit-Überschreitung zeigt das UI eine Liste der laufenden Test-Instanzen mit Stop-Buttons („Stoppen, um Platz zu machen").
- Kein Pro-User-Cap — Argos ist kein Multi-Tenant-System.

**Lifecycle**

- Auto-Stop nach konfigurierbarem TTL (Default 24h).
- Stop-Button im UI.
- Automatischer Stop wenn der zugehörige PR gemerged oder geschlossen wird.

**Fehlende Compose-Datei**

- Beim Speichern des `test_compose_path` im RepoProfile-Form gegen den Default-Branch prüfen und eine **nicht-blockierende Validierungs-Warnung** zeigen, wenn die Datei nicht existiert (User kann trotzdem speichern, falls er sie gerade erst hinzufügen will).
- Beim eigentlichen Test-Deploy: **hart fehlschlagen** mit präziser Meldung („Datei `docker-compose.test.yml` nicht gefunden im Branch `feat/abc` — KI-Lauf hat sie möglicherweise entfernt oder das Konzept hat sie nie angelegt").
- **Kein Default-Branch-Fallback** — der wäre stillschweigend irreführend, weil eine alte Compose-Version gegen neue Code-Änderungen liefe.

**UI**

- Task-Detail-Seite zeigt nach `in_review` einen Block „Test-Instanz" mit Status, Link, Logs-Link, Stop-Button.
- RepoProfile-Form bekommt einen Tab/Bereich „Test-Deployment" mit den zwei Feldern + Hilfetext.

#### Sicherheit

- Test-Container führen Code aus dem KI-Branch aus — also potenziell jeden Code, den Claude generiert hat. Eigenes Docker-Network ohne Outbound-Zugriff (oder nur zu definierten Whitelists). Resource-Limits per Compose (`mem_limit`, `cpus`).
- Das `docker-compose.test.yml` selbst kommt aus dem Feature-Branch und kann vom KI-Lauf manipuliert worden sein. Wir vertrauen ihm bewusst — sonst ist das Feature wertlos. Alternative: Compose-File aus dem `default_branch` lesen statt aus dem Feature-Branch (KI darf es nicht ändern). Im UI Hinweis darauf.
- Routing nur intern erreichbar (VPN/Cloudflare-Access), keine öffentliche Exposition per Default.

#### Out-of-Scope (bewusst)

- TLS-Terminierung im Traefik — erstmal HTTP only. Wer Argos öffentlich exponiert, setzt einen eigenen Proxy davor.
- Persistente Test-Daten über Re-Deploys hinweg — jedes Deploy startet bei Null, Setup-Commands seeden.
- Multi-Branch-Vergleich (Feature-Branch vs. `main` parallel hochfahren) — schön, aber separates Feature.
- Custom-Health-Checks über das hinaus, was Compose von Haus aus liefert.
- Test-Deployments für Sub-Tasks (siehe oben) — vorerst nur am Parent möglich, sonst explodiert die Container-Anzahl.

#### Offene Punkte (bewusst zurückgestellt)

- **Routing-Mapping inkl. DNS**: Subdomain (`<task-slug>.test.<host>` → Wildcard-DNS-Pflicht), Pfad-basiert (`<host>/test/<slug>/` → kein DNS-Aufwand) oder Port-basiert (`:18000+offset` → ugly aber simpel). Wird in einem späteren Schritt entschieden, sobald wir das Feature konkret bauen — die DNS-Frage fällt automatisch mit ab.

---

### Review-Phase: KI-Triage des eigenen Diffs

Nach dem Implement-Step läuft eine zusätzliche Review-Phase mit eigenem System-Prompt, die den Diff verifiziert und dem User die fragilen Stellen markiert. Die UI hebt diese Stellen im Diff-Viewer hervor, damit der menschliche Reviewer seine Aufmerksamkeit gezielt darauf richten kann.

#### Motivation

Der Wert liegt nicht im „bessere Bugs finden als der Implementer" — derselbe Modell-Stack reviewt sich selbst, da gewinnt man wenig zusätzliche Bug-Findung. Der Wert ist **Triage für den menschlichen Reviewer**: ein Prompt mit anderem Fokus („wo ist das fragil, wo wurden Annahmen gemacht, wo ist die Test-Abdeckung dünn?") und ohne Implementierungs-Bias („ich muss das fertig kriegen") zeigt dem User, auf welche 3-5 Stellen im Diff er besonders schauen sollte. Spart Review-Zeit, hebt das Vertrauen in den Rest des Diffs.

#### Ansatz

- Neue Phase `review` zwischen `implement` und `push` (oder als optionaler Schritt — pro Repo-Profile aktivierbar).
- Eigener System-Prompt unter `worker/prompts/review.system.md`. Fokus: fragile Stellen, fehlende Edge-Cases, ungetestete Pfade, riskante Annahmen — **nicht** Code-Style, **nicht** „könnte man auch anders machen".
- Strukturierter JSON-Output mit Severity, kein Freitext. Schema unter `worker/schemas/review.schema.json`:
  ```json
  {
    "concerns": [
      {"file": "app/Foo.php", "line": 42, "severity": "high|medium|low", "reason": "..."}
    ],
    "summary": "kurzer Gesamteindruck"
  }
  ```
- Severity-Disziplin im Prompt: **`high` = real riskant, würde ich vor Merge fixen wollen**; `medium` = bemerkenswert, aber lebbar; `low` = Notiz. Prompt zwingt auf max. 3-5 `high`-Findings, sonst verliert das Signal seinen Wert.
- KI fixt **nicht** selbst — würde sonst endlos laufen, weil ein Reviewer-Prompt immer irgendwas findet. Entscheidung bleibt beim User.
- UI rendert nur `high` als hervorgehobene Linie im Diff-Viewer, `medium` ausklappbar, `low` geloggt. Plus ein Banner mit dem Summary über dem Diff.

#### Out-of-Scope (bewusst)

- **Auto-Fix-Loop** — der Reviewer findet immer was, ein Auto-Fix-Loop divergiert oder oszilliert. Wenn der User aus dem Review-Befund eine Änderung will, geht das über den bestehenden `respond`-Flow.
- **Style/Convention-Reviews** — dafür sind Quality-Gates und Pint da.
- **Cross-Phase-Review** — kein Review der gesamten Task-Historie, nur des aktuellen Implement-Diffs.
- **Severity-Schwellen pro Repo-Profile** — erstmal hart im Prompt, Tuning später wenn nötig.

#### Offene Fragen

- Lohnt sich der zusätzliche Claude-Run kosten-/zeitmäßig? ~30% Overhead pro Task. Erste Version optional pro Repo-Profile, dann an echten Tasks messen, ob die Highlights den manuellen Review wirklich abkürzen oder nur Lärm sind.
- Was, wenn der Reviewer einen `high`-Befund hat und der User trotzdem mergen will? Vermutlich: einfach mergen, kein Block. Befund bleibt im Task-Log dokumentiert.

---

### Weitere Ideen

Skizzen, noch nicht ausgearbeitet:

- **Multi-Source-Orchestrator**: liest GitHub Issues, GitLab, eigene Tools und legt Tasks automatisch an.
- **Concept-Auto-Mode**: Konzept generieren, Issue kommentieren, X Min warten, dann automatisch implementieren.
- **Approval-Gate**: Reaction oder Kommentar als Freigabe für nächste Phase.
- **Webhook-Endpoint** als Alternative zum Polling.
- **VCS-Provider GitLab**: `glab`-CLI-Integration im Worker.

---

## Operations & Quality

- **Strukturierte JSON-Logs** im Worker (für Loki/Elasticsearch).
- **Performance-Metriken**: durchschnittliche Phase-Dauer, Quality-Gate-Failure-Rate.
- **Health-Endpoint** im Manager.
- **Sentry-Integration** für Worker-Container.
