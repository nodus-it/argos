<?php

declare(strict_types=1);

use App\Enums\AuthMethod;
use App\Models\AgentCredential;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\LiveReadySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const LIVE_SEED_KEYS = [
    'SEED_GITHUB_OAUTH_TOKEN', 'SEED_GITHUB_REFRESH_TOKEN', 'SEED_GITHUB_USER',
    'SEED_REPO_URL', 'SEED_REPO_BRANCH', 'SEED_CLAUDE_OAUTH_TOKEN',
];

function setSeedEnv(array $values): void
{
    foreach ($values as $key => $value) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function clearSeedEnv(): void
{
    foreach (LIVE_SEED_KEYS as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}

beforeEach(fn () => clearSeedEnv());
afterEach(fn () => clearSeedEnv());

it('skips entirely when not local', function () {
    // Default test env is "testing", not "local".
    setSeedEnv(['SEED_GITHUB_OAUTH_TOKEN' => 'gho_x', 'SEED_REPO_URL' => 'https://github.com/x/y.git']);

    $this->seed(LiveReadySeeder::class);

    expect(User::count())->toBe(0);
    expect(ConnectedAccount::count())->toBe(0);
    expect(RepoProfile::count())->toBe(0);
});

it('skips wiring cleanly when required env is unset but keeps the admin user', function () {
    $this->app->detectEnvironment(fn () => 'local');

    $this->seed(LiveReadySeeder::class);

    expect(User::where('email', 'admin@argos.local')->exists())->toBeTrue();
    expect(RepoProfile::where('name', 'argos (live)')->exists())->toBeFalse();
    expect(ConnectedAccount::count())->toBe(0);
});

it('wires a runnable github-oauth task when env is set', function () {
    $this->app->detectEnvironment(fn () => 'local');
    setSeedEnv([
        'SEED_GITHUB_OAUTH_TOKEN' => 'gho_live_token',
        'SEED_GITHUB_USER' => 'octocat',
        'SEED_REPO_URL' => 'https://github.com/nodus-it/argos.git',
        'SEED_REPO_BRANCH' => 'develop',
        'SEED_CLAUDE_OAUTH_TOKEN' => 'oat-live',
    ]);

    $this->seed(LiveReadySeeder::class);

    $account = ConnectedAccount::where('provider', 'github')->first();
    expect($account)->not->toBeNull();
    expect($account->instance_url)->toBe('');
    expect($account->token)->toBe('gho_live_token');

    $profile = RepoProfile::where('name', 'argos (live)')->first();
    expect($profile)->not->toBeNull();
    expect($profile->auth_method)->toBe(AuthMethod::OAuth);
    expect($profile->connected_account_id)->toBe($account->id);

    expect(AgentCredential::where('name', 'live')->where('status', 'active')->exists())->toBeTrue();
    expect(Task::where('name', 'Live demo task')->where('workflow_status', 'draft')->exists())->toBeTrue();

    // expires_at=null ⇒ no refresh round-trip ⇒ the seeded token is returned offline.
    expect($profile->resolveToken())->toBe('gho_live_token');
});
