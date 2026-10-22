<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\Account\Resources\AgentCredentialResource\Pages;

use App\Enums\AgentName;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource;
use App\Filament\Concerns\HasArgosEditHeading;
use App\Filament\Concerns\VerifiesCredentialOnSave;
use App\Filament\Support\Actions\ServiceDeleteAction;
use App\Filament\Support\Pages\EditRecord;
use App\Models\AgentCredential;
use App\Services\Credentials\AgentCredentialService;
use App\Services\Credentials\CredentialVerifier;
use App\Services\EntityService;

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
            ServiceDeleteAction::make(AgentCredentialService::class),
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
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['agent_name'] ?? null) === AgentName::ClaudeCode->value) {
            $verification = app(CredentialVerifier::class)
                ->verifyClaudeToken((string) ($data['credentials']['token'] ?? ''));
            $data = $this->applyVerification($verification, $data);
        }

        return $data;
    }
}
