<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // The plan: stored as both the task description and the concept notes.
            'plan' => ['required', 'string'],
            // Required for User tokens, optional for project-bound tokens
            // (the controller resolves and validates against the token's scope).
            'project' => ['nullable', 'string'],
            'base_branch' => ['nullable', 'string', 'max:255'],
        ];
    }
}
