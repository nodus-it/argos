<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AgentCredentialResource\Pages;

use App\Enums\AgentName;
use App\Filament\Admin\Resources\AgentCredentialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentCredential extends CreateRecord
{
    protected static string $resource = AgentCredentialResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normaliseCredentials($data);
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
