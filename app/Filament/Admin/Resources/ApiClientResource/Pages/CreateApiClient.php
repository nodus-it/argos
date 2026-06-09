<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiClientResource\Pages;

use App\Filament\Admin\Resources\ApiClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApiClient extends CreateRecord
{
    protected static string $resource = ApiClientResource::class;
}
