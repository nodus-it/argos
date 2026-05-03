<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRepoProfile extends EditRecord
{
    protected static string $resource = RepoProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Derive github_repo / github_branch from the persisted url + default_branch
     * so the OAuth pickers show the current values when the form opens.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $url = $data['url'] ?? null;
        if (is_string($url) && preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#', $url, $m)) {
            $data['github_repo'] = $m[1];
        }

        if (isset($data['default_branch']) && is_string($data['default_branch'])) {
            $data['github_branch'] = $data['default_branch'];
        }

        return $data;
    }

    /**
     * In the OAuth path the visible Select writes to `github_branch`; the real
     * column is `default_branch`. Map it back before save and drop the helper key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return RepoProfileResource::mutateBranchKey($data);
    }
}
