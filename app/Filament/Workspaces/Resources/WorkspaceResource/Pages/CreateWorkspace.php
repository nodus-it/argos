<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Resources\WorkspaceResource\Pages;

use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $profile = RepoProfile::query()->findOrFail($data['repo_profile_id']);
            $issue = filled($data['issue'] ?? null) ? app(WorkspaceIssueService::class)->load($profile, (string) $data['issue']) : null;

            return app(WorkspaceService::class)->createWorkspace(
                $user,
                $profile,
                (string) $data['name'],
                isset($data['base_branch']) ? (string) $data['base_branch'] : null,
                $issue,
            );
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return WorkspaceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
