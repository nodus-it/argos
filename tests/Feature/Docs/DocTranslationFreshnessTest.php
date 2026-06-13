<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Services\Docs\DocManifest;
use App\Services\Docs\DocTranslations;
use Tests\TestCase;

/**
 * Enforces that every configured translation locale carries an up-to-date copy
 * of every manifest page. English is the source of truth: when an English doc
 * changes, its recorded hash no longer matches and this test fails — the fix is
 * to re-translate that page FROM the English (never the reverse) and re-run
 * `php artisan argos:docs:stamp-translations`.
 */
class DocTranslationFreshnessTest extends TestCase
{
    public function test_every_locale_has_a_current_translation_of_every_page(): void
    {
        $translations = app(DocTranslations::class);
        $pages = app(DocManifest::class)->pages();

        foreach ($translations->locales() as $locale) {
            foreach ($pages as $page) {
                $file = $page['file'];

                $this->assertTrue(
                    $translations->hasTranslation($locale, $file),
                    "Missing {$locale} translation for {$file}. Translate docs/{$file} into "
                    ."docs/{$locale}/{$file}, then run `php artisan argos:docs:stamp-translations`.",
                );

                $this->assertTrue(
                    $translations->isFresh($locale, $file),
                    "The {$locale} translation of {$file} is stale (the English source changed). "
                    ."Re-translate docs/{$file} into docs/{$locale}/{$file} FROM the English, then run "
                    .'`php artisan argos:docs:stamp-translations`.',
                );
            }
        }
    }
}
