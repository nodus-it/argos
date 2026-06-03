<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Jobs\BuildDemoImageJob;
use App\Services\Demo\DemoImageBuilder;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WarmDemoImageTest extends TestCase
{
    public function test_skips_when_previews_disabled(): void
    {
        Bus::fake();
        config()->set('argos.preview.enabled', false);

        $this->artisan('argos:warm-demo-image')->assertSuccessful();

        Bus::assertNotDispatched(BuildDemoImageJob::class);
    }

    public function test_dispatches_build_when_enabled_and_image_missing(): void
    {
        Bus::fake();
        config()->set('argos.preview.enabled', true);
        $this->app->instance(DemoImageBuilder::class, new class extends DemoImageBuilder
        {
            public function imageExists(string $tag): bool
            {
                return false;
            }
        });

        $this->artisan('argos:warm-demo-image')->assertSuccessful();

        Bus::assertDispatched(BuildDemoImageJob::class);
    }

    public function test_skips_dispatch_when_image_already_cached(): void
    {
        Bus::fake();
        config()->set('argos.preview.enabled', true);
        $this->app->instance(DemoImageBuilder::class, new class extends DemoImageBuilder
        {
            public function imageExists(string $tag): bool
            {
                return true;
            }
        });

        $this->artisan('argos:warm-demo-image')->assertSuccessful();

        Bus::assertNotDispatched(BuildDemoImageJob::class);
    }
}
