<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Thread
 */
class ThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
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
            'url' => $this->workspace->url($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
