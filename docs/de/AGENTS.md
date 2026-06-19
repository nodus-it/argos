# Coding-Agents und Zugangsdaten

Wenn Argos eine Aufgabe ausführt, übernehmen das Denken und das Tippen ein
**Coding-Agent** — eine CLI für ein großes Sprachmodell, die innerhalb des
Worker-Containers läuft, dein Repository liest, ein Konzept vorschlägt, die
Implementierung schreibt und den Pull Request vorbereitet. Argos bringt kein
eigenes Modell mit; es steuert einen Agenten, den du authentifizierst.

Dieses Dokument erklärt in einfachen Worten, welche Agenten Argos unterstützt,
wie sich jeder einzelne authentifiziert, wo du diese Zugangsdaten verwaltest und
wie ein Projekt oder eine einzelne Aufgabe den Agenten auswählt, der sie
ausführt. Es richtet sich an Nutzerinnen und Nutzer, die Argos einrichten — du
musst den Worker-Quellcode nicht lesen, um es nachzuvollziehen.

## Inhalt

- [Was ein Agent in Argos ist](#was-ein-agent-in-argos-ist)
- [Claude Code](#claude-code)
- [OpenAI Codex](#openai-codex)
- [Agent-Zugangsdaten](#agent-zugangsdaten)
- [Eine Zugangsdaten während des Onboardings hinzufügen](#eine-zugangsdaten-während-des-onboardings-hinzufügen)
- [Zugangsdaten später verwalten](#zugangsdaten-später-verwalten)
- [Status von Zugangsdaten](#status-von-zugangsdaten)
- [Wie ein Projekt seinen Agenten auswählt](#wie-ein-projekt-seinen-agenten-auswählt)
- [Überschreibungen pro Aufgabe](#überschreibungen-pro-aufgabe)
- [Modellauswahl](#modellauswahl)
- [Sicherheit](#sicherheit)

## Was ein Agent in Argos ist

Ein **Agent** ist die Kombination aus einem Coding-Modell und dem
Kommandozeilen-Werkzeug, das es steuert — installiert im Worker-Image und für
jede Phase einer Aufgabe (Konzept, Implementierung, Push) ausgeführt. Argos
liefert Unterstützung für zwei Agenten:

| Agent | Identifier | CLI | Distribution |
|---|---|---|---|
| Claude Code | `claude-code` | `claude` | `@anthropic-ai/claude-code` (npm) |
| OpenAI Codex | `codex` | `codex` | `@openai/codex` (npm) |

Beide Agenten benötigen einen Worker-Stack, der Node bereitstellt — die CLI wird
zur Build-Zeit ins Image installiert. Siehe [WORKER-STACKS.md](WORKER-STACKS.md)
dazu, wie der Agent in das lauffähige Image eingebacken wird.

Jeder Agent benötigt **seine eigene Art von Zugangsdaten**, wie unten
beschrieben. Die Zugangsdaten werden in Argos gespeichert und dem
Worker-Container ausschließlich zur Laufzeit als Umgebungsvariablen übergeben —
niemals ins Image geschrieben und niemals geloggt.

## Claude Code

Claude Code ist der Standard-Agent. Sein entscheidender Vorteil: Er
authentifiziert sich mit dem **OAuth-Token deines Claude-Abonnements** (Pro,
Max oder Team), *nicht* mit einem nutzungsabhängig abgerechneten API-Key. Die
von Argos geleistete Arbeit wird gegen deinen bestehenden Claude-Plan
abgerechnet, nicht als separate API-Nutzung pro Token.

So erhältst du ein Token:

1. Führe `claude setup-token` in einem Terminal aus, in dem die Claude CLI
   angemeldet ist.
2. Kopiere das ausgegebene Token.
3. Füge es in Argos ein (während des Onboardings oder auf der Seite „Agent
   Credentials").

Argos speichert das Token und übergibt es dem Worker als Umgebungsvariable
`CLAUDE_CODE_OAUTH_TOKEN`. Wenn du das Token im Onboarding-Assistenten
speicherst, validiert Argos es vor der Annahme gegen die Anthropic-API; ist die
API nicht erreichbar, wird das Token dennoch gespeichert, aber als unvalidiert
markiert.

Claude Code bietet drei Modelle, pro Phase auswählbar (siehe
[Modellauswahl](#modellauswahl)):

| Modell | Standard-Phase |
|---|---|
| Claude Opus 4.7 | Konzept |
| Claude Sonnet 4.6 | Implementierung |
| Claude Haiku 4.5 | Commit-Nachricht |

## OpenAI Codex

Codex ist der alternative Agent — für Nutzer, die die Modelle von OpenAI
bevorzugen oder bereits einen ChatGPT-Plan haben, der Codex einschließt.

Codex authentifiziert sich mit dem Inhalt seiner `auth.json`-Datei:

1. Führe lokal `codex login` aus und melde dich an (mit ChatGPT oder einem
   `OPENAI_API_KEY`).
2. Öffne `~/.codex/auth.json` und kopiere den gesamten Inhalt.
3. Füge das JSON in Argos ein.

Argos speichert das JSON verschlüsselt und erstellt zur Laufzeit die
`auth.json`-Datei im Worker-Container neu (übergeben über die Umgebungsvariable
`CODEX_AUTH_JSON_CONTENT`, die der Worker auf die Festplatte schreibt und dann
leert, bevor irgendeine Phase läuft). Anders als Claude Code hat Codex **keinen
Fallback** — eine Aufgabe, die auf Codex aufgelöst wird, schlägt sofort fehl,
wenn keine Codex-Zugangsdaten konfiguriert sind.

Codex stellt derzeit ein einziges Modell bereit, GPT-5 Codex, das für jede Phase
verwendet wird.

## Agent-Zugangsdaten

Ein **Agent-Zugangsdatensatz** ist ein gespeicherter Eintrag, der einen Agenten
mit einem Satz Authentifizierungsmaterial verknüpft:

- **Agent** — Claude Code oder OpenAI Codex.
- **Beschreibung** — ein frei wählbarer Name, um Zugangsdaten zu unterscheiden,
  z. B. „Persönlich" oder „Team-Account".
- **Secret** — für Claude Code das OAuth-Token; für Codex der Inhalt von
  `auth.json`. Verschlüsselt in der Datenbank gespeichert.
- **Status** — Active, Expired oder Revoked (siehe
  [Status von Zugangsdaten](#status-von-zugangsdaten)).
- **Zuletzt validiert** — wann das Secret zuletzt geprüft wurde.

Du kannst mehr als einen Zugangsdatensatz pro Agent speichern (zum Beispiel ein
persönliches und ein Team-Claude-Abonnement) und auswählen, welcher davon für
eine Aufgabe verwendet wird.

## Eine Zugangsdaten während des Onboardings hinzufügen

Der erste Schritt des Onboarding-Assistenten
(`${APP_URL}/admin/onboarding`) ist „Agents". Du musst **mindestens einen**
Agenten authentifizieren, bevor du fortfahren und ein Repository verbinden
kannst.

- Für **Claude Code** fügst du die Ausgabe von `claude setup-token` ein. Argos
  validiert sie und speichert sie als Zugangsdatensatz mit dem Namen „Default".
- Für **OpenAI Codex** führst du `codex login` aus und fügst dann den Inhalt von
  `~/.codex/auth.json` ein. Argos validiert, dass es sich um wohlgeformtes JSON
  handelt, und speichert es.

Du kannst Agenten später hinzufügen oder ändern — das Onboarding benötigt nur
einen, um dich zu einem funktionierenden Projekt zu bringen. Siehe
[SETUP.md](SETUP.md) für die vollständige Anleitung zum ersten Start.

## Zugangsdaten später verwalten

Nach dem Onboarding verwaltest du jeden Zugangsdatensatz auf der Seite **Agent
Credentials** unter der Navigationsgruppe *Worker*
(`${APP_URL}/admin/agent-credentials`). Dort kannst du:

- Neue Zugangsdaten für einen der beiden Agenten hinzufügen.
- Beschreibung, Secret oder Status eines bestehenden Zugangsdatensatzes
  bearbeiten.
- Die Liste nach Agent oder Status filtern.
- Zugangsdaten löschen, die du nicht mehr benötigst.

Das Formular zeigt das passende Secret-Feld für den gewählten Agenten: ein
einblendbares Token-Eingabefeld für Claude Code oder ein mehrzeiliges
`auth.json`-Einfügefeld für Codex.

## Status von Zugangsdaten

Jeder Zugangsdatensatz trägt einen von drei Status:

| Status | Bedeutung |
|---|---|
| Active | Verwendbar. Nur Active-Zugangsdaten werden automatisch für eine Aufgabe ausgewählt. |
| Expired | Zur Dokumentation aufbewahrt, aber nicht mehr gültig; nicht automatisch ausgewählt. |
| Revoked | Deaktiviert; nicht automatisch ausgewählt. |

Wenn eine Aufgabe keinen Zugangsdatensatz explizit benennt, verwendet Argos den
**ersten aktiven Zugangsdatensatz** für den aufgelösten Agenten (nach
Erstellungsdatum sortiert).

## Wie ein Projekt seinen Agenten auswählt

Jedes Projekt (Repo-Profil) kann in seinen **Worker**-Einstellungen einen
Standard-Agenten festlegen. Lässt ein Projekt den Agenten ungesetzt, fallen
Aufgaben auf **Claude Code** zurück. Der Projekt-Standard bestimmt auch, welche
Modelle und Zugangsdaten für seine Aufgaben angeboten werden. Siehe
[PROJECTS.md](PROJECTS.md) für die Projektkonfiguration.

Der effektive Agent für eine Aufgabe wird in dieser Reihenfolge aufgelöst:

1. Die Agent-Überschreibung der Aufgabe, falls gesetzt.
2. Der Standard-Agent des Projekts, falls gesetzt.
3. Claude Code.

## Überschreibungen pro Aufgabe

Im **Worker**-Tab einer Aufgabe kannst du die geerbten Standardwerte für genau
diese eine Aufgabe überschreiben:

- **Agent (Override)** — diese Aufgabe mit einem anderen Agenten als dem
  Projekt-Standard ausführen. Bleibt das Feld leer, wird der Projekt-Standard
  verwendet. Das Ändern des Agenten löscht alle zuvor gewählten Zugangsdaten und
  fixierten Modelle, da sie zum alten Agenten gehörten.
- **Agent Credential** — welchen gespeicherten Zugangsdatensatz der Agent
  verwendet. Bleibt das Feld leer, wird der erste aktive Zugangsdatensatz für
  den aufgelösten Agenten verwendet.

## Modellauswahl

Für Agenten, die mehr als ein Modell anbieten (Claude Code), kannst du das
Modell pro Phase fixieren — auf Projektebene oder, enger gefasst, pro Aufgabe:

- **Concept model** — wird verwendet, während der Agent den Ansatz erarbeitet.
- **Implement model** — wird verwendet, während der Agent die Änderung schreibt.

Die Auflösungsreihenfolge für jede Phase lautet: Aufgaben-Überschreibung →
Projekt-Standard → eingebauter Standard des Agenten für diese Phase. Bleiben
beide Auswahlfelder leer, wird der Agent-Standard verwendet (für Claude Code:
Opus 4.7 für Konzept, Sonnet 4.6 für Implementierung). Codex bietet ein einziges
Modell, daher hat die Modellauswahl bei ihm keine Wirkung.

## Sicherheit

- Secrets (Claude-OAuth-Tokens, Codex-`auth.json`) werden **verschlüsselt** in
  der Datenbank gespeichert.
- Tokens werden **niemals geloggt**, nicht einmal zu Diagnosezwecken.
- Zugangsdaten werden dem Worker-Container nur zur Laufzeit als
  Umgebungsvariablen übergeben, und die `auth.json`-Umgebungsvariable von Codex
  wird auf der Festplatte geleert, bevor irgendein Phasenskript läuft. Nichts
  Geheimes wird in das Worker-Image eingebacken.

Für einen Überblick darüber, wie Worker, Manager und Zugangsdaten
zusammenpassen, siehe [OVERVIEW.md](OVERVIEW.md).
