<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Phase\StateReader;
use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StateReaderTest extends TestCase
{
    use RefreshDatabase;

    // --- read ---

    public function test_read_returns_null_when_process_fails(): void
    {
        $reader = $this->readerWithProcess(successful: false, output: '');

        $this->assertNull($reader->read('my-task'));
    }

    public function test_read_returns_null_for_invalid_json(): void
    {
        $reader = $this->readerWithProcess(successful: true, output: 'not-json');

        $this->assertNull($reader->read('my-task'));
    }

    public function test_read_returns_parsed_state(): void
    {
        $state = ['phases' => ['concept' => ['current_status' => 'completed']]];
        $reader = $this->readerWithProcess(successful: true, output: json_encode($state));

        $result = $reader->read('my-task');

        $this->assertSame('completed', $result['phases']['concept']['current_status']);
    }

    // --- readConcept ---

    public function test_read_concept_returns_null_when_process_fails(): void
    {
        $reader = $this->readerWithProcess(successful: false, output: '');

        $this->assertNull($reader->readConcept('my-task'));
    }

    public function test_read_concept_returns_null_for_empty_output(): void
    {
        $reader = $this->readerWithProcess(successful: true, output: '');

        $this->assertNull($reader->readConcept('my-task'));
    }

    public function test_read_concept_returns_content(): void
    {
        $reader = $this->readerWithProcess(successful: true, output: '# My Concept');

        $this->assertSame('# My Concept', $reader->readConcept('my-task'));
    }

    // --- readNotes ---

    public function test_read_notes_returns_null_when_process_fails(): void
    {
        $reader = $this->readerWithProcess(successful: false, output: '');

        $this->assertNull($reader->readNotes('my-task'));
    }

    public function test_read_notes_returns_content(): void
    {
        $reader = $this->readerWithProcess(successful: true, output: 'my note');

        $this->assertSame('my note', $reader->readNotes('my-task'));
    }

    // --- writeNotes ---

    public function test_write_notes_returns_true_on_success(): void
    {
        $reader = $this->readerWithProcess(successful: true, output: '');

        $this->assertTrue($reader->writeNotes('my-task', 'content'));
    }

    public function test_write_notes_returns_false_on_failure(): void
    {
        $reader = $this->readerWithProcess(successful: false, output: '');

        $this->assertFalse($reader->writeNotes('my-task', 'content'));
    }

    // --- getPhaseStatus ---

    public function test_get_phase_status_extracts_from_state(): void
    {
        $state = ['phases' => ['concept' => ['current_status' => 'completed']]];
        $reader = $this->readerWithProcess(successful: true, output: json_encode($state));

        $this->assertSame('completed', $reader->getPhaseStatus('my-task', 'concept'));
    }

    public function test_get_phase_status_returns_null_for_missing_phase(): void
    {
        $state = ['phases' => []];
        $reader = $this->readerWithProcess(successful: true, output: json_encode($state));

        $this->assertNull($reader->getPhaseStatus('my-task', 'concept'));
    }

    // --- syncToDb ---

    public function test_sync_to_db_does_nothing_when_state_is_null(): void
    {
        $task = Task::factory()->create();
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->andReturn(null);
        });

        $reader->syncToDb($task);

        // Task unchanged
        $this->assertNull($task->fresh()->current_phase);
    }

    public function test_sync_to_db_updates_running_phase_run_to_completed(): void
    {
        $task = Task::factory()->create();
        $run = PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        $state = [
            'phases' => ['concept' => ['current_status' => 'completed']],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_sync_to_db_does_not_overwrite_already_finished_phase_runs(): void
    {
        $task = Task::factory()->create();
        $run = PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'status' => 'failed',
        ]);

        $state = [
            'phases' => ['concept' => ['current_status' => 'completed']],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        // Already-finished runs are untouched (query filters status = 'running')
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_sync_to_db_updates_task_current_phase_and_status(): void
    {
        $task = Task::factory()->create();
        $state = [
            'phases' => [
                'concept' => ['current_status' => 'completed'],
                'implement' => ['current_status' => 'running'],
            ],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        $fresh = $task->fresh();
        $this->assertSame('implement', $fresh->current_phase);
        $this->assertSame('running', $fresh->current_status);
    }

    public function test_sync_to_db_updates_feature_branch(): void
    {
        $task = Task::factory()->create(['feature_branch' => null]);
        $state = [
            'phases' => ['concept' => ['current_status' => 'completed']],
            'repo' => ['feature_branch' => 'feature/my-task'],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        $this->assertSame('feature/my-task', $task->fresh()->feature_branch);
    }

    public function test_sync_to_db_extracts_pr_url_from_repo_state(): void
    {
        $task = Task::factory()->create(['pr_url' => null]);
        $state = [
            'phases' => ['push' => ['current_status' => 'completed', 'iterations' => []]],
            'repo' => ['pr_url' => 'https://github.com/org/repo/pull/42'],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        $this->assertSame('https://github.com/org/repo/pull/42', $task->fresh()->pr_url);
    }

    public function test_sync_to_db_does_not_overwrite_existing_pr_url(): void
    {
        $task = Task::factory()->create(['pr_url' => 'https://github.com/org/repo/pull/1']);
        $state = [
            'phases' => [],
            'repo' => ['pr_url' => 'https://github.com/org/repo/pull/1'],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        // Same URL → no update needed, still the original
        $this->assertSame('https://github.com/org/repo/pull/1', $task->fresh()->pr_url);
    }

    public function test_sync_to_db_skips_pending_phases(): void
    {
        $task = Task::factory()->create();
        $state = [
            'phases' => [
                'concept' => ['current_status' => 'pending'],
                'implement' => ['current_status' => 'completed'],
            ],
        ];
        $reader = $this->partialMock(StateReader::class, function (MockInterface $mock) use ($state): void {
            $mock->shouldReceive('read')->andReturn($state);
        });

        $reader->syncToDb($task);

        // implement completed is the last non-pending
        $this->assertSame('implement', $task->fresh()->current_phase);
    }

    // --- helpers ---

    private function readerWithProcess(bool $successful, string $output): StateReader
    {
        $processMock = \Mockery::mock(Process::class);
        $processMock->shouldReceive('setTimeout')->andReturnSelf();
        $processMock->shouldReceive('setEnv')->andReturnSelf();
        $processMock->shouldReceive('run')->andReturn(0);
        $processMock->shouldReceive('isSuccessful')->andReturn($successful);
        $processMock->shouldReceive('getOutput')->andReturn($output);

        return $this->partialMock(StateReader::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
        });
    }
}
