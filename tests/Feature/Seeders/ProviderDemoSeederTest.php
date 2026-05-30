<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\TaskProviderBinding;
use App\Models\User;
use Database\Seeders\ProviderDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_github_demo_profile_with_webhook_and_poll_bindings(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $profile = RepoProfile::where('name', 'provider-demo (github)')->first();
        $this->assertNotNull($profile);
        $this->assertSame('github', $profile->platform->value);

        $bindings = TaskProviderBinding::where('repo_profile_id', $profile->id)
            ->where('kind', 'github')->get();
        $this->assertCount(2, $bindings);
        $this->assertEqualsCanonicalizing(
            ['webhook', 'poll'],
            $bindings->map(fn (TaskProviderBinding $b): string => $b->mode->value)->all(),
        );

        $webhook = $bindings->firstWhere('mode.value', 'webhook');
        $this->assertNotNull($webhook->webhook_secret);
        $this->assertSame(['labels' => ['argos-demo']], $webhook->filters);
        $this->assertSame('nodus-it/argos-test', $webhook->external_project_ref);
    }

    public function test_it_links_an_existing_connected_account(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
        ]);

        $this->seed(ProviderDemoSeeder::class);

        $binding = TaskProviderBinding::where('kind', 'github')->first();
        $this->assertSame($account->id, $binding->connected_account_id);
    }

    public function test_it_stays_account_less_when_no_account_is_connected(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $binding = TaskProviderBinding::where('kind', 'github')->first();
        $this->assertNull($binding->connected_account_id);
    }

    public function test_it_is_idempotent_and_preserves_the_webhook_secret(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);
        $secret = TaskProviderBinding::where('mode', 'webhook')->first()->webhook_secret;

        $this->seed(ProviderDemoSeeder::class);

        $this->assertSame(1, RepoProfile::where('name', 'provider-demo (github)')->count());
        $this->assertSame(2, TaskProviderBinding::count());
        $this->assertSame($secret, TaskProviderBinding::where('mode', 'webhook')->first()->webhook_secret);
    }

    public function test_it_seeds_gitlab_and_linear_when_configured(): void
    {
        config([
            'argos.provider_demo.gitlab_ref' => 'group/sub/widget',
            'argos.provider_demo.linear_team' => 'ENG',
        ]);
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $this->assertNotNull(RepoProfile::where('name', 'provider-demo (gitlab)')->first());
        $this->assertSame(2, TaskProviderBinding::where('kind', 'gitlab')->count());

        // Linear has no git repo; its bindings hang off the GitHub demo profile.
        $github = RepoProfile::where('name', 'provider-demo (github)')->first();
        $linear = TaskProviderBinding::where('kind', 'linear')->get();
        $this->assertCount(2, $linear);
        $this->assertSame($github->id, $linear->first()->repo_profile_id);
        $this->assertSame('ENG', $linear->first()->external_project_ref);
    }

    public function test_it_skips_gitlab_and_linear_by_default(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $this->assertSame(0, TaskProviderBinding::where('kind', 'gitlab')->count());
        $this->assertSame(0, TaskProviderBinding::where('kind', 'linear')->count());
    }
}
