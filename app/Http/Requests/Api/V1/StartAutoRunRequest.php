<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /workspaces/{workspace}/tasks: an automatic task with its run settings. */
class StartAutoRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth + ability enforced by route middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:20000'],
            'base_branch' => ['nullable', 'string', 'max:255'],
            'issue' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:90'],
            'plan' => ['nullable', 'in:off,auto,approve'],
            'ship' => ['nullable', 'in:pr,draft_pr,none'],
            'max_fix_rounds' => ['nullable', 'integer', 'min:0', 'max:20'],
            'max_turns' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_cost_usd' => ['nullable', 'numeric', 'min:0'],
            'max_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->only(['plan', 'ship', 'max_fix_rounds', 'max_turns', 'max_cost_usd', 'max_minutes']);
    }
}
