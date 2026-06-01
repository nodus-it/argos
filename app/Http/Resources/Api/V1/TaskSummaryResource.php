<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight task representation for list endpoints — omits the heavy
 * concept/implement bodies (mirrors the MCP task_list tool).
 *
 * @mixin Task
 */
class TaskSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'project' => [
                'id' => $this->repoProfile?->id,
                'name' => $this->repoProfile?->name,
            ],
            'workflow_status' => $this->workflow_status->value,
            'current_phase' => $this->current_phase?->value,
            'current_status' => $this->current_status?->value,
            'feature_branch' => $this->feature_branch,
            'pr_url' => $this->pr_url,
            'created_at' => $this->created_at,
        ];
    }
}
