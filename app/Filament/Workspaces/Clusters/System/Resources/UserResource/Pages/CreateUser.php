<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters\System\Resources\UserResource\Pages;

use App\Filament\Workspaces\Clusters\System\Resources\UserResource;
use App\Filament\Support\Pages\CreateRecord;
use App\Services\EntityService;
use App\Services\User\UserService;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function service(): EntityService
    {
        return app(UserService::class);
    }
}
