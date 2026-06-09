<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\IntegrationProvider;
use App\Enums\ProviderCredentialStatus;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\CreateProviderCredential;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\EditProviderCredential;
use App\Filament\Admin\Resources\ProviderCredentialResource\Pages\ListProviderCredentials;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Credentials\CredentialVerification;
use App\Services\Credentials\CredentialVerifier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class ProviderCredentialResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * Stub the on-save verification (the live API probe) with a fixed result so
     * these UI tests stay transport-agnostic and offline.
     */
    private function fakeVerifier(CredentialVerification $result): void
    {
        $verifier = Mockery::mock(CredentialVerifier::class);
        $verifier->shouldReceive('verifyProvider')->andReturn($result);
        $this->app->instance(CredentialVerifier::class, $verifier);
    }

    public function test_list_page_renders(): void
    {
        ProviderCredential::factory()->count(2)->create();

        Livewire::test(ListProviderCredentials::class)
            ->assertSuccessful();
    }

    public function test_create_persists_encrypted_pat(): void
    {
        $this->fakeVerifier(CredentialVerification::valid());

        Livewire::test(CreateProviderCredential::class)
            ->fillForm([
                'label' => 'GitHub acme org',
                'provider' => IntegrationProvider::GitHub->value,
                'token' => 'ghp-secret-1234',
                'scopes_hint' => 'repo',
                'status' => ProviderCredentialStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cred = ProviderCredential::query()->where('label', 'GitHub acme org')->first();
        $this->assertNotNull($cred);
        $this->assertSame(IntegrationProvider::GitHub, $cred->provider);
        $this->assertSame('ghp-secret-1234', $cred->token);

        // Token is stored encrypted, never in plaintext.
        $raw = $cred->getRawOriginal('token');
        $this->assertNotSame('ghp-secret-1234', $raw);
    }

    public function test_selecting_provider_shows_prefilled_token_link(): void
    {
        Livewire::test(CreateProviderCredential::class)
            ->fillForm(['provider' => IntegrationProvider::GitHub->value])
            ->assertSee('github.com/settings/tokens/new');
    }

    public function test_create_requires_label_provider_and_token(): void
    {
        Livewire::test(CreateProviderCredential::class)
            ->fillForm([
                'label' => null,
                'provider' => null,
                'token' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'label' => 'required',
                'provider' => 'required',
                'token' => 'required',
            ]);
    }

    public function test_self_hosted_instance_url_persists(): void
    {
        $this->fakeVerifier(CredentialVerification::valid());

        Livewire::test(CreateProviderCredential::class)
            ->fillForm([
                'label' => 'Self-hosted GitLab',
                'provider' => IntegrationProvider::GitLab->value,
                'instance_url' => 'https://gitlab.acme.test',
                'token' => 'glpat-secret',
                'status' => ProviderCredentialStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cred = ProviderCredential::query()->where('label', 'Self-hosted GitLab')->first();
        $this->assertNotNull($cred);
        $this->assertSame('https://gitlab.acme.test', $cred->instance_url);
        $this->assertSame('https://gitlab.acme.test', $cred->getInstanceUrl());
    }

    public function test_test_connection_marks_validated_on_success(): void
    {
        Saloon::fake([
            'api.github.com/user/repos*' => MockResponse::make([['full_name' => 'acme/widget']]),
        ]);

        $cred = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'last_validated_at' => null,
        ]);

        Livewire::test(ListProviderCredentials::class)
            ->callAction(TestAction::make('testConnection')->table($cred))
            ->assertNotified();

        $this->assertNotNull($cred->fresh()->last_validated_at);
    }

    public function test_test_connection_reports_failure(): void
    {
        Saloon::fake([
            'api.github.com/user/repos*' => MockResponse::make('unauthorized', 401),
        ]);

        $cred = ProviderCredential::factory()->create([
            'provider' => IntegrationProvider::GitHub,
            'last_validated_at' => null,
        ]);

        Livewire::test(ListProviderCredentials::class)
            ->callAction(TestAction::make('testConnection')->table($cred))
            ->assertNotified();

        // A failed probe must not stamp the credential as validated.
        $this->assertNull($cred->fresh()->last_validated_at);
    }

    public function test_edit_updates_token(): void
    {
        $this->fakeVerifier(CredentialVerification::valid());

        $cred = ProviderCredential::factory()->create([
            'label' => 'Rotate me',
            'token' => 'old-token',
        ]);

        Livewire::test(EditProviderCredential::class, ['record' => $cred->getKey()])
            ->fillForm(['token' => 'new-token'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new-token', $cred->fresh()->token);
    }

    public function test_create_is_blocked_when_provider_rejects_the_token(): void
    {
        $this->fakeVerifier(CredentialVerification::rejected('remove the Bearer prefix'));

        Livewire::test(CreateProviderCredential::class)
            ->fillForm([
                'label' => 'Bad token',
                'provider' => IntegrationProvider::Linear->value,
                'token' => 'lin_api_bad',
                'status' => ProviderCredentialStatus::Active->value,
            ])
            ->call('create')
            ->assertNotified();

        // Rejected → halted → never persisted.
        $this->assertDatabaseMissing('provider_credentials', ['label' => 'Bad token']);
    }

    public function test_create_stamps_validated_on_success(): void
    {
        $this->fakeVerifier(CredentialVerification::valid());

        Livewire::test(CreateProviderCredential::class)
            ->fillForm([
                'label' => 'Good token',
                'provider' => IntegrationProvider::GitHub->value,
                'token' => 'ghp-ok',
                'status' => ProviderCredentialStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cred = ProviderCredential::query()->where('label', 'Good token')->first();
        $this->assertNotNull($cred);
        $this->assertSame(ProviderCredentialStatus::Active, $cred->status);
        $this->assertNotNull($cred->last_validated_at);
    }

    public function test_create_saves_unverified_when_provider_unreachable(): void
    {
        $this->fakeVerifier(CredentialVerification::unreachable('Could not resolve host'));

        Livewire::test(CreateProviderCredential::class)
            ->fillForm([
                'label' => 'Offline check',
                'provider' => IntegrationProvider::GitHub->value,
                'token' => 'ghp-maybe',
                'status' => ProviderCredentialStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Unreachable → saved, but not marked validated.
        $cred = ProviderCredential::query()->where('label', 'Offline check')->first();
        $this->assertNotNull($cred);
        $this->assertNull($cred->last_validated_at);
    }
}
