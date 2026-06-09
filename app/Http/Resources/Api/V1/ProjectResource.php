<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\RepoProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RepoProfile
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'platform' => $this->platform->value,
            'default_branch' => $this->default_branch,
            'auto_concept' => $this->auto_concept,
            'auto_pr' => $this->auto_pr,
            'open_tasks' => $this->whenCounted('openTasks'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
