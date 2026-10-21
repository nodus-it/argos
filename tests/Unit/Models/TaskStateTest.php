<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AutoTaskState;
use App\Enums\SessionStatus;
use App\Enums\TaskState;
use App\Enums\TaskStatus;
use App\Models\Task;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskStateTest extends TestCase
{
    /**
     * @return array<string, array{0: TaskStatus, 1: SessionStatus, 2: AutoTaskState|null, 3: bool, 4: TaskState}>
     */
    public static function cases(): array
    {
        return [
            'preparing' => [TaskStatus::Preparing, SessionStatus::Closed, null, false, TaskState::Preparing],
            'idle is ready' => [TaskStatus::Active, SessionStatus::Idle, null, false, TaskState::Ready],
            'closed is ready' => [TaskStatus::Active, SessionStatus::Closed, null, false, TaskState::Ready],
            'running' => [TaskStatus::Active, SessionStatus::Running, null, false, TaskState::Running],
            'starting' => [TaskStatus::Active, SessionStatus::Starting, null, false, TaskState::Running],
            'waiting for input' => [TaskStatus::Active, SessionStatus::WaitingInput, null, false, TaskState::Waiting],
            'plan waits' => [TaskStatus::Active, SessionStatus::Idle, AutoTaskState::AwaitingPlanApproval, false, TaskState::Waiting],
            'auto run between turns' => [TaskStatus::Active, SessionStatus::Idle, AutoTaskState::Gating, false, TaskState::Running],
            'pr open' => [TaskStatus::PrOpen, SessionStatus::Closed, null, false, TaskState::PrOpen],
            'pr open but working' => [TaskStatus::PrOpen, SessionStatus::Running, null, false, TaskState::Running],
            'done' => [TaskStatus::Done, SessionStatus::Closed, null, false, TaskState::Done],
            'cancelled' => [TaskStatus::Cancelled, SessionStatus::Closed, null, false, TaskState::Cancelled],
            'archived wins' => [TaskStatus::Active, SessionStatus::Running, null, true, TaskState::Archived],
        ];
    }

    #[DataProvider('cases')]
    public function test_state_combines_status_session_and_archive(TaskStatus $status, SessionStatus $session, ?AutoTaskState $auto, bool $archived, TaskState $expected): void
    {
        $task = new Task;
        $task->forceFill([
            'status' => $status,
            'session_status' => $session,
            'auto_state' => $auto,
            'archived_at' => $archived ? now() : null,
        ]);

        $this->assertSame($expected, $task->state());
    }

    public function test_groups_follow_the_switcher_order(): void
    {
        $this->assertSame(['waiting', 'running', 'ready', 'pr_open', 'done'], TaskState::groups());
        $this->assertSame('done', TaskState::Cancelled->group());
        $this->assertSame('running', TaskState::Preparing->group());
    }
}
