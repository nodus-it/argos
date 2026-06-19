<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\RepoProfile;
use App\Services\EntityService;

/**
 * Operations on a repository profile (a connected project). Plain CRUD via the
 * base today; the onboarding flow's project creation moves here next.
 */
class RepoProfileService extends EntityService
{
    protected function model(): string
    {
        return RepoProfile::class;
    }
}
