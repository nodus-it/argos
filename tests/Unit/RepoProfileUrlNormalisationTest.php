<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RepoProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepoProfileUrlNormalisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_trailing_slash_is_stripped_on_save(): void
    {
        $profile = RepoProfile::factory()->create([
            'url' => 'https://github.com/nodus-it/argos/',
        ]);

        $this->assertSame('https://github.com/nodus-it/argos', $profile->fresh()->url);
    }

    public function test_whitespace_is_trimmed(): void
    {
        $profile = RepoProfile::factory()->create([
            'url' => '  https://github.com/nodus-it/argos  ',
        ]);

        $this->assertSame('https://github.com/nodus-it/argos', $profile->fresh()->url);
    }

    public function test_clean_url_is_left_alone(): void
    {
        $profile = RepoProfile::factory()->create([
            'url' => 'https://github.com/nodus-it/argos',
        ]);

        $this->assertSame('https://github.com/nodus-it/argos', $profile->fresh()->url);
    }
}
