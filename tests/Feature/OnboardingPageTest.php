<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\Onboarding;
use App\Models\RepoProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_onboarding_page_renders(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSuccessful()
            ->assertSee('Argos einrichten');
    }

    public function test_onboarding_shows_token_help_when_missing(): void
    {
        config(['argos.claude_token' => null]);

        Livewire::test(Onboarding::class)
            ->assertSee('claude setup-token');
    }

    public function test_onboarding_shows_check_when_token_set(): void
    {
        config(['argos.claude_token' => 'sk-ant-test']);

        Livewire::test(Onboarding::class)
            ->assertSee('CLAUDE_CODE_OAUTH_TOKEN');
    }

    public function test_can_create_project_via_onboarding(): void
    {
        Livewire::test(Onboarding::class)
            ->set('name', 'Mein Projekt')
            ->set('url', 'https://github.com/org/repo')
            ->set('platform', 'github')
            ->set('default_branch', 'main')
            ->call('createProject')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(RepoProfile::class, ['name' => 'Mein Projekt']);
    }

    public function test_onboarding_validates_required_fields(): void
    {
        Livewire::test(Onboarding::class)
            ->set('name', '')
            ->set('url', '')
            ->set('platform', '')
            ->call('createProject')
            ->assertHasErrors(['name', 'url', 'platform']);
    }

    public function test_onboarding_validates_url_format(): void
    {
        Livewire::test(Onboarding::class)
            ->set('name', 'Test')
            ->set('url', 'keine-url')
            ->set('platform', 'github')
            ->set('default_branch', 'main')
            ->call('createProject')
            ->assertHasErrors(['url']);
    }

    public function test_onboarding_hidden_from_nav_when_project_exists(): void
    {
        RepoProfile::factory()->create();

        $this->assertFalse(Onboarding::shouldRegisterNavigation());
    }

    public function test_onboarding_visible_in_nav_when_no_project(): void
    {
        $this->assertTrue(Onboarding::shouldRegisterNavigation());
    }
}
