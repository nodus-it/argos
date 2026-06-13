# Media Library Setup

Argos ships with [spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary)
configured so file and image uploads can be attached to Eloquent models. This
page is for operators who need to make uploads **persist** and be **served**
correctly — especially in the Docker deployment, where the default `public`
disk is *not* persisted out of the box.

> Note: as of today no Argos model implements `HasMedia`, so the media library
> is a configured-but-dormant capability rather than an active feature. There is
> nothing to configure unless an add-on or future feature actually stores media.
> The migration and config are present so that, when one does, the storage is
> ready.

## Contents

- [What it is used for](#what-it-is-used-for)
- [Storage disk and where files persist](#storage-disk-and-where-files-persist)
- [Docker deployment: persistence caveat](#docker-deployment-persistence-caveat)
- [Configuration keys](#configuration-keys)
- [Public vs. private visibility](#public-vs-private-visibility)
- [One-time setup](#one-time-setup)
- [Using a different disk](#using-a-different-disk)
- [Further reading](#further-reading)

## What it is used for

The media library attaches files (documents, images) to models via a single
`media` table. The `create_media_table` migration uses `ulidMorphs('model')`,
so it works with Argos's ULID-keyed models without further migration changes.

## Storage disk and where files persist

The disk is selected by `MEDIA_DISK` (default `public`), read into
`config/media-library.php` as `disk_name`. The disks themselves are defined in
`config/filesystems.php`, which intentionally exposes only two:

| Disk | Driver | Root | Visibility |
|---|---|---|---|
| `local` | local | `storage/app/private` | private |
| `public` | local | `storage/app/public` | public |

With the default `public` disk, files land under `storage/app/public/` and are
served from `APP_URL` + `/storage` via the `public/storage` symlink (see
[One-time setup](#one-time-setup)). The `public` disk's `url` is derived from
`APP_URL`, so a correct `APP_URL` is required for upload URLs to resolve.

`config/filesystems.php` is headed "driver choices are fixed, only credentials
are ENV-driven" — adding a disk is a code change, not just an env change (see
[Using a different disk](#using-a-different-disk)).

## Docker deployment: persistence caveat

This is the part operators most often miss. In the Compose stack
(`.tools/docker/docker-compose.yml`):

- The `public` disk resolves to `/app/storage/app/public` **inside the app
  container**. There is **no named volume mounted at `/app/storage`**, so
  anything written there is lost when the container is recreated (image update,
  `docker compose down`, redeploy).
- The `argos-public` volume is **not** the upload store. It holds the built
  front-end assets (`/app/public`): the app entrypoint wipes and re-copies it
  from the image on every boot, and nginx mounts it read-only. Uploads written
  under `storage/app/public` never reach it.
- The app entrypoint does **not** run `storage:link`, and nginx serves
  `/app/public` (assets), not `storage/app/public`.

Consequence: the default `public` disk is **not** suitable for persistent,
served uploads in the Docker deployment as shipped. Before relying on media
uploads in Docker, an operator must either:

1. Add a persistent named volume for the app's `storage/app` (or specifically
   `storage/app/public`) directory in the Compose stack **and** arrange for the
   `public/storage` symlink + nginx to serve it; or
2. Point `MEDIA_DISK` at an off-box disk (e.g. object storage) — see
   [Using a different disk](#using-a-different-disk).

Both touch areas that are otherwise locked down (new Compose volumes; fixed
filesystem drivers), so treat this as a deliberate deployment decision rather
than a drop-in toggle.

## Configuration keys

Keys read from the environment by `config/media-library.php`:

| ENV variable | Default | Purpose |
|---|---|---|
| `MEDIA_DISK` | `public` | Disk (from `config/filesystems.php`) where originals are stored. |
| `MEDIA_CONVERSIONS_DISK` | _null_ | Disk for generated conversions/responsive images. `null` = same disk as the original. |
| `MEDIA_QUEUE` | _empty_ | Queue used for image conversions. Empty = default queue. |
| `QUEUE_CONVERSIONS_BY_DEFAULT` | `true` | Whether conversions run on a queue rather than synchronously. |
| `MEDIA_PREFIX` | _empty_ | Subdirectory prefix prepended to all stored media paths. |

The conversion-queue connection follows `QUEUE_CONNECTION` (Redis in the Docker
stack, `sync` for `php artisan serve`). When conversions are queued, a queue
worker must be running for thumbnails/responsive images to be generated.

Not env-driven but worth knowing:

- `max_file_size` is fixed at 10 MB; larger uploads raise an exception.
- `disallowed_extensions` blocks dangerous extensions (including interior
  segments like the `php` in `shell.php.jpg`); `allowed_extensions` is `null`
  (no allowlist) by default.

See also [Media library (optional)](CONFIGURATION.md#media-library-optional) in
the configuration reference.

## Public vs. private visibility

- The **`public`** disk has `visibility => public`; files are reachable by URL
  via the `/storage` symlink. Use it only for media that may be served openly.
- The **`local`** disk roots at `storage/app/private` and is not web-served. For
  media that must stay behind authorization, store it on a private disk and hand
  out time-limited URLs with the media library's temporary-URL helpers rather
  than a public URL.

## One-time setup

The `media` table migration runs with the normal migration flow
(`php artisan migrate`); in the Docker stack the app entrypoint applies
migrations on boot, so no manual step is needed there.

If you use the `public` disk, the storage symlink must exist so files are
web-accessible:

```bash
php artisan storage:link
```

In the Docker deployment the entrypoint does not create this link, so it (and
the storage-persistence volume from the caveat above) must be handled as part of
the deployment.

## Using a different disk

Because the filesystem drivers are fixed in code, switching to off-box storage
is a code change in `config/filesystems.php` plus an env change:

1. Add a new disk entry to the `disks` array in `config/filesystems.php` with
   the appropriate driver and credentials (driver credentials are ENV-driven).
2. Set `MEDIA_DISK=your-disk` (and optionally `MEDIA_CONVERSIONS_DISK`).

For remote disks, `config/media-library.php` already sends sensible upload
headers (e.g. `CacheControl: max-age=604800`) under its `remote.extra_headers`
key. Refer to Laravel's filesystem documentation for the exact disk
configuration for your storage backend, and do not assume public visibility —
set it explicitly when public access is required.

## Further reading

- [Media Library docs](https://spatie.be/docs/laravel-medialibrary)
- [Defining media collections](https://spatie.be/docs/laravel-medialibrary/v11/working-with-media-collections/defining-media-collections)
- [Image conversions](https://spatie.be/docs/laravel-medialibrary/v11/converting-images/defining-conversions) — not configured in Argos today; add per model when needed.
- [Configuration reference](CONFIGURATION.md#media-library-optional)
