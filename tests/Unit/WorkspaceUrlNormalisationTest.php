<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceUrlNormalisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_trailing_slash_is_stripped_on_save(): void
    {
        $workspace = Workspace::factory()->create([
            'repo_url' => 'https://github.com/nodus-it/argos/',
        ]);

        $this->assertSame('https://github.com/nodus-it/argos', $workspace->fresh()->repo_url);
    }

    public function test_whitespace_is_trimmed(): void
    {
        $workspace = Workspace::factory()->create([
            'repo_url' => '  https://github.com/nodus-it/argos  ',
        ]);

        $this->assertSame('https://github.com/nodus-it/argos', $workspace->fresh()->repo_url);
    }

    public function test_clean_url_is_left_alone(): void
    {
        $workspace = Workspace::factory()->create([
            'repo_url' => 'https://github.com/nodus-it/argos',
        ]);

        $this->assertSame('https://github.com/nodus-it/argos', $workspace->fresh()->repo_url);
    }
}
