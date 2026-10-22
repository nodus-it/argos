<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\AgentCredentialStatus;
use App\Models\AgentCredential;
use App\Models\User;
use App\Services\EntityService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Operations on a stored agent credential (the Claude auth the worker
 * runs with). Plain CRUD via the base today; form-side input normalisation and
 * the live token probe still run in the resource's save hooks.
 */
class AgentCredentialService extends EntityService
{
    protected function model(): string
    {
        return AgentCredential::class;
    }

    /**
     * A new token revives an agent Claude had refused (expired): nobody has to
     * remember to switch its status back as well.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $record, array $data): Model
    {
        /** @var AgentCredential $record */
        $newToken = (string) ($data['credentials']['token'] ?? '');
        if ($record->status === AgentCredentialStatus::Expired && $newToken !== '' && $newToken !== $record->oauthToken()
            && ($data['status'] ?? AgentCredentialStatus::Expired->value) === AgentCredentialStatus::Expired->value) {
            $data['status'] = AgentCredentialStatus::Active->value;
        }

        return parent::update($record, $data);
    }

    /**
     * Claude refused the agent's token: take the agent out of rotation and
     * tell whoever renews it (its owner; administrators for shared agents).
     * Only the first refusal notifies.
     */
    public function markExpired(AgentCredential $agent): void
    {
        $changed = AgentCredential::query()->whereKey($agent->id)->where('status', AgentCredentialStatus::Active->value)
            ->update(['status' => AgentCredentialStatus::Expired->value]);
        $agent->status = AgentCredentialStatus::Expired;
        if ($changed === 0) {
            return;
        }

        $recipients = $agent->user_id !== null
            ? User::query()->whereKey($agent->user_id)->get()
            : User::query()->where('is_admin', true)->get();
        foreach ($recipients as $user) {
            Notification::make()
                ->title(__('workspaces.notifications.agent_expired', ['agent' => $agent->name]))
                ->body(__('workspaces.notifications.agent_expired_body'))
                ->icon('heroicon-o-key')
                ->danger()
                ->actions([Action::make('renew')->label(__('workspaces.notifications.agent_renew'))->url(route('filament.app.account.resources.agents.edit', $agent))])
                ->sendToDatabase($user);
        }
    }
}
