# Task-Provider-Anbindung (Issue-Tracker)

Argos kann Issues aus externen Systemen (GitHub, GitLab) als Tasks importieren und bei Phasenabschluss automatisch einen Kommentar im Issue hinterlassen.

## Voraussetzungen

- Ein konfiguriertes **Connected Account** (OAuth) für GitHub oder GitLab
  (siehe `docs/SETUP-GITHUB.md` bzw. `docs/SETUP-GITLAB.md`)
- Das Projekt (Repo Profile) muss bereits in Argos angelegt sein

## Binding anlegen

1. Im Admin-Panel unter **Repo Profiles** das gewünschte Projekt öffnen
2. Tab **Task Provider Bindings** wählen → **New Binding**
3. Felder ausfüllen:

| Feld | Beschreibung |
|---|---|
| **Kind** | GitHub oder GitLab |
| **Mode** | `Poll` (periodisches Abrufen alle 5 min) oder `Webhook` (Push-Events) |
| **Connected Account** | Das OAuth-Konto mit API-Zugriff |
| **External Project Ref** | Repository im Format `owner/repo` (z. B. `acme/widget`) |
| **Labels** | Optional: nur Issues mit diesen Labels werden importiert |

4. Speichern → Binding ist im Status **Pending**
5. Aktion **Setup** ausführen → Binding wird aktiviert (Status **Active**)

## Webhook-Modus (empfohlen für Echtzeit)

Beim Setup im Webhook-Modus generiert Argos automatisch ein Webhook-Secret und
gibt die Webhook-URL aus. Diese URL muss im externen System eingetragen werden:

**GitHub:** Repository → Settings → Webhooks → Add webhook
- Payload URL: `https://<ARGOS_URL>/webhooks/issues/github/<binding-id>`
- Content type: `application/json`
- Secret: (das generierte Secret)
- Events: **Issues**

**GitLab:** Projekt → Settings → Webhooks
- URL: `https://<ARGOS_URL>/webhooks/issues/gitlab/<binding-id>`
- Secret token: (das generierte Secret)
- Trigger: **Issues events**

## Poll-Modus

Im Poll-Modus fragt Argos alle 5 Minuten aktiv neue Issues ab. Kein Webhook im
externen System erforderlich. `APP_URL` muss dafür nicht öffentlich erreichbar sein.

## Filter

Über das Feld **Labels** lassen sich Issues filtern: nur Issues, die mindestens
eines der angegebenen Labels tragen, werden als Tasks importiert. Ohne Filter
werden alle offenen Issues importiert.

## Umgebungsvariablen

Keine zusätzlichen Variablen erforderlich. `APP_URL` wird als Basis der
Webhook-URL verwendet und muss öffentlich erreichbar sein (nur Webhook-Modus).

```env
# Basis-URL — muss öffentlich erreichbar sein wenn Webhook-Modus genutzt wird
APP_URL=https://argos.example.com
```

## Rückkommentierung

Nach jedem Phasenabschluss postet Argos automatisch einen Kommentar im
verknüpften Issue:

```
**Argos** — Phase **implement** abgeschlossen mit Status: **success**
```

Schlägt die Kommentierung fehl (z. B. wegen abgelaufenem Token), wird der
Fehler geloggt aber der Workflow nicht unterbrochen.
