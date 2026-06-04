<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\IntegrationProvider;
use App\Enums\ProviderCredentialStatus;
use App\Models\AgentCredential;
use App\Models\ProviderCredential;

/**
 * Seeds the credential variants Full-Demo needs so the agent-credential and
 * provider-credential list/badge views show every status at once. Idempotent —
 * keyed on the natural unique columns (agent_name+name / provider+label) so a
 * re-seed updates in place rather than duplicating.
 */
final class CredentialMatrixBuilder
{
    /**
     * Claude active, Claude expired, Codex active, Codex revoked.
     *
     * @return list<AgentCredential>
     */
    public function agentCredentialMatrix(): array
    {
        return [
            $this->agentCredential('Claude · Active', AgentName::ClaudeCode, AgentCredentialStatus::Active, ['token' => 'oat-demo-active']),
            $this->agentCredential('Claude · Expired', AgentName::ClaudeCode, AgentCredentialStatus::Expired, ['token' => 'oat-demo-expired']),
            $this->agentCredential('Codex · Active', AgentName::Codex, AgentCredentialStatus::Active, ['OPENAI_API_KEY' => 'sk-demo-active']),
            $this->agentCredential('Codex · Revoked', AgentName::Codex, AgentCredentialStatus::Revoked, ['OPENAI_API_KEY' => 'sk-demo-revoked']),
        ];
    }

    /**
     * An active GitHub PAT and an expired GitLab PAT.
     *
     * @return list<ProviderCredential>
     */
    public function providerCredentialMatrix(): array
    {
        return [
            $this->providerCredential('GitHub PAT (active)', IntegrationProvider::GitHub, ProviderCredentialStatus::Active, 'repo'),
            $this->providerCredential('GitLab PAT (expired)', IntegrationProvider::GitLab, ProviderCredentialStatus::Expired, 'api'),
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function agentCredential(string $name, AgentName $agent, AgentCredentialStatus $status, array $credentials): AgentCredential
    {
        return AgentCredential::updateOrCreate(
            ['agent_name' => $agent->value, 'name' => $name],
            [
                'credentials' => $credentials,
                'status' => $status->value,
                'last_validated_at' => $status === AgentCredentialStatus::Active ? now() : null,
            ],
        );
    }

    private function providerCredential(string $label, IntegrationProvider $provider, ProviderCredentialStatus $status, string $scopes): ProviderCredential
    {
        return ProviderCredential::updateOrCreate(
            ['provider' => $provider->value, 'label' => $label],
            [
                'instance_url' => null,
                'token' => 'pat-demo-'.strtolower($provider->value),
                'scopes_hint' => $scopes,
                'status' => $status->value,
                'last_validated_at' => $status === ProviderCredentialStatus::Active ? now() : null,
            ],
        );
    }
}
