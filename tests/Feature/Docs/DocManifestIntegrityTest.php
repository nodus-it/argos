<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Services\Docs\DocManifest;
use Tests\TestCase;

/**
 * Guards against dead manifest entries: every doc the in-app viewer offers must
 * exist on disk (docs/ ships in the app image), so no sidebar link 404s.
 */
class DocManifestIntegrityTest extends TestCase
{
    public function test_every_manifest_file_exists_on_disk(): void
    {
        $manifest = app(DocManifest::class);

        $this->assertNotEmpty($manifest->pages(), 'The docs manifest has no pages.');

        foreach ($manifest->pages() as $page) {
            $this->assertFileExists(
                $manifest->absolutePath($page['file']),
                "Manifest page '{$page['slug']}' points at a missing file: {$page['file']}",
            );
        }
    }

    public function test_slugs_are_unique(): void
    {
        $slugs = array_map(static fn (array $p): string => $p['slug'], app(DocManifest::class)->pages());

        $this->assertSame(array_values(array_unique($slugs)), $slugs, 'Duplicate doc slugs in the manifest.');
    }
}
