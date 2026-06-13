<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Documentation;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Docs\DocManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // A project exists so the onboarding redirect is out of the way for the
        // default-access tests (the pre-onboarding case has its own test).
        RepoProfile::factory()->create();
    }

    public function test_default_route_renders_the_first_manifest_page(): void
    {
        $this->get(route('filament.admin.pages.docs'))
            ->assertSuccessful()
            ->assertSee('Getting Started')    // sidebar section
            ->assertSee('How Argos Works');   // OVERVIEW.md H1 → page title (default landing)
    }

    public function test_a_specific_slug_renders(): void
    {
        $this->get(route('filament.admin.pages.docs', ['slug' => 'configuration']))
            ->assertSuccessful()
            ->assertSee('Configuration Reference');
    }

    /**
     * Smoke per manifest entry: every documented page renders without error.
     * Catches a broken/missing doc file or a Markdown that trips the renderer.
     */
    public function test_every_manifest_page_renders(): void
    {
        foreach (app(DocManifest::class)->pages() as $page) {
            $this->get(route('filament.admin.pages.docs', ['slug' => $page['slug']]))
                ->assertSuccessful();
        }
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->get(route('filament.admin.pages.docs', ['slug' => 'nope']))
            ->assertNotFound();
    }

    public function test_page_wiring_resolves_via_livewire(): void
    {
        Livewire::test(Documentation::class, ['slug' => 'mcp'])
            ->assertSuccessful()
            ->assertSet('docSlug', 'mcp');
    }

    public function test_reachable_before_onboarding(): void
    {
        // No project yet: docs must still load (whitelisted in RedirectToOnboarding)
        // rather than bouncing to the onboarding page (which would be a 302).
        RepoProfile::query()->delete();

        $this->get(route('filament.admin.pages.docs', ['slug' => 'setup']))
            ->assertSuccessful()
            ->assertSee('Setup Guide');
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->get(route('filament.admin.pages.docs'))->assertRedirect();
    }
}
