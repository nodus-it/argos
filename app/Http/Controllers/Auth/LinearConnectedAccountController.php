<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ReverifiesConnectedAccount;
use App\Http\Controllers\Controller;
use App\Integrations\Linear\LinearConnector;
use App\Integrations\Linear\Requests\GraphQLRequest;
use App\Integrations\Linear\Requests\OAuthTokenExchange;
use App\Models\User;
use App\Services\OAuth\ConnectedAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LinearConnectedAccountController extends Controller
{
    use ReverifiesConnectedAccount;

    private const RETURN_SESSION_KEY = 'oauth.linear.return';

    private const STATE_SESSION_KEY = 'oauth.linear.state';

    public function redirect(Request $request): RedirectResponse
    {
        if ($request->query('return') === 'onboarding') {
            $request->session()->put(self::RETURN_SESSION_KEY, 'onboarding');
        } else {
            $request->session()->forget(self::RETURN_SESSION_KEY);
        }

        $state = bin2hex(random_bytes(16));
        $request->session()->put(self::STATE_SESSION_KEY, $state);

        $params = http_build_query([
            'client_id' => config('services.linear.client_id'),
            'redirect_uri' => url((string) config('services.linear.redirect')),
            'response_type' => 'code',
            'scope' => 'read write issues:create comments:create admin',
            'state' => $state,
            'prompt' => 'consent',
        ]);

        return redirect("https://linear.app/oauth/authorize?{$params}");
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_SESSION_KEY);

        if ($expectedState === null || $request->query('state') !== $expectedState) {
            abort(403, 'Invalid OAuth state');
        }

        $code = $request->query('code');
        if ($code === null || $code === '') {
            abort(400, 'Missing OAuth code');
        }

        $tokenResponse = (new LinearConnector)->send(new OAuthTokenExchange(
            clientId: (string) config('services.linear.client_id'),
            clientSecret: (string) config('services.linear.client_secret'),
            code: $code,
            redirectUri: url((string) config('services.linear.redirect')),
        ))->throw()->json();

        $accessToken = (string) $tokenResponse['access_token'];

        $viewerResponse = (new LinearConnector($accessToken))->send(
            new GraphQLRequest('{ viewer { id name email avatarUrl } }'),
        )->throw()->json();

        /** @var array<string, mixed> $viewer */
        $viewer = $viewerResponse['data']['viewer'];

        /** @var User $user */
        $user = Auth::user();

        $account = app(ConnectedAccountService::class)->upsert($user, 'linear', [
            'provider_id' => (string) $viewer['id'],
            'token' => $accessToken,
            'refresh_token' => isset($tokenResponse['refresh_token']) ? (string) $tokenResponse['refresh_token'] : null,
            'expires_at' => null,
            'name' => (string) ($viewer['name'] ?? ''),
            'nickname' => (string) ($viewer['email'] ?? ''),
            'avatar' => isset($viewer['avatarUrl']) ? (string) $viewer['avatarUrl'] : null,
        ]);
        $this->reverifyConnectedAccount($account);

        $returnTo = $request->session()->pull(self::RETURN_SESSION_KEY);

        if ($returnTo === 'onboarding') {
            return redirect()->route('filament.admin.pages.onboarding');
        }

        return redirect()->route('filament.admin.pages.connected-accounts');
    }

    public function disconnect(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        app(ConnectedAccountService::class)->disconnect($user, 'linear');

        return redirect()->route('filament.admin.pages.connected-accounts');
    }
}
