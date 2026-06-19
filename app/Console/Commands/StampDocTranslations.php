<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Docs\DocTranslations;
use Illuminate\Console\Command;

/**
 * Records the English-source hashes for each translated locale after a
 * translation pass, so DocTranslationFreshnessTest considers them current.
 *
 * Workflow (English is the source — NEVER translate the other way):
 *   1. Edit the English doc(s) under docs/.
 *   2. Translate the changed pages into docs/<locale>/.
 *   3. Run this command to stamp the new source hashes, then commit.
 */
class StampDocTranslations extends Command
{
    protected $signature = 'argos:docs:stamp-translations {--locale= : Only stamp this locale}';

    protected $description = 'Record the English-source hashes for translated docs (run after a translation pass).';

    public function handle(DocTranslations $translations): int
    {
        $locales = $this->option('locale') !== null
            ? [(string) $this->option('locale')]
            : $translations->locales();

        foreach ($locales as $locale) {
            $translations->stamp($locale);
            $this->info("Stamped translation hashes for '{$locale}'.");
        }

        if ($locales === []) {
            $this->info('No translation locales configured (config docs.translations).');
        }

        return self::SUCCESS;
    }
}
