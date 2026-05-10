<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Resources\WorkerStackResource;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\CreateWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\EditWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ListWorkerStacks;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ViewWorkerStack;
use App\Jobs\BuildWorkerImageJob;
use App\Models\User;
use App\Models\WorkerStack;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkerStackResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders(): void
    {
        WorkerStack::factory()->count(2)->create();
        WorkerStack::factory()->builtin()->create(['name' => 'php-8.4']);

        Livewire::test(ListWorkerStacks::class)
            ->assertSuccessful();
    }

    public function test_create_form_persists_user_stack(): void
    {
        Livewire::test(CreateWorkerStack::class)
            ->fillForm([
                'name' => 'rust-stable',
                'label' => 'Rust stable',
                'base_image' => 'rust:1.85-slim',
                'capabilities' => ['rust', 'cargo'],
                'common_tools' => ['git', 'curl'],
                'status' => WorkerImageEntityStatus::Active->value,
                'dockerfile_body' => "FROM rust:1.85-slim\nRUN apt-get update\n",
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $stack = WorkerStack::query()->where('name', 'rust-stable')->first();
        $this->assertNotNull($stack);
        $this->assertFalse($stack->is_builtin);
        $this->assertSame(['rust', 'cargo'], $stack->capabilities);
    }

    public function test_edit_page_blocks_built_in(): void
    {
        $stack = WorkerStack::factory()->builtin()->create();

        $this->assertFalse(WorkerStackResource::canEdit($stack));
    }

    public function test_edit_page_allows_user_stack(): void
    {
        $stack = WorkerStack::factory()->create();

        Livewire::test(EditWorkerStack::class, ['record' => $stack->getKey()])
            ->fillForm(['label' => 'Updated label'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated label', $stack->fresh()->label);
    }

    public function test_view_page_renders_for_built_in(): void
    {
        $stack = WorkerStack::factory()->builtin()->create();

        Livewire::test(ViewWorkerStack::class, ['record' => $stack->getKey()])
            ->assertSuccessful();
    }

    public function test_create_dispatches_build_jobs_for_compatible_agents(): void
    {
        Queue::fake();

        Livewire::test(CreateWorkerStack::class)
            ->fillForm([
                'name' => 'rust-stable',
                'label' => 'Rust stable',
                'base_image' => 'rust:1.85-slim',
                // node => compatible with claude-code AND codex (both registered)
                'capabilities' => ['rust', 'cargo', 'node'],
                'common_tools' => ['git'],
                'status' => WorkerImageEntityStatus::Active->value,
                'dockerfile_body' => "FROM rust:1.85-slim\n",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Queue::assertPushed(BuildWorkerImageJob::class, 2);
    }

    public function test_create_skips_dispatch_for_incompatible_stack(): void
    {
        Queue::fake();

        Livewire::test(CreateWorkerStack::class)
            ->fillForm([
                'name' => 'python-only',
                'label' => 'Python only',
                'base_image' => 'python:3.12-slim',
                // No 'node' → claude-code/codex are both incompatible
                'capabilities' => ['python', 'pip'],
                'common_tools' => ['git'],
                'status' => WorkerImageEntityStatus::Active->value,
                'dockerfile_body' => "FROM python:3.12-slim\n",
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(BuildWorkerImageJob::class);
    }

    public function test_edit_dispatches_build_only_when_dockerfile_changed(): void
    {
        Queue::fake();

        $stack = WorkerStack::factory()->create([
            'capabilities' => ['node'],
            'dockerfile_body' => "FROM php:8.4\n",
        ]);

        // Label-only change: no rebuild needed
        Livewire::test(EditWorkerStack::class, ['record' => $stack->getKey()])
            ->fillForm(['label' => 'Updated label'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(BuildWorkerImageJob::class);

        // Dockerfile change: must dispatch
        Livewire::test(EditWorkerStack::class, ['record' => $stack->getKey()])
            ->fillForm(['dockerfile_body' => "FROM php:8.4\nRUN apt-get install jq\n"])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(BuildWorkerImageJob::class);
    }

    public function test_duplicate_action_clones_built_in_into_user_stack(): void
    {
        Queue::fake();
        $original = WorkerStack::factory()->builtin()->create([
            'name' => 'php-8.4',
            'label' => 'PHP 8.4',
            'dockerfile_body' => "FROM php:8.4\n",
            'capabilities' => ['php', 'node'],
        ]);

        Livewire::test(ListWorkerStacks::class)
            ->callAction(TestAction::make('duplicate')->table($original))
            ->assertNotified();

        $copy = WorkerStack::query()
            ->where('name', 'php-8.4-copy')
            ->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_builtin);
        $this->assertSame("FROM php:8.4\n", $copy->dockerfile_body);
        $this->assertSame(['php', 'node'], $copy->capabilities);
        $this->assertStringContainsString('Kopie', $copy->label);
    }

    public function test_duplicate_action_avoids_name_collision(): void
    {
        $original = WorkerStack::factory()->builtin()->create(['name' => 'php-8.4']);
        // Pre-existing user stack at the natural -copy slot
        WorkerStack::factory()->create(['name' => 'php-8.4-copy']);

        Livewire::test(ListWorkerStacks::class)
            ->callAction(TestAction::make('duplicate')->table($original));

        // Falls through to -copy-2 — original and -copy already taken.
        $this->assertTrue(WorkerStack::query()->where('name', 'php-8.4-copy-2')->exists());
    }
}
