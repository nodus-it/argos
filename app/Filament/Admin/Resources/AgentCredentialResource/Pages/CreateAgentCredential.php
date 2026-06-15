<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AgentCredentialResource\Pages;

use App\Enums\AgentName;
use App\Filament\Admin\Concerns\VerifiesCredentialOnSave;
use App\Filament\Admin\Resources\AgentCredentialResource;
use App\Filament\Admin\Support\Pages\CreateRecord;
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
        $data = self::normaliseCredentials($data);

        // Only Claude has a cheap live probe; Codex is validated structurally
        // in normaliseCredentials() — there is no equivalent token endpoint.
        if (($data['agent_name'] ?? null) === AgentName::ClaudeCode->value) {
            $verification = app(CredentialVerifier::class)
                ->verifyClaudeToken((string) ($data['credentials']['token'] ?? ''));
            $data = $this->applyVerification($verification, $data);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normaliseCredentials(array $data): array
    {
        $agent = $data['agent_name'] ?? null;

        if ($agent === AgentName::Codex->value) {
            $raw = trim((string) ($data['credentials_json'] ?? ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('auth.json content is not valid JSON.');
            }
            $data['credentials'] = $decoded;
            unset($data['credentials_json']);
        }

        // Claude already arrives as ['credentials' => ['token' => '…']]
        // because the field was Filament\Forms\Components\TextInput::make('credentials.token').
        return $data;
    }
}
