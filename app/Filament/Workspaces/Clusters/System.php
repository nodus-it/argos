<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Support\Facades\Gate;

/**
 * Administration (`/system`), for Argos administrators only: overview
 * (state, worker images, logs), users, access tokens, OAuth apps and API
 * clients. The rail shows it only to administrators.
 */
class System extends Cluster
{
    protected static ?string $slug = 'system';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return Gate::allows('administer-argos');
    }

    public static function getNavigationLabel(): string
    {
        return __('system.title');
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('system.title');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }
}
