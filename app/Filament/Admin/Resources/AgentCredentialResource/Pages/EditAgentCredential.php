<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AgentCredentialResource\Pages;

use App\Enums\AgentName;
use App\Filament\Admin\Concerns\HasArgosEditHeading;
use App\Filament\Admin\Concerns\VerifiesCredentialOnSave;
use App\Filament\Admin\Resources\AgentCredentialResource;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Models\AgentCredential;
use App\Services\Credentials\AgentCredentialService;
use App\Services\Credentials\CredentialVerifier;
use App\Services\EntityService;
use Filament\Actions\DeleteAction;

class EditAgentCredential extends EditRecord
{
    use HasArgosEditHeading;
    use VerifiesCredentialOnSave;

    protected static string $resource = AgentCredentialResource::class;

    protected function service(): EntityService
    {
        return app(AgentCredentialService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @return array{icon?: string, label: string}|null
     */
    protected function argosHeadingChip(): ?array
    {
        /** @var AgentCredential $record */
        $record = $this->getRecord();

        return ['icon' => 'heroicon-o-cpu-chip', 'label' => $record->agent_name->value];
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
        $data = CreateAgentCredential::normaliseCredentials($data);

        if (($data['agent_name'] ?? null) === AgentName::ClaudeCode->value) {
            $verification = app(CredentialVerifier::class)
                ->verifyClaudeToken((string) ($data['credentials']['token'] ?? ''));
            $data = $this->applyVerification($verification, $data);
        }

        return $data;
    }
}
