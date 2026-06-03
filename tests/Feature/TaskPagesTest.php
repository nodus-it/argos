<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewQualityGateLog;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskConcept;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskDiff;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskLogs;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskRespond;
use App\Jobs\DeployDemoJob;
use App\Jobs\RunPhaseJob;
use App\Jobs\StopDemoJob;
use App\Models\Demo;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\StateReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class TaskPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Bus::fake();
        Process::fake();

        $this->mock(StateReader::class, function ($mock) {
            $mock->shouldReceive('syncToDb')->andReturn(null);
            $mock->shouldReceive('readNotesHistory')->andReturn([]);
            $mock->shouldReceive('readConceptHistory')->andReturn([]);
            $mock->shouldReceive('readImplementHistory')->andReturn([]);
            $mock->shouldReceive('readImplementNotesHistory')->andReturn([]);
            $mock->shouldReceive('listLogIterations')->andReturn([]);
        });

        // PhaseRunner uses Symfony\Process directly (docker run …), which
        // Laravel's Process::fake() does not intercept. On hosts without a
        // docker socket — e.g. the worker container that runs phpunit during
        // quality gates — the real exec fails. Stub the methods that the
        // pages call so the tests check Filament behaviour, not Process I/O.
        $this->mock(PhaseRunner::class, function ($mock) {
            $mock->shouldReceive('writeFeedbackToVolume');
            $mock->shouldIgnoreMissing();
        });
    }

    // ── ViewTask ─────────────────────────────────────────────────────────────

    public function test_view_task_renders(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee($task->name);
    }

    public function test_view_task_shows_workflow_badge(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('In Review');
    }

    public function test_view_task_strips_outer_code_fence_from_legacy_concept_md(): void
    {
        // Pre-fix concept_md was persisted with the ```markdown wrapper that
        // some agent replies produce. Render-time strip heals these rows
        // without requiring a backfill migration. The thread renders the
        // concept from the phase_run, so attach it there.
        $task = Task::factory()->conceptReady()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'status' => 'completed',
            'concept_md' => "```markdown\n# Konzept: Foo\n\nBody.\n```",
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            // Heading should render as <h1>, not as plain text inside <pre><code>.
            ->assertSeeHtml('<h1>Konzept: Foo</h1>')
            ->assertDontSeeHtml('<pre><code class="language-markdown">');
    }

    public function test_view_task_concept_action_dispatches_job(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('concept')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept');
    }

    public function test_view_task_implement_action_dispatches_job(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'concept', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('implement')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement');
    }

    public function test_view_task_implement_action_sets_workflow_status_to_implement_running(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'concept', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('implement')
            ->assertNotified();

        $this->assertEquals(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_view_task_push_action_dispatches_job(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('push')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'push');
    }

    public function test_view_task_shows_live_demo_card(): void
    {
        $task = Task::factory()->create();
        Demo::factory()->live()->create([
            'task_id' => $task->id,
            'url' => 'http://demo-abc.127.0.0.1.nip.io:8080',
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('http://demo-abc.127.0.0.1.nip.io:8080');
    }

    public function test_view_task_rebuild_demo_action_dispatches_job(): void
    {
        config(['argos.preview.enabled' => true]);
        $profile = RepoProfile::factory()->create(['live_demo_enabled' => true]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('rebuildDemo')
            ->assertNotified();

        Bus::assertDispatched(DeployDemoJob::class, fn (DeployDemoJob $j): bool => $j->taskId === $task->id);
    }

    public function test_view_task_survives_a_failing_diff_load(): void
    {
        // The diff loads lazily via `docker run` when the user opens the panel.
        // If that times out or errors, it must degrade to an inline notice and
        // never 500 — including not re-escalating through a failing log write.
        Process::fake(function (): void {
            throw new \RuntimeException('docker run timed out');
        });

        $task = Task::factory()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->call('loadDiff')
            ->assertSet('diffLoaded', true)
            ->assertSet('diffError', __('tasks.view.diff.error'));
    }

    public function test_view_task_does_not_auto_load_diff_on_mount(): void
    {
        // Auto-loading the diff on mount made the page wait on a 15s docker
        // timeout for tasks with a slow/polluted workspace. It must stay lazy.
        Process::fake(function (): void {
            throw new \RuntimeException('docker must not run on mount');
        });

        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed', 'iteration' => 1,
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSet('diffLoaded', false);

        Process::assertNothingRan();
    }

    public function test_rebuild_demo_hidden_when_preview_disabled(): void
    {
        config(['argos.preview.enabled' => false]);
        $profile = RepoProfile::factory()->create(['live_demo_enabled' => true]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('rebuildDemo');
    }

    public function test_view_task_stop_demo_action_dispatches_job(): void
    {
        $task = Task::factory()->create();
        Demo::factory()->live()->create(['task_id' => $task->id]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('stopDemo')
            ->assertNotified();

        Bus::assertDispatched(StopDemoJob::class, fn (StopDemoJob $j): bool => $j->taskId === $task->id);
    }

    public function test_stop_demo_hidden_without_running_demo(): void
    {
        $task = Task::factory()->create();
        Demo::factory()->failed()->create(['task_id' => $task->id]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('stopDemo');
    }

    public function test_view_task_mark_completed_action(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('markCompleted')
            ->assertNotified();

        $this->assertEquals(WorkflowStatus::Completed, $task->fresh()->workflow_status);
    }

    public function test_mark_completed_hidden_when_already_completed(): void
    {
        $task = Task::factory()->completed()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('markCompleted');
    }

    public function test_mark_completed_visible_only_when_not_completed(): void
    {
        $incomplete = Task::factory()->inReview()->create();
        $complete = Task::factory()->completed()->create();

        Livewire::test(ViewTask::class, ['record' => $incomplete->getKey()])
            ->assertActionVisible('markCompleted');

        Livewire::test(ViewTask::class, ['record' => $complete->getKey()])
            ->assertActionHidden('markCompleted');
    }

    public function test_logs_download_action_links_to_logs_page(): void
    {
        $task = Task::factory()->create();

        $expectedUrl = TaskResource::getUrl('logs', ['record' => $task]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionVisible('logsDownload')
            ->assertActionHasUrl('logsDownload', $expectedUrl);
    }

    public function test_continue_action_visible_only_when_implement_paused(): void
    {
        $running = Task::factory()->create();
        PhaseRun::factory()->paused()->create(['task_id' => $running->id, 'phase' => 'implement']);

        $other = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $other->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $running->getKey()])
            ->assertActionVisible('continueImplement');

        Livewire::test(ViewTask::class, ['record' => $other->getKey()])
            ->assertActionHidden('continueImplement');
    }

    public function test_continue_action_dispatches_job_with_continue_and_max_turns(): void
    {
        $task = Task::factory()->create(['max_turns_implement' => 250]);
        PhaseRun::factory()->paused()->create(['task_id' => $task->id, 'phase' => 'implement']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('continueImplement', ['max_turns' => 300])
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement'
            && $j->flags === ['continue' => true, 'max_turns' => 300]);
    }

    public function test_continue_concept_action_visible_only_when_concept_paused(): void
    {
        $paused = Task::factory()->create();
        PhaseRun::factory()->paused()->create(['task_id' => $paused->id, 'phase' => 'concept']);

        $completed = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $completed->id, 'phase' => 'concept', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $paused->getKey()])
            ->assertActionVisible('continueConcept');

        Livewire::test(ViewTask::class, ['record' => $completed->getKey()])
            ->assertActionHidden('continueConcept');
    }

    public function test_continue_concept_action_dispatches_job_with_continue_and_max_turns(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->paused()->create(['task_id' => $task->id, 'phase' => 'concept']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('continueConcept', ['max_turns' => 45])
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept'
            && $j->flags === ['continue' => true, 'max_turns' => 45]);
    }

    public function test_paused_banner_renders_for_paused_implement_run(): void
    {
        // Realistic persisted state for a paused implement run: PhaseRunner
        // promotes the task to ImplementPaused (afterPhase implement+Paused).
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementPaused,
            'current_phase' => 'implement',
            'current_status' => 'paused',
        ]);
        PhaseRun::factory()->paused()->create(['task_id' => $task->id, 'phase' => 'implement']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Implementation paused')
            ->assertSee('turn limit');
    }

    public function test_phase_action_warns_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('concept')
            ->assertNotified();

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    // ── ViewTaskConcept ───────────────────────────────────────────────────────

    public function test_concept_page_renders_with_markdown(): void
    {
        $task = Task::factory()->conceptReady()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Test-Konzept Inhalt.');
    }

    public function test_concept_page_start_implement_dispatches_job(): void
    {
        $task = Task::factory()->conceptReady()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->call('startImplement')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement');
        $this->assertEquals(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_concept_page_save_notes(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->call('startEditingNotes')
            ->set('notes', 'Meine Anmerkung')
            ->call('saveNotes')
            ->assertNotified();
    }

    public function test_concept_page_cancel_notes(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->call('startEditingNotes')
            ->set('notes', 'wird verworfen')
            ->call('cancelEditingNotes')
            ->assertSet('editingNotes', false);
    }

    public function test_concept_page_run_concept_again(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->callAction('runConcept')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept');
    }

    // ── ViewTaskLogs ─────────────────────────────────────────────────────────

    public function test_logs_page_renders(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskLogs::class, ['record' => $task->getKey()])
            ->assertSuccessful();
    }

    // ── ViewTaskDiff ─────────────────────────────────────────────────────────

    public function test_diff_page_renders(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskDiff::class, ['record' => $task->getKey()])
            ->assertSuccessful();
    }

    // ── ViewTaskRespond ───────────────────────────────────────────────────────

    public function test_respond_page_renders(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTaskRespond::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Review Feedback');
    }

    public function test_respond_submit_feedback_dispatches_job(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTaskRespond::class, ['record' => $task->getKey()])
            ->set('feedback', 'Bitte Methode umbenennen.')
            ->call('submitFeedback')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'respond');
    }

    public function test_respond_rejects_empty_feedback(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTaskRespond::class, ['record' => $task->getKey()])
            ->set('feedback', '')
            ->call('submitFeedback')
            ->assertNotified();

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    // ── ViewQualityGateLog ──────────────────────────────────────────────────

    public function test_quality_gate_log_page_renders_empty_when_no_logs(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSet('availableKeys', [])
            ->assertSee(__('tasks.view.quality_gate_log.no_logs'));
    }

    public function test_quality_gate_log_page_lists_available_keys(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'quality_gate_logs' => [
                'pest' => 'pest initial fail',
                'pest.fix1' => 'pest fix1 fail',
                'phpstan' => 'phpstan errors',
            ],
        ]);

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSet('availableKeys', ['pest', 'pest.fix1', 'phpstan'])
            ->assertSee('PEST');
    }

    public function test_quality_gate_log_page_defaults_to_failed_gate_last_fix(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'result_json' => ['failed_gate' => 'pest'],
            'quality_gate_logs' => [
                'pest' => 'initial output',
                'pest.fix1' => 'fix1 output',
                'pest.fix2' => 'fix2 output FINAL',
                'phpstan' => 'phpstan output',
            ],
        ]);

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->assertSet('activeKey', 'pest.fix2')
            ->assertSet('logContent', 'fix2 output FINAL');
    }

    public function test_quality_gate_log_page_selects_key_via_action(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'quality_gate_logs' => [
                'pest' => 'pest output',
                'phpstan' => 'phpstan output FINAL',
            ],
        ]);

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->call('selectKey', 'phpstan')
            ->assertSet('activeKey', 'phpstan')
            ->assertSet('logContent', 'phpstan output FINAL');
    }

    public function test_quality_gate_log_page_switches_phase(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'quality_gate_logs' => ['pest' => 'implement pest'],
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'respond',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'quality_gate_logs' => ['pest' => 'respond pest'],
        ]);

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->call('switchPhase', 'respond')
            ->assertSet('phase', 'respond')
            ->assertSet('logContent', 'respond pest');
    }

    public function test_quality_gate_log_page_honors_query_string_key(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'result_json' => ['failed_gate' => 'pest'],
            'quality_gate_logs' => [
                'pest' => 'initial',
                'pest.fix1' => 'fix1',
            ],
        ]);

        request()->query->set('key', 'pest.fix1');

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->assertSet('activeKey', 'pest.fix1')
            ->assertSet('logContent', 'fix1');
    }

    public function test_quality_gate_log_page_ignores_unknown_query_key(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'quality_gate_logs' => ['pest' => 'pest only'],
        ]);

        request()->query->set('key', 'bogus');

        Livewire::test(ViewQualityGateLog::class, ['record' => $task->getKey()])
            ->assertSet('activeKey', 'pest');
    }

    public function test_view_task_renders_clickable_link_for_failed_gate_with_log(): void
    {
        $task = Task::factory()->create([
            'implement_summary_technical' => 'Technical summary body.',
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'quality_gate_failed',
            'result_json' => [
                'quality_gates' => ['pest' => 'fail', 'phpstan' => 'pass'],
                'failed_gate' => 'pest',
            ],
            'quality_gate_logs' => [
                'pest' => 'initial fail',
                'pest.fix1' => 'fix1 fail',
            ],
        ]);

        $expectedUrl = TaskResource::getUrl('quality-gates', [
            'record' => $task,
            'phase' => 'implement',
            'key' => 'pest.fix1',
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSeeHtml('href="'.e($expectedUrl).'"');
    }
}
