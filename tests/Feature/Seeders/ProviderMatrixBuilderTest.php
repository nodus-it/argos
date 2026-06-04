<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\TaskProviderBinding;
use App\Models\User;
use Database\Seeders\Support\ProviderMatrixBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buildMatrix(User $user): void
{
    (new ProviderMatrixBuilder)->build($user);
}

it('seeds a repo profile for every git provider', function () {
    buildMatrix(User::factory()->create());

    // Defaults come from tests/External/providers.defaults.php.
    foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
        $profile = RepoProfile::where('name', "provider-demo ({$platform})")->first();
        expect($profile)->not->toBeNull("{$platform} demo profile should exist")
            ->and($profile->platform->value)->toBe($platform);
    }
});

it('gives github and gitlab a webhook and poll binding on their own profile', function () {
    buildMatrix(User::factory()->create());

    foreach (['github' => 'nodus-it/argos-test', 'gitlab' => 'bastian-schur/argos-test'] as $kind => $ref) {
        $profile = RepoProfile::where('name', "provider-demo ({$kind})")->first();
        $bindings = TaskProviderBinding::where('repo_profile_id', $profile->id)
            ->where('kind', $kind)->get();

        expect($bindings->map(fn (TaskProviderBinding $b): string => $b->mode->value)->all())
            ->toEqualCanonicalizing(['webhook', 'poll']);
        expect($bindings->first()->external_project_ref)->toBe($ref);
        expect($bindings->first()->filters)->toBe(['labels' => ['argos-demo']]);
        expect($bindings->firstWhere('mode.value', 'webhook')->webhook_secret)->not->toBeNull();
    }
});

it('seeds the linear binding by default on the bitbucket profile', function () {
    buildMatrix(User::factory()->create());

    $bitbucket = RepoProfile::where('name', 'provider-demo (bitbucket)')->first();
    $linear = TaskProviderBinding::where('kind', 'linear')->get();

    expect($linear)->toHaveCount(2); // webhook + poll
    expect($linear->first()->repo_profile_id)->toBe($bitbucket->id);
    // Default team comes from providers.defaults.php.
    expect($linear->first()->external_project_ref)->toBe('BAS');
});

it('lets the linear team env override win', function () {
    config(['argos.provider_demo.linear_team' => 'ENG']);

    buildMatrix(User::factory()->create());

    expect(TaskProviderBinding::where('kind', 'linear')->first()->external_project_ref)->toBe('ENG');
});

it('links existing connected accounts', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->create(['user_id' => $user->id, 'provider' => 'github']);

    buildMatrix($user);

    expect(TaskProviderBinding::where('kind', 'github')->first()->connected_account_id)->toBe($account->id);
});

it('stays account-less when no account is connected', function () {
    buildMatrix(User::factory()->create());

    expect(TaskProviderBinding::where('kind', 'github')->first()->connected_account_id)->toBeNull();
});

it('lets the env override win over the committed default', function () {
    config(['argos.provider_demo.gitlab_ref' => 'team/widget']);

    buildMatrix(User::factory()->create());

    expect(TaskProviderBinding::where('kind', 'gitlab')->first()->external_project_ref)->toBe('team/widget');
});

it('is idempotent and preserves webhook secrets', function () {
    config(['argos.provider_demo.linear_team' => 'ENG']);
    $user = User::factory()->create();

    buildMatrix($user);
    $profileCount = RepoProfile::count();
    $bindingCount = TaskProviderBinding::count();
    $secret = TaskProviderBinding::where('mode', 'webhook')->first()->webhook_secret;

    buildMatrix($user);

    expect(RepoProfile::count())->toBe($profileCount);
    expect(TaskProviderBinding::count())->toBe($bindingCount);
    expect(TaskProviderBinding::where('mode', 'webhook')->first()->webhook_secret)->toBe($secret);
});
