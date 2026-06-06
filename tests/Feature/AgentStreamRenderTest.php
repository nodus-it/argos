<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskLogs;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verifies the CLI-near agent-stream rendering is actually wired into the
 * embedding pages — both the stored transcript (DB stream_log → ViewTask) and
 * the live tail (.bg.log → ViewTaskLogs). The `as-tool` / `as-think` classes
 * only exist in the new <x-argos.agent-stream> component, so seeing them proves
 * the component renders, not just that the parser returns data.
 */
final class AgentStreamRenderTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->tmpDir = sys_get_temp_dir().'/argos-stream-'.uniqid();
        config(['argos.config_dir' => $this->tmpDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    private function transcript(): string
    {
        return implode("\n", [
            (string) json_encode(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'thinking', 'thinking' => 'Considering the routes file'],
            ]]]),
            (string) json_encode(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'tool_use', 'name' => 'Read', 'id' => 't1', 'input' => ['file_path' => 'app/routes.php']],
            ]]]),
            (string) json_encode(['type' => 'user', 'message' => ['content' => [
                ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '42 lines read'],
            ]]]),
        ]);
    }

    public function test_stored_transcript_renders_agent_stream_blocks_on_view_task(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptReview,
            'current_phase' => 'concept',
            'current_status' => 'completed',
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'concept_md' => "# Concept v1\n\nDraft.",
            'stream_log' => $this->transcript(),
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->call('loadLogIteration', 'concept', 1)
            ->assertSeeHtml('as-tool')
            ->assertSeeHtml('as-think')
            ->assertSee('Read')
            ->assertSee('app/routes.php')
            ->assertSee('Considering the routes file');
    }

    public function test_live_bg_log_renders_agent_stream_blocks_on_logs_page(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_phase' => 'implement',
            'current_status' => 'running',
        ]);

        $logDir = "{$this->tmpDir}/tasks/{$task->name}";
        mkdir($logDir, 0755, true);
        // .bg.log mixes worker bash logs (argos) with the agent's JSON stream.
        file_put_contents(
            "{$logDir}/implement.bg.log",
            "[INFO] implement: calling agent\n".$this->transcript()."\n",
        );

        Livewire::test(ViewTaskLogs::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSeeHtml('as-argos')
            ->assertSeeHtml('as-tool')
            ->assertSee('implement: calling agent')
            ->assertSee('app/routes.php');
    }
}
