<?php

declare(strict_types=1);

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\DemoStatus;
use App\Enums\PhaseStatus;
use App\Enums\ProviderCredentialStatus;
use App\Enums\WorkerSource;
use App\Models\AgentCredential;
use App\Models\Demo;
use App\Models\ExternalIssueLink;
use App\Models\PhaseRun;
use App\Models\ProviderCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Support\Workflow\TaskStage;
use Database\Seeders\FullDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an agent credential in every status', function () {
    $this->seed(FullDemoSeeder::class);

    expect(AgentCredential::where('agent_name', AgentName::ClaudeCode->value)->where('status', AgentCredentialStatus::Active->value)->exists())->toBeTrue();
    expect(AgentCredential::where('agent_name', AgentName::ClaudeCode->value)->where('status', AgentCredentialStatus::Expired->value)->exists())->toBeTrue();
    expect(AgentCredential::where('agent_name', AgentName::Codex->value)->where('status', AgentCredentialStatus::Active->value)->exists())->toBeTrue();
    expect(AgentCredential::where('agent_name', AgentName::Codex->value)->where('status', AgentCredentialStatus::Revoked->value)->exists())->toBeTrue();
});

it('seeds one showcase task per workflow stage with a failed run present', function () {
    $this->seed(FullDemoSeeder::class);

    expect(Task::where('name', 'like', 'Showcase%')->count())->toBe(count(TaskStage::cases()));
    expect(PhaseRun::where('status', PhaseStatus::Failed->value)->exists())->toBeTrue();
});

it('seeds the full provider matrix', function () {
    $this->seed(FullDemoSeeder::class);

    foreach (['github', 'gitlab', 'bitbucket'] as $platform) {
        expect(RepoProfile::where('name', "provider-demo ({$platform})")->exists())->toBeTrue();
    }
});

it('seeds a live demo in every status', function () {
    $this->seed(FullDemoSeeder::class);

    foreach ([DemoStatus::Building, DemoStatus::Live, DemoStatus::Failed, DemoStatus::Stopped] as $status) {
        expect(Demo::where('status', $status->value)->exists())->toBeTrue();
    }
});

it('seeds active and expired provider credentials', function () {
    $this->seed(FullDemoSeeder::class);

    expect(ProviderCredential::where('status', ProviderCredentialStatus::Active->value)->exists())->toBeTrue();
    expect(ProviderCredential::where('status', ProviderCredentialStatus::Expired->value)->exists())->toBeTrue();
});

it('seeds a task imported from an external issue', function () {
    $this->seed(FullDemoSeeder::class);

    $link = ExternalIssueLink::whereNotNull('task_id')->first();
    expect($link)->not->toBeNull();
    expect($link->task_imported_at)->not->toBeNull();
});

it('seeds repo profile feature variants', function () {
    $this->seed(FullDemoSeeder::class);

    expect(RepoProfile::where('auto_concept', true)->exists())->toBeTrue();
    expect(RepoProfile::where('auto_pr', true)->exists())->toBeTrue();
    expect(RepoProfile::where('live_demo_enabled', true)->exists())->toBeTrue();
    expect(RepoProfile::where('worker_source', WorkerSource::Byoi->value)->exists())->toBeTrue();
});

it('is idempotent across re-seeds', function () {
    $this->seed(FullDemoSeeder::class);
    $tasks = Task::count();
    $credentials = AgentCredential::count();
    $demos = Demo::count();

    $this->seed(FullDemoSeeder::class);

    expect(Task::count())->toBe($tasks);
    expect(AgentCredential::count())->toBe($credentials);
    expect(Demo::count())->toBe($demos);
});
