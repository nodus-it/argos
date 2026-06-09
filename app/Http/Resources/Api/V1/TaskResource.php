<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full task detail — mirrors the MCP task_get tool, including the checkout
 * block needed to clone the result locally.
 *
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
            'name' => $this->name,
            'description' => $this->description,
            'workflow_status' => $this->workflow_status->value,
            'current_phase' => $this->current_phase?->value,
            'current_status' => $this->current_status?->value,
            'project' => [
                'id' => $this->repoProfile?->id,
                'name' => $this->repoProfile?->name,
            ],
            'concept_md' => $this->concept_md,
            'concept_notes' => $this->concept_notes,
            'implement_summary_nontechnical' => $this->implement_summary_nontechnical,
            'implement_summary_technical' => $this->implement_summary_technical,
            'checkout' => [
                'repo_url' => $this->repoProfile?->url,
                'base_branch' => $this->base_branch ?? $this->repoProfile?->default_branch,
                'feature_branch' => $this->feature_branch,
            ],
            'pr_url' => $this->pr_url,
            'phase_runs' => $this->whenLoaded('phaseRuns', fn () => $this->phaseRuns->map(fn ($run): array => [
                'phase' => $run->phase,
                'iteration' => $run->iteration,
                'status' => $run->status,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
