<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages;

use App\Filament\Admin\Resources\ProviderOAuthConfigResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProviderOAuthConfig extends CreateRecord
{
    protected static string $resource = ProviderOAuthConfigResource::class;
}
