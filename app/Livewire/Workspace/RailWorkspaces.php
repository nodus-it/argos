<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Models\User;
use App\Services\Workspace\WorkspaceSummaryService;
use App\Support\Initials;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The workspaces of the icon rail: one short abbreviation per workspace the
 * user can open, with a badge counting the tasks waiting for them. Live:
 * refreshes on workspace signals and every 30 seconds.
 */
class RailWorkspaces extends Component
{
    /** Workspace open on the current page, highlighted. */
    public ?string $activeId = null;

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $summary = app(WorkspaceSummaryService::class);
        $waiting = $summary->waitingCountsFor($user);

        return view('livewire.workspace.rail-workspaces', [
            'rows' => $summary->visibleWorkspaces($user)
                ->map(fn ($workspace): array => [
                    'workspace' => $workspace,
                    'short' => Initials::abbreviate($workspace->name),
                    'waiting' => $waiting[$workspace->id] ?? 0,
                ])
                ->all(),
        ]);
    }
}
