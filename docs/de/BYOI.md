# Eigenes Worker-Image (BYOI)

Mit BYOI („bring your own image“) läuft ein Workspace auf dem **eigenen
Worker-Basis-Image** des Repositorys, definiert in einer Datei —
`.argos/worker.dockerfile`. Argos baut diese Datei als Stack-Schicht und legt
die Claude-Code-CLI, seine Gate-Helfer und die Workspace-Schicht darüber. Nutze
es, wenn keiner der
[eingebauten Stacks](WORKER-STACKS.md#die-eingebauten-stacks) (PHP 8.5, PHP 8.4,
Node 22) die Toolchain mitbringt, die das Projekt braucht.

Von Hand schreibst du sie selten: Die
[Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) schreibt
`.argos/worker.dockerfile`, wenn kein eingebauter Stack passt, und
**Einrichtung testen** baut und prüft sie. Diese Seite ist die Referenz für die
Datei.

- [Wann du es nutzt](#wann-du-es-nutzt)
- [Die Datei](#die-datei)
- [Anforderungen an das Image](#anforderungen-an-das-image)
- [BYOI auswählen](#byoi-auswahlen)
- [Wie das Image gebaut wird](#wie-das-image-gebaut-wird)
- [Neubauten](#neubauten)
- [Fehlerbehebung](#fehlerbehebung)

## Wann du es nutzt

Greif nur zu BYOI, wenn die eingebauten Stacks nicht passen:

- eine andere **Runtime** (Python, Go, Ruby, eine native Toolchain),
- ein **Systempaket** oder eine PHP-Extension, ohne die Build oder Tests nicht
  auskommen,
- ein **festgelegtes Basis-Image**, zu dem das Projekt passen muss,
- ein Service, für den der Workspace keinen Sidecar hat (z. B. Postgres) und
  der im Container laufen muss.

BYOI brauchst du **nicht** für:

- **private Composer-Registries** oder andere Secrets — Einstellungen →
  Umgebung → *Environment & Secrets* (siehe
  [Ein Repository vorbereiten](PREPARE-PROJECT.md#private-registries-und-secrets)),
- **MySQL/MariaDB oder Redis** — die Backing-Services des Workspaces.

## Die Datei

`.argos/worker.dockerfile` wird über die API des Git-Providers (GitHub,
GitLab, Bitbucket) am **Setup-Ref** des Workspaces gelesen: dem
Default-Branch oder — solange eine getestete Einrichtung noch nicht gemergt
ist — dem Branch der Einrichtungs-Aufgabe. Es ist ein normales Dockerfile, das
**nur die Basis** definiert: kein `ENTRYPOINT`/`CMD`, keine Agent-Installation.

```dockerfile
# .argos/worker.dockerfile — base image for the Argos worker.
# Argos layers the agent CLI, gate helpers and workspace tools on top; provide only the base.
FROM python:3.12-bookworm

# Tools the worker harness requires, plus Node for the agent CLI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git jq curl ca-certificates gnupg sed grep gawk \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Your project's toolchain goes here, e.g.:
# RUN pip install --no-cache-dir poetry

# Argos runs the workspace as this user.
RUN useradd --create-home --shell /bin/bash --uid 1000 agent
```

## Anforderungen an das Image

Direkt nach dem Build macht Argos einen Smoke-Test des Images mit
`command -v` für jedes nötige Tool. Fehlt eines, wird der Build als
**Fehlgeschlagen** markiert und das Image **enttaggt**, sodass ein kaputtes
Image nie wiederverwendet wird.

- Im `PATH`: `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl` und die
  CLI des Agenten, `claude` — Argos installiert sie, aber deine Basis **muss
  dafür Node.js / npm bereitstellen**.
- Ein Nicht-Root-Benutzer **`agent` mit UID `1000`**.
- **Kein `ENTRYPOINT`/`CMD`**, **keine Agent-Installation**.
- **Kein `COPY` aus dem Repository** — der Build-Context ist nicht dein
  Checkout. Alles kommt über `FROM` und `RUN` ins Image.
- Alles, was dein Projekt braucht, um seine Abhängigkeiten zu installieren und
  seine Tests auszuführen.

## BYOI auswählen

- **Automatisch:** Wenn **Einrichtung testen** der Einrichtungs-Aufgabe mit
  einer `.argos/worker.dockerfile` auf ihrem Branch besteht, stellt der
  Workspace darauf um.
- **Von Hand:** Einstellungen → Umgebung → Worker → **Image-Quelle** →
  *Eigenes Dockerfile im Repo (BYOI)* (gespeichert als `byoi`; Standard ist
  `standard`, *Registrierter Stack*). Die Stack-Auswahl ist dann ausgeblendet.

Committe die Datei auf den Default-Branch (oder teste sie über die
Einrichtungs-Aufgabe), **bevor** du von Hand umstellst — sonst scheitert der
nächste Container-Start (siehe [Fehlerbehebung](#fehlerbehebung)).
Backing-Services funktionieren mit BYOI genauso wie mit einem eingebauten
Stack.

## Wie das Image gebaut wird

Wenn der Workspace startet und sein Image noch nicht existiert:

1. Argos holt `.argos/worker.dockerfile` am Setup-Ref.
2. Die Datei wird als Stack `byoi-<workspace-id>` erfasst und zu einem
   **Stack-Image** gebaut, getaggt mit dem Inhalts-Hash des Dockerfiles.
3. Darüber wird das Worker-Image gebaut: die CLI des Agenten und die
   Gate-Helfer.
4. Der Smoke-Test läuft; bei Erfolg ist das Image bereit, bei einem Fehler wird
   es enttaggt und der Start scheitert.
5. Darüber wird das Workspace-Image (Daemon + Editor) gebaut.

**Einrichtung testen** führt die Schritte 1–4 für den Branch der
Einrichtungs-Aufgabe aus und meldet das Build-Log in der Aufgabe.

## Neubauten

Der Image-Tag enthält den Hash des Dockerfiles, die Agent-Version und einen
Fingerabdruck der Gate-Helfer, daher wird beim nächsten Start ein neues Image
gebaut, sobald

- sich `.argos/worker.dockerfile` am Setup-Ref ändert (committe sie — über den
  Pull Request einer Aufgabe oder die Einrichtungs-Aufgabe),
- sich die festgelegte Version des Agenten ändert oder
- Argos neue Gate-Helfer ausliefert.

**Neu bauen** (Einstellungen → Umgebung → Einrichtung) baut das Image auf
Wunsch erneut; der Workspace nutzt es ab seinem nächsten Start.

## Fehlerbehebung

**„BYOI is enabled for '<workspace>' but '.argos/worker.dockerfile' was not
found on '<branch>'.“**
Die Datei fehlt am Setup-Ref oder ist leer. Committe eine nicht-leere Datei auf
den Default-Branch oder stelle die Image-Quelle zurück auf *Registrierter
Stack*.

**„Worker image validation failed“ mit `MISSING <tool>`.**
Ergänze das fehlende Paket mit `RUN apt-get install …`. Ein fehlendes `claude`
heißt meist, dass **Node.js nicht installiert ist** und die npm-Installation
der CLI deshalb nicht laufen konnte — ergänze Node 22.

**Ein `COPY`-Schritt scheitert oder kopiert unerwartete Dateien.**
Der Build-Context ist nicht dein Checkout. Hol dir, was du brauchst, in einem
`RUN`.

**Berechtigungsfehler / der Workspace verhält sich wie root.**
Lege den Benutzer an: `RUN useradd --create-home --shell /bin/bash --uid 1000 agent`.

**Änderungen wirken nicht.**
Das Image hängt an der Datei am Setup-Ref — eine nicht committete Änderung oder
ein Commit auf einem anderen Branch wird nicht gesehen. Der laufende Container
behält sein Image, bis er neu startet; stoppe den Workspace und starte ihn
wieder.
