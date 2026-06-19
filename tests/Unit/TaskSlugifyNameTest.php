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

    // ── slugifyForBranch (mirrors the worker's _concept_branch_slug) ──────────

    public function test_branch_slug_replaces_space_and_slash_with_hyphen(): void
    {
        $this->assertSame('feat-my-feature', Task::slugifyForBranch('feat/my feature'));
    }

    public function test_branch_slug_transliterates_umlauts(): void
    {
        $this->assertSame('Ueber-Strasse-Oel', Task::slugifyForBranch('Über Straße Öl'));
    }

    public function test_branch_slug_strips_disallowed_chars_and_keeps_dot_dash_underscore(): void
    {
        $this->assertSame('Fix-a-bc_d.e-f', Task::slugifyForBranch('Fix: (a) b@c_d.e-f!'));
    }

    public function test_branch_slug_trims_leading_and_trailing_separators(): void
    {
        $this->assertSame('clean', Task::slugifyForBranch('  -clean-  '));
    }

    // ── volumeName is keyed by the frozen slug ───────────────────────────────

    public function test_volume_name_uses_the_slug(): void
    {
        $task = new Task(['slug' => 'my-feature-task']);
        $this->assertSame('task_ws_my-feature-task', $task->volumeName());
    }

    public function test_volume_name_prefix_is_always_present(): void
    {
        $task = new Task(['slug' => 'simple']);
        $this->assertStringStartsWith('task_ws_', $task->volumeName());
    }
}
