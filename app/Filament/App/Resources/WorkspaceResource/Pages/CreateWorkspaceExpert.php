<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WorkspaceResource\Pages;

use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\Shared\WorkspaceForm;
use App\Filament\Support\Pages\CreateRecord;
use App\Support\DocsLinkAction;
use Filament\Actions\Action;
use Filament\Schemas\Schema;

/**
 * New workspace, expert mode: every setting in the shared workspace form,
 * prefilled with what the guided page had so far.
 */
class CreateWorkspaceExpert extends CreateRecord
{
    use CreatesWorkspace;

    public const DRAFT_KEY = 'workspace-create-draft';

    protected static string $resource = WorkspaceResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();
        $draft = session()->pull(self::DRAFT_KEY);
        if (is_array($draft)) {
            $this->form->fill($draft);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([WorkspaceForm::tabs()]);
    }

    public function getTitle(): string
    {
        return __('workspaces.create.expert_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('guided')
                ->label(__('workspaces.create.back_to_guided'))
                ->link()
                ->url(WorkspaceResource::getUrl('create')),
            DocsLinkAction::make('workspace-settings'),
        ];
    }
}
