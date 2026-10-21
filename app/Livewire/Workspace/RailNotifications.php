<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\View\View;

/** The bell of the workspaces icon rail: Filament's notifications with a rail-sized trigger. */
class RailNotifications extends DatabaseNotifications
{
    public function getTrigger(): ?View
    {
        return view('filament.workspaces.rail-bell');
    }
}
