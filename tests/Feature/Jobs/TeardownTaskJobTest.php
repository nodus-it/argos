<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\TeardownTaskJob;
use App\Services\Task\TaskTeardown;
use Tests\TestCase;

class TeardownTaskJobTest extends TestCase
{
    public function test_handle_delegates_to_task_teardown_with_its_identifiers(): void
    {
        $teardown = $this->mock(TaskTeardown::class);
        $teardown->shouldReceive('purge')
            ->once()
            ->with('T1', 'task_ws_x', 'demo-x');

        (new TeardownTaskJob('T1', 'task_ws_x', 'demo-x'))->handle($teardown);
    }

    public function test_job_does_not_auto_retry(): void
    {
        $this->assertSame(1, (new TeardownTaskJob('T1', 'v', 's'))->tries);
    }
}
