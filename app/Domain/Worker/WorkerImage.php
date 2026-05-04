<?php

declare(strict_types=1);

namespace App\Domain\Worker;

use Illuminate\Contracts\Foundation\Application;

class WorkerImage
{
    /**
     * Standard image options for the active environment, as a [value => label] map.
     * The fallback chain is: argos.worker_images.<env> → argos.worker_images.local → [].
     *
     * @return array<string, string>
     */
    public static function standardOptions(): array
    {
        /** @var Application $app */
        $app = app();
        $env = $app->environment();

        /** @var array<string, array<int, string>> $map */
        $map = config('argos.worker_images', []);
        $images = $map[$env] ?? $map['local'] ?? [];

        $options = [];
        foreach ($images as $image) {
            $options[$image] = $image;
        }

        return $options;
    }

    /**
     * Options for a Filament Select. If $current is a non-empty value that is not
     * in the standard list, it is appended with a "(custom)" suffix so the form
     * does not silently drop a hand-set image tag.
     *
     * @return array<string, string>
     */
    public static function optionsFor(?string $current): array
    {
        $options = self::standardOptions();
        if ($current !== null && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current.' (custom)';
        }

        return $options;
    }
}
