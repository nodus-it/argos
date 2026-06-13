<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Filament\Admin\Pages\ConnectedAccounts;
use App\Filament\Admin\Pages\Onboarding;
use App\Filament\Admin\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
use App\Filament\Admin\Resources\ApiClientResource\Pages\ListApiClients;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\ListProviderCredentials;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\ListProviderOAuthConfigs;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ListWorkerStacks;
use App\Models\User;
use App\Support\DocLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D.3 deep-links: the DocsLinkAction is wired at high-value places and points at
 * the in-app doc viewer. Tested through the embedding pages (per CLAUDE.md), not
 * the action in isolation.
 */
class DocsDeepLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_onboarding_links_to_the_setup_doc(): void
    {
        Livewire::test(Onboarding::class)
            ->assertSuccessful()
            ->assertSee(DocLink::url('setup'), escape: false);
    }

    public function test_oauth_apps_list_links_to_the_oauth_doc(): void
    {
        Livewire::test(ListProviderOAuthConfigs::class)
            ->assertSuccessful()
            ->assertSee(DocLink::url('oauth'), escape: false);
    }

    public function test_worker_source_field_links_to_the_byoi_doc(): void
    {
        // The worker section is only visible once a platform is chosen.
        Livewire::test(CreateRepoProfile::class)
            ->fillForm(['platform' => 'github'])
            ->assertSee(DocLink::url('byoi'), escape: false);
    }

    public function test_section_landings_link_to_their_doc(): void
    {
        $landings = [
            [ListRepoProfiles::class, 'projects'],
            [ListWorkerStacks::class, 'worker-stacks'],
            [ListAgentCredentials::class, 'agents'],
            [ListProviderCredentials::class, 'credentials'],
            [ListApiClients::class, 'rest-api'],
        ];

        foreach ($landings as [$page, $slug]) {
            Livewire::test($page)
                ->assertSuccessful()
                ->assertSee(DocLink::url($slug), escape: false);
        }
    }

    public function test_connected_accounts_links_to_in_app_docs_not_github(): void
    {
        Livewire::test(ConnectedAccounts::class)
            ->assertSuccessful()
            ->assertSee(DocLink::url('github'), escape: false)
            // The previously-external GitHub blob link is gone (internalized).
            ->assertDontSee('github.com/nodus-it/argos/blob', escape: false);
    }
}
