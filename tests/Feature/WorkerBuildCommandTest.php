<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

class WorkerBuildCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'worker:build',
            $this->app->make(Kernel::class)->all(),
        );
    }

    public function test_unknown_php_variant_returns_failure(): void
    {
        $this->artisan('worker:build', ['--php' => ['7.4']])
            ->expectsOutputToContain('Unknown --php variant')
            ->assertExitCode(1);
    }
}
