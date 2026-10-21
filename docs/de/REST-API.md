# Argos REST API

Argos stellt eine versionierte Server-zu-Server-REST-API auf dieselben
Workspaces und Aufgaben bereit, die du in der Web-UI nutzt: Workspaces
auflisten, anlegen, **automatische Aufgaben** starten, die planen, umsetzen,
fehlschlagende Quality-Gates beheben und einen Pull Request öffnen, sie
verfolgen und lenken und dein eigenes Ticket-System anbinden. Sie läuft gegen
exakt dieselbe Logik wie die UI und der [MCP-Server](SETUP-MCP.md).

## Basis-URL und Versionierung

Alle Endpunkte liegen unter `/api/v1`:

```
https://your-argos-host/api/v1
```

`v1` ist die aktuelle und einzige API-Version.

## Authentifizierung

Die API verwendet **Sanctum-Bearer-Tokens**: Sende bei jeder Anfrage
`Authorization: Bearer <token>` (und `Accept: application/json`). Tokens
tragen **Abilities**, die die Endpunkte freischalten.

### Token-Arten

| Art | Angelegt unter | Reichweite | Handelt für |
|---|---|---|---|
| **API-Client-Token** | Admin-Panel → **Konfiguration → API-Clients** → ein Client → *API-Tokens* | Jeder Workspace, den die Person unter **Handelt als** des Clients sehen darf (ein Argos-Admin sieht alle) | Die Person unter **Handelt als** des Clients |
| **Workspace-Token** | **Einstellungen → Integrationen → API-Tokens** des Workspaces (Workspace-Admins) oder der Workspace im Admin-Panel | Nur dieser Workspace — andere Workspaces und deren Aufgaben antworten mit `404` | Der **Automatik-Nutzer** des Workspaces (Einstellungen → Automatik) |

Das Klartext-Token wird bei der Erstellung **einmalig** angezeigt; gespeichert
wird nur sein Hash.

### Abilities

| Ability | Gewährt |
| --- | --- |
| `workspaces:read` | Workspaces auflisten und lesen |
| `workspaces:write` | Workspaces anlegen — nur API-Client-Tokens, deren Person unter **Handelt als** Argos-Admin ist; Workspace-Tokens bekommen sie nie |
| `tasks:read` | Die Aufgaben eines Workspaces auflisten und eine Aufgabe lesen |
| `tasks:write` | Automatische Aufgaben starten, in Aufgaben schreiben, Pläne entscheiden, pausieren/fortsetzen, ausliefern, Ticket-Kommentare posten |

Eine Anfrage an einen Endpunkt, dessen Ability dem Token fehlt, antwortet mit
`403`.

### Für wen eine Anfrage handelt

Jede Anfrage handelt für eine **Person**: Ihr Claude-Agent (Abo) erledigt die
Arbeit, ihr gehören die Aufgaben, die dabei starten, und ihre Workspace-Rolle
entscheidet, was sie darf. Ohne diese Person (kein Automatik-Nutzer / keine
Person unter *Handelt als*) antworten Anfragen mit `422` und
`"code": "no_actor"`.

- `admin` und `member` dürfen Aufgaben starten; ein `viewer` liest nur.
- Alle in einem Workspace lesen alle seine Aufgaben, aber nur die Person, der
  eine Aufgabe gehört, ihre Mitarbeitenden und Workspace-Admins dürfen
  hineinschreiben, sie lenken oder ausliefern — andere Aufgaben antworten mit
  `403`.
- Eine Aufgabe zu starten braucht einen nutzbaren Claude-Agenten der
  handelnden Person (sonst `no_usable_agent`).

## Endpunkte

`{workspace}` und `{task}` sind ULIDs.

| Methode | Pfad | Ability | Zweck |
| --- | --- | --- | --- |
| GET | `/api/v1/workspaces` | `workspaces:read` | Workspaces der handelnden Person, nach Name (Filter: `status`; max. 200) |
| GET | `/api/v1/workspaces/{workspace}` | `workspaces:read` | Ein Workspace |
| POST | `/api/v1/workspaces` | `workspaces:write` | [Einen Workspace anlegen](#einen-workspace-anlegen) |
| GET | `/api/v1/workspaces/{workspace}/tasks` | `tasks:read` | Die Aufgaben des Workspaces, zuletzt aktualisierte zuerst (Filter: `origin` = `manual`/`api`/`mcp`/`issue`, `archived=1`, um archivierte einzuschließen; max. 200) |
| GET | `/api/v1/tasks/{task}` | `tasks:read` | Eine Aufgabe |
| POST | `/api/v1/workspaces/{workspace}/tasks` | `tasks:write` | [Eine automatische Aufgabe starten](#eine-aufgabe-starten) |
| POST | `/api/v1/tasks/{task}/messages` | `tasks:write` | In eine Aufgabe schreiben |
| POST | `/api/v1/tasks/{task}/plan` | `tasks:write` | Einen wartenden Plan entscheiden |
| POST | `/api/v1/tasks/{task}/pause` | `tasks:write` | Automatik pausieren |
| POST | `/api/v1/tasks/{task}/resume` | `tasks:write` | Die Aufgabe an die Automatik zurückgeben |
| POST | `/api/v1/tasks/{task}/ship` | `tasks:write` | Jetzt committen, pushen und den Pull Request öffnen/aktualisieren |
| POST | `/api/v1/tasks/{task}/ticket-comments` | `tasks:write` | Einen Kommentar deines eigenen Ticket-Systems in der Aufgabe zeigen |

## Workspaces

```bash
curl https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "my-service",
      "repository": "acme/my-service",
      "platform": "github",
      "default_branch": "main",
      "status": "running",
      "setup": "ready",
      "url": "https://your-argos-host/w/workspaces/01J...",
      "created_at": "2026-10-21T10:00:00.000000Z",
      "updated_at": "2026-10-21T10:00:00.000000Z"
    }
  ]
}
```

| Feld | Werte |
|---|---|
| `platform` | `github`, `gitlab`, `bitbucket` |
| `status` | der Container: `provisioning`, `starting`, `running`, `stopping`, `stopped`, `failed` |
| `setup` | der [Stand der Einrichtung](PREPARE-PROJECT.md#stand-der-einrichtung): `needed`, `running`, `testing`, `ready`, `failed`, `needs_agent` oder `null` vor der ersten Prüfung |

### Einen Workspace anlegen

`POST /api/v1/workspaces` (`workspaces:write`, API-Client-Token eines
Argos-Admins; ein Workspace-Token bekommt `403`):

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `name` | ja | Anzeigename (max. 120) |
| `repo_url` | ja | Clone-URL, z. B. `https://github.com/acme/app` |
| `platform` | ja | `github`, `gitlab` oder `bitbucket` |
| `default_branch` | ja | Branch, von dem Aufgaben starten |
| `auth_method` | ja | `pat` oder `oauth` |
| `token` | bei `pat` | Das Access-Token für das Repository |

Bei `oauth` wird der verbundene Account der Person unter *Handelt als* für die
Plattform genutzt (ohne einen `422` `no_connected_account`). Die Antwort ist
`201` mit dem Workspace; die handelnde Person bekommt darin als Erste die Rolle Admin. Argos
prüft dann das Repository und startet bei Bedarf die
[Setup-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) auf dem Abo dieser Person —
verfolge `setup`. Weitere Einstellungen (Services, Secrets, Mitglieder, …)
setzt du in der UI.

## Aufgaben

Eine Aufgabe, wie die API sie zurückgibt:

```json
{
  "data": {
    "id": "01J...",
    "title": "Add a priority to notes",
    "workspace_id": "01J...",
    "owner": { "id": 3, "name": "Ada" },
    "origin": "api",
    "status": "pr_open",
    "session": "closed",
    "branch": "argos/add-a-priority-to-notes",
    "base_branch": "main",
    "kind": "task",
    "pull_request": {
      "url": "https://github.com/acme/app/pull/7",
      "number": 7,
      "state": "open",
      "checks": "success",
      "review": "approved",
      "conflicts": false
    },
    "auto_merge": false,
    "gates": { "status": "passed", "results": { "...": "..." } },
    "ticket": { "key": "gitlab-42", "number": "42", "title": "…", "url": "https://…" },
    "model": "sonnet",
    "permission_mode": "bypassPermissions",
    "cost_usd": 0.42,
    "auto": {
      "state": "done",
      "message": null,
      "plan": "approve",
      "ship": "pr",
      "fix_rounds": 1,
      "max_fix_rounds": 3,
      "turns": 12
    },
    "url": "https://your-argos-host/w/workspaces/01J...?task=01J...",
    "created_at": "…",
    "updated_at": "…"
  }
}
```

| Feld | Werte |
|---|---|
| `origin` | `manual`, `api`, `mcp`, `issue` |
| `status` | `preparing`, `running`, `waiting`, `ready`, `pr_open`, `done`, `cancelled`, `archived` (siehe [Aufgaben](TASKS.md#status-und-der-aufgaben-wechsler)) |
| `session` | `closed`, `starting`, `idle`, `running`, `waiting_input`, `error` |
| `kind` | `task` oder `setup` für die [Setup-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) |
| `pull_request` | `null`, bis ausgeliefert wurde. `state`: `draft`, `open`, `merged`, `closed`; `checks`: `pending`, `success`, `failure`, `none` (oder `null` vor dem ersten Sync); `review`: `approved`, `changes_requested`, `commented` oder `null`; `conflicts`: boolean. Siehe [Pull Requests](PULL-REQUESTS.md). |
| `auto_merge` | Ob das Auto-Merge des Providers eingeschaltet ist (bzw. werden soll) |
| `gates` | Der letzte Gate-Lauf (`status`, `results` pro Gate) oder `null` |
| `ticket` | Das verknüpfte [Ticket](TICKETS.md) oder `null` |
| `auto` | `null` für eine Aufgabe, die niemand automatisch ausführt; sonst der Zustand des Laufs (`queued`, `planning`, `awaiting_plan_approval`, `implementing`, `gating`, `fixing`, `shipping`, `done`, `paused`, `failed`, `budget_exceeded`), seine Meldung, Einstellungen und Zähler |

### Eine Aufgabe starten

`POST /api/v1/workspaces/{workspace}/tasks` startet eine **automatische
Aufgabe** (siehe [Aufgaben → Automatische Aufgaben](TASKS.md#automatische-aufgaben)).
Die Antwort ist `202` mit der Aufgabe — sie läuft asynchron weiter; frag die
Aufgabe ab, um sie zu verfolgen.

| Feld | Pflicht | Bedeutung |
| --- | --- | --- |
| `prompt` | ja | Was umgesetzt werden soll (Markdown, max. 20.000) |
| `title` | nein | Titel der Aufgabe (Standard: der Titel des Tickets oder der Anfang des Prompts; max. 120) |
| `base_branch` | nein | Ausgangs-Branch (Standard: der Default-Branch des Workspaces) |
| `auto_merge` | nein | `true` schaltet das Auto-Merge des Providers ein, sobald der Pull Request existiert |
| `issue` | nein | Ticket-Nummer in der GitHub-/GitLab-/Linear-Integration des Workspaces: benennt den Branch danach, verknüpft und meldet zurück |
| `ticket` | nein | Ein Ticket deines [eigenen Systems](TICKETS.md#eigene-ticket-systeme): `key` und `title` Pflicht, `url` und `body` optional. Nicht mit `issue` kombinierbar. |
| `agent_id` | nein | Einer der nutzbaren Agenten der handelnden Person (Standard: der erste) |
| `model` | nein | Modell-ID oder Alias (`sonnet`, `opus`, …) |
| `plan` | nein | `off`, `auto` oder `approve` (Standard: Workspace-Einstellung) |
| `ship` | nein | `pr`, `draft_pr` oder `none` (Standard: Workspace-Einstellung) |
| `max_fix_rounds` (0–20), `max_turns` (1–1000), `max_cost_usd` (≥ 0), `max_minutes` (1–1440) | nein | Budgets (Standard: Workspace-Einstellung) |

```bash
curl -X POST https://your-argos-host/api/v1/workspaces/01J.../tasks \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"prompt": "Add a priority (low/medium/high) to notes", "plan": "approve", "auto_merge": true}'
```

Ein gestoppter Workspace wird gestartet; die Aufgabe wird vorbereitet
(Worktree, Datenbank, Setup-Schritte) und läuft dann. Ihr `auto.state` läuft
`queued → planning → (awaiting_plan_approval) → implementing → gating ⇄
fixing → shipping → done`; sie kann auch mit `failed` oder `budget_exceeded`
enden oder von einer Person `paused` werden.

### Eine Aufgabe lenken

| Endpunkt | Body | Antwort |
|---|---|---|
| `POST /tasks/{task}/messages` | `text` (Pflicht), `resume_auto` (boolean) | `202` `{"message_id": …}`. In einer laufenden automatischen Aufgabe pausiert das die Automatik, außer `resume_auto` ist true. |
| `POST /tasks/{task}/plan` | `decision` = `approve` oder `reject`, optional `feedback` | `202` mit der Aufgabe (`no_plan_waiting`, wenn keiner wartet) |
| `POST /tasks/{task}/pause` | — | `200` mit der Aufgabe |
| `POST /tasks/{task}/resume` | — | `202` mit der Aufgabe — sie prüft die Gates und liefert aus |
| `POST /tasks/{task}/ship` | optional `draft` (boolean) | `200` `{"pull_request_url": "…"}` |
| `POST /tasks/{task}/ticket-comments` | `author` (Pflicht), `body` (Pflicht), optional `url` | `202` — siehe [Tickets](TICKETS.md#kommentare-posten) |

## Typischer Ablauf

1. `GET /api/v1/workspaces` — den Workspace finden.
2. `POST /api/v1/workspaces/{workspace}/tasks` — eine automatische Aufgabe
   starten.
3. `GET /api/v1/tasks/{task}` pollen und `auto.state` verfolgen.
4. Mit `plan` = `approve` den Plan entscheiden, sobald der Zustand
   `awaiting_plan_approval` ist: `POST /api/v1/tasks/{task}/plan`.
5. Ist der Zustand `done`, steht der Pull Request unter `pull_request`; poll
   weiter, um seine Checks, Reviews und — nach dem Merge — `status: done` zu
   sehen.
6. `POST /api/v1/tasks/{task}/messages` — Feedback senden (mit `resume_auto`,
   damit der Lauf selbstständig weitermacht).

## Antworten und Fehler

- Lesezugriffe liefern `200` mit der Ressource in einer `data`-Hülle (Objekt
  oder Array).
- Schreibzugriffe, die asynchrone Arbeit anstoßen, liefern `202`.
- Fachliche Fehler antworten mit `422`, einer `message` und einem
  maschinenlesbaren `code`, z. B. `no_actor`, `no_usable_agent`,
  `no_plan_waiting`, `capacity` (zu viele laufende Workspaces),
  `no_connected_account` und weitere, benannt nach dem Fehler. Eine
  verweigerte Berechtigung antwortet mit `403` und `"code": "forbidden"`.

| Status | Bedeutung |
| --- | --- |
| `401` | Fehlendes oder ungültiges Token. |
| `403` | Dem Token fehlt die Ability, oder die handelnde Person darf das nicht (`"code": "forbidden"`). |
| `404` | Nicht gefunden — oder vor einem Workspace-Token bzw. der handelnden Person verborgen. |
| `422` | Validierungsfehler (`errors` pro Feld) oder ein fachlicher Fehler mit `code`. |

```json
{
  "message": "The prompt field is required.",
  "errors": { "prompt": ["The prompt field is required."] }
}
```

## Interaktive API-Referenz

Eine automatisch generierte OpenAPI-Referenz (Scramble) liegt unter
`https://your-argos-host/docs/api`, das rohe Dokument unter `/docs/api.json` —
für angemeldete Argos-Nutzende.

## Siehe auch

- [SETUP-MCP.md](SETUP-MCP.md) — der MCP-Server, dieselben Aufgaben für einen
  KI-Client.
- [TASKS.md](TASKS.md#automatische-aufgaben) — wie automatische Aufgaben
  funktionieren.
- [TICKETS.md](TICKETS.md#eigene-ticket-systeme) — eigene Ticket-Systeme und der
  signierte Webhook.
