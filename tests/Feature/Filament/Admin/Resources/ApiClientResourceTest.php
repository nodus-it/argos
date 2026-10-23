<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\App\Clusters\System\Resources\ApiClientResource;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\CreateApiClient;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\EditApiClient;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\ListApiClients;
use App\Filament\App\Resources\WorkspaceResource\Pages\EditWorkspaceSettings;
use App\Filament\App\Resources\WorkspaceResource\RelationManagers\ApiTokensRelationManager;
use App\Models\ApiClient;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiClientResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_list_renders(): void
    {
        ApiClient::factory()->count(2)->create();

        Livewire::test(ListApiClients::class)->assertSuccessful();
    }

    public function test_create_persists_client(): void
    {
        Livewire::test(CreateApiClient::class)
            ->fillForm(['name' => 'CI'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(ApiClient::class, ['name' => 'CI']);
    }

    public function test_edit_page_wires_the_tokens_relation_manager(): void
    {
        $client = ApiClient::factory()->create();

        $this->assertContains(
            ApiTokensRelationManager::class,
            ApiClientResource::getRelations(),
        );

        Livewire::test(EditApiClient::class, ['record' => $client->getKey()])
            ->assertSuccessful()
            ->assertSeeLivewire(ApiTokensRelationManager::class);
    }

    public function test_workspace_also_wires_the_tokens_relation_manager(): void
    {
        $workspace = Workspace::factory()->create();

        // The workspace settings embed it in the Integrations tab.
        Livewire::test(EditWorkspaceSettings::class, ['record' => $workspace->getKey()])
            ->assertSuccessful()
            ->assertSeeLivewire(ApiTokensRelationManager::class);
    }

    public function test_mints_and_revokes_a_token_via_the_relation_manager(): void
    {
        $client = ApiClient::factory()->create();

        Livewire::test(ApiTokensRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => EditApiClient::class,
        ])
            ->callAction(TestAction::make('generate')->table(), [
                'name' => 'ci-token',
                'abilities' => ['tasks:write'],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(1, $client->tokens()->count());
        $token = $client->tokens()->first();
        $this->assertSame('ci-token', $token->name);
        $this->assertContains('tasks:write', $token->abilities);

        Livewire::test(ApiTokensRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => EditApiClient::class,
        ])
            ->callTableAction('delete', $token);

        $this->assertSame(0, $client->tokens()->count());
    }
}
