<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources\ProviderCredentialResource\Pages;

use App\Filament\App\Clusters\System\Resources\ProviderCredentialResource;
use App\Filament\Concerns\HasArgosEditHeading;
use App\Filament\Concerns\VerifiesCredentialOnSave;
use App\Filament\Support\Actions\ServiceDeleteAction;
use App\Filament\Support\Pages\EditRecord;
use App\Models\ProviderCredential;
use App\Services\Credentials\CredentialVerifier;
use App\Services\Credentials\ProviderCredentialService;
use App\Services\EntityService;

class EditProviderCredential extends EditRecord
{
    use HasArgosEditHeading;
    use VerifiesCredentialOnSave;

    protected static string $resource = ProviderCredentialResource::class;

    protected function service(): EntityService
    {
        return app(ProviderCredentialService::class);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $verification = app(CredentialVerifier::class)->verifyProvider(
            (string) ($data['provider'] ?? ''),
            (string) ($data['token'] ?? ''),
            (string) ($data['instance_url'] ?? ''),
        );

        return $this->applyVerification($verification, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ServiceDeleteAction::make(ProviderCredentialService::class),
        ];
    }

    protected function argosHeadingAttribute(): string
    {
        return 'label';
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        /** @var ProviderCredential $record */
        $record = $this->getRecord();

        return ['icon' => 'heroicon-o-globe-alt', 'label' => $record->provider->value];
    }
}
