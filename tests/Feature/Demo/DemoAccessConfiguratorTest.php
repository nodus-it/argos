<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\DemoAccessMode;
use App\Models\Task;
use App\Services\Demo\DemoAccessConfigurator;
use App\Services\Demo\DemoDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccessConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // applyAccessMode shells out to Docker/Traefik — stub it out.
        $this->mock(DemoDeployer::class)->shouldReceive('applyAccessMode')->andReturnNull();
    }

    public function test_basic_mode_auto_generates_a_password_when_none_exists(): void
    {
        $task = Task::factory()->create(['demo_access_mode' => DemoAccessMode::Inherit, 'demo_basic_password' => null]);

        $password = app(DemoAccessConfigurator::class)->apply($task, DemoAccessMode::Basic, null);

        $this->assertNotEmpty($password);
        $this->assertSame($password, $task->fresh()->demo_basic_password);
        $this->assertSame(DemoAccessMode::Basic, $task->fresh()->demo_access_mode);
    }

    public function test_basic_mode_keeps_the_entered_password(): void
    {
        $task = Task::factory()->create(['demo_basic_password' => null]);

        $password = app(DemoAccessConfigurator::class)->apply($task, DemoAccessMode::Basic, 'hunter2');

        $this->assertSame('hunter2', $password);
        $this->assertSame('hunter2', $task->fresh()->demo_basic_password);
    }

    public function test_public_mode_persists_without_inventing_a_password(): void
    {
        $task = Task::factory()->create(['demo_basic_password' => null]);

        $password = app(DemoAccessConfigurator::class)->apply($task, DemoAccessMode::Public, null);

        $this->assertNull($password);
        $this->assertSame(DemoAccessMode::Public, $task->fresh()->demo_access_mode);
    }
}
