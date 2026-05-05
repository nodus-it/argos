<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TaskService;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaskService;
    }

    public function test_create_saves_task_to_database(): void
    {
        $profile = RepoProfile::factory()->create();

        $task = $this->service->create([
            'name' => 'My Task',
            'repo_profile_id' => $profile->id,
            'description' => 'Do something',
        ]);

        $this->assertDatabaseHas(Task::class, [
            'id' => $task->id,
            'name' => 'My Task',
            'description' => 'Do something',
            'repo_profile_id' => $profile->id,
        ]);
    }

    public function test_create_without_repo_profile(): void
    {
        $task = $this->service->create([
            'name' => 'Standalone',
            'description' => 'No profile',
        ]);

        $this->assertNull($task->repo_profile_id);
        $this->assertDatabaseHas(Task::class, ['name' => 'Standalone']);
    }

    public function test_list_returns_all_tasks(): void
    {
        Task::factory()->count(3)->create();

        $result = $this->service->list();

        $this->assertCount(3, $result);
    }

    public function test_list_eager_loads_repo_profile(): void
    {
        $profile = RepoProfile::factory()->create();
        Task::factory()->create(['repo_profile_id' => $profile->id]);

        $result = $this->service->list();

        $this->assertTrue($result->first()->relationLoaded('repoProfile'));
        $this->assertSame($profile->id, $result->first()->repoProfile->id);
    }

    public function test_find_by_name(): void
    {
        $task = Task::factory()->create(['name' => 'search-me']);

        $found = $this->service->find('search-me');

        $this->assertNotNull($found);
        $this->assertSame($task->id, $found->id);
    }

    public function test_find_by_id(): void
    {
        $task = Task::factory()->create();

        $found = $this->service->find($task->id);

        $this->assertNotNull($found);
        $this->assertSame($task->id, $found->id);
    }

    public function test_find_returns_null_for_unknown(): void
    {
        $this->assertNull($this->service->find('nonexistent'));
    }

    public function test_delete_removes_task_from_database(): void
    {
        $task = Task::factory()->create();

        $this->service->delete($task);

        $this->assertDatabaseMissing(Task::class, ['id' => $task->id]);
    }
}
