<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds URLs into the in-app documentation viewer (feature D.3). Keeps the
 * `/admin/docs/{slug}#{anchor}` route shape in one place so contextual help
 * links from the UI (onboarding, resource headers, form-field hints) stay
 * consistent and survive a route change.
 */
final class DocLink
{
    /** A URL to a documentation page, optionally to a heading anchor on it. */
    public static function url(string $slug, ?string $anchor = null): string
    {
        $url = route('filament.admin.pages.docs', ['slug' => $slug]);

        return $anchor !== null && $anchor !== ''
            ? $url.'#'.$anchor
            : $url;
    }
}
