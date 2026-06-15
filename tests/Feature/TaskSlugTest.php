<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I3: `name` is a free, renameable display field; the frozen `slug` carries the
 * operational identity (volume, TASK_ID/branch, log paths).
 */
class TaskSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_is_auto_generated_from_name_on_create(): void
    {
        $task = Task::factory()->create(['name' => 'Fix the login bug']);

        $this->assertSame('Fix-the-login-bug', $task->slug);
    }

    public function test_explicit_slug_is_respected(): void
    {
        $task = Task::factory()->create(['name' => 'Whatever', 'slug' => 'custom-slug']);

        $this->assertSame('custom-slug', $task->slug);
    }

    public function test_duplicate_names_get_a_unique_slug_suffix(): void
    {
        $a = Task::factory()->create(['name' => 'Same name']);
        $b = Task::factory()->create(['name' => 'Same name']);

        $this->assertSame('Same-name', $a->slug);
        $this->assertSame('Same-name-2', $b->slug);
        $this->assertNotSame($a->slug, $b->slug);
    }

    public function test_rename_keeps_the_slug_and_volume_stable(): void
    {
        $task = Task::factory()->create(['name' => 'Original name']);
        $slug = $task->slug;
        $volume = $task->volumeName();

        $task->update(['name' => 'A completely different name']);
        $task->refresh();

        $this->assertSame($slug, $task->slug);
        $this->assertSame($volume, $task->volumeName());
        $this->assertSame('A completely different name', $task->name);
    }

    public function test_name_is_not_unique(): void
    {
        Task::factory()->create(['name' => 'Duplicate']);

        // No unique violation on the display name.
        $second = Task::factory()->create(['name' => 'Duplicate']);

        $this->assertSame('Duplicate', $second->name);
    }
}
