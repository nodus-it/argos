<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StateReader;
use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StateReaderTest extends TestCase
{
    use RefreshDatabase;

    // --- read (still uses Docker, kept for CLI/diagnostics) ---

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

    // --- readConcept (DB) ---

    public function test_read_concept_returns_null_when_task_has_no_concept(): void
    {
        $task = Task::factory()->create(['concept_md' => null]);
        $reader = app(StateReader::class);

        $this->assertNull($reader->readConcept($task));
    }

    public function test_read_concept_returns_concept_from_db(): void
    {
        $task = Task::factory()->create(['concept_md' => '# My Concept']);
        $reader = app(StateReader::class);

        $this->assertSame('# My Concept', $reader->readConcept($task));
    }

    // --- readNotes (DB) ---

    public function test_read_notes_returns_null_when_empty(): void
    {
        $task = Task::factory()->create(['concept_notes' => null]);
        $reader = app(StateReader::class);

        $this->assertNull($reader->readNotes($task));
    }

    public function test_read_notes_returns_notes_from_db(): void
    {
        $task = Task::factory()->create(['concept_notes' => 'my note']);
        $reader = app(StateReader::class);

        $this->assertSame('my note', $reader->readNotes($task));
    }

    // --- writeNotes (DB) ---

    public function test_write_notes_saves_to_db(): void
    {
        $task = Task::factory()->create(['concept_notes' => null]);
        $reader = app(StateReader::class);

        $result = $reader->writeNotes($task, 'new feedback');

        $this->assertTrue($result);
        $this->assertSame('new feedback', $task->fresh()->concept_notes);
    }

    public function test_write_notes_clears_on_empty_string(): void
    {
        $task = Task::factory()->create(['concept_notes' => 'old notes']);
        $reader = app(StateReader::class);

        $reader->writeNotes($task, '');

        $this->assertNull($task->fresh()->concept_notes);
    }

    // --- readNotesHistory (DB) ---

    public function test_read_notes_history_returns_empty_when_no_runs(): void
    {
        $task = Task::factory()->create();
        $reader = app(StateReader::class);

        $this->assertSame([], $reader->readNotesHistory($task));
    }

    public function test_read_notes_history_returns_concept_runs_with_notes(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'concept_notes' => 'first feedback',
            'finished_at' => now()->subDay(),
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 2,
            'status' => 'completed',
            'concept_notes' => null,
        ]);
        $reader = app(StateReader::class);

        $history = $reader->readNotesHistory($task);

        $this->assertCount(1, $history);
        $this->assertSame('first feedback', $history[0]['content']);
        $this->assertStringContainsString('Iteration 1', $history[0]['timestamp']);
    }

    // --- readConceptHistory (DB) ---

    public function test_read_concept_history_excludes_current_iteration(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'concept_md' => '# v1',
            'finished_at' => now()->subDay(),
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 2,
            'status' => 'completed',
            'concept_md' => '# v2',
            'finished_at' => now(),
        ]);
        $reader = app(StateReader::class);

        $history = $reader->readConceptHistory($task, currentIteration: 2);

        $this->assertCount(1, $history);
        $this->assertSame('# v1', $history[0]['content']);
    }

    // --- listLogIterations (DB) ---

    public function test_list_log_iterations_returns_only_runs_with_stream_log(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'completed',
            'stream_log' => '{"type":"result"}',
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 2,
            'status' => 'completed',
            'stream_log' => null,
        ]);
        $reader = app(StateReader::class);

        $iters = $reader->listLogIterations($task, 'implement');

        $this->assertSame([1], $iters);
    }

    // --- readStreamLogIteration (DB) ---

    public function test_read_stream_log_returns_empty_for_missing_run(): void
    {
        $task = Task::factory()->create();
        $reader = app(StateReader::class);

        $this->assertSame([], $reader->readStreamLogIteration($task, 'implement', 1));
    }

    public function test_read_stream_log_parses_assistant_text_events(): void
    {
        $task = Task::factory()->create();
        $log = json_encode([
            'type' => 'assistant',
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello from Claude'],
                ],
            ],
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'completed',
            'stream_log' => $log,
        ]);
        $reader = app(StateReader::class);

        $lines = $reader->readStreamLogIteration($task, 'implement', 1);

        $this->assertCount(1, $lines);
        $this->assertSame('Hello from Claude', $lines[0]['text']);
        $this->assertSame('text-slate-300', $lines[0]['class']);
    }

    // --- syncToDb (DB-only) ---

    public function test_sync_to_db_marks_stuck_running_runs_as_failed(): void
    {
        $task = Task::factory()->create();
        $run = PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'status' => 'running',
            'started_at' => now()->subHours(3),
        ]);
        $reader = app(StateReader::class);

        $reader->syncToDb($task);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_sync_to_db_does_not_touch_recently_started_runs(): void
    {
        $task = Task::factory()->create();
        $run = PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'status' => 'running',
            'started_at' => now()->subMinutes(10),
        ]);
        $reader = app(StateReader::class);

        $reader->syncToDb($task);

        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_sync_to_db_syncs_pr_url_from_push_result_json(): void
    {
        $task = Task::factory()->create(['pr_url' => null]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'push',
            'status' => 'completed',
            'result_json' => ['pr_url' => 'https://github.com/org/repo/pull/42', 'branch' => 'ai/my-task'],
        ]);
        $reader = app(StateReader::class);

        $reader->syncToDb($task);

        $this->assertSame('https://github.com/org/repo/pull/42', $task->fresh()->pr_url);
        $this->assertSame('ai/my-task', $task->fresh()->feature_branch);
    }

    public function test_sync_to_db_does_not_overwrite_existing_pr_url(): void
    {
        $task = Task::factory()->create(['pr_url' => 'https://github.com/org/repo/pull/1']);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'push',
            'status' => 'completed',
            'result_json' => ['pr_url' => 'https://github.com/org/repo/pull/1'],
        ]);
        $reader = app(StateReader::class);

        $reader->syncToDb($task);

        $this->assertSame('https://github.com/org/repo/pull/1', $task->fresh()->pr_url);
    }

    // --- helpers ---

    private function readerWithProcess(bool $successful, string $output): StateReader
    {
        $processMock = \Mockery::mock(Process::class);
        $processMock->shouldReceive('setTimeout')->andReturnSelf();
        $processMock->shouldReceive('run')->andReturn(0);
        $processMock->shouldReceive('isSuccessful')->andReturn($successful);
        $processMock->shouldReceive('getOutput')->andReturn($output);

        return $this->partialMock(StateReader::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
        });
    }
}
