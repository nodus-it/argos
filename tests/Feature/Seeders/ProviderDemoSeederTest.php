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

    public function test_it_seeds_a_repo_profile_for_every_git_provider(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        // Defaults come from tests/External/providers.defaults.php.
        foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
            $profile = RepoProfile::where('name', "provider-demo ({$platform})")->first();
            $this->assertNotNull($profile, "{$platform} demo profile should exist");
            $this->assertSame($platform, $profile->platform->value);
        }
    }

    public function test_github_and_gitlab_get_webhook_and_poll_bindings_on_their_own_profile(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        foreach (['github' => 'nodus-it/argos-test', 'gitlab' => 'bastian-schur/argos-test'] as $kind => $ref) {
            $profile = RepoProfile::where('name', "provider-demo ({$kind})")->first();
            $bindings = TaskProviderBinding::where('repo_profile_id', $profile->id)
                ->where('kind', $kind)->get();

            $this->assertEqualsCanonicalizing(
                ['webhook', 'poll'],
                $bindings->map(fn (TaskProviderBinding $b): string => $b->mode->value)->all(),
                "{$kind} should have a webhook and a poll binding",
            );
            $this->assertSame($ref, $bindings->first()->external_project_ref);
            $this->assertSame(['labels' => ['argos-demo']], $bindings->first()->filters);

            $webhook = $bindings->firstWhere('mode.value', 'webhook');
            $this->assertNotNull($webhook->webhook_secret);
        }
    }

    public function test_linear_binding_seeds_by_default_on_the_bitbucket_profile(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $bitbucket = RepoProfile::where('name', 'provider-demo (bitbucket)')->first();
        $linear = TaskProviderBinding::where('kind', 'linear')->get();

        $this->assertCount(2, $linear); // webhook + poll
        $this->assertSame($bitbucket->id, $linear->first()->repo_profile_id);
        // Default team comes from providers.defaults.php.
        $this->assertSame('BAS', $linear->first()->external_project_ref);
    }

    public function test_linear_team_env_override_wins(): void
    {
        config(['argos.provider_demo.linear_team' => 'ENG']);
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $this->assertSame('ENG', TaskProviderBinding::where('kind', 'linear')->first()->external_project_ref);
    }

    public function test_it_links_existing_connected_accounts(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
        ]);

        $this->seed(ProviderDemoSeeder::class);

        $this->assertSame(
            $account->id,
            TaskProviderBinding::where('kind', 'github')->first()->connected_account_id,
        );
    }

    public function test_it_stays_account_less_when_no_account_is_connected(): void
    {
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $this->assertNull(TaskProviderBinding::where('kind', 'github')->first()->connected_account_id);
    }

    public function test_env_override_wins_over_the_committed_default(): void
    {
        config(['argos.provider_demo.gitlab_ref' => 'team/widget']);
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);

        $this->assertSame(
            'team/widget',
            TaskProviderBinding::where('kind', 'gitlab')->first()->external_project_ref,
        );
    }

    public function test_it_is_idempotent_and_preserves_webhook_secrets(): void
    {
        config(['argos.provider_demo.linear_team' => 'ENG']);
        User::factory()->create();

        $this->seed(ProviderDemoSeeder::class);
        $profileCount = RepoProfile::count();
        $bindingCount = TaskProviderBinding::count();
        $secret = TaskProviderBinding::where('mode', 'webhook')->first()->webhook_secret;

        $this->seed(ProviderDemoSeeder::class);

        $this->assertSame($profileCount, RepoProfile::count());
        $this->assertSame($bindingCount, TaskProviderBinding::count());
        $this->assertSame($secret, TaskProviderBinding::where('mode', 'webhook')->first()->webhook_secret);
    }
}
