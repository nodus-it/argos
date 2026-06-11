<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderCredentialResource\Pages;

use App\Filament\Admin\Concerns\VerifiesCredentialOnSave;
use App\Filament\Admin\Resources\ProviderCredentialResource;
use App\Filament\Admin\Support\Pages\CreateRecord;
use App\Services\Credentials\CredentialVerifier;
use App\Services\Credentials\ProviderCredentialService;
use App\Services\EntityService;

class CreateProviderCredential extends CreateRecord
{
    use VerifiesCredentialOnSave;

    protected static string $resource = ProviderCredentialResource::class;

    protected function service(): EntityService
    {
        return app(ProviderCredentialService::class);
    }

    /** Set when reached from the onboarding wizard (?return=onboarding). */
    public ?string $returnTo = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $verification = app(CredentialVerifier::class)->verifyProvider(
            (string) ($data['provider'] ?? ''),
            (string) ($data['token'] ?? ''),
            (string) ($data['instance_url'] ?? ''),
        );

        return $this->applyVerification($verification, $data);
    }

    public function mount(): void
    {
        parent::mount();
        $this->returnTo = request()->query('return') === 'onboarding' ? 'onboarding' : null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->returnTo === 'onboarding'
            ? route('filament.admin.pages.onboarding')
            : parent::getRedirectUrl();
    }
}
