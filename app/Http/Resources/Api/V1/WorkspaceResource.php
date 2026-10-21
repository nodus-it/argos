<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'repository' => $this->getOwnerRepo(),
            'platform' => $this->platform->value,
            'default_branch' => $this->default_branch,
            'status' => $this->status->value,
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'url' => $this->url(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
