<?php

declare(strict_types=1);

use App\Providers\E2eFakeServiceProvider;
use App\Services\Anthropic\AnthropicTokenValidator;
use App\Testing\FakeAnthropicTokenValidator;

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

it('refuses to boot in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app()->register(E2eFakeServiceProvider::class))
        ->toThrow(RuntimeException::class);
});

it('is never registered unconditionally in bootstrap/providers.php', function (): void {
    $providers = require base_path('bootstrap/providers.php');

    expect($providers)->not->toContain(E2eFakeServiceProvider::class);
});
