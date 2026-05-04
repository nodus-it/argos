<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Task;
use PHPUnit\Framework\TestCase;

class TaskSlugifyNameTest extends TestCase
{
    public function test_spaces_are_replaced_with_underscore(): void
    {
        $this->assertSame('hello_world', Task::slugifyName('hello world'));
    }

    public function test_multiple_spaces_each_become_underscore(): void
    {
        $this->assertSame('a_b_c', Task::slugifyName('a b c'));
    }

    public function test_slashes_and_special_chars_are_replaced(): void
    {
        $this->assertSame('feat_my-feature', Task::slugifyName('feat/my-feature'));
        $this->assertSame('hello__world_', Task::slugifyName('hello! world?'));
    }

    public function test_valid_chars_are_preserved(): void
    {
        $this->assertSame('abc-123_foo.bar', Task::slugifyName('abc-123_foo.bar'));
    }

    public function test_volume_name_uses_slugified_task_name(): void
    {
        $task = new Task(['name' => 'My Feature Task']);
        $this->assertSame('task_ws_My_Feature_Task', $task->volumeName());
    }

    public function test_volume_name_prefix_is_always_present(): void
    {
        $task = new Task(['name' => 'simple']);
        $this->assertStringStartsWith('task_ws_', $task->volumeName());
    }
}
