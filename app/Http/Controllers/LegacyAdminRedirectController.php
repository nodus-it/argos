<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/admin/…` from before the admin panel was folded into the app: each old
 * page has a new home under `/account`, `/system`, `/docs` or `/setup`.
 * Record ids and the rest of the path carry over.
 */
class LegacyAdminRedirectController extends Controller
{
    /** Old first path segment → new prefix. */
    private const MOVED = [
        'agent-credentials' => 'account/agents',
        'connected-accounts' => 'account/connections',
        'profile' => 'account/profile',
        'users' => 'system/users',
        'provider-credentials' => 'system/tokens',
        'provider-oauth-configs' => 'system/oauth-apps',
        'api-clients' => 'system/api-clients',
        'settings' => 'system/overview',
        'docs' => 'docs',
        'onboarding' => 'setup',
        'login' => 'login',
    ];

    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $segments = array_values(array_filter(explode('/', (string) $path), fn (string $s): bool => $s !== ''));
        $first = array_shift($segments);

        $target = match (true) {
            $first === null => '',
            $first === 'workspaces' => $this->workspace($segments),
            isset(self::MOVED[$first]) => implode('/', [self::MOVED[$first], ...$segments]),
            default => null,
        };
        abort_if($target === null, 404);
        $query = $request->getQueryString();

        return redirect()->to(url('/'.$target).($query !== null ? '?'.$query : ''), 301);
    }

    /** @param  list<string>  $segments */
    private function workspace(array $segments): ?string
    {
        if ($segments === []) {
            return 'workspaces';
        }
        if ($segments === ['create']) {
            return 'new';
        }
        $workspace = Workspace::query()->where('id', $segments[0])->orWhere('slug', $segments[0])->first();

        return $workspace !== null ? $workspace->slug.'/settings' : null;
    }
}
