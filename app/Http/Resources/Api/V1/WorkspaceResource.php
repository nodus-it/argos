<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceGateService;
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
        $gates = app(WorkspaceGateService::class)->latest($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'project' => ['id' => $this->repoProfile->id, 'name' => $this->repoProfile->name],
            'status' => $this->status->value,
            'origin' => $this->origin->value,
            'branch' => $this->branch,
            'base_branch' => $this->base_branch,
            'pull_request' => $this->pr_url === null ? null : ['url' => $this->pr_url, 'number' => $this->pr_number, 'state' => $this->pr_state],
            'gates' => $gates === null ? null : ['status' => $gates->status->value, 'results' => $gates->gates],
            'issue' => $this->external_issue === null ? null : ['number' => $this->external_issue['number'], 'url' => $this->external_issue['url']],
            'threads' => ThreadResource::collection($this->whenLoaded('threads')),
            'url' => $this->url(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
