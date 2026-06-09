<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages;

use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Resources\ProviderOAuthConfigResource;
use App\Models\ProviderOAuthConfig;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProviderOAuthConfig extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = ProviderOAuthConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function argosHeadingAttribute(): string
    {
        return 'provider';
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        /** @var ProviderOAuthConfig $record */
        $record = $this->getRecord();
        $instance = $record->instance_url;

        return [
            'icon' => 'heroicon-o-globe-alt',
            'label' => ($instance !== null && $instance !== '')
                ? $instance
                : (string) __('credentials.oauth.public_instance'),
        ];
    }
}
