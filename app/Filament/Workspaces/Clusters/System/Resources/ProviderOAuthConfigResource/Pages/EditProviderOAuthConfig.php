<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters\System\Resources\ProviderOAuthConfigResource\Pages;

use App\Filament\Concerns\HasArgosEditHeading;
use App\Filament\Workspaces\Clusters\System\Resources\ProviderOAuthConfigResource;
use App\Filament\Support\Pages\EditRecord;
use App\Models\ProviderOAuthConfig;
use App\Services\EntityService;
use App\Services\OAuth\ProviderOAuthConfigService;
use Filament\Actions\DeleteAction;

class EditProviderOAuthConfig extends EditRecord
{
    use HasArgosEditHeading;

    protected static string $resource = ProviderOAuthConfigResource::class;

    protected function service(): EntityService
    {
        return app(ProviderOAuthConfigService::class);
    }

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
