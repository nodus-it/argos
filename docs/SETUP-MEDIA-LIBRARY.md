# Media Library Setup

Argos uses [spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary) to attach files and images to Eloquent models.

## Configuration

| ENV variable | Default | Purpose |
|---|---|---|
| `MEDIA_DISK` | `public` | Filesystem disk where uploads are stored |

Files are stored under `storage/app/public/` and served via the `/storage` symlink (`php artisan storage:link`).

## Attaching media to a model

1. Add the `HasMedia` interface and `InteractsWithMedia` trait to the model:

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class YourModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk(config('media-library.disk_name'));
    }
}
```

2. Create a migration if `model_id` column types need adjusting — **not required here** because `create_media_table` already uses `ulidMorphs()` for full ULID compatibility.

3. Upload a file:

```php
$model->addMedia($request->file('upload'))
    ->toMediaCollection('attachments');
```

4. Retrieve a URL:

```php
$model->getFirstMediaUrl('attachments');
```

## Adding a new storage disk

Edit `config/filesystems.php` and add a disk entry, then set `MEDIA_DISK=your-disk` in `.env`.

For S3:

```php
's3-media' => [
    'driver' => 's3',
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url'    => env('AWS_URL'),
],
```

Then set `MEDIA_DISK=s3-media`.

## Further reading

- [Media Library docs](https://spatie.be/docs/laravel-medialibrary)
- [Media collections](https://spatie.be/docs/laravel-medialibrary/v11/working-with-media-collections/defining-media-collections)
- [Image conversions](https://spatie.be/docs/laravel-medialibrary/v11/converting-images/defining-conversions) (not configured — add when needed)
