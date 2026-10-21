<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/** POST /workspaces: a new workspace with an automatic run. */
class StoreWorkspaceRequest extends StartAutoRunRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'project' => ['required', 'string'],
            'base_branch' => ['nullable', 'string', 'max:255'],
            'issue' => ['nullable', 'string', 'max:64'],
        ];
    }
}
