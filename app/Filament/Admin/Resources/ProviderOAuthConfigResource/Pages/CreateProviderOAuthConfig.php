<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages;

use App\Enums\IntegrationProvider;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProviderOAuthConfig extends CreateRecord
{
    protected static string $resource = ProviderOAuthConfigResource::class;

    /** Set when reached from the onboarding wizard (?return=onboarding). */
    public ?string $returnTo = null;

    public function mount(): void
    {
        parent::mount();
        $this->returnTo = request()->query('return') === 'onboarding' ? 'onboarding' : null;
        $this->preselectProvider();
    }

    /**
     * Preselect the provider when linked here with ?provider=… (e.g. from the
     * "create OAuth app" buttons on the Connected Accounts page). The callback
     * URL is computed up front since the field's afterStateUpdated hook does not
     * fire on a programmatic fill.
     */
    private function preselectProvider(): void
    {
        $provider = request()->query('provider');

        if (! is_string($provider) || IntegrationProvider::tryFrom($provider) === null) {
            return;
        }

        $this->form->fill([
            ...$this->data,
            'provider' => $provider,
            'callback_url' => ProviderOAuthConfigResource::callbackUrl($provider),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->returnTo === 'onboarding'
            ? route('filament.admin.pages.onboarding')
            : parent::getRedirectUrl();
    }
}
