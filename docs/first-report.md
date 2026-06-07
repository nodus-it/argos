# Code-Struktur-Analyse Argos — Befunde & Stand

Fünf parallele Analysen + eigene Verifikation, kritisch gefiltert (Over-Engineering
wie „DemoDeployer in 10 Micro-Services" verworfen). Vier Problemklassen:
**A)** echte Duplikate, **B)** God-Classes / vermischte Logik, **C)** Logik in
Filament & Views, **D)** Folge-Aktionen synchron statt über Events.

> **Stand 2026-06-07** — **alle Befunde abgearbeitet**: Block **A**, **B** (B1–B4),
> **C** und **D** erledigt (Branch `feat/saloon-github-pilot`). Es verbleibt nur
> noch optionaler Kleinkram (siehe unten).

---

## ✅ Erledigt (Runde 1)

| Ref | Was | Lösung | Commit |
| --- | --- | --- | --- |
| A4 | HTTP-Setup 7× dupliziert (`http()` in allen Provider-Services) | **Saloon-Rollout**: alle 4 Provider × GitService+Tracker hinter Connectoren in `app/Integrations/`; Arch-Regel bewacht die Grenze | `a9bf775`…`5373db3` |
| A1 | `parseDiffStructured` 2× + Docker-Diff-Shell-out 2× | `App\Services\Git\DiffParser` + `WorkspaceDiffService` | `5959e4f` |
| A2 | `parseRef` 4× identisch | `ParsesExternalProjectRef`-Trait | `43a3f8f` |
| A3 | Phase→Icon-Mapping 3× in Views | `App\Support\PhaseGlyph` | `0403618` |
| A5 | Cost/Token-Format 5× | `App\Support\CostFormatter` (ViewTask auf 4 Dezimal vereinheitlicht) | `ecb2996` |
| A7 | Git-Provider-Zugriff in Onboarding + RepoProfileResource | `App\Services\Git\RepositoryFetcher` | `5714c43` |
| A6 | *(angeblich URL-Parsing 5×)* | **Fehlbefund** — `repoPathFromUrl` ist schon zentralisiert, die übrigen Stellen rufen sie nur auf | — |
| D | Folge-Aktionen synchron inline statt über Events | 5 Listener in `app/Listeners/Task` an `PhaseCompleted`/`TaskCompleted`; `WorkflowService::completePhase` = reine State-Transition, `TaskService::markCompleted` = reiner DB-Write + Event (`docker volume rm` raus aus dem DB-Service) | `2f6f698` |
| B3 | Presentation-Logik im Fat Model `Task.php` | `App\Presenters\TaskPresenter` (statusLabel/Color, badgeStatus, phaseRail + Helfer), erreichbar via `Task::presenter()`; Domain-Methoden bleiben im Model | `6637dce` |
| C | Logik in Filament-Pages & Views | `TaskThreadBuilder` (Thread-Aufbau aus ViewTask), `DemoAccessConfigurator` (Passwort/Apply aus der Action), `App\Support\BadgeClass` + `DemoAccessMode::icon()` + Quality-Gate-`lastKeys` aus `view-task-thread` | `a32b480`, `ae92547`, `99d0969` |
| B4 | `IssueCommentNotifier` / `IssueIngestService` mischen Orchestrierung + Markdown/Filter/Hash | `CommentFormatter` (Markdown+Cap), `IssueFilterMatcher`, `IssueSignature` abgespalten; öffentliche APIs unverändert | `82082e4` |

Außerdem teil-erledigt aus Block **C**: die Docker-Diff-Generierung verließ ViewTask/
ViewTaskDiff (→ A1) und die Git-Provider-Form-Closures verließen RepoProfileResource/
Onboarding (→ A7).

---

## B) God-Classes / vermischte Verantwortlichkeiten — erledigt

### B1 `PhaseRunner` — von ~1207 → **405 Z.**, 4 Klassen abgespalten

| Schritt | Klasse | Inhalt | Commit |
| --- | --- | --- | --- |
| B1.1 | `WorkerVolumeReader` | Volume-I/O + Gate-Log-Lesen | `0f0513c` |
| B1.2 | `PhaseCommandBuilder` | Env-/Command-Bau + `resolve*`-Config-Resolver (`resolveModel`/`resolveAgentName` public); per `app()` aufgelöst | `8d2b331` |
| B1.3 | `PhaseResultSync` | `postPhaseSync` ~180 Z. + `extract*StreamLog`; Runner liest das `.bg.log` und reicht es an `sync()`, `postPhaseSync` bleibt dünne public Delegation (partial-mock-Seam) | `10a4f33` |
| B1.4 | `UsageLimitManager` | Cost-Recovery + `usage_limit.env`-Read + Usage-Limit-Cache; `UsageLimitBanner`/`RunPhaseJob` lesen über `current()`/`isActive()`/`retryDelaySeconds()`/`clear()` statt direkt `Cache::get` | `9461bb1` |

PhaseRunner orchestriert jetzt nur noch den Phasen-Lauf. Die partial-mock-Tests sind
pro Schnitt mitgewandert — die Test-Kopplung an die alte Struktur war der eigentliche
Aufwand (Helfer per Konstruktor-Args statt `$this->partialMock`, das den Konstruktor
überspringt).

### B2 `DemoDeployer` — von 727 → **454 Z.**, 2 Klassen abgespalten

| Klasse | Inhalt |
| --- | --- |
| `TraefikRouter` | Route-Datei schreiben/entfernen (`writeRoute`/`removeRoute`), Auth-Middleware (Session/Basic), Port-Recovery, Demo-URL-Bau |
| `DemoComposeBuilder` | Compose-Override-YAML (`buildOverrideYaml`) inkl. APP_KEY/Cookie; bekommt die Demo-URL vom `TraefikRouter` als Parameter |

`DemoDeployer` bleibt lesbarer Orchestrator (deploy/teardown/Concurrency-Cap/Health-
Probe/Contract-Lesen). „10 Micro-Services" wurde als Over-Engineering verworfen.

Gegenbefund: Es gibt **keine** Git-Operationen in den IssueTracker-Klassen — die
vermutete Git/Issue-Vermischung existiert dort nicht. Die echte Vermischung saß im
HTTP-Setup (A4) und den Orchestrierungs-Services (B4).

---

## Optionaler Rest (nicht eingeplant)

Keiner dieser Punkte ist ein Blocker — alle Pflicht-Befunde (A/B/C/D) sind erledigt.
Aufgeführt als bewusst offen gelassene Möglichkeiten, nicht als offene Schuld.

| # | Was | Wo | Aufwand / Wert | Warum offen gelassen |
| --- | --- | --- | --- | --- |
| O1 | Onboarding Step-State (`done`/`active`/`reachable`) aus dem Blade in die Livewire-Page ziehen (`#[Computed]`) | `resources/views/livewire/onboarding.blade.php` + zugehörige Livewire-Page | klein / gering | Reine UI-Conditionals, kein echter Logik-Schnitt. Kosten > Nutzen, solange das Blade übersichtlich bleibt. |
| O2 | Provider-Descriptor („neuer Provider = ein Ort": URL-Pattern, Token-Scopes, Capabilities zentral) | `app/Services/GitProvider/` + `app/Services/IssueTracker/` | mittel / mittel | Eigenständiges Vorhaben aus der Saloon-/Driver-Diskussion, kein God-Class-Symptom. Erst sinnvoll, wenn ein **neuer** Provider tatsächlich ansteht. |
| O3 | `CredentialStore` ist nach B1 eine tote Konstruktor-Dependency in `PhaseRunner` (nur noch von den Tests gemockt) | `app/Services/Workflow/PhaseRunner.php` | klein / gering | Während B1 bewusst nicht angefasst, um den Diff fokussiert zu halten. Sauberer Folge-Cleanup: prüfen, ob der Claude-Token-Pfad noch über den Store läuft, sonst Parameter entfernen. |

---

## Vor dem Merge: vollständiger Testlauf nötig

Die bisherige Verifikation deckt nur die **PHP-Ebene** ab und ist grün:

- ✅ `vendor/bin/pest` — volle Suite **1256 Tests / 2859 Assertions / 0 Fehler**
- ✅ `vendor/bin/phpstan analyse` — **0 errors**
- ✅ `vendor/bin/pint` — clean

**Noch ausstehend** und vor dem Merge zwingend, weil B1 den Kern-Workflow
(`PhaseRunner`) und B2 den Demo-Deploy umgebaut haben — beides Pfade, die die
Pest-Suite nur gemockt durchläuft:

1. **Browser-E2E (Playwright)** gegen den Compose-Stack — `composer test:browser`
   (gemockt, `ARGOS_E2E_FAKE=1`). Deckt Login → Onboarding → Projekt → Task →
   Concept/Implement über die 4-Run-Matrix ab. Läuft heute nur lokal, nicht in CI —
   also die eigentliche Absicherung des umgebauten Workflows.
2. **Mindestens ein echter Phasen-Lauf** (`concept` → `implement` → `push`) gegen ein
   Test-Repo, um den neuen `PhaseCommandBuilder`/`PhaseResultSync`/`UsageLimitManager`-
   Pfad mit echtem Worker-Container + Volume-I/O zu bestätigen (die Pest-Tests mocken
   `newProcess`/`WorkerVolumeReader`).
3. **Ein echter Demo-Deploy** (`live_demo_enabled`), um `TraefikRouter` (Route-Datei im
   echten `traefik_dir` + Reachability) und `DemoComposeBuilder` (echtes `compose up`)
   end-to-end zu prüfen.

Erst nach grünem Schritt 1 (mindestens) ist der Branch merge-reif; Schritte 2–3
idealerweise einmal manuell auf der Stage (`argos-stage`).

---

Jeder Schritt war für sich lauffähig und getestet — pro Extraktion erst Test (bzw.
bestehenden Filter grün), dann Schnitt.
