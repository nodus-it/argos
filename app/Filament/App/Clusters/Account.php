<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters;

use App\Filament\App\Clusters\Account\Pages\Profile;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * "My account" (`/account`): what belongs to the person — profile,
 * notifications and language, their Claude subscriptions (agents) and the
 * accounts they connected (GitHub, GitLab, Bitbucket, Linear). The side
 * navigation lists the pages; `/account` opens the first one.
 */
class Account extends Cluster
{
    protected static ?string $slug = 'account';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /** Profile is the panel's profile page, not a cluster member — listed first by hand. */
    public static function getClusteredComponents(): array
    {
        return [Profile::class, ...parent::getClusteredComponents()];
    }

    public static function getNavigationLabel(): string
    {
        return __('account.title');
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('account.title');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-circle';
    }
}
