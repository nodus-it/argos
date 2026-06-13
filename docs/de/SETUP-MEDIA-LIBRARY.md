# Media-Library-Setup

Argos liefert [spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary)
vorkonfiguriert mit, sodass Datei- und Bild-Uploads an Eloquent-Modelle
angehängt werden können. Diese Seite richtet sich an Operatoren, die dafür
sorgen müssen, dass Uploads **persistent** bleiben und korrekt **ausgeliefert**
werden — insbesondere im Docker-Deployment, wo die Standard-Disk `public` von
Haus aus *nicht* persistiert wird.

> Hinweis: Stand heute implementiert kein Argos-Modell `HasMedia`, die
> Media-Library ist also eine konfigurierte, aber ruhende Fähigkeit und kein
> aktives Feature. Es gibt nichts zu konfigurieren, solange nicht ein Add-on
> oder ein zukünftiges Feature tatsächlich Medien speichert. Die Migration und
> die Konfiguration sind vorhanden, damit der Speicher bereitsteht, sobald das
> der Fall ist.

## Inhalt

- [Wofür sie verwendet wird](#what-it-is-used-for)
- [Storage-Disk und wo Dateien persistiert werden](#storage-disk-and-where-files-persist)
- [Docker-Deployment: Persistenz-Vorbehalt](#docker-deployment-persistence-caveat)
- [Konfigurationsschlüssel](#configuration-keys)
- [Öffentliche vs. private Sichtbarkeit](#public-vs-private-visibility)
- [Einmalige Einrichtung](#one-time-setup)
- [Eine andere Disk verwenden](#using-a-different-disk)
- [Weiterführende Lektüre](#further-reading)

## Wofür sie verwendet wird

Die Media-Library hängt Dateien (Dokumente, Bilder) über eine einzelne
`media`-Tabelle an Modelle an. Die Migration `create_media_table` verwendet
`ulidMorphs('model')`, sodass sie ohne weitere Migrationsänderungen mit den
ULID-basierten Modellen von Argos funktioniert.

## Storage-Disk und wo Dateien persistiert werden

Die Disk wird über `MEDIA_DISK` ausgewählt (Standard `public`) und in
`config/media-library.php` als `disk_name` eingelesen. Die Disks selbst sind in
`config/filesystems.php` definiert, die absichtlich nur zwei davon bereitstellt:

| Disk | Driver | Root | Sichtbarkeit |
|---|---|---|---|
| `local` | local | `storage/app/private` | privat |
| `public` | local | `storage/app/public` | öffentlich |

Mit der Standard-Disk `public` landen Dateien unter `storage/app/public/` und
werden über den `public/storage`-Symlink von `${APP_URL}/storage` ausgeliefert
(siehe [Einmalige Einrichtung](#one-time-setup)). Die `url` der `public`-Disk
wird aus `APP_URL` abgeleitet, daher ist ein korrektes `APP_URL` erforderlich,
damit sich Upload-URLs auflösen lassen.

`config/filesystems.php` trägt die Überschrift „driver choices are fixed, only
credentials are ENV-driven" — eine Disk hinzuzufügen ist eine Code-Änderung,
nicht bloß eine ENV-Änderung (siehe
[Eine andere Disk verwenden](#using-a-different-disk)).

## Docker-Deployment: Persistenz-Vorbehalt

Das ist der Teil, den Operatoren am häufigsten übersehen. Im Compose-Stack
(`.tools/docker/docker-compose.yml`):

- Die `public`-Disk löst sich **innerhalb des App-Containers** zu
  `/app/storage/app/public` auf. Es ist **kein Named Volume an `/app/storage`
  gemountet**, sodass alles, was dorthin geschrieben wird, verloren geht, wenn
  der Container neu erstellt wird (Image-Update, `docker compose down`,
  Redeploy).
- Das Volume `argos-public` ist **nicht** der Upload-Speicher. Es enthält die
  gebauten Frontend-Assets (`/app/public`): Der App-Entrypoint löscht es bei
  jedem Boot und kopiert es aus dem Image neu, und nginx mountet es
  schreibgeschützt. Uploads, die unter `storage/app/public` geschrieben werden,
  erreichen es nie.
- Der App-Entrypoint führt **nicht** `storage:link` aus, und nginx liefert
  `/app/public` (Assets) aus, nicht `storage/app/public`.

Konsequenz: Die Standard-Disk `public` ist im Docker-Deployment im
Auslieferungszustand **nicht** für persistente, ausgelieferte Uploads geeignet.
Bevor man sich in Docker auf Media-Uploads verlässt, muss ein Operator
entweder:

1. Ein persistentes Named Volume für das Verzeichnis `storage/app` der App (oder
   konkret `storage/app/public`) im Compose-Stack hinzufügen **und** dafür
   sorgen, dass der `public/storage`-Symlink + nginx es ausliefern; oder
2. `MEDIA_DISK` auf eine externe Disk zeigen lassen (z. B. Object Storage) —
   siehe [Eine andere Disk verwenden](#using-a-different-disk).

Beides berührt Bereiche, die ansonsten abgeriegelt sind (neue Compose-Volumes;
fixe Filesystem-Driver), daher sollte dies als bewusste Deployment-Entscheidung
behandelt werden statt als Drop-in-Schalter.

## Konfigurationsschlüssel

Von `config/media-library.php` aus der Umgebung eingelesene Schlüssel:

| ENV-Variable | Standard | Zweck |
|---|---|---|
| `MEDIA_DISK` | `public` | Disk (aus `config/filesystems.php`), auf der Originale gespeichert werden. |
| `MEDIA_CONVERSIONS_DISK` | _null_ | Disk für generierte Conversions/responsive Bilder. `null` = dieselbe Disk wie das Original. |
| `MEDIA_QUEUE` | _leer_ | Queue, die für Bild-Conversions verwendet wird. Leer = Default-Queue. |
| `QUEUE_CONVERSIONS_BY_DEFAULT` | `true` | Ob Conversions auf einer Queue statt synchron laufen. |
| `MEDIA_PREFIX` | _leer_ | Unterverzeichnis-Präfix, das allen gespeicherten Media-Pfaden vorangestellt wird. |

Die Verbindung der Conversion-Queue folgt `QUEUE_CONNECTION` (Redis im
Docker-Stack, `sync` für `php artisan serve`). Wenn Conversions in die Queue
gestellt werden, muss ein Queue-Worker laufen, damit
Thumbnails/responsive Bilder generiert werden.

Nicht ENV-gesteuert, aber wissenswert:

- `max_file_size` ist fest auf 10 MB gesetzt; größere Uploads lösen eine
  Exception aus.
- `disallowed_extensions` blockiert gefährliche Erweiterungen (einschließlich
  innerer Segmente wie dem `php` in `shell.php.jpg`); `allowed_extensions` ist
  standardmäßig `null` (keine Allowlist).

Siehe auch [Media library (optional)](CONFIGURATION.md#media-library-optional)
in der Konfigurationsreferenz.

## Öffentliche vs. private Sichtbarkeit

- Die **`public`**-Disk hat `visibility => public`; Dateien sind per URL über
  den `/storage`-Symlink erreichbar. Verwende sie nur für Medien, die offen
  ausgeliefert werden dürfen.
- Die **`local`**-Disk wurzelt in `storage/app/private` und wird nicht über das
  Web ausgeliefert. Für Medien, die hinter einer Autorisierung bleiben müssen,
  speichere sie auf einer privaten Disk und gib mit den Temporary-URL-Helfern
  der Media-Library zeitlich begrenzte URLs heraus statt einer öffentlichen URL.

## Einmalige Einrichtung

Die Migration der `media`-Tabelle läuft im normalen Migrationsablauf
(`php artisan migrate`); im Docker-Stack wendet der App-Entrypoint Migrationen
beim Boot an, sodass dort kein manueller Schritt nötig ist.

Wenn du die `public`-Disk verwendest, muss der Storage-Symlink existieren, damit
Dateien über das Web zugänglich sind:

```bash
php artisan storage:link
```

Im Docker-Deployment erstellt der Entrypoint diesen Link nicht, daher muss er
(und das Storage-Persistenz-Volume aus dem obigen Vorbehalt) als Teil des
Deployments behandelt werden.

## Eine andere Disk verwenden

Da die Filesystem-Driver im Code fixiert sind, ist der Wechsel zu externem
Storage eine Code-Änderung in `config/filesystems.php` plus eine ENV-Änderung:

1. Füge dem `disks`-Array in `config/filesystems.php` einen neuen Disk-Eintrag
   mit dem passenden Driver und den passenden Credentials hinzu
   (Driver-Credentials sind ENV-gesteuert).
2. Setze `MEDIA_DISK=your-disk` (und optional `MEDIA_CONVERSIONS_DISK`).

Für Remote-Disks sendet `config/media-library.php` unter seinem Schlüssel
`remote.extra_headers` bereits sinnvolle Upload-Header (z. B.
`CacheControl: max-age=604800`). Die genaue Disk-Konfiguration für dein
Storage-Backend entnimmst du der Filesystem-Dokumentation von Laravel, und gehe
nicht von öffentlicher Sichtbarkeit aus — setze sie explizit, wenn öffentlicher
Zugriff erforderlich ist.

## Weiterführende Lektüre

- [Media Library docs](https://spatie.be/docs/laravel-medialibrary)
- [Defining media collections](https://spatie.be/docs/laravel-medialibrary/v11/working-with-media-collections/defining-media-collections)
- [Image conversions](https://spatie.be/docs/laravel-medialibrary/v11/converting-images/defining-conversions) — heute in Argos nicht konfiguriert; bei Bedarf pro Modell hinzufügen.
- [Configuration reference](CONFIGURATION.md#media-library-optional)
