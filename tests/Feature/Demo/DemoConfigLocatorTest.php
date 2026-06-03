<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\RepoProfile;
use App\Services\Demo\DemoConfigLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoConfigLocatorTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): RepoProfile
    {
        return RepoProfile::factory()->create([
            'platform' => 'github',
            'url' => 'https://github.com/acme/widget',
            'token' => 'ghp-test',
            'default_branch' => 'main',
        ]);
    }

    public function test_detects_both_demo_files(): void
    {
        Http::fake([
            'api.github.com/repos/acme/widget/contents/*' => Http::response([
                'content' => base64_encode('services: {}'),
                'encoding' => 'base64',
            ]),
        ]);

        $this->assertTrue(app(DemoConfigLocator::class)->hasConfig($this->profile()));
    }

    public function test_missing_compose_file_means_no_config(): void
    {
        Http::fake([
            'api.github.com/repos/acme/widget/contents/.argos/demo.yml*' => Http::response([
                'content' => base64_encode('entry: {}'),
                'encoding' => 'base64',
            ]),
            // demo.compose.yml (and anything else) → 404
            'api.github.com/repos/*' => Http::response('', 404),
        ]);

        $this->assertFalse(app(DemoConfigLocator::class)->hasConfig($this->profile()));
    }
}
