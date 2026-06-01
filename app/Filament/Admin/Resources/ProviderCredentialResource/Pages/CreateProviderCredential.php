<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderCredentialResource\Pages;

use App\Filament\Admin\Resources\ProviderCredentialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProviderCredential extends CreateRecord
{
    protected static string $resource = ProviderCredentialResource::class;

    /** Set when reached from the onboarding wizard (?return=onboarding). */
    public ?string $returnTo = null;

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
