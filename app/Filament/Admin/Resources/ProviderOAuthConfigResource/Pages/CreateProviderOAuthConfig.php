<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages;

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
    }

    protected function getRedirectUrl(): string
    {
        return $this->returnTo === 'onboarding'
            ? route('filament.admin.pages.onboarding')
            : parent::getRedirectUrl();
    }
}
