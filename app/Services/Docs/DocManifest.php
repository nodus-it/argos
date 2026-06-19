<?php

declare(strict_types=1);

namespace App\Services\Docs;

/**
 * Reads the curated in-app documentation manifest (config/docs.php) — the
 * single source for the doc sidebar order, the slug→file resolution, and the
 * internal-link rewrite. Nothing outside this manifest is reachable in-app, so
 * contributor/architecture docs stay repo-only by simply being absent.
 */
class DocManifest
{
    /**
     * The sections with their pages, in manifest order.
     *
     * @return list<array{title: string, pages: list<array{slug: string, title: string, file: string}>}>
     */
    public function sections(): array
    {
        /** @var list<array{title: string, pages: list<array{slug: string, title: string, file: string}>}> $sections */
        $sections = config('docs.sections', []);

        return $sections;
    }

    /**
     * Every page flattened, in manifest order.
     *
     * @return list<array{slug: string, title: string, file: string}>
     */
    public function pages(): array
    {
        $pages = [];
        foreach ($this->sections() as $section) {
            foreach ($section['pages'] as $page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * Resolve a page entry by slug, or null when the slug is not in the manifest.
     *
     * @return array{slug: string, title: string, file: string}|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->pages() as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** The slug of the first manifest page — the landing page when none is given. */
    public function defaultSlug(): ?string
    {
        return $this->pages()[0]['slug'] ?? null;
    }

    /** The slug a doc file maps to, or null when the file is not in the manifest. */
    public function slugForFile(string $file): ?string
    {
        foreach ($this->pages() as $page) {
            if ($page['file'] === $file) {
                return $page['slug'];
            }
        }

        return null;
    }

    /** Absolute path to the canonical (English) doc file. */
    public function absolutePath(string $file): string
    {
        return base_path((string) config('docs.path', 'docs').'/'.$file);
    }

    /**
     * Absolute path to the doc file for a locale: the translated copy under
     * `docs/<locale>/` when it exists, otherwise the English source. English is
     * always the reference; a missing translation falls back to it.
     */
    public function localizedPath(string $file, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($locale !== 'en' && $locale !== '') {
            $translated = base_path((string) config('docs.path', 'docs').'/'.$locale.'/'.$file);
            if (is_file($translated)) {
                return $translated;
            }
        }

        return $this->absolutePath($file);
    }
}
