<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ArgosSourceFooterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['argos.source_url' => 'https://github.com/nodus-it/argos']);
    }

    public function test_release_version_links_to_the_github_release(): void
    {
        config(['argos.version' => '0.1.0-beta.3']);

        $this->blade('<x-argos-source-footer />')
            ->assertSee('https://github.com/nodus-it/argos/releases/tag/0.1.0-beta.3', false);
    }

    public function test_stage_build_links_to_the_exact_commit(): void
    {
        config(['argos.version' => 'stage-2026-05-31-79ce23e']);

        $this->blade('<x-argos-source-footer />')
            ->assertSee('https://github.com/nodus-it/argos/commit/79ce23e', false)
            ->assertDontSee('/releases/tag/', false);
    }

    public function test_unknown_version_falls_back_to_the_repo_root(): void
    {
        config(['argos.version' => 'dev']);

        $this->blade('<x-argos-source-footer />')
            ->assertDontSee('/releases/tag/', false)
            ->assertDontSee('/commit/', false);
    }
}
