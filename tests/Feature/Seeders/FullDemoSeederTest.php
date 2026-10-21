<?php

declare(strict_types=1);

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\ProviderCredentialStatus;
use App\Enums\WorkerSource;
use App\Models\AgentCredential;
use App\Models\ExternalIssueLink;
use App\Models\ProviderCredential;
use App\Models\RepoProfile;
use Database\Seeders\FullDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an agent credential in every status', function () {
    $this->seed(FullDemoSeeder::class);

    foreach ([AgentCredentialStatus::Active, AgentCredentialStatus::Expired, AgentCredentialStatus::Revoked] as $status) {
        expect(AgentCredential::where('agent_name', AgentName::ClaudeCode->value)->where('status', $status->value)->exists())->toBeTrue();
    }
});

it('seeds the full provider matrix', function () {
    $this->seed(FullDemoSeeder::class);

    foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
        expect(RepoProfile::where('name', "provider-demo ({$platform})")->exists())->toBeTrue();
    }
});

it('seeds active and expired provider credentials', function () {
    $this->seed(FullDemoSeeder::class);

    expect(ProviderCredential::where('status', ProviderCredentialStatus::Active->value)->exists())->toBeTrue();
    expect(ProviderCredential::where('status', ProviderCredentialStatus::Expired->value)->exists())->toBeTrue();
});

it('seeds an external issue link that has not started a run yet', function () {
    $this->seed(FullDemoSeeder::class);

    $link = ExternalIssueLink::where('external_id', '4242')->first();
    expect($link)->not->toBeNull();
    expect($link->workspace_id)->toBeNull();
});

it('seeds repo profile feature variants', function () {
    $this->seed(FullDemoSeeder::class);

    expect(RepoProfile::where('live_demo_enabled', true)->exists())->toBeTrue();
    expect(RepoProfile::where('worker_source', WorkerSource::Byoi->value)->exists())->toBeTrue();
    expect(RepoProfile::where('worker_source', WorkerSource::Devcontainer->value)->exists())->toBeTrue();
});

it('is idempotent across re-seeds', function () {
    $this->seed(FullDemoSeeder::class);
    $profiles = RepoProfile::count();
    $credentials = AgentCredential::count();
    $links = ExternalIssueLink::count();

    $this->seed(FullDemoSeeder::class);

    expect(RepoProfile::count())->toBe($profiles);
    expect(AgentCredential::count())->toBe($credentials);
    expect(ExternalIssueLink::count())->toBe($links);
});
