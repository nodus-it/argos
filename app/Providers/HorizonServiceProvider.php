<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        // Job payloads carry every workspace's prompts and messages: operators only.
        Gate::define('viewHorizon', fn ($user = null) => $user !== null && Gate::forUser($user)->allows('administer-argos'));
    }
}
