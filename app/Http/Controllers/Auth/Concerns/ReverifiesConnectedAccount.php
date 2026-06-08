<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\ConnectedAccount;
use App\Services\Credentials\CredentialVerifier;
use Filament\Notifications\Notification;

/**
 * Re-verifies a freshly connected OAuth account against the provider's API.
 *
 * The OAuth grant itself already succeeded (the callback fetched the user), so
 * this never blocks the connection — but it actively probes a real endpoint
 * (listing references) and surfaces a warning on a definitive rejection, e.g.
 * an OAuth app configured with insufficient scopes. Transient/unreachable
 * results stay silent so a provider blip doesn't nag the user.
 */
trait ReverifiesConnectedAccount
{
    protected function reverifyConnectedAccount(ConnectedAccount $account): void
    {
        $verification = app(CredentialVerifier::class)->verifyProvider(
            (string) $account->provider,
            (string) $account->token,
            (string) ($account->instance_url ?? ''),
        );

        if ($verification->isRejected()) {
            Notification::make()
                ->title(__('credentials.verify.oauth_scope_title'))
                ->body($verification->message ?? __('credentials.verify.token_rejected'))
                ->warning()
                ->send();
        }
    }
}
