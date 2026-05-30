<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\TaskProviderKind;
use App\Models\ExternalIssueLink;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulateIssueWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Run the dispatched ProcessIncomingIssueJob inline so the command's
        // result is deterministic regardless of the configured queue driver.
        config(['queue.default' => 'sync']);
    }

    public function test_it_creates_a_task_from_a_simulated_github_issue(): void
    {
        $binding = TaskProviderBinding::factory()->webhook()->create([
            'repo_profile_id' => RepoProfile::factory()->create()->id,
            'kind' => TaskProviderKind::GitHub,
        ]);

        $this->artisan('argos:webhook:simulate', [
            'binding' => $binding->id,
            '--title' => 'Hello from simulator',
            '--id' => '4242',
        ])->assertSuccessful();

        $this->assertDatabaseHas('external_issue_links', [
            'task_provider_binding_id' => $binding->id,
            'external_id' => '4242',
        ]);
        $this->assertDatabaseHas('tasks', ['name' => 'Hello from simulator']);
    }

    public function test_it_rejects_a_binding_without_provider_kind_gracefully(): void
    {
        $this->artisan('argos:webhook:simulate', ['binding' => 999999])
            ->assertFailed();
    }

    public function test_label_filter_uses_or_semantics(): void
    {
        // OR semantics: an issue carrying at least one of the configured
        // labels is imported; an issue with none of them is filtered out.
        $binding = TaskProviderBinding::factory()->webhook()->create([
            'repo_profile_id' => RepoProfile::factory()->create()->id,
            'kind' => TaskProviderKind::GitHub,
            'filters' => ['labels' => ['argos', 'ready']],
        ]);

        // One of two configured labels is enough → task created.
        $this->artisan('argos:webhook:simulate', [
            'binding' => $binding->id,
            '--title' => 'One matching label',
            '--label' => ['argos'],
            '--id' => '555',
        ])->assertSuccessful();

        $oneMatch = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
            ->where('external_id', '555')->first();
        $this->assertNotNull($oneMatch);
        $this->assertNotNull($oneMatch->task_id, 'OR semantics: one configured label is enough to import');

        // None of the configured labels → filtered, link exists but no task.
        $this->artisan('argos:webhook:simulate', [
            'binding' => $binding->id,
            '--title' => 'No matching label',
            '--label' => ['unrelated'],
            '--id' => '556',
        ])->assertSuccessful();

        $noMatch = ExternalIssueLink::where('task_provider_binding_id', $binding->id)
            ->where('external_id', '556')->first();
        $this->assertNotNull($noMatch);
        $this->assertNull($noMatch->task_id, 'No configured label present → no task');

        // Only the matching issue produced a task.
        $this->assertSame(1, Task::count());
    }
}
