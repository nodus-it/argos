<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Models\Task;
use App\Models\Workspace;
use App\Services\Workspace\TaskEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $services */
    private function task(array $services, string $slug = 'fix-login', array $config = []): Task
    {
        $workspace = Workspace::factory()->withBackingServices($services)->withServiceConfig($config)->create();

        return Task::factory()->create(['workspace_id' => $workspace->id, 'slug' => $slug]);
    }

    private function values(Task $task): array
    {
        return app(TaskEnvironment::class)->values($task);
    }

    public function test_a_workspace_without_services_sets_nothing(): void
    {
        $this->assertSame([], $this->values($this->task([])));
    }

    public function test_mysql_points_the_task_at_its_own_database(): void
    {
        $this->assertSame([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'db',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'task_fix_login',
            'DB_USERNAME' => 'app',
            'DB_PASSWORD' => 'secret',
        ], $this->values($this->task(['mysql'], config: ['mysql' => ['database' => 'shop', 'username' => 'app', 'password' => 'secret']])));
    }

    public function test_redis_gets_a_key_prefix_per_task(): void
    {
        $this->assertSame([
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_PREFIX' => 'fix_login_',
            'CACHE_PREFIX' => 'fix_login_',
        ], $this->values($this->task(['redis'])));
    }

    public function test_both_services_combine(): void
    {
        $env = $this->values($this->task(['mysql', 'redis']));

        $this->assertSame('task_fix_login', $env['DB_DATABASE']);
        $this->assertSame('fix_login_', $env['REDIS_PREFIX']);
    }

    public function test_the_database_name_stays_within_the_mysql_limit(): void
    {
        $task = (new Task)->forceFill(['slug' => str_repeat('a-', 40)]);

        $name = TaskEnvironment::databaseName($task);

        $this->assertSame(64, strlen($name));
        $this->assertStringStartsWith('task_a_a_', $name);
    }
}
