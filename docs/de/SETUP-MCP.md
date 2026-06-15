# MCP-Server

Argos bringt einen eingebauten [MCP](https://modelcontextprotocol.io)-Server
mit, sodass ein externer MCP-Client — insbesondere **Claude Code** — Argos
direkt steuern kann: Projekte und Tasks auflisten, einen Plan als neuen Task
übergeben, die Phasen Concept / Implement / Push ausführen und den
resultierenden Feature-Branch lokal abrufen.

Das verwandelt die übliche Schleife („in Claude Code planen → in die Argos-UI
kopieren → den Browser beobachten → den Branch-Namen kopieren") in einen
einzigen Ablauf innerhalb der Session, ohne deinen Planungs-Client zu verlassen.

Wenn du Argos lieber aus Skripten oder einer CI automatisieren möchtest als aus
einem interaktiven MCP-Client, ist derselbe Workflow auch als schlichte
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

Der Server stellt neun Tools bereit. Ein `task`- oder `project`-Argument
akzeptiert stets entweder die ULID des Datensatzes **oder** seinen exakten Namen.

### Projekte (lesen)

| Tool | Was es tut |
|---|---|
| `project_list` | Listet die konfigurierten Repository-Profile (Projekte), gegen die Argos Tasks ausführen kann, mit ihren Workflow-Defaults und der Anzahl offener Tasks. |
| `project_get` | Gibt ein Projekt zurück (per id oder Name) zusammen mit einer Übersicht seiner Tasks. |

### Tasks (lesen)

| Tool | Was es tut |
|---|---|
| `task_list` | Listet Tasks auf, optional gefiltert nach `project` und nach Workflow-`status`. |
| `task_get` | Gibt das vollständige Detail eines Tasks zurück: Beschreibung, Concept, Implement-Zusammenfassungen, jüngste Phase-Runs, den **Checkout-Block** (`repo_url`, `base_branch`, `feature_branch`) und die PR-URL. |

Der `status`-Filter akzeptiert einen Workflow-Status-String: `draft`,
`concept_running`, `concept_review`, `implement_running`, `implement_paused`,
`implement_completed`, `in_review`, `completed`, `failed`, `aborted`.

### Workflow (schreiben)

| Tool | Was es tut |
|---|---|
| `task_create` | Erstellt einen Task aus einem Plan und startet die Concept-Phase. Der Plan wird sowohl als Task-Beschreibung als auch als Concept-Notizen gespeichert, sodass der Concept-Run ihn berücksichtigt. Der Feature-Branch wird während der Concept-Phase angelegt. Args: `name`, `project`, `plan`, optional `base_branch`. |
| `task_concept` | Führt die Concept-Phase aus (oder erneut). Ist der vorherige Concept-Run pausiert, wird er mit einem frischen Turn-Budget fortgesetzt. Optional `max_turns`. |
| `task_implement` | Führt die Implement-Phase aus (oder erneut). Erfordert einen abgeschlossenen Concept-Run; setzt einen pausierten Run mit einem frischen Turn-Budget fort. Optional `max_turns`. |
| `task_pr` | Führt die Push-Phase aus — pusht den Feature-Branch und öffnet einen Pull Request. Erfordert einen abgeschlossenen Implement-Run. |
| `task_feedback` | Sendet Review-Feedback (`feedback`, Markdown) für einen Task und führt die Respond-Phase aus, die auf das Feedback reagiert. |

Schreib-Tools, die eine Phase starten, kehren sofort zurück: Phasen laufen
asynchron im Worker, daher kommst du voran, indem du `task_get` erneut liest,
nicht indem du auf den Schreib-Aufruf wartest.

## Typischer Ablauf

Ein Plan-zu-PR-Durchlauf aus einer Planungs-Session:

1. `project_list` — wähle das Repository-Profil, in dem du arbeiten möchtest.
2. `task_create` — übergib den Plan. Die Concept-Phase startet automatisch und
   der Feature-Branch wird während dieses Runs angelegt.
3. `task_get` — frage den Workflow-Status ab. Phasen laufen asynchron, daher
   kehren Schreib-Tools sofort zurück; lies erneut, um den Fortschritt zu
   verfolgen.
4. `task_implement`, dann `task_pr` — gehe weiter, sobald die vorherige Phase
   fertig ist (jeweils daran gekoppelt, dass die vorangehende Phase
   abgeschlossen ist).
5. Nach `task_pr` legt `task_get` den Checkout-Block offen, sodass du lokal
   `git checkout <feature_branch>` ausführen und in deiner IDE reviewen kannst.
6. `task_feedback` — sende Review-Feedback, das die Respond-Phase ausführt.
   Wiederhole das, bis die Änderung mergebereit ist.

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
- **Tool-Aufrufe gelingen, aber in der UI passiert nichts** — Phasen laufen
  asynchron. Prüfe, ob der Queue-Worker (`queue`-Service) läuft; lies mit
  `task_get` erneut, um den Status weiterlaufen zu sehen.

## Siehe auch

- [REST-API.md](REST-API.md) — derselbe Workflow als schlichte HTTP-API für
  Skripte und CI (der Nicht-MCP-Automatisierungspfad).
- [OAUTH.md](OAUTH.md) — die OAuth-App-Verdrahtung, die Argos für git-Provider
  nutzt.
- [CONFIGURATION.md](CONFIGURATION.md) — die zugehörigen Umgebungsvariablen
  (`APP_URL`, `PASSPORT_KEYS_PATH`).
