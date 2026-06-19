<?php

declare(strict_types=1);

namespace App\Services\Docs;

/**
 * Tracks which manifest docs have an up-to-date translation. English is the
 * single source of truth; each translated locale under `docs/<locale>/` carries
 * a `.translations.json` recording the sha256 of the English source it was
 * translated from. When the English source changes, the recorded hash no longer
 * matches and the translation is "stale" — it must be re-translated FROM the
 * English (never the reverse) and re-stamped.
 *
 * Used by the `argos:docs:stamp-translations` command (writes the hashes after a
 * translation pass) and by DocTranslationFreshnessTest (fails CI on drift).
 */
class DocTranslations
{
    public function __construct(private readonly DocManifest $manifest) {}

    /** @return list<string> the locales expected to carry a full translation */
    public function locales(): array
    {
        /** @var list<string> $locales */
        $locales = config('docs.translations', []);

        return $locales;
    }

    /** The sha256 of the English source of a doc file. */
    public function sourceHash(string $file): string
    {
        return hash_file('sha256', $this->manifest->absolutePath($file)) ?: '';
    }

    /** Absolute path to a locale's translated copy of a doc file. */
    public function translationPath(string $locale, string $file): string
    {
        return base_path((string) config('docs.path', 'docs').'/'.$locale.'/'.$file);
    }

    public function hasTranslation(string $locale, string $file): bool
    {
        return is_file($this->translationPath($locale, $file));
    }

    /** Path to a locale's recorded-hash state file. */
    public function statePath(string $locale): string
    {
        return base_path((string) config('docs.path', 'docs').'/'.$locale.'/.translations.json');
    }

    /**
     * The recorded English-source hashes for a locale (file => sha256), or an
     * empty map when no state file exists yet.
     *
     * @return array<string, string>
     */
    public function recordedHashes(string $locale): array
    {
        $path = $this->statePath($locale);
        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true) ?: [];

        return $decoded;
    }

    /**
     * Whether a locale's translation of a file is present and current.
     */
    public function isFresh(string $locale, string $file): bool
    {
        if (! $this->hasTranslation($locale, $file)) {
            return false;
        }

        return ($this->recordedHashes($locale)[$file] ?? null) === $this->sourceHash($file);
    }

    /**
     * Re-record the English-source hashes for a locale across every manifest
     * page, and return the JSON written. Call after a translation pass.
     */
    public function stamp(string $locale): string
    {
        $hashes = [];
        foreach ($this->manifest->pages() as $page) {
            $hashes[$page['file']] = $this->sourceHash($page['file']);
        }
        ksort($hashes);

        $json = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        file_put_contents($this->statePath($locale), $json);

        return $json;
    }
}
