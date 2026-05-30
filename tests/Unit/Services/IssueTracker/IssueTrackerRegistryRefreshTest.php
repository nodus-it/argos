<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ConnectedAccount;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IssueTrackerRegistryRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gitlab.client_id', 'gl-client-id');
        config()->set('services.gitlab.client_secret', 'gl-client-secret');
    }

    private function gitlabBinding(?Carbon $expiresAt): TaskProviderBinding
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'gitlab',
            'token' => 'expired-token',
            'refresh_token' => 'a-refresh-token',
            'expires_at' => $expiresAt,
            'instance_url' => null,
        ]);

        return TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitLab,
            'connected_account_id' => $account->id,
        ]);
    }

    public function test_make_refreshes_an_expired_oauth_token(): void
    {
        Http::fake([
            'https://gitlab.com/oauth/token' => Http::response([
                'access_token' => 'fresh-token',
                'refresh_token' => 'rotated-refresh',
                'expires_in' => 7200,
            ]),
        ]);

        $binding = $this->gitlabBinding(now()->subMinute());

        app(IssueTrackerRegistry::class)->make(TaskProviderKind::GitLab, $binding->fresh());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gitlab.com/oauth/token'));
        $this->assertSame('fresh-token', $binding->connectedAccount->fresh()->token);
    }

    public function test_make_does_not_refresh_a_token_far_from_expiry(): void
    {
        Http::fake();

        $binding = $this->gitlabBinding(now()->addHours(8));

        app(IssueTrackerRegistry::class)->make(TaskProviderKind::GitLab, $binding->fresh());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/oauth/token'));
    }
}
