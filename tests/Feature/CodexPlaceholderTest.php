<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CodexPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_repo_profile_edit_with_codex_shows_codex_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $profile = RepoProfile::factory()->create([
            'worker_agent_name' => 'codex',
            'model_concept' => null,
            'model_implement' => null,
        ]);

        $html = Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertSuccessful()
            ->html();

        $this->assertStringContainsString('GPT-5 Codex', $html);
        $this->assertStringNotContainsString('Claude Sonnet', $html);
        $this->assertStringNotContainsString('Claude Opus', $html);
    }

    public function test_create_task_under_codex_profile_shows_codex_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $profile = RepoProfile::factory()->create([
            'worker_agent_name' => 'codex',
            'model_concept' => null,
            'model_implement' => null,
        ]);

        $html = Livewire::test(CreateTask::class)
            ->fillForm(['repo_profile_id' => $profile->getKey()])
            ->html();

        $this->assertStringContainsString('GPT-5 Codex', $html);
        $this->assertStringNotContainsString('Claude Sonnet', $html);
        $this->assertStringNotContainsString('Claude Opus', $html);
    }

    public function test_create_repo_profile_then_pick_codex_shows_codex_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $html = Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'platform' => 'github',
                'auth_method' => 'pat',
                'name' => 'Test',
                'token' => 'gh-token',
                'url' => 'https://github.com/acme/widget',
                'default_branch' => 'main',
                'worker_agent_name' => 'codex',
            ])
            ->html();

        $this->assertStringContainsString('GPT-5 Codex', $html);
        $this->assertStringNotContainsString('Claude Sonnet', $html);
        $this->assertStringNotContainsString('Claude Opus', $html);
    }

    public function test_create_repo_profile_picks_codex_via_set_then_models_show_codex_default(): void
    {
        // Reproduces the screenshot: user opens the Create page, fills basics,
        // then sets Agent = Codex via the live select. The model placeholders
        // must update to the Codex default — not stay on the initial Claude
        // fallback used while no agent had been picked yet.
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(CreateRepoProfile::class)
            ->fillForm([
                'platform' => 'github',
                'auth_method' => 'pat',
                'name' => 'Test',
                'token' => 'gh-token',
                'url' => 'https://github.com/acme/widget',
                'default_branch' => 'main',
            ])
            // Trigger the live afterStateUpdated path the same way the UI does.
            ->set('data.worker_agent_name', 'codex');

        $html = $component->html();

        $this->assertStringContainsString('GPT-5 Codex', $html);
        $this->assertStringNotContainsString('Claude Sonnet', $html);
        $this->assertStringNotContainsString('Claude Opus', $html);
    }

    public function test_create_task_under_codex_profile_with_stale_claude_models_still_shows_codex(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Edge case: profile was on claude_code with explicit model overrides,
        // user switched it to codex but the model_* columns still hold the
        // old Claude values. The TaskResource placeholder must NOT show the
        // stale Claude label.
        $profile = RepoProfile::factory()->create([
            'worker_agent_name' => 'codex',
            'model_concept' => 'claude-opus-4-7',
            'model_implement' => 'claude-sonnet-4-6',
        ]);

        $html = Livewire::test(CreateTask::class)
            ->fillForm(['repo_profile_id' => $profile->getKey()])
            ->html();

        $this->assertStringContainsString('GPT-5 Codex', $html);
        $this->assertStringNotContainsString('Claude Sonnet', $html);
        $this->assertStringNotContainsString('Claude Opus', $html);
    }

    public function test_edit_claude_profile_switched_to_codex_shows_codex_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $profile = RepoProfile::factory()->create([
            'worker_agent_name' => 'claude-code',
            'model_concept' => 'claude-opus-4-7',
            'model_implement' => 'claude-sonnet-4-6',
        ]);

        $html = Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['worker_agent_name' => 'codex'])
            ->html();

        $this->assertStringContainsString('GPT-5 Codex', $html);
    }
}
