<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use App\Services\Docs\DocManifest;
use Tests\TestCase;

class DocManifestTest extends TestCase
{
    private function manifest(): DocManifest
    {
        return app(DocManifest::class);
    }

    public function test_find_resolves_a_known_slug(): void
    {
        $entry = $this->manifest()->find('setup');

        $this->assertNotNull($entry);
        $this->assertSame('SETUP.md', $entry['file']);
    }

    public function test_find_returns_null_for_unknown_slug(): void
    {
        $this->assertNull($this->manifest()->find('does-not-exist'));
    }

    public function test_default_slug_is_the_first_manifest_page(): void
    {
        $this->assertSame('overview', $this->manifest()->defaultSlug());
    }

    public function test_slug_for_file_maps_back(): void
    {
        $this->assertSame('setup', $this->manifest()->slugForFile('SETUP.md'));
        $this->assertNull($this->manifest()->slugForFile('CONTRIBUTING.md'));
    }
}
