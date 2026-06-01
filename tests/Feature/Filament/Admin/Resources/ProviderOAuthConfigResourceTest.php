<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\IntegrationProvider;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\CreateProviderOAuthConfig;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\ListProviderOAuthConfigs;
use App\Models\ProviderOAuthConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProviderOAuthConfigResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders(): void
    {
        ProviderOAuthConfig::factory()->create(['provider' => IntegrationProvider::GitHub]);
        ProviderOAuthConfig::factory()->create(['provider' => IntegrationProvider::GitLab]);

        Livewire::test(ListProviderOAuthConfigs::class)
            ->assertSuccessful();
    }

    public function test_create_persists_with_encrypted_secret(): void
    {
        config(['app.url' => 'https://argos.test']);

        Livewire::test(CreateProviderOAuthConfig::class)
            ->fillForm([
                'provider' => IntegrationProvider::GitHub->value,
                'instance_url' => '',
                'client_id' => 'cid-123',
                'client_secret' => 'sec-456',
                'enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $config = ProviderOAuthConfig::query()->where('client_id', 'cid-123')->first();
        $this->assertNotNull($config);
        $this->assertSame(IntegrationProvider::GitHub, $config->provider);
        $this->assertSame('sec-456', $config->client_secret);
        $this->assertNotSame('sec-456', $config->getRawOriginal('client_secret'));
    }

    public function test_callback_url_reflects_selected_provider(): void
    {
        config(['app.url' => 'https://argos.test']);

        Livewire::test(CreateProviderOAuthConfig::class)
            ->fillForm(['provider' => IntegrationProvider::GitLab->value])
            ->assertFormSet(['callback_url' => 'https://argos.test/auth/gitlab/callback']);
    }

    public function test_selecting_provider_shows_prefilled_oauth_app_link(): void
    {
        config(['app.url' => 'https://argos.test']);

        Livewire::test(CreateProviderOAuthConfig::class)
            ->fillForm(['provider' => IntegrationProvider::GitHub->value])
            ->assertSee('github.com/settings/applications/new');
    }

    public function test_create_requires_provider_client_id_and_secret(): void
    {
        Livewire::test(CreateProviderOAuthConfig::class)
            ->fillForm([
                'provider' => null,
                'client_id' => null,
                'client_secret' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'provider' => 'required',
                'client_id' => 'required',
                'client_secret' => 'required',
            ]);
    }

    public function test_duplicate_provider_instance_is_rejected(): void
    {
        ProviderOAuthConfig::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'instance_url' => '',
        ]);

        Livewire::test(CreateProviderOAuthConfig::class)
            ->fillForm([
                'provider' => IntegrationProvider::GitHub->value,
                'instance_url' => '',
                'client_id' => 'cid-dup',
                'client_secret' => 'sec-dup',
            ])
            ->call('create')
            ->assertHasFormErrors(['provider']);
    }
}
