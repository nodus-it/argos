<?php

declare(strict_types=1);

use App\Providers\E2eFakeServiceProvider;
use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\GitProvider\GitHubGitService;
use App\Services\GitProvider\GitProviderRegistry;
use App\Services\Workflow\PhaseRunner;
use App\Testing\FakeAnthropicTokenValidator;
use App\Testing\FakeGitService;
use App\Testing\FakePhaseRunner;

it('uses the real Anthropic validator by default', function (): void {
    expect(app(AnthropicTokenValidator::class))
        ->toBeInstanceOf(AnthropicTokenValidator::class)
        ->not->toBeInstanceOf(FakeAnthropicTokenValidator::class);
});

it('binds the fake Anthropic validator once the e2e provider is registered', function (): void {
    app()->register(E2eFakeServiceProvider::class);

    expect(app(AnthropicTokenValidator::class))->toBeInstanceOf(FakeAnthropicTokenValidator::class);
    expect(app(AnthropicTokenValidator::class)->validate('whatever'))->toBeTrue();
});

it('uses the real git provider services by default', function (): void {
    expect(app(GitProviderRegistry::class)->make('github', 'token'))
        ->toBeInstanceOf(GitHubGitService::class);
});

it('serves canonical git data through the fake provider once registered', function (): void {
    app()->register(E2eFakeServiceProvider::class);

    $service = app(GitProviderRegistry::class)->make('github', 'token');

    expect($service)->toBeInstanceOf(FakeGitService::class)
        ->and($service->getRepoOptions())->not->toBeEmpty()
        ->and($service->getDefaultBranch('argos-e2e/demo-app'))->toBe('main')
        ->and($service->getBranchOptions('argos-e2e/demo-app'))->toHaveKey('main');
});

it('uses the real phase runner by default', function (): void {
    expect(app(PhaseRunner::class))
        ->toBeInstanceOf(PhaseRunner::class)
        ->not->toBeInstanceOf(FakePhaseRunner::class);
});

it('binds the fake phase runner once the e2e provider is registered', function (): void {
    app()->register(E2eFakeServiceProvider::class);

    expect(app(PhaseRunner::class))->toBeInstanceOf(FakePhaseRunner::class);
});

it('refuses to boot in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app()->register(E2eFakeServiceProvider::class))
        ->toThrow(RuntimeException::class);
});

it('is never registered unconditionally in bootstrap/providers.php', function (): void {
    $providers = require base_path('bootstrap/providers.php');

    expect($providers)->not->toContain(E2eFakeServiceProvider::class);
});
