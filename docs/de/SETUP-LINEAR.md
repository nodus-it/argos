# Linear Setup-Anleitung

Diese Anleitung richtet sich an **Argos-Betreiber**, die **Linear** als
Task-/Issue-Provider anbinden möchten: Linear-Issues als Argos-Tasks
importieren, Phasenergebnisse zurück auf das Issue posten, die Implement-Phase
durch eine 👍-Reaktion freigeben und optional das Issue schließen, wenn der Task
fertig ist.

Sie behandelt ausschließlich die Linear-spezifischen Teile. Die gemeinsamen
Mechanismen — wie eine Bindung erstellt wird, wie Webhook- und Poll-Modus
funktionieren, das Approve-Gate, die Status-Synchronisation — sind einmalig in
[SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md) beschrieben und hier nur
zusammengefasst. Lies diese Seite zusammen mit der vorliegenden.

## Inhalt

- [Wahl der Zugangsdaten: OAuth oder API-Key](#wahl-der-zugangsdaten-oauth-oder-api-key)
- [Variante A: OAuth-Anwendung](#variante-a-oauth-anwendung)
- [Variante B: Persönlicher API-Key](#variante-b-persönlicher-api-key)
- [Bindung erstellen](#bindung-erstellen)
- [Webhook-Modus](#webhook-modus)
- [Poll-Modus](#poll-modus)
- [Issue zu Task](#issue-zu-task)
- [Konzept-Rückschrieb und das Approve-Gate](#konzept-rückschrieb-und-das-approve-gate)
- [Status-Synchronisation beim Abschluss](#status-synchronisation-beim-abschluss)
- [Issue-Identifikatoren](#issue-identifikatoren)
- [Fehlerbehebung](#fehlerbehebung)

## Wahl der Zugangsdaten: OAuth oder API-Key

Argos kann sich auf zwei Arten gegenüber Linears GraphQL-API authentifizieren,
und beide lassen sich als **Zugang** (Zugangsdaten) einer Bindung auswählen:

- **OAuth-Konto** (ein Connected Account) — empfohlen für eine
  Mehrbenutzer-Instanz. Das Access-Token wird über Linears OAuth-Flow bezogen
  und als `Bearer`-Token gesendet.
- **Persönlicher API-Key** (ein gespeichertes Access Token / PAT) — ein
  `lin_api_…`-Key, der in Linear erstellt wurde. Argos erkennt das
  `lin_api_`-Präfix und sendet den Key **roh** (ohne `Bearer`-Präfix), wie
  Linear es verlangt.

Beide Arten von Zugangsdaten funktionieren im **Poll-Modus**. Für den
**Webhook-Modus** müssen die Zugangsdaten die Berechtigung haben, einen Webhook
auf dem Ziel-Team zu erstellen (siehe [Webhook-Modus](#webhook-modus)).

## Variante A: OAuth-Anwendung

### 1. Die OAuth-Anwendung in Linear registrieren

1. Gehe in Linear zu **Settings** → **API** → **OAuth applications** →
   **New application** (Deep-Link:
   `https://linear.app/settings/api/applications/new`).
2. Fülle aus:
   - **Name**: z. B. `Argos`
   - **Callback URL**: `${APP_URL}/auth/linear/callback`
     (ersetze `${APP_URL}` durch die URL deiner Argos-Instanz, z. B.
     `https://argos.example.com`)
3. Erstelle die App und kopiere dann die **Client ID** und das
   **Client Secret**.

### 2. Umgebungsvariablen konfigurieren

Füge zur `.env` des Argos-Managers hinzu:

```env
LINEAR_CLIENT_ID=<your-client-id>
LINEAR_CLIENT_SECRET=<your-client-secret>
```

Der Callback-Pfad ist fest (`config('services.linear.redirect')` ist
standardmäßig `/auth/linear/callback`); es gibt keine separate URL-Variable.
Starte den Manager neu, damit er die neuen Variablen übernimmt.

### 3. Das Konto verbinden

1. Gehe im Admin-Panel zu **Connected Accounts** (oder durchlaufe den
   Onboarding-Flow) und starte die Linear-Verbindung.
2. Argos leitet dich zu `https://linear.app/oauth/authorize` weiter, um die
   Anwendung zu autorisieren; bestätige die angeforderten Scopes.
3. Du wirst zurück zu Argos geleitet und das Linear-Konto wird als verbunden
   angezeigt.

Der OAuth-Flow fordert diese Scopes an:

```
read write issues:create comments:create admin
```

Der `admin`-Scope ist enthalten, weil die **Webhook-Verwaltung** (Erstellen und
Löschen eines Webhooks auf einem Team) in Linear Admin-Berechtigungen
benötigt. Wenn du ausschließlich den Poll-Modus nutzt, werden nur die
read/write/issues/comments-Scopes beansprucht, aber der Flow fordert immer
`admin` an, damit der Webhook-Modus ohne erneute Verbindung funktioniert.

## Variante B: Persönlicher API-Key

Nutze diese Variante, wenn du keine OAuth-App registrieren möchtest, oder für
eine Einzelbetreiber-Instanz.

1. Gehe in Linear zu **Settings** → **API** → **Personal API keys**
   (`https://linear.app/settings/api`) und erstelle einen Key. Er beginnt mit
   `lin_api_`.
2. Öffne im Argos-Admin-Panel **Access Tokens** (Provider Credentials) →
   erstelle Zugangsdaten mit **Provider** = `Linear` und füge den Key ein. Die
   Inline-Hilfe nennt als vorgeschlagenen Scope `read, write`.

Ein persönlicher API-Key erbt die Berechtigungen des Nutzers, der ihn erstellt
hat. Kommentare posten und Issues lesen benötigt read/write; **einen Webhook
erstellen** erfordert, dass dieser Nutzer Admin auf dem Ziel-Team ist. Ist der
Besitzer des Keys kein Team-Admin, schlägt die Webhook-Registrierung während
des Setups fehl — nutze stattdessen OAuth (mit dem `admin`-Scope) oder den
Poll-Modus.

## Bindung erstellen

Bindungen werden auf dem **Task-Provider**-Tab eines Projekts erstellt. Die
vollständige, provider-unabhängige Vorgehensweise findet sich in
[SETUP-TASK-PROVIDERS.md#creating-a-binding](SETUP-TASK-PROVIDERS.md#creating-a-binding).
Die Linear-spezifischen Feldwerte:

| Feld (deutsches Label) | Wert für Linear |
|---|---|
| **Provider** | `Linear` |
| **Modus** (Mode) | `Webhook (Push)`, `Polling` oder `Deaktiviert` |
| **Zugang** (Credential) | Das Linear-OAuth-Konto **oder** der Linear-API-Key (PAT) |
| **Projekt / Team** | Ein Linear-**Team**, ausgewählt aus einer Liste, die anhand der Zugangsdaten geladen wird (der Team-Key + Name, z. B. `ENG — Engineering`). Gespeichert wird der Team-Key, kein `owner/repo`-Pfad |
| **Labels-Filter** | Optional: nur Issues importieren, die mindestens eines dieser Labels tragen |
| **Issue schließen bei Task-Abschluss** | Optional: das Quell-Issue schließen, wenn der Argos-Task abgeschlossen ist ([Status-Synchronisation](#status-synchronisation-beim-abschluss)) |

Die **Projekt / Team**-Liste wird gefüllt, indem Linears `teams`-API mit den
gewählten Zugangsdaten abgefragt wird — wähle also zuerst **Provider** und
**Zugang**. Denselben Team-Key findest du in Linear unter **Settings** →
**Teams** (das kurze Großbuchstaben-Kürzel neben dem Team-Namen, z. B. `ENG`).

Führe nach dem Speichern die Aktion **Einrichten** (Setup) in der
Bindungs-Zeile aus, um sie zu aktivieren. Im Poll-Modus markiert Einrichten die
Bindung lediglich als Active. Im Webhook-Modus registriert Einrichten den
Webhook (siehe unten). Bei einem Fehler wird dieser in der Spalte
**Letzter Fehler** (Last error) angezeigt und die Bindung bleibt Pending.

## Webhook-Modus

Im Webhook-Modus registriert die Aktion **Einrichten** automatisch einen
Webhook auf dem ausgewählten Linear-**Team** — es gibt keinen manuellen Schritt
in der Linear-UI. Der Webhook ist auf das Team hinter der gewählten
**Projekt / Team**-Referenz beschränkt und abonniert ausschließlich den
Ressourcentyp `Issue`; Kommentar- und Projekt-Events werden ignoriert.

Der Inbound-Endpunkt (für alle Provider gemeinsam) ist:

```
POST  ${APP_URL}/webhooks/issues/linear/<binding-id>
```

`APP_URL` muss öffentlich erreichbar sein, damit Linear ausliefern kann. Argos
erzeugt während des Setups ein Webhook-Secret und registriert es mit dem
Webhook. Linear signiert jede Auslieferung mit **HMAC-SHA256** über den rohen
Request-Body und sendet sie als **rohen Hex-Digest** im `Linear-Signature`-Header
(ohne `sha256=`-Präfix). Der Controller verifiziert sie mit `hash_equals`; eine
Abweichung oder ein fehlendes Secret führt zu `401`.

Auslieferungen werden 24 Stunden lang anhand des `Linear-Delivery`-Headers
dedupliziert, sodass ein Linear-Retry keinen doppelten Task erzeugt.

**Voraussetzungen für den Webhook-Modus:**

- `APP_URL` von Linears Servern aus öffentlich erreichbar.
- Zugangsdaten, die einen Webhook auf dem Team erstellen dürfen: ein
  OAuth-Konto mit dem `admin`-Scope oder ein API-Key, dessen Besitzer Team-Admin
  ist.

Wenn eine Org-Richtlinie die API-gesteuerte Webhook-Registrierung blockiert,
gibt es für Linear keinen unterstützten manuellen Fallback (das Secret wird
serverseitig erzeugt und nie angezeigt); nutze stattdessen den Poll-Modus.

## Poll-Modus

Im Poll-Modus ruft Argos die Issues des Teams nach einem Zeitplan ab, statt
Pushes zu empfangen. In Linear wird kein Webhook registriert und `APP_URL` muss
nicht öffentlich erreichbar sein. **Einrichten** markiert die Bindung sofort als
Active.

Der Poll läuft über den geplanten Befehl `argos:poll-issues`. Das Intervall ist
über `ARGOS_POLL_INTERVAL_MINUTES` konfigurierbar (Standard **5**, begrenzt auf
**1–59**); setze es lokal auf `1` für schnelleres Feedback. Dasselbe Intervall
steuert auch die Approve-Gate-Prüfung (`argos:check-concept-approvals`). Der
Scheduler (`php artisan schedule:work` oder ein System-Cron, der
`schedule:run` ausführt) muss für den Poll-Modus *und* für das Approve-Gate
aktiv sein — siehe
[SETUP-TASK-PROVIDERS.md#poll-mode](SETUP-TASK-PROVIDERS.md#poll-mode).

## Issue zu Task

Wenn ein passendes Linear-Issue zum ersten Mal gesehen wird, erstellt Argos
einen Task im Projekt der Bindung: der Task-Name ist der Issue-Titel und die
Beschreibung ist der Issue-Body. Ist im Projekt Auto-Konzept aktiviert, startet
die Konzept-Phase automatisch. Der Import erfolgt einmalig pro Issue. Siehe
[SETUP-TASK-PROVIDERS.md#issue-to-task](SETUP-TASK-PROVIDERS.md#issue-to-task)
für das vollständige Verhalten.

## Konzept-Rückschrieb und das Approve-Gate

Nach der Konzept-Phase postet Argos einen Kommentar mit dem Konzept-Text auf
das Linear-Issue. Solange der Task im Concept Review ist, fragt die
Approve-Gate-Prüfung die Reaktionen dieses Kommentars ab. Eine 👍-Reaktion von
einem **autorisierten** Nutzer startet die Implement-Phase.

Für Linear bedeutet „berechtigt zu freizugeben" ein **aktives,
nicht-Gast-Organisationsmitglied**: der reagierende Nutzer muss in Linear
`active = true` und `guest != true` haben. (Linear kennt keine
Per-Repo-Berechtigungen, daher steht diese Org-Mitglied-Prüfung stellvertretend
für den write-/admin-Zugriff, der bei GitHub/GitLab verwendet wird. Admins sind
eine nicht-Gast-Obermenge und qualifizieren sich.)

Reaktionen werden von Linear nie gepusht, daher verlässt sich das Gate selbst
im Webhook-Modus auf den geplanten `argos:check-concept-approvals`-Lauf.
Vollständige Mechanik:
[SETUP-TASK-PROVIDERS.md#concept-write-back-and-the-approve-gate](SETUP-TASK-PROVIDERS.md#concept-write-back-and-the-approve-gate).

## Status-Synchronisation beim Abschluss

Die Status-Synchronisation ist pro Bindung über den Schalter **Issue schließen
bei Task-Abschluss** (`close_on_complete`) optional aktivierbar. Ist sie
aktiviert und der Argos-Task wird abgeschlossen, schließt Argos das
Linear-Issue.

Linear hat kein generisches „closed"-Flag, daher verschiebt Argos das Issue in
den **ersten Workflow-Status vom Typ `completed`** auf dem Team des Issues. Hat
das Team keinen Status vom Typ completed, schlägt der Schließvorgang fehl
(geloggt, Best-Effort — er blockiert nie den Abschluss des Tasks in Argos).
Siehe
[SETUP-TASK-PROVIDERS.md#status-sync-on-completion](SETUP-TASK-PROVIDERS.md#status-sync-on-completion).

## Issue-Identifikatoren

Linear identifiziert Issues per **UUID**. Argos speichert diese UUID als
`external_id` auf dem External Issue Link und verwendet sie für alle
nachfolgenden API-Aufrufe (Kommentar-Rückschrieb, Reaktions-Polling,
Schließen). Das geschieht transparent — keine Konfiguration erforderlich.

## Fehlerbehebung

| Symptom | Wahrscheinliche Ursache |
|---|---|
| „Linear team not found for key: …" während des Setups | Der Team-Key hinter **Projekt / Team** ist falsch oder die Zugangsdaten können dieses Team nicht sehen — wähle es erneut aus der geladenen Liste aus oder prüfe den Key unter Linear Settings → Teams |
| OAuth-Redirect schlägt fehl / „Invalid OAuth state" | Die Callback-URL der Linear-OAuth-App muss exakt mit `${APP_URL}/auth/linear/callback` übereinstimmen; ein nicht passender oder abgelaufener Session-State löst dies ebenfalls aus |
| Setup schlägt im Webhook-Modus fehl | Den Zugangsdaten fehlt die Berechtigung, einen Webhook auf dem Team zu erstellen — nutze ein OAuth-Konto mit dem `admin`-Scope oder einen API-Key-Besitzer, der Team-Admin ist. Der genaue Fehler steht in **Letzter Fehler** |
| Webhook-Auslieferungen abgelehnt (401) | Signatur-/Secret-Abweichung oder kein Secret gespeichert. Führe **Einrichten** erneut aus, um das Secret neu zu erzeugen und zu registrieren |
| Keine Tasks erscheinen im Poll-Modus | Der Scheduler läuft nicht, die Bindung ist nicht **Active** oder der **Labels-Filter** schließt die Issues aus. Prüfe die Spalte **Letzter Poll** |
| 👍 startet Implement nicht | Der Task muss im Concept Review sein, die Reaktion muss 👍 sein, der reagierende Nutzer muss ein aktives nicht-Gast-Linear-Mitglied sein und der Scheduler muss die Approval-Prüfung ausführen |
| `400` / `401` bei API-Aufrufen | Ein `lin_api_…`-Key, der mit einem `Bearer`-Präfix gesendet wird, wird von Linear abgelehnt — Argos behandelt dies automatisch, aber auch ein widerrufenes/abgelaufenes Token schlägt fehl. Verbinde das OAuth-Konto neu oder erstelle den API-Key neu |
