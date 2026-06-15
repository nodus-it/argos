<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderCredentialResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Concerns\VerifiesCredentialOnSave;
use App\Filament\Admin\Resources\ProviderCredentialResource;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Models\ProviderCredential;
use App\Services\Credentials\CredentialVerifier;
use App\Services\Credentials\ProviderCredentialService;
use App\Services\EntityService;
use Filament\Actions\DeleteAction;

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
            DeleteAction::make(),
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
