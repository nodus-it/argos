<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters\Account\Resources\AgentCredentialResource\Pages;

use App\Enums\AgentName;
use App\Filament\Concerns\VerifiesCredentialOnSave;
use App\Filament\Workspaces\Clusters\Account\Resources\AgentCredentialResource;
use App\Filament\Support\Pages\CreateRecord;
use App\Services\Credentials\AgentCredentialService;
use App\Services\Credentials\CredentialVerifier;
use App\Services\EntityService;

class CreateAgentCredential extends CreateRecord
{
    use VerifiesCredentialOnSave;

    protected static string $resource = AgentCredentialResource::class;

    protected function service(): EntityService
    {
        return app(AgentCredentialService::class);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // A non-admin always creates a personal agent on their own subscription.
        if (! AgentCredentialResource::isAdmin()) {
            $data['user_id'] = auth()->id();
            $data['agent_name'] = AgentName::ClaudeCode->value;
            $data['max_concurrent_sessions'] = 1;
        }

        // Claude has a cheap live probe of the token.
        if (($data['agent_name'] ?? null) === AgentName::ClaudeCode->value) {
            $verification = app(CredentialVerifier::class)
                ->verifyClaudeToken((string) ($data['credentials']['token'] ?? ''));
            $data = $this->applyVerification($verification, $data);
        }

        return $data;
    }
}
