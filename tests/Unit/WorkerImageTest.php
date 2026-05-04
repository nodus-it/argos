<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Worker\WorkerImage;
use Tests\TestCase;

class WorkerImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'argos.worker_images' => [
                'local' => ['argos-worker:local-php8.4', 'argos-worker:local-php8.3'],
                'staging' => ['ghcr.io/x/argos-worker:stage-php8.4'],
                'production' => ['ghcr.io/x/argos-worker:php8.4'],
            ],
        ]);
    }

    public function test_standard_options_returns_images_for_active_environment(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $options = WorkerImage::standardOptions();

        $this->assertArrayHasKey('argos-worker:local-php8.4', $options);
        $this->assertArrayHasKey('argos-worker:local-php8.3', $options);
        $this->assertCount(2, $options);
    }

    public function test_standard_options_falls_back_to_local_on_unknown_environment(): void
    {
        app()->detectEnvironment(fn (): string => 'unknown-env');

        $options = WorkerImage::standardOptions();

        $this->assertArrayHasKey('argos-worker:local-php8.4', $options);
    }

    public function test_options_for_appends_custom_value_when_not_in_standard_list(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $options = WorkerImage::optionsFor('argos-worker:hand-rolled');

        $this->assertArrayHasKey('argos-worker:hand-rolled', $options);
        $this->assertSame('argos-worker:hand-rolled (custom)', $options['argos-worker:hand-rolled']);
    }

    public function test_options_for_does_not_duplicate_when_value_is_in_standard_list(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $options = WorkerImage::optionsFor('argos-worker:local-php8.4');

        $this->assertSame('argos-worker:local-php8.4', $options['argos-worker:local-php8.4']);
        $this->assertCount(2, $options);
    }

    public function test_options_for_handles_null_and_empty(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $this->assertSame(WorkerImage::standardOptions(), WorkerImage::optionsFor(null));
        $this->assertSame(WorkerImage::standardOptions(), WorkerImage::optionsFor(''));
    }
}
