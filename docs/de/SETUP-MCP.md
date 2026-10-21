# MCP-Server

Argos bringt einen eingebauten [MCP](https://modelcontextprotocol.io)-Server
mit, sodass ein externer MCP-Client — insbesondere **Claude Code** — Argos
direkt steuern kann: Projekte auflisten, das, was umgesetzt werden soll, als
**automatischen Lauf** in einem neuen Workspace übergeben, ihn verfolgen, seinen
Plan freigeben, ihn mit Feedback lenken und den Pull Request abholen.

Das verwandelt die übliche Schleife („in Claude Code planen → in die Argos-UI
kopieren → den Browser beobachten → den Pull-Request-Link kopieren") in einen
einzigen Ablauf innerhalb der Session, ohne deinen Planungs-Client zu verlassen.

Wenn du Argos lieber aus Skripten oder einer CI automatisieren möchtest als aus
einem interaktiven MCP-Client, sind dieselben Workspaces auch als schlichte
HTTP-API verfügbar — siehe [REST-API.md](REST-API.md). Die beiden Schnittstellen
sind gleichwertig; MCP ist der Eingang für Chat-Clients, die REST-API der
Eingang für Skripte.

## Inhalt

- [Funktionsweise](#how-it-works)
- [Voraussetzungen](#prerequisites)
- [Einen MCP-Client verbinden](#connect-an-mcp-client)
- [Verfügbare Tools](#available-tools)
- [Typischer Ablauf](#typical-flow)
- [Sicherheit](#security)
- [Fehlersuche](#troubleshooting)

## Funktionsweise

- Der Server ist unter **`${APP_URL}/mcp`** eingehängt und Teil des
  `app`-Service — kein zusätzlicher Container. Er baut auf `laravel/mcp` auf und
  spricht den Streamable-HTTP-Transport.
- Die Authentifizierung erfolgt über **OAuth 2.1** mittels Laravel Passport.
  Jede Anfrage muss ein Access-Token mit dem Scope **`mcp:use`** mitführen; die
  `/mcp`-Route ist durch `auth:api` plus eine `mcp:use`-Scope-Prüfung
  abgesichert, sodass ein Token ohne diesen Scope auch dann abgelehnt wird, wenn
  es ansonsten gültig ist.
- Clients registrieren sich selbst über OAuth Dynamic Client Registration
  (`/oauth/register`); die Discovery-Metadaten liegen unter den
  `/.well-known/oauth-*`-Endpunkten. Du registrierst einen Client nicht von Hand
  vorab.
- Der erste Verbindungsaufbau öffnet ein **Browser-Login** als Argos-Benutzer
  plus einen Consent-Screen für den `mcp:use`-Scope. Argos ist heute
  Single-User, daher ist das einfach dein Admin-Login.

## Voraussetzungen

| Voraussetzung | Warum |
|---|---|
| `APP_URL` auf die **öffentliche** URL gesetzt | Sie dient zugleich als OAuth-Issuer und als Redirect-Basis. Sie muss von dort aus erreichbar sein, wo der MCP-Client läuft, sonst schlagen Registrierung und Login fehl. |
| Stack über das Netzwerk erreichbar | Der MCP-Client spricht per HTTP mit `${APP_URL}/mcp`. Hinter einem Reverse-Proxy terminierst du TLS dort und leitest an den `nginx`-Service weiter. |
| Passport-Keys persistiert | Wird automatisch erledigt — einmalig in `PASSPORT_KEYS_PATH` (`/data/passport` in Compose) auf dem persistenten Volume erzeugt, sodass ausgestellte Tokens Image-Rebuilds überstehen. |

Es gibt keine Flags umzulegen: Der MCP-Server ist immer an. Er ist nur mit einem
gültigen `mcp:use`-Token erreichbar, sodass ein nicht authentifiziertes
`POST /mcp` ein `401` zurückgibt.

## Einen MCP-Client verbinden

Für **Claude Code**:

```bash
claude mcp add --transport http argos https://your-argos.example.com/mcp
```

Führe dann innerhalb von Claude Code `/mcp` aus, wähle `argos` und dann
**Authenticate**. Ein Browser öffnet sich unter `${APP_URL}/oauth/authorize`;
melde dich als dein Argos-Admin-Benutzer an und genehmige den `mcp:use`-Scope.
Der Status springt auf **connected** und die folgenden Tools werden verfügbar.

> Andere Clients, die HTTP-MCP mit OAuth sprechen (Cursor, VS Code),
> funktionieren genauso — ihre Redirect-Schemata zur privaten Nutzung
> (`claude`, `cursor`, `vscode`) sind bereits in `config/mcp.php`
> (`custom_schemes`) auf der Allowlist, und `redirect_domains` steht
> standardmäßig auf `*`.

## Verfügbare Tools

Der Server stellt zehn Tools bereit. Ein `project`- oder `workspace`-Argument
akzeptiert stets entweder die ULID des Datensatzes **oder** seinen exakten Namen.

### Workspaces und automatische Läufe

| Tool | Was es tut |
| --- | --- |
| `workspace_create` | Legt einen Workspace an und startet einen automatischen Lauf: planen (je nach `plan`: off / auto / approve), umsetzen, fehlgeschlagene Quality-Gates beheben, Pull Request öffnen. Argumente: `project`, `prompt`, optional `title`, `base_branch`, `plan`, `ship`. Kehrt sofort zurück. |
| `workspace_list` | Listet deine Workspaces (optional `origin`) mit Läufen, Pull Request und Gate-Ergebnissen. |
| `workspace_get` | Ein Workspace per ID oder Name — frag ihn ab, um einem Lauf zu folgen (`threads[].auto.state`, `pull_request`). |
| `thread_plan_decide` | Gibt einen wartenden Plan frei oder lehnt ihn ab (`decision`, optional `feedback`). |
| `thread_message` | Schreibt in einen Thread (`text`); mit `resume_auto` läuft der Lauf danach selbstständig weiter. |
| `thread_pause` / `thread_resume` | Automatik pausieren / den Thread an sie zurückgeben. |
| `workspace_ship` | Committet, pusht und öffnet/aktualisiert jetzt den Pull Request (optional `draft`). |

Läufe handeln für dich: Dein Claude-Agent arbeitet, und dir gehört der Workspace.

### Projekte (lesen)

| Tool | Was es tut |
|---|---|
| `project_list` | Listet die konfigurierten Repository-Profile (Projekte), an denen Argos arbeitet, mit ihren Automatisierungs-Vorgaben (`auto_settings`) und der Zahl offener Workspaces. |
| `project_get` | Liefert ein Projekt (per id oder Name) mit seinen Automatisierungs-Vorgaben und einer Übersicht seiner Workspaces (Status, Herkunft, Branch, Pull Request). |

## Typischer Ablauf

Ein Plan-zu-PR-Durchlauf aus einer Planungs-Session:

1. `project_list` / `project_get` — wähle das Projekt, in dem du arbeiten
   möchtest.
2. `workspace_create` — übergib, was umgesetzt werden soll (`prompt`). Wähle
   `plan` und `ship` oder überlass sie den Projektvorgaben. Das Tool kehrt
   sofort zurück.
3. `workspace_get` — frage den Lauf ab: `auto.state` des Threads geht von
   queued → planning → implementing → gating/fixing → shipping → done.
4. Mit `plan = approve` hält der Lauf bei `awaiting_plan_approval` an:
   `thread_plan_decide` gibt frei oder lehnt ab (mit Feedback).
5. Ist der Lauf fertig, zeigt `workspace_get` den Pull Request unter
   `pull_request`; checke seinen Branch lokal aus, um in deiner IDE zu reviewen.
6. `thread_message` — schick Review-Feedback in den Thread; mit `resume_auto`
   macht der Lauf danach selbstständig weiter. `workspace_ship` liefert den
   aktuellen Stand auf Anforderung aus.

## Sicherheit

- **Scope-gegated.** Nur Tokens, die den `mcp:use`-Scope tragen, erreichen den
  Server; der Consent-Screen beim ersten Verbindungsaufbau ist die Stelle, an
  der dieser Scope erteilt wird. Ein Token, das für einen anderen Zweck
  ausgestellt wurde, kann Argos nicht über MCP steuern.
- **Token-Lebensdauer.** Access-Tokens laufen nach **30 Tagen** ab und
  Refresh-Tokens nach **60 Tagen** (`Passport::tokensExpireIn` /
  `refreshTokensExpireIn`). Wenn das Access-Token abläuft, erneuert es ein
  OAuth-fähiger Client transparent; läuft auch das Refresh-Token ab, durchläuft
  der Client erneut den Browser-Consent.
- **Signing-Keys bleiben erhalten.** Die Passport-Keys liegen auf dem
  persistenten Volume (`PASSPORT_KEYS_PATH`, `/data/passport` in Compose),
  sodass ein Image-Rebuild nicht stillschweigend jedes ausgestellte Token
  invalidiert.
- **Heute Single-User.** Argos ist Single-User, daher ist das OAuth-Login dein
  Admin-Login. Wer ein gültiges `mcp:use`-Token besitzt, hat dieselbe Reichweite
  wie dieser Benutzer — behandle das Token wie ein Passwort und widerrufe es in
  der Argos-UI, wenn ein Client verloren geht.

## Fehlersuche

- **`401` mit `WWW-Authenticate`** — ohne Token erwartet; vollziehe den
  OAuth-Connect oben.
- **Client-Registrierung schlägt fehl / Redirect abgelehnt** — `APP_URL` passt
  nicht zur URL, die der Client tatsächlich erreicht, oder das Redirect-Schema
  des Clients steht nicht in `config/mcp.php` (`custom_schemes` /
  `redirect_domains`).
- **Token funktioniert nach einem Rebuild nicht mehr** — `PASSPORT_KEYS_PATH`
  liegt nicht auf einem persistenten Volume; im Standard-Compose-Stack zeigt es
  auf `/data/passport`.
- **Tool-Aufrufe gelingen, aber in der UI passiert nichts** — Läufe sind
  asynchron. Prüfe, ob der Queue-Worker (`queue`-Service) läuft; lies mit
  `workspace_get` erneut, um den Zustand weiterlaufen zu sehen.
- **`workspace_create` scheitert mit „kein nutzbarer Agent"** — du brauchst
  einen aktiven eigenen (oder geteilten) Claude-Agenten unter *Agenten*.

## Siehe auch

- [REST-API.md](REST-API.md) — dieselben Workspaces als schlichte HTTP-API für
  Skripte und CI (der Nicht-MCP-Automatisierungspfad).
- [OAUTH.md](OAUTH.md) — die OAuth-App-Verdrahtung, die Argos für git-Provider
  nutzt.
- [CONFIGURATION.md](CONFIGURATION.md) — die zugehörigen Umgebungsvariablen
  (`APP_URL`, `PASSPORT_KEYS_PATH`).
