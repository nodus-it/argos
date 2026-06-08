<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Services\Credentials\CredentialVerification;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Shared handling of a live credential verification inside a Create/Edit page's
 * mutate hook. Policy:
 *   - Rejected    → danger notification + halt (the record is never persisted).
 *   - Valid       → stamp status=active + last_validated_at, then persist.
 *   - Unreachable → warning notification, persist as-is (not marked validated).
 */
trait VerifiesCredentialOnSave
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws Halt when the provider rejected the credential
     */
    protected function applyVerification(CredentialVerification $verification, array $data): array
    {
        if ($verification->isRejected()) {
            Notification::make()
                ->title(__('credentials.verify.rejected_title'))
                ->body($verification->message ?? __('credentials.verify.token_rejected'))
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        if ($verification->isValid()) {
            $data['status'] = 'active';
            $data['last_validated_at'] = now();

            return $data;
        }

        // Unreachable: keep the save, but make clear it wasn't verified.
        Notification::make()
            ->title(__('credentials.verify.unreachable_title'))
            ->body($verification->message ?? __('credentials.verify.provider_unreachable'))
            ->warning()
            ->send();

        return $data;
    }
}
