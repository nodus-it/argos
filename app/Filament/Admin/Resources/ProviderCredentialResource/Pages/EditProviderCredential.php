<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderCredentialResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\ProviderCredentialResource;
use App\Models\ProviderCredential;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProviderCredential extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = ProviderCredentialResource::class;

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
