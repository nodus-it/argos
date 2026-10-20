<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\WorkspacesPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    WorkspacesPanelProvider::class,
];
