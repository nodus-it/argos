# MCP-Server

Argos bringt einen eingebauten [MCP](https://modelcontextprotocol.io)-Server
mit, sodass ein externer MCP-Client — insbesondere **Claude Code** — Argos
direkt steuern kann: Workspaces auflisten, das, was umgesetzt werden soll, als
**automatische Aufgabe** in einem Workspace übergeben, sie verfolgen, ihren
Plan freigeben, sie mit Feedback lenken und den Pull Request abholen.

Das verwandelt die übliche Schleife („in Claude Code planen → in die Argos-UI
kopieren → den Browser beobachten → den Pull-Request-Link kopieren“) in einen
einzigen Ablauf innerhalb der Session, ohne deinen Planungs-Client zu
verlassen.

Wenn du Argos lieber aus Skripten oder einer CI automatisierst als aus einem
interaktiven MCP-Client, sind dieselben Workspaces auch als schlichte HTTP-API
verfügbar — siehe [REST-API.md](REST-API.md). Die beiden Schnittstellen sind
gleichwertig; MCP ist der Eingang für Chat-Clients, die REST API der Eingang
für Skripte.

## Inhalt

- [Funktionsweise](#funktionsweise)
- [Voraussetzungen](#voraussetzungen)
- [Einen MCP-Client verbinden](#einen-mcp-client-verbinden)
- [Verfügbare Tools](#verfugbare-tools)
- [Typischer Ablauf](#typischer-ablauf)
- [Sicherheit](#sicherheit)
- [Fehlerbehebung](#fehlerbehebung)

## Funktionsweise

- Der Server ist unter **`${APP_URL}/mcp`** eingehängt und Teil des
  `app`-Service — kein zusätzlicher Container. Er baut auf `laravel/mcp` auf
  und spricht den Streamable-HTTP-Transport.
- Die Authentifizierung erfolgt über **OAuth 2.1** mittels Laravel Passport.
  Jede Anfrage muss ein Access-Token mit dem Scope **`mcp:use`** mitführen;
  die `/mcp`-Route ist durch `auth:api` plus eine `mcp:use`-Scope-Prüfung
  abgesichert, sodass ein Token ohne diesen Scope auch dann abgelehnt wird,
  wenn es ansonsten gültig ist.
- Clients registrieren sich selbst über OAuth Dynamic Client Registration
  (`/oauth/register`); die Discovery-Metadaten liegen unter den
  `/.well-known/oauth-*`-Endpunkten. Du registrierst einen Client nicht vorab
  von Hand.
- Der erste Verbindungsaufbau öffnet ein **Browser-Login** als Argos-Person
  plus einen Consent-Screen für den Scope `mcp:use`. Die Tools handeln dann
  als diese Person.

## Voraussetzungen

| Voraussetzung | Warum |
|---|---|
| `APP_URL` auf die **öffentliche** URL gesetzt | Sie dient zugleich als OAuth-Issuer und als Redirect-Basis. Sie muss von dort erreichbar sein, wo der MCP-Client läuft, sonst schlagen Registrierung und Login fehl. |
| Stack über das Netzwerk erreichbar | Der MCP-Client spricht per HTTP mit `${APP_URL}/mcp`. Hinter einem Reverse-Proxy terminierst du TLS dort und leitest an den `nginx`-Service weiter. |
| Passport-Keys persistiert | Wird automatisch erledigt — einmalig in `PASSPORT_KEYS_PATH` (`/data/passport` in Compose) auf dem persistenten Volume erzeugt, sodass ausgestellte Tokens Image-Rebuilds überstehen. |

Keine Flags umzulegen: Der MCP-Server ist immer an. Er ist nur mit einem
gültigen `mcp:use`-Token erreichbar, daher gibt ein nicht authentifiziertes
`POST /mcp` ein `401` zurück.

## Einen MCP-Client verbinden

Für **Claude Code**:

```bash
claude mcp add --transport http argos https://your-argos.example.com/mcp
```

Führe dann in Claude Code `/mcp` aus, wähle `argos` und dann
**Authenticate**. Ein Browser öffnet sich unter `${APP_URL}/oauth/authorize`;
melde dich mit deinem Argos-Account an und genehmige den Scope `mcp:use`. Der
Status springt auf **connected**, und die folgenden Tools werden verfügbar.

> Andere Clients, die HTTP-MCP mit OAuth sprechen (Cursor, VS Code),
> funktionieren genauso — ihre Redirect-Schemata zur privaten Nutzung
> (`claude`, `cursor`, `vscode`) stehen bereits in `config/mcp.php`
> (`custom_schemes`) auf der Allowlist, und `redirect_domains` steht
> standardmäßig auf `*`.

## Verfügbare Tools

Der Server stellt neun Tools bereit — `workspace_*` für Workspaces, `task_*`
für Aufgaben. Ein `workspace`-Argument akzeptiert entweder die ULID des
Workspaces **oder** seinen exakten Namen; ein `task`-Argument nimmt die ULID
der Aufgabe. Workspaces lassen sich über MCP nicht anlegen (nutze die UI oder
die [REST API](REST-API.md#einen-workspace-anlegen)).

### Workspaces (lesen)

| Tool | Was es tut |
| --- | --- |
| `workspace_list` | Listet die Workspaces, die du sehen darfst: Repository, Default-Branch und Container-Status. |
| `workspace_get` | Ein Workspace per ID oder Name, mit seinen offenen Aufgaben (Besitz, Status, Branch, Pull Request, Gates, Ticket, `auto.state`). |

### Aufgaben und automatische Läufe

| Tool | Was es tut |
| --- | --- |
| `task_create` | Startet eine automatische Aufgabe in einem Workspace: planen (je nach `plan`: `off` / `auto` / `approve`), auf eigenem Branch umsetzen, fehlschlagende Quality-Gates beheben, ausliefern (je nach `ship`: `pr` / `draft_pr` / `none`). Argumente: `workspace`, `prompt`, optional `title`, `issue` (Ticket-Nummer im Tracker des Workspaces), `base_branch`, `plan`, `ship`, `auto_merge` (schaltet das Auto-Merge des Providers ein, siehe [Pull Requests](PULL-REQUESTS.md#auto-merge)). Läuft auf deinem Abo; kehrt sofort zurück. |
| `task_get` | Eine Aufgabe — Status, Besitz, Branch, Pull Request (Zustand, Checks, Review, Konflikte), Gates, Ticket und `auto.state`. Frag sie ab, um einem Lauf zu folgen. |
| `task_plan_decide` | Gibt einen wartenden Plan frei oder lehnt ihn ab (`decision`, optional `feedback`). |
| `task_message` | Schreibt in eine Aufgabe (`text`); mit `resume_auto` läuft der Lauf danach selbstständig weiter. |
| `task_pause` / `task_resume` | Automatik pausieren / eine pausierte, fehlgeschlagene oder wegen des Budgets gestoppte Aufgabe an sie zurückgeben (sie prüft die Gates und liefert aus). |
| `task_ship` | Committet, pusht und öffnet/aktualisiert jetzt den Pull Request der Aufgabe (optional `draft`). |

Aufgaben handeln für dich: Dein Claude-Agent erledigt die Arbeit, und dir
gehört die Aufgabe. Du kannst jede Aufgabe eines Workspaces lesen, dem du
angehörst, aber nur in deine eigenen Aufgaben und die, an denen du mitarbeitest,
schreiben, sie lenken oder ausliefern (Workspace-Admins dürfen alle).

## Typischer Ablauf

Ein Durchlauf vom Plan zum PR aus einer Planungs-Session:

1. `workspace_list` / `workspace_get` — wähle den Workspace, in dem du
   arbeiten willst.
2. `task_create` — übergib, was umgesetzt werden soll (`prompt`). Wähle
   `plan` und `ship` oder überlass sie den Vorgaben des Workspaces. Das Tool
   kehrt sofort zurück.
3. `task_get` — frag den Lauf ab: Sein `auto.state` geht von queued →
   planning → implementing → gating/fixing → shipping → done.
4. Mit `plan = approve` hält der Lauf bei `awaiting_plan_approval` an:
   `task_plan_decide` gibt frei oder lehnt ab (mit Feedback).
5. Ist der Lauf fertig, zeigt `task_get` den Pull Request unter
   `pull_request` mit seinen Checks und seinem Review-Status; checke seinen
   Branch lokal aus, um in deiner IDE zu reviewen.
6. `task_message` — schick Review-Feedback in die Aufgabe; mit `resume_auto`
   macht der Lauf danach selbstständig weiter. `task_ship` liefert den
   aktuellen Stand auf Anforderung aus.

## Sicherheit

- **Durch den Scope geschützt.** Nur Tokens mit dem Scope `mcp:use` erreichen
  den Server; der Consent-Screen beim ersten Verbindungsaufbau ist die Stelle,
  an der dieser Scope erteilt wird. Ein Token, das für einen anderen Zweck
  ausgestellt wurde, kann Argos nicht über MCP steuern.
- **Token-Lebensdauer.** Access-Tokens laufen nach **30 Tagen** ab,
  Refresh-Tokens nach **60 Tagen** (`Passport::tokensExpireIn` /
  `refreshTokensExpireIn`). Läuft das Access-Token ab, erneuert es ein
  OAuth-fähiger Client transparent; läuft auch das Refresh-Token ab,
  durchläuft der Client erneut den Browser-Consent.
- **Signing-Keys bleiben erhalten.** Die Passport-Keys liegen auf dem
  persistenten Volume (`PASSPORT_KEYS_PATH`, `/data/passport` in Compose),
  sodass ein Image-Rebuild nicht stillschweigend jedes ausgestellte Token
  ungültig macht.
- **Handelt als du.** Wer ein gültiges `mcp:use`-Token besitzt, hat dieselbe
  Reichweite wie die Person, die es erteilt hat — behandle das Token wie ein
  Passwort und widerrufe es in der Argos-UI, wenn ein Client verloren geht.

## Fehlerbehebung

- **`401` mit `WWW-Authenticate`** — ohne Token erwartet; führe den
  OAuth-Connect oben durch.
- **Client-Registrierung schlägt fehl / Redirect abgelehnt** — `APP_URL`
  passt nicht zur URL, die der Client tatsächlich erreicht, oder das
  Redirect-Schema des Clients steht nicht in `config/mcp.php`
  (`custom_schemes` / `redirect_domains`).
- **Token funktioniert nach einem Rebuild nicht mehr** — `PASSPORT_KEYS_PATH`
  liegt nicht auf einem persistenten Volume; im Standard-Compose-Stack zeigt
  es auf `/data/passport`.
- **Tool-Aufrufe gelingen, aber in der UI passiert nichts** — Läufe sind
  asynchron. Prüfe, ob der Queue-Worker (`queue`-Service) läuft; lies mit
  `task_get` erneut, um den Zustand fortschreiten zu sehen.
- **`task_create` scheitert mit „no usable agent“** — du brauchst einen
  aktiven eigenen (oder geteilten) Claude-Agenten unter *Agents* und die Rolle
  *admin* oder *member* im Workspace.

## Siehe auch

- [REST-API.md](REST-API.md) — dieselben Workspaces als schlichte HTTP-API für
  Skripte und CI (der Automatisierungsweg ohne MCP).
- [OAUTH.md](OAUTH.md) — die OAuth-App-Verdrahtung, die Argos für
  Git-Provider nutzt.
- [CONFIGURATION.md](CONFIGURATION.md) — die zugehörigen Umgebungsvariablen
  (`APP_URL`, `PASSPORT_KEYS_PATH`).
