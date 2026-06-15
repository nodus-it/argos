# Tasks & der Workflow

Ein **Task** ist die zentrale Arbeitseinheit in Argos. Sie beschreiben eine
Änderung, die an einem Repository vorgenommen werden soll, und Argos führt sie
durch eine feste Abfolge von Phasen — **Concept → Implement → Push (Pull
Request)** — und pausiert bei jedem Schritt, sodass Sie vor dem Weitermachen
prüfen können. Nachdem der Pull Request geöffnet ist, können Sie mit
Review-Feedback weiter iterieren (**Respond**).

Dieses Dokument erklärt aus Anwendersicht, was ein Task ist, wie man einen
erstellt, was jede Phase tut, was Sie zur Prüfung erhalten und jeden Status, den
ein Task im Verlauf anzeigen kann — einschließlich wie man pausiert, fortsetzt,
erneut versucht, abbricht und Feedback gibt.

Für die zugrunde liegenden Worker-Kommandos und wie Phasen innerhalb des
Docker-Workers laufen, siehe [EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md). Für
Projekte/Repo-Profile, welche die von einem Task geerbten Standardwerte liefern,
siehe [PROJECTS.md](PROJECTS.md). Für den Agent/das Modell, das die Arbeit
erledigt, siehe [AGENTS.md](AGENTS.md).

## Inhalt

- [Was ein Task ist](#what-a-task-is)
- [Einen Task erstellen](#creating-a-task)
- [Der Lebenszyklus im Überblick](#the-lifecycle-at-a-glance)
- [Die Phasen](#the-phases)
  - [Concept](#concept)
  - [Implement](#implement)
  - [Push & Pull Request](#push--pull-request)
  - [Respond (Review-Feedback)](#respond-review-feedback)
- [Selbst am Branch arbeiten](#working-on-the-branch-yourself)
- [Status & Stufen](#statuses--stages)
- [Pausieren & Fortsetzen (das Turn-Limit)](#pausing--resuming-the-turn-limit)
- [Eine fehlgeschlagene Phase erneut versuchen](#retrying-a-failed-phase)
- [Einen hängenden Implement-Run zwangsweise entsperren](#force-unlock-a-stuck-implement-run)
- [Einen laufenden Task abbrechen](#aborting-a-running-task)
- [Einen Task abschließen](#completing-a-task)
- [Die Live-Demo](#the-live-demo)
- [Aus Issues erstellte Tasks](#tasks-created-from-issues)

## Was ein Task ist

Ein Task verknüpft:

- einen **Namen** — einen eindeutigen Slug, der auch in URLs, im Namen des
  Docker-Workspace-Volumes und im Präfix des Feature-Branches auftaucht;
- ein **Projekt** (Repo-Profil) — in welchem Repository gearbeitet werden soll.
  Der Worker-Stack, der Agent, die Modelle und der Base-Branch werden alle vom
  Projektstandard geerbt, sofern Sie sie nicht am Task überschreiben;
- eine **Beschreibung** — was sich ändern soll, *warum*, und woran Sie erkennen,
  dass es funktioniert hat. Konkrete Akzeptanzkriterien führen zu besseren
  Ergebnissen;
- ein privates **Docker-Workspace-Volume**, das das geklonte Repository und den
  Arbeitszustand des Agents über die Laufzeit des Tasks hinweg hält.

Jeder Task trägt seinen eigenen Fortschritt (aktuelle Phase, Status), seine
erzeugten Artefakte (das Concept, die Implementierungs-Zusammenfassungen, den
Diff, die Pull-Request-URL) sowie ein Log pro Iteration, durch das Sie
zurückscrollen können.

## Einen Task erstellen

Öffnen Sie die Task-Liste unter `${APP_URL}/admin/tasks` und wählen Sie **New
task**. Das Formular hat zwei Tabs:

**General**

- **Name** — der eindeutige Slug (siehe oben).
- **Project** — das Repo-Profil, in dem gearbeitet werden soll.
- **Description** — die vorzunehmende Änderung, mit Akzeptanzkriterien.
- **Start concept immediately** — wenn aktiviert, startet die Concept-Phase
  direkt nach der Erstellung (Auto-Concept). Wenn deaktiviert, wird der Task als
  **Draft** erstellt, und Sie starten das Concept selbst.
- **Base branch (override)** — Branch, auf dem die Arbeit basieren soll. Leer
  verwendet den Projektstandard.

**Worker & models** (alle optionale Overrides; leer erbt den Projekt-/
Agent-Standard)

- **Worker stack**, **Agent** und **Agent credential** — die Laufzeitumgebung
  und Identität, die den Task ausführt (siehe [AGENTS.md](AGENTS.md)).
- **Concept model** / **Implement model** — das pro Phase verwendete
  Claude-Modell. Die Auflösung erfolgt als Task-Override → Projektstandard →
  Argos-Standard.
- **Max turns for Concept** / **Max turns for Implement** — die Obergrenze für
  Tool-Calls pro Run. Leer verwendet den Standard. Dies ist das Budget, das,
  sobald es erschöpft ist, eine Phase *pausiert*, statt sie scheitern zu lassen
  (siehe [Pausieren & Fortsetzen](#pausing--resuming-the-turn-limit)).

Wenn Sie speichern, erstellt Argos das Workspace-Volume und — falls Auto-Concept
aktiviert ist — stellt sofort die Concept-Phase in die Warteschlange.

## Der Lebenszyklus im Überblick

Phasen laufen **asynchron** im Worker. Wenn Sie eine starten, wechselt der Task
in einen *queued*-Zustand, bis ein Worker sie aufnimmt, dann *running*, und
schließlich in einen *review*-Zustand, der auf Ihre Entscheidung wartet. Die
Reihenfolge ist strikt und nur vorwärtsgerichtet:

```
Draft
  │  start concept
  ▼
Concept ──► (review the concept) ──► Implement ──► (review the implementation)
  │                                                          │
  │                                                          ▼
  │                                              Push & Pull Request
  │                                                          │
  │                                                          ▼
  │                                              In review (PR is open)
  │                                                  │           ▲
  │                                                  │ feedback  │
  │                                                  ▼           │
  │                                              Respond ────────┘
  │                                                  │
  ▼                                                  ▼ mark complete
                                                  Completed
```

Zwei wichtige Regeln:

- **Das Concept wird gesperrt, sobald die Implementierung beginnt.** Sie können
  nach dem ersten Implement-Run nicht zur Concept-Phase zurückkehren —
  verfeinern Sie stattdessen die Implementierung.
- **Push läuft automatisch nach einem erfolgreichen Implement**, wenn bereits ein
  Pull Request existiert (d.h. bei späteren Iterationen). Beim ersten Mal lösen
  Sie **Push & PR** selbst vom Review-Dock aus.

## Die Phasen

Sie prüfen jede Phase von der Task-Detailseite unter
`${APP_URL}/admin/tasks/{id}`. Am unteren Rand dieser Seite befindet sich das
**Review-Dock**: ein Composer, der die passenden Buttons und den Hinweis für die
aktuelle Stufe anzeigt. Der Header trägt nur die wenigen Aktionen, die das Dock
nicht besitzt (eine pausierte Phase fortsetzen, einen Lock freigeben, nach dem PR
abschließen) plus ein `⋯`-Menü mit Zusatzaktionen (Logs, Demo-Steuerung,
Abbruch).

### Concept

Der Agent analysiert Ihre Beschreibung gegen das Repository und entwirft einen
Plan: was er ändern möchte und die nächsten Schritte. Er erstellt während dieser
Phase den Feature-Branch. Es wird noch kein Code geändert.

**Was Sie prüfen:** das vorgeschlagene Concept, auf dem **Concept**-Tab. Im
Review-Dock können Sie:

- **Update concept** — eingeben, was sich ändern oder ergänzen soll, und das
  Concept mit Ihren Anmerkungen als Feedback erneut ausführen (dies bearbeitet
  das Concept, nicht bloß einen Kommentar hinterlassen); oder
- **Start implementation** — das Concept akzeptieren und weitergehen.

### Implement

Der Agent wendet die tatsächlichen Code-Änderungen auf dem Feature-Branch an.
Standardmäßig startet er von einem sauberen Checkout des Base-Branches; wenn Sie
eine geprüfte Implementierung **verfeinern**, baut er auf dem Working Tree der
vorherigen Iteration auf, statt zurückzusetzen. Nachdem der Agent fertig ist,
führt der Worker die **Quality Gates** des Projekts (Formatter, Tests, statische
Analyse, sofern konfiguriert) als blockierenden Verifizierungsschritt erneut aus.

**Was Sie prüfen:** den **Implementation**-Tab (eine Zusammenfassung in
einfacher Sprache und eine technische Zusammenfassung) und den **Diff**-Tab. Im
Review-Dock können Sie:

- **Refine implementation** — eingeben, was sich ändern soll, und Implement auf
  Basis der aktuellen Arbeit erneut ausführen; oder
- **Create Push & PR** — die Implementierung akzeptieren und den Pull Request
  öffnen.

Eine [Live-Demo](#the-live-demo) wird nach einem erfolgreichen Implement-Run
automatisch gebaut, wenn das Projekt Demos aktiviert hat.

### Push & Pull Request

Der Worker generiert eine Commit-Message, committet die Änderungen, pusht den
Feature-Branch zum Remote und öffnet (oder aktualisiert) den Pull Request.

**Was Sie prüfen:** der **Pull Request**-Tab zeigt den PR-Link. Der Task wechselt
nach **In review** — öffnen Sie den PR in Ihrem Git-Host und prüfen Sie ihn dort.
Von hier aus schließen Sie entweder den Task ab oder senden Feedback.

### Respond (Review-Feedback)

Wenn ein PR offen ist und Sie Änderungen wünschen, nutzen Sie **Review
feedback** (erreichbar vom in-review-Task). Sie schreiben Ihr Feedback; Argos
übergibt es dem Agent, der *nur die angesprochenen Punkte* in den bestehenden
Feature-Branch einarbeitet — kein unverbundenes Refactoring — und der Pull
Request wird aktualisiert. Der Task kehrt nach **In review** zurück, sodass Sie
das Ergebnis lesen und bei Bedarf weiteres Feedback senden können. Wiederholen
Sie dies, bis Sie zufrieden sind, und schließen Sie dann den Task ab.

## Selbst am Branch arbeiten

Sobald der Feature-Branch auf dem Remote liegt (nach einem **Push**), können Sie
ihn auschecken, bearbeiten und eigene Commits pushen — wie jeden Branch. Wenn Sie
den Task danach fortsetzen (ein **Refine**-Implement oder **Review
feedback**/Respond), **pullt Argos den Branch zuerst** und arbeitet auf Ihren
Commits weiter, sodass Ihre manuellen Änderungen erhalten bleiben und darauf
aufgebaut wird. Ein **Neuaufbau der Live-Demo** spiegelt ebenso den gepushten
Remote-Stand.

Zwei Dinge sind wichtig:

- Das gilt für die **fortsetzenden** Läufe (Refine, Respond). Ein bewusstes
  **Fresh-Re-Implement** baut die Änderung von der Base neu auf und würde externe
  Commits ersetzen — nutzen Sie Refine/Respond, wenn Ihre manuelle Arbeit
  weitergetragen werden soll.
- Pushen Sie auf den Branch, *während* eine Phase läuft, verweigert dieser Lauf
  das Überschreiben Ihrer Commits und scheitert mit einer klaren Meldung —
  starten Sie den Task einfach erneut, dann werden Ihre Änderungen übernommen.

## Status & Stufen

Intern hat ein Task einen persistierten Workflow-Status plus eine aktuelle
Phase/einen aktuellen Status. Die UI fasst diese zu einer einzigen **Stufe**
zusammen, die im Status-Banner angezeigt wird. Das ist, was Sie tatsächlich sehen
und worauf Sie reagieren:

| Stufe (Banner) | Bedeutung | Ihre Aktion |
| --- | --- | --- |
| **Draft** | Erstellt, Concept noch nicht gestartet. | Optional Hinweise hinzufügen, dann **Start concept**. |
| **Concept waiting for worker** | Concept in Warteschlange; wartet auf einen freien Worker. | Warten. |
| **Concept running** | Der Agent entwirft das Concept. | Warten (oder **Abort**). |
| **Concept paused (turn limit)** | Concept hat mitten im Run sein Turn-Budget erreicht. | **Continue concept** mit einem frischen Budget. |
| **Review concept** | Concept fertig. | **Update concept** oder **Start implementation**. |
| **Concept failed** | Der Concept-Run ist fehlgeschlagen. | **Try again** vom Dock. |
| **Implementation waiting for worker** | Implement in Warteschlange. | Warten. |
| **Implementation running** | Der Agent ändert Code. | Warten (oder **Abort**). |
| **Implementation paused (turn limit)** | Implement hat sein Turn-Budget erreicht. | **Continue** mit einem frischen Budget. |
| **Review implementation** | Code + Zusammenfassungen + Diff fertig. | **Refine implementation** oder **Create Push & PR**. |
| **Implementation failed** | Implement fehlgeschlagen (oder durch einen Lock blockiert). | **Try again**, oder **Release lock** falls blockiert. |
| **Push waiting for worker** | Push & PR in Warteschlange. | Warten. |
| **Push & PR running** | Committen, pushen, PR öffnen. | Warten. |
| **Push failed** | Der Push-/PR-Schritt ist fehlgeschlagen. | **Try again** vom Dock. |
| **Pull request created** | PR ist offen; in review. | Den PR prüfen, dann **Complete** oder Feedback senden (**Respond**). |
| **Completed** | Task abgeschlossen; Workspace entfernt. | Endzustand. |
| **Aborted** | Manuell gestoppt. | Endzustand, schreibgeschützt. |

Hinweise:

- *Waiting for worker* (queued) und *running* sind verschieden: queued bedeutet,
  der Job ist dispatcht, aber noch kein Worker hat ihn aufgenommen.
- Während eine Phase **running oder queued** ist, sind das Review-Dock und die
  Phasen-Steuerungen ausgeblendet — nur das `⋯`-Menü (Recovery + Logs) und
  **Abort** bleiben übrig.

## Pausieren & Fortsetzen (das Turn-Limit)

Jeder Run hat ein **max-turns**-Budget (die Obergrenze für Tool-Calls). Wenn ein
Concept- oder Implement-Run dieses Budget erreicht, bevor er fertig ist,
**pausiert** er, statt zu scheitern — der Workspace und die Agent-Session bleiben
erhalten.

Um fortzufahren, nutzen Sie die Header-Aktion **Continue** (Implement) oder
**Continue concept**. Sie öffnet ein Modal, das mit einem frischen Turn-Budget
vorausgefüllt ist; das Fortsetzen setzt dieselbe Claude-Session mit vollem
Kontext fort, sodass keine Arbeit verloren geht.

Wenn eine Phase das Turn-Limit **wiederholt** erreicht hat (der Agent
konvergiert nicht), warnt Sie Argos im Resume-Modal — erwägen Sie, den Task
einzugrenzen oder max-turns deutlich anzuheben, statt einfach erneut
fortzusetzen.

## Eine fehlgeschlagene Phase erneut versuchen

Wenn eine Phase mit einem Fehler endet, zeigt der Task eine *failed*-Stufe, und
das Banner verlinkt auf das Log. Das Review-Dock bietet **Try again**, das
dieselbe Phase erneut ausführt. (Ein fehlgeschlagener Concept-Retry übergibt Ihre
bestehenden Anmerkungen als Feedback; ein fehlgeschlagener Implement-Retry
startet von einem sauberen Checkout.)

## Einen hängenden Implement-Run zwangsweise entsperren

Wenn ein Worker-Container abstürzt, kann der Worker-Lock gesetzt bleiben, was sich
als **Implementation failed**-Stufe mit einem Hinweis "blocked by a lock" zeigt.
Nutzen Sie **Release lock** (im Header / `⋯`-Menü), um den Lock zu lösen und die
Implement-Phase neu zu starten. Tun Sie dies nur, wenn Sie sicher sind, dass kein
Worker mehr läuft.

## Einen laufenden Task abbrechen

Während eine Phase running oder queued ist, bietet das `⋯`-Menü **Abort**. Dies
killt sofort hart den laufenden Worker (und etwaige Sidecar-Container), sodass die
Phase augenblicklich stoppt, schließt den in Bearbeitung befindlichen Run und
versetzt den Task in den **Aborted**-Endzustand.

Das Workspace-**Volume bleibt erhalten** (sodass Sie es weiterhin inspizieren
können); es wird erst entfernt, wenn Sie den Task löschen. Aborted ist
schreibgeschützt — es gibt kein Dock und keine Phasen-Steuerungen. Um erneut zu
handeln, erstellen Sie einen neuen Task.

## Einen Task abschließen

Wenn Sie mit dem Pull Request zufrieden sind, nutzen Sie **Complete** (die
primäre Header-Aktion in der Stufe *Pull request created*). Dies markiert den
Task als **Completed**, löscht sein Docker-Workspace-Volume und schließt — falls
der Task aus einem externen Issue stammt — das Quell-Issue. Sowohl die
Volume-Entfernung als auch der Abschluss sind unwiderruflich.

## Die Live-Demo

Wenn das Projekt Live-Demos aktiviert hat, baut Argos nach jedem erfolgreichen
Implement-Run automatisch eine laufende Vorschau des implementierten Branches.
Das **Live demo**-Panel auf der Task-Seite zeigt die URL und das Ablaufdatum und
lässt Sie die Demo neu bauen, neu starten, stoppen oder den Zugriffsschutz der
Demo ändern. Die Demo wird nach dem Pull Request abgebaut, aber Sie können sie
jederzeit neu starten.

Siehe [LIVE-DEMOS.md](LIVE-DEMOS.md) dafür, wie Demos gebaut, exponiert und
geschützt werden.

## Aus Issues erstellte Tasks

Tasks können auch automatisch aus Issues in einem verbundenen Issue-Tracker
(GitHub, GitLab, Linear, …) erstellt werden. Ein solcher Task trägt einen Link
zurück zu seinem Quell-Issue (im Task-Header angezeigt), und der Abschluss des
Tasks kann dieses Issue schließen. Siehe
[SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md) für das Verbinden einer
Issue-Quelle und wie eingehende Issues zu Tasks werden.
