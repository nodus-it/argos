<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Filament\App\Clusters\Account\Pages\ConnectedAccounts;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages\ListAgentCredentials;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\ListApiClients;
use App\Filament\App\Clusters\System\Resources\ProviderCredentialResource\Pages\ListProviderCredentials;
use App\Filament\App\Clusters\System\Resources\ProviderOAuthConfigResource\Pages\ListProviderOAuthConfigs;
use App\Filament\App\Pages\Documentation;
use App\Filament\App\Pages\Onboarding;
use App\Filament\App\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\App\Resources\WorkspaceResource\Pages\ListWorkspaces;
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
        $this->actingAs(User::factory()->admin()->create());
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
        Livewire::test(CreateWorkspace::class)
            ->fillForm(['platform' => 'github'])
            ->assertSee(DocLink::url('byoi'), escape: false);
    }

    public function test_section_landings_link_to_their_doc(): void
    {
        $landings = [
            [ListWorkspaces::class, 'workspaces'],
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

    public function test_create_masks_link_to_their_docs(): void
    {
        Livewire::test(CreateWorkspace::class)
            ->assertSuccessful()
            ->assertSee(DocLink::url('workspace-settings'), escape: false);
    }

    public function test_connected_accounts_links_to_in_app_docs_not_github(): void
    {
        Livewire::test(ConnectedAccounts::class)
            ->assertSuccessful()
            ->assertSee(DocLink::url('github'), escape: false)
            // The previously-external GitHub blob link is gone (internalized).
            ->assertDontSee('github.com/nodus-it/argos/blob', escape: false);
    }

    public function test_renamed_pages_keep_their_old_address(): void
    {
        Livewire::test(Documentation::class, ['slug' => 'projects'])
            ->assertRedirect(Documentation::getUrl(['slug' => 'workspace-settings']));
    }
}
