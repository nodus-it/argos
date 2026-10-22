<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource;
use App\Filament\App\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\User\FirstSteps;
use App\Services\Workspace\MyWorkOverview;
use App\Support\DocLink;
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
    protected string $view = 'filament.app.my-work';

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
            'workspacesUrl' => WorkspaceResource::getUrl('index', panel: 'app'),
            'createUrl' => $user->can('create', Workspace::class) ? WorkspaceResource::getUrl('create', panel: 'app') : null,
            'firstSteps' => app(FirstSteps::class)->for($user),
            'agentsUrl' => AgentCredentialResource::getUrl('index', panel: 'app'),
            'docsUrl' => DocLink::url('tasks'),
        ];
    }
}
