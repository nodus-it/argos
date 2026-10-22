<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Pages;

use App\Enums\TaskProviderKind;
use App\Filament\Admin\Resources\WorkspaceResource as AdminWorkspaceResource;
use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\MyWorkOverview;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "My work" — the home of the workspace app (/w): the user's own and
 * invited tasks across all workspaces, waiting ones first. Live through
 * workspace signals and a 30-second poll.
 */
class MyWork extends Page
{
    protected string $view = 'filament.workspaces.my-work';

    protected static ?string $slug = 'my-work';

    /** The app's home: `/`. */
    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    /** First navigation item, so /w redirects here. */
    protected static ?int $navigationSort = -10;

    public static function getNavigationLabel(): string
    {
        return __('workspaces.my_work.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('workspaces.my_work.title');
    }

    /**
     * Ticket chip text: the tracker's key ("ENG-118") or "<provider> #<number>".
     *
     * @param  array<string, mixed>  $ref
     */
    public static function ticketLabel(array $ref): string
    {
        $key = (string) ($ref['key'] ?? '');
        if ($key !== '') {
            return $key;
        }
        $provider = TaskProviderKind::tryFrom((string) ($ref['provider'] ?? ''))?->label() ?? (string) ($ref['provider'] ?? '');

        return trim($provider.' #'.(string) ($ref['number'] ?? ''));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $overview = app(MyWorkOverview::class)->for($user);

        return [
            'user' => $user,
            'groups' => [
                MyWorkOverview::WAITING => $overview[MyWorkOverview::WAITING],
                MyWorkOverview::RUNNING => $overview[MyWorkOverview::RUNNING],
                MyWorkOverview::PR_OPEN => $overview[MyWorkOverview::PR_OPEN],
                MyWorkOverview::READY => $overview[MyWorkOverview::READY],
            ],
            'readyMore' => $overview['ready_more'],
            'workspacesUrl' => WorkspaceResource::getUrl('index', panel: 'workspaces'),
            'createUrl' => $user->can('create', Workspace::class) ? AdminWorkspaceResource::getUrl('create', panel: 'admin') : null,
        ];
    }
}
