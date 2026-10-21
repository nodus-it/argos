<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Task;
use App\Services\Workspace\WorkspaceGateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'workspace_id' => $this->workspace_id,
            'owner' => $this->owner === null ? null : ['id' => $this->owner->id, 'name' => $this->owner->name],
            'origin' => $this->origin->value,
            'status' => $this->state()->value,
            'session' => $this->session_status->value,
            'branch' => $this->branch,
            'base_branch' => $this->base_branch,
            'pull_request' => $this->pr_url === null ? null : ['url' => $this->pr_url, 'number' => $this->pr_number, 'state' => $this->pr_state],
            'gates' => ($gates = app(WorkspaceGateService::class)->latest($this->resource)) === null ? null : ['status' => $gates->status->value, 'results' => $gates->gates],
            'ticket' => $this->external_ref === null ? null : ['key' => $this->external_ref['key'] ?? null, 'number' => $this->external_ref['number'], 'title' => $this->external_ref['title'], 'url' => $this->external_ref['url']],
            'model' => $this->model,
            'permission_mode' => $this->permission_mode->value,
            'cost_usd' => (float) $this->cost_usd,
            'auto' => $this->auto_state === null ? null : [
                'state' => $this->auto_state->value,
                'message' => $this->auto_message,
                'plan' => $this->auto_config['plan'] ?? null,
                'ship' => $this->auto_config['ship'] ?? null,
                'fix_rounds' => (int) ($this->auto_counters['fix_rounds'] ?? 0),
                'max_fix_rounds' => (int) ($this->auto_config['max_fix_rounds'] ?? 0),
                'turns' => (int) ($this->auto_counters['turns'] ?? 0),
            ],
            'url' => $this->url(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
