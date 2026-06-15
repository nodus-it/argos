# Bring Your Own Image (BYOI)

Mit BYOI kann ein Projekt sein **eigenes Worker-Basis-Image** über eine einzige
Datei im Repository bereitstellen — `.argos/worker.dockerfile`. Argos liest diese
Datei, baut sie in den Worker ein und legt das Agent-CLI sowie die eigenen
Worker-Skripte darüber. Nutze es, wenn die eingebauten Worker-Stacks nicht die
Toolchain mitbringen, die dein Projekt benötigt.

Diese Seite richtet sich an Operatoren / Projektverantwortliche, die ein
Repo-Profil konfigurieren. Für die vollständige Projektvorbereitung
(Ausführungsumgebung *und* Live-Demo) siehe
[PREPARE-PROJECT.md](PREPARE-PROJECT.md#part-a--execution-environment) — diese
Seite ist die fokussierte Referenz für die Worker-Image-Seite.

- [Was BYOI ist](#was-byoi-ist)
- [Wann es einzusetzen ist (vs. ein registrierter Stack)](#wann-es-einzusetzen-ist-vs-ein-registrierter-stack)
- [Die Datei, die du dem Repo hinzufügst](#die-datei-die-du-dem-repo-hinzufügst)
- [Anforderungen, die das Image erfüllen muss](#anforderungen-die-das-image-erfüllen-muss)
- [BYOI im Projektformular auswählen](#byoi-im-projektformular-auswählen)
- [Wie das Image gebaut und geschichtet wird](#wie-das-image-gebaut-und-geschichtet-wird)
- [Wie Neubauten ausgelöst werden](#wie-neubauten-ausgelöst-werden)
- [Fehlerbehebung](#fehlerbehebung)

## Was BYOI ist

Für jede Aufgabe führt Argos den Agenten in einem einzelnen, kurzlebigen
Worker-Container aus. Das Image dieses Containers wird in Schichten gebaut:

1. **Stack-Basis** — das Betriebssystem + die Sprach-Toolchain (PHP, Node,
   Systempakete).
2. **Agent-Schicht** — das Agent-CLI (Claude Code / Codex), installiert über npm.
3. **Worker-Code** — Argos' eigene Phasen-Skripte, Libs, Prompts und Schemas.

BYOI ersetzt **nur die Stack-Basis (Schicht 1)**. Die Agent-Schicht und der
Worker-Code werden weiterhin automatisch von Argos hinzugefügt. Das Repo liefert
das Basis-Rezept als `.argos/worker.dockerfile`; alles andere bleibt unverändert.

Argos liest die Datei über die API des Git-Providers (GitHub, GitLab, Bitbucket)
am Base-Branch der Aufgabe — es muss das Repo dafür **nicht** klonen, um sie zu
erkennen oder zu lesen. Der Dateiinhalt wird dann in ein Stack-Image gebaut und
über seinen Content-Hash getaggt, sodass er dieselbe On-Demand-Build- /
Caching-Pipeline durchläuft wie die eingebauten Stacks.

## Wann es einzusetzen ist (vs. ein registrierter Stack)

Der Standard-Worker-Stack (`php-8.4`) ist PHP 8.4 CLI mit den gängigen
Erweiterungen, Composer, Git, `gh`, `jq` und Node 22. Es existiert außerdem ein
`php-8.3`-Stack. Das sind die **registrierten Stacks**, die du unter
*Image source → Registered stack* auswählst.

Greife nur dann zu BYOI, wenn die registrierten Stacks wirklich nicht passen:

- Eine **exotische Laufzeitumgebung**, die die Stacks nicht mitbringen (Python,
  Go, Ruby, eine native Toolchain).
- Ein **Systempaket**, das dein Build oder deine Testsuite benötigt und das nicht
  im Stack enthalten ist.
- Ein **fixiertes Basis-Image**, an das sich dein Projekt halten muss.

Du brauchst BYOI **nicht** für:

- **Private Composer-Registries** oder andere Secrets — konfiguriere diese im
  Repo-Profil unter *Worker → Environment & secrets*. Siehe
  [PREPARE-PROJECT.md](PREPARE-PROJECT.md#private-registries--secrets-no-contract-file).
- **MySQL/MariaDB oder Redis** während der Tests — schalte sie als Backing
  Services im Worker-Tab um; sie starten zusammen mit dem Worker in einem privaten
  Netzwerk.

Wenn nur ein einziges zusätzliches Paket fehlt, ist ein dreizeiliges
BYOI-Dockerfile oft immer noch günstiger, als das Projekt zu verbiegen — ziehe
aber zuerst Option 2 in Betracht (das Projekt an den Standard anpassen); siehe
[PREPARE-PROJECT.md](PREPARE-PROJECT.md#option-2--adjust-the-project).

## Die Datei, die du dem Repo hinzufügst

Füge **`.argos/worker.dockerfile`** am Base-Branch des Repositorys hinzu (der
Default-Branch des Projekts oder der Branch, von dem eine Aufgabe ausgeht). Argos
liest sie von diesem Ref über die Provider-API.

Es ist ein normales Dockerfile, definiert aber **nur die Basis** — füge keinen
`ENTRYPOINT`/`CMD` hinzu und installiere das Agent-CLI nicht selbst. Argos hängt
diese Schichten an.

```dockerfile
# .argos/worker.dockerfile — base image for the Argos worker.
# Argos layers the agent CLI + worker scripts on top; provide only the base.
FROM python:3.12-bookworm

# Tools the worker harness requires, plus Node for the agent CLI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git jq curl ca-certificates gnupg sed grep gawk \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Your project's toolchain goes here, e.g.:
# RUN pip install --no-cache-dir poetry

# Argos runs the worker as this user.
RUN useradd --create-home --shell /bin/bash --uid 1000 agent
```

## Anforderungen, die das Image erfüllen muss

Direkt nach dem Build unterzieht Argos das fertige Worker-Image einem
Smoke-Test: Es führt `command -v` für jedes erforderliche Tool innerhalb des
Containers aus. Fehlt eines, wird der Build als **Failed** markiert und das Image
**ent-taggt** (sodass ein kaputtes Image beim nächsten Lauf nie stillschweigend
wiederverwendet wird).

Auf dem `PATH` erforderlich:

- `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`
- das CLI-Binary des Agenten (für Claude Code ist das `claude`) — dieses stammt
  aus der Agent-Schicht, die Argos installiert, du installierst es also nicht,
  aber deine Basis **muss Node.js / npm bereitstellen**, damit diese Installation
  gelingt.

Weitere Erwartungen:

- Ein Non-Root-Benutzer **`agent` mit UID `1000`** — der Worker läuft als dieser
  Benutzer.
- **Kein `ENTRYPOINT`/`CMD`** und **keine Agent-Installation** in deiner Datei —
  diese Schichten gehören Argos.
- **Kein `COPY` aus dem Repo.** Der Build-Kontext ist nicht dein
  Repository-Checkout, daher schlägt ein `COPY ./something` fehl oder zieht den
  falschen Baum. Alles, was deine Basis benötigt, muss über `FROM` und `RUN`
  hereinkommen (Paketinstallationen, Downloads).
- Was auch immer **dein** Projekt benötigt, um Abhängigkeiten zu installieren und
  seine Tests auszuführen (z. B. die Sprachlaufzeit, ein DB-Client).

## BYOI im Projektformular auswählen

Öffne im Bearbeitungsformular des Projekts den **Worker**-Tab → den Abschnitt
**Worker (Stack & Agent)**. Das relevante Feld ist **Image source**
(`worker_source`):

| Option (Label) | Bedeutung |
| --- | --- |
| **Registered stack** | Einen eingebauten / registrierten Worker-Stack verwenden (Standard). |
| **Own Dockerfile in the repo (BYOI)** | `.argos/worker.dockerfile` aus dem Repo lesen. |

Wenn du die BYOI-Option wählst:

- Ein Info-Callout erscheint — *"Repo defines its own image"* — der wiederholt,
  dass Argos `.argos/worker.dockerfile` vom Base-Branch liest und den Agenten +
  Worker-Code darüber legt.
- Das Dropdown **Worker stack** (`worker_stack_id`) wird **ausgeblendet** — das
  Repo definiert die Basis, es gibt also nichts auszuwählen.

Der **Worker agent** (`worker_agent_name`) und die Backing-Service-Schalter
gelten mit BYOI genauso wie mit einem registrierten Stack.

Stelle sicher, dass `.argos/worker.dockerfile` auf dem Branch committet ist, den
Argos liest (der Base-Branch), **bevor** du eine Aufgabe startest — andernfalls
schlägt die Aufgabe fehl (siehe Fehlerbehebung).

## Wie das Image gebaut und geschichtet wird

Beim ersten Phasenlauf einer BYOI-Aufgabe:

1. Argos holt `.argos/worker.dockerfile` über die Provider-API aus dem Repo, am
   Base-Branch der Aufgabe (mit Rückfall auf den Default-Branch des Projekts).
2. Der Dateikörper wird als Worker-Stack namens `byoi-<profile-id>` erfasst und in
   ein **Stack-Image** gebaut, getaggt nach dem Content-Hash des Dockerfiles.
3. Argos baut das finale Worker-Image aus dieser Stack-Basis über
   `Dockerfile.compose`, das:
   - das CLI des ausgewählten Agenten obenauf installiert (eigene Cache-Schicht),
     und
   - die Worker-Skripte (`worker/lib`, `worker/phases`, `worker/prompts`,
     `worker/schemas`) und den Entrypoint hineinkopiert.
4. Der Smoke-Test (oben) läuft; bei Erfolg wird das Image als bereit getaggt, bei
   Fehlschlag wird es ent-taggt und die Aufgabe schlägt fehl.

Der Tag des finalen Worker-Images ist content-adressiert: Er fasst den Hash des
BYOI-Dockerfiles, den Agent-Namen + die fixierte Version und einen Fingerprint
von Argos' eigenem Worker-Code zusammen. Identische Eingaben verwenden das
gecachte Image wieder; jede Änderung erzeugt einen neuen Tag und einen frischen
Build.

## Wie Neubauten ausgelöst werden

Es gibt keinen manuellen "Rebuild"-Button — Neubauten werden durch die
content-gehashten Tags gesteuert. Ein neues Worker-Image wird automatisch
gebaut, sobald:

- **Du `.argos/worker.dockerfile`** im Repo änderst (der Stack-Hash ändert sich →
  neuer Tag → Rebuild beim nächsten Phasenlauf). Committe die Änderung auf den
  Branch, gegen den die Aufgabe läuft.
- **Du den Agenten wechselst** (oder sich dessen fixierte Version ändert) — die
  Agent-Schicht ist Teil des Tags.
- **Argos neuen Worker-Code ausliefert** — der Fingerprint des Worker-Codes ist
  Teil des Tags.

Da der Tag auf den **Inhalt** von `.argos/worker.dockerfile` aufsetzt, ist der
Auslöser der Commit, nicht eine UI-Aktion: Pushe die aktualisierte Datei auf den
Base-Branch, und die nächste Aufgabe nimmt sie auf.

## Fehlerbehebung

**"BYOI is enabled for '<project>' but '.argos/worker.dockerfile' was not found
on '<branch>'."**
Die Datei fehlt (oder ist leer) auf dem Branch, den Argos gelesen hat — der
Base-Branch der Aufgabe oder der Default-Branch des Projekts, falls die Aufgabe
keinen gesetzt hat. Committe eine nicht-leere `.argos/worker.dockerfile` auf
diesen Branch und versuche es erneut.

**Build schlägt fehl mit "Worker image validation failed" und listet
`MISSING <tool>`.**
Deinem Basis-Image fehlt eines der erforderlichen Tools (`bash`, `sh`, `jq`,
`git`, `sed`, `grep`, `awk`, `curl` oder das `claude`-Binary des Agenten). Füge
das fehlende Paket über `RUN apt-get install ...` hinzu (oder das Äquivalent für
deine Basis). Ein fehlendes `claude` bedeutet meist, dass **Node.js nicht
installiert ist** in der Basis, sodass die npm-Installation des Agenten nicht
laufen konnte — füge Node 22 hinzu. Das kaputte Image wird automatisch
ent-taggt; das Dockerfile zu reparieren und es erneut zu versuchen, löst einen
sauberen Rebuild aus.

**`COPY`-Schritt schlägt fehl oder kopiert unerwartete Dateien.**
Der Build-Kontext ist nicht dein Repo-Checkout. Entferne jedes `COPY` aus dem
Repo und hole, was du brauchst, stattdessen über das Netzwerk in einem `RUN`.

**Der Worker verhält sich, als liefe er als Root / Berechtigungsfehler.**
Stelle sicher, dass der Benutzer `agent` mit UID `1000` existiert
(`RUN useradd --create-home --shell /bin/bash --uid 1000 agent`). Argos führt den
Worker als diesen Benutzer aus.

**Änderungen an `.argos/worker.dockerfile` scheinen keine Wirkung zu zeigen.**
Bestätige, dass die Änderung auf dem Branch committet ist, gegen den die Aufgabe
tatsächlich läuft (der Base-Branch). Das Image setzt auf den Inhalt der Datei an
diesem Ref auf — eine nicht committete lokale Änderung oder ein Commit auf einem
anderen Branch wird nicht gesehen.
