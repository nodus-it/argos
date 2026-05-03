<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\Pages;

use App\Filament\Admin\Resources\RepoProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepoProfile extends CreateRecord
{
    protected static string $resource = RepoProfileResource::class;

    /**
     * In the OAuth path the visible Select writes to `github_branch`; the real
     * column is `default_branch`. Map it back before create and drop the helper key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RepoProfileResource::mutateBranchKey($data);
    }
}
