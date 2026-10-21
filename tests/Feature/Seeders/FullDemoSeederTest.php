<?php

declare(strict_types=1);

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\ProviderCredentialStatus;
use App\Enums\WorkerSource;
use App\Models\AgentCredential;
use App\Models\ExternalIssueLink;
use App\Models\ProviderCredential;
use App\Models\Workspace;
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
        expect(Workspace::where('name', "provider-demo ({$platform})")->exists())->toBeTrue();
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
    expect($link->task_id)->toBeNull();
});

it('seeds workspace feature variants', function () {
    $this->seed(FullDemoSeeder::class);

    expect(Workspace::where('live_demo_enabled', true)->exists())->toBeTrue();
    expect(Workspace::where('worker_source', WorkerSource::Byoi->value)->exists())->toBeTrue();
});

it('is idempotent across re-seeds', function () {
    $this->seed(FullDemoSeeder::class);
    $workspaces = Workspace::count();
    $credentials = AgentCredential::count();
    $links = ExternalIssueLink::count();

    $this->seed(FullDemoSeeder::class);

    expect(Workspace::count())->toBe($workspaces);
    expect(AgentCredential::count())->toBe($credentials);
    expect(ExternalIssueLink::count())->toBe($links);
});
