<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AgentCredentialResource\Pages;

use App\Enums\AgentName;
use App\Filament\Admin\Resources\AgentCredentialResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAgentCredential extends EditRecord
{
    protected static string $resource = AgentCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Codex: surface the JSON text in the virtual field. Token-shaped
        // claude credentials are already mapped via dot-notation.
        if (($data['agent_name'] ?? null) === AgentName::Codex->value) {
            $data['credentials_json'] = json_encode(
                $data['credentials'] ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateAgentCredential::normaliseCredentials($data);
    }
}
