# Coding-Agents und Zugangsdaten

Wenn du in einem Workspace arbeitest, übernimmt ein **Coding-Agent** das
Denken und das Tippen — eine CLI für ein großes Sprachmodell, die im
Workspace-Container läuft, dein Repository liest, plant, die Änderung schreibt
und sie committet. Argos bringt kein eigenes Modell mit; es steuert einen
Agenten, den du authentifizierst.

Dieses Dokument erklärt, welchen Agenten Argos unterstützt, wie er sich
authentifiziert, wo du die Zugangsdaten verwaltest und wie eine Aufgabe Agent
und Modell auswählt, auf denen sie läuft. Es richtet sich an alle, die Argos
einrichten — den Quellcode des Daemons musst du dafür nicht lesen.

## Inhalt

- [Was ein Agent in Argos ist](#was-ein-agent-in-argos-ist)
- [Claude Code](#claude-code)
- [Agent-Zugangsdaten](#agent-zugangsdaten)
- [Zugangsdaten beim Onboarding hinzufügen](#zugangsdaten-beim-onboarding-hinzufugen)
- [Zugangsdaten später verwalten](#zugangsdaten-spater-verwalten)
- [Status von Zugangsdaten](#status-von-zugangsdaten)
- [Persönliche und geteilte Agenten](#personliche-und-geteilte-agenten)
- [Modellauswahl](#modellauswahl)
- [Weitere Agenten anbinden](#weitere-agenten-anbinden)
- [Sicherheit](#sicherheit)

## Was ein Agent in Argos ist

Ein **Agent** ist die Kombination aus einem Coding-Modell und dem
Kommandozeilen-Tool, das es steuert. Die CLI wird ins Worker-Image installiert
(die Basis jedes Workspace-Images); im laufenden Workspace hostet der
Workspace-Daemon die Sessions des Agenten — eine pro Aufgabe, im Worktree der
Aufgabe.

| Agent | Kennung | CLI | Distribution |
|---|---|---|---|
| Claude Code | `claude-code` | `claude` | `@anthropic-ai/claude-code` (npm) |

Der Agent braucht einen Stack mit Node — alle eingebauten Stacks bringen ihn
mit, ein eigenes `.argos/worker.dockerfile` eines Repositorys muss ihn
mitbringen. Die CLI wird beim Bauen des Images installiert; siehe
[Stacks und Images](WORKER-STACKS.md).

## Claude Code

Claude Code authentifiziert sich mit dem **OAuth-Token deines
Claude-Abonnements** (Pro, Max oder Team), *nicht* mit einem abgerechneten
API-Key. Was in Argos erledigt wird, läuft über deinen bestehenden Claude-Plan
und nicht als separate API-Nutzung pro Token.

So bekommst du einen Token:

1. Führe `claude setup-token` in einem Terminal aus, in dem die Claude-CLI
   angemeldet ist.
2. Kopiere den ausgegebenen Token.
3. Füge ihn in Argos ein (beim Onboarding oder auf der Seite
   *Agent-Credentials*).

Beim Speichern prüft Argos den Token gegen die Anthropic-API. Ist die API
nicht erreichbar, wird der Token trotzdem gespeichert, aber als ungeprüft
markiert.

## Agent-Zugangsdaten

Ein **Agent-Zugang** (in der Oberfläche als *Agent* geführt) ist ein
gespeicherter Datensatz, der den Agenten mit einem Satz Zugangsdaten
verbindet:

- **Agent** — Claude Code.
- **Name** — ein frei wählbarer Name zur Unterscheidung, z. B. „Persönlich“
  oder „Team-Konto“.
- **Token** — der OAuth-Token, verschlüsselt in der Datenbank gespeichert.
- **Status** — Aktiv, Abgelaufen oder Widerrufen (siehe
  [Status von Zugangsdaten](#status-von-zugangsdaten)).
- **Besitzer**, **Parallele Sessions** und **Standardmodell** — siehe
  [Persönliche und geteilte Agenten](#personliche-und-geteilte-agenten).
- **Zuletzt geprüft** — wann der Token zuletzt geprüft wurde.

Du kannst mehrere Agenten anlegen (zum Beispiel ein persönliches und ein
Team-Abo) und wählen, welchen eine Aufgabe nutzt.

## Zugangsdaten beim Onboarding hinzufügen

Der Agent-Schritt des Onboarding-Assistenten (`${APP_URL}/admin/onboarding`)
fragt nach einem Claude-Token. Ohne authentifizierten Agenten geht es nicht
weiter zur Repository-Anbindung. Füge die Ausgabe von `claude setup-token`
ein; Argos prüft sie und speichert sie als geteilten Agenten namens „Default“.

Den vollständigen Ersteinrichtungs-Ablauf beschreibt [SETUP.md](SETUP.md).

## Zugangsdaten später verwalten

Nach dem Onboarding verwaltest du Agenten auf der Seite **Agent-Credentials**
(`${APP_URL}/admin/agent-credentials`). Dort kannst du:

- neue Agenten anlegen,
- Name, Token, Status, Besitzer oder Limits eines Agenten bearbeiten,
- die Liste nach Agent oder Status filtern,
- nicht mehr benötigte Agenten löschen.

## Status von Zugangsdaten

Jeder Agent hat einen von drei Status:

| Status | Bedeutung |
|---|---|
| Aktiv | Für neue Aufgaben nutzbar. |
| Abgelaufen | Bleibt erhalten, ist aber nicht mehr gültig; wird nicht angeboten. |
| Widerrufen | Deaktiviert; wird nicht angeboten. |

## Persönliche und geteilte Agenten

Ein Agent ist entweder **persönlich** (er hat einen Besitzer und läuft auf dem
Abo dieser Person) oder **geteilt** (ohne Besitzer).

- Alle Nutzenden können unter *Agent-Credentials* einen **eigenen**
  Claude-Agenten anlegen — mit der Ausgabe von `claude setup-token`; das Profil
  unter `/w` listet sie ebenfalls auf. Alle sehen nur ihre eigenen Agenten.
- Admins sehen alle Agenten und können einen Besitzer zuweisen oder das Feld
  für einen geteilten Agenten leer lassen.
- Eine **Aufgabe** läuft auf dem Agenten, den die Person, der sie gehört, beim
  Anlegen gewählt hat — einem eigenen oder einem geteilten (siehe
  [Aufgaben](TASKS.md)). Auch Nachrichten von Mitwirkenden laufen darauf. Wird
  eine Aufgabe übergeben, wechselt sie auf einen Agenten der neuen Person
  (zuerst einen eigenen). Automatische Aufgaben, die über API, MCP oder einen
  Issue-Trigger gestartet werden, nutzen den ersten verfügbaren Agenten der
  Person, für die sie handeln.
- **Parallele Sessions** (Admins) begrenzt, wie viele Aufgaben gleichzeitig
  über einen Agenten mit Claude sprechen dürfen (Standard `1`); weitere
  Nachrichten warten in der [Session-Warteschlange](TASKS.md#session-warteschlange).
  Der Workspace hat zusätzlich ein eigenes Limit. Meldet Claude, dass das Abo
  sein Nutzungslimit erreicht hat, zeigt der Agent *Nutzungslimit bis …* und
  startet bis dahin keine neuen Sessions.

## Modellauswahl

Claude Code meldet beim Start einer Session, welche Modelle das Abo nutzen
darf; Argos merkt sich diese Liste pro Agent und bietet sie beim Anlegen einer
Aufgabe an. Vor der ersten Session eines Agenten stehen die Aliase (`sonnet`,
`opus`, …) zur Wahl — sie zeigen immer auf das neueste Modell. Das
**Standardmodell** des Agenten ist für neue Aufgaben vorausgewählt; ohne
Angabe nutzt Argos `sonnet`.

## Weitere Agenten anbinden

Argos liefert nur Claude Code aus, die Nahtstellen für einen weiteren Agenten
(etwa Codex) sind aber vorhanden:

- **Gespeicherte Zugangsdaten** — `agent_credentials.agent_name` hält die
  Kennung des Agenten, Zugangsdaten eines neuen Agenten passen also in die
  bestehende Tabelle.
- **Agent-Enum** — ein neuer Fall in `App\Enums\AgentName`. Dessen
  `supportsWorkspaces()` entscheidet, ob Aufgaben darauf laufen dürfen; der
  Agent-Pool bietet nur Agenten an, deren Fall `true` liefert.
- **Image** — ein `AgentRunner` mit seiner `AgentSpec` (npm-Paket, CLI,
  benötigte Stack-Fähigkeiten) und ein Installationsskript unter
  `.tools/docker/worker/agents/`, registriert in der `AgentRegistry`. Der Build
  des Worker-Images installiert dann die CLI.
- **Laufzeit** — der Workspace-Daemon kapselt den Agenten in `TaskSession`
  hinter einer `QueryFactory`. Ein neuer Agent ist eine zweite
  Session-Implementierung mit denselben Events und denselben HTTP-Routen; die
  Manager-Seite bleibt unverändert.

## Sicherheit

- Tokens werden **verschlüsselt** in der Datenbank gespeichert.
- Tokens werden **nie geloggt**, auch nicht zur Diagnose.
- Der Token erreicht den Workspace-Daemon nur im Speicher, wenn die Session
  einer Aufgabe startet — er landet nie auf der Platte, in der
  Container-Umgebung oder im Image.

Einen Überblick, wie Workspace, Manager und Zugangsdaten zusammenspielen,
gibt [OVERVIEW.md](OVERVIEW.md).
