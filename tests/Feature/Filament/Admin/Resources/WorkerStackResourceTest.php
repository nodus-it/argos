<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Resources\WorkerStackResource;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\CreateWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\EditWorkerStack;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ListWorkerStacks;
use App\Filament\Admin\Resources\WorkerStackResource\Pages\ViewWorkerStack;
use App\Models\User;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
