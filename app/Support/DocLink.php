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
    /**
     * Maps the legacy `config('argos.docs.*')` keys that point at OUR docs to
     * their in-app slug, so existing help links resolve to the in-app viewer
     * instead of GitHub. Keys NOT listed here (provider PAT settings pages,
     * `claude_setup_token`, `contributing`) stay external — they are not our
     * in-app docs.
     *
     * @var array<string, string>
     */
    private const DOC_KEY_SLUGS = [
        'base' => 'overview',
        'setup' => 'setup',
        'configuration' => 'configuration',
        'oauth' => 'oauth',
        'setup_github' => 'github',
        'setup_gitlab' => 'gitlab',
        'setup_bitbucket' => 'bitbucket',
        'setup_linear' => 'linear',
    ];

    /** A URL to a documentation page, optionally to a heading anchor on it. */
    public static function url(string $slug, ?string $anchor = null): string
    {
        $url = route('filament.admin.pages.docs', ['slug' => $slug]);

        return $anchor !== null && $anchor !== ''
            ? $url.'#'.$anchor
            : $url;
    }

    /**
     * The in-app URL for a legacy `argos.docs.*` key that maps to one of our
     * docs, or null when the key is an external link (keep it external).
     */
    public static function forDocKey(string $key): ?string
    {
        $slug = self::DOC_KEY_SLUGS[$key] ?? null;

        return $slug !== null ? self::url($slug) : null;
    }
}
