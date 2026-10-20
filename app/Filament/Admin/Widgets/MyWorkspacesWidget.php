<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceSummaryService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

/**
 * "My workspaces" on the dashboard: what is waiting for me, what is
 * working, and the rest by recent activity. Refreshes on workspace signals
 * (see resources/js/workspace/notifications.js) and every 30s as a fallback.
 */
class MyWorkspacesWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.my-workspaces';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && Gate::forUser($user)->allows('viewAny', Workspace::class)
            && $user->workspaces()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return ['rows' => app(WorkspaceSummaryService::class)->overviewFor($user)];
    }
}
