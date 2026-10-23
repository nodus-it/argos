<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Filament\App\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Support\DocsLinkAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListWorkspaces extends ListRecords
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocsLinkAction::make('workspaces'),
            // Only Argos admins create workspaces (repository, stack, secrets).
            Action::make('create')
                ->label(__('workspaces.actions.create'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('create', Workspace::class) ?? false)
                ->url(fn (): string => WorkspaceResource::getUrl('create')),
        ];
    }
}
