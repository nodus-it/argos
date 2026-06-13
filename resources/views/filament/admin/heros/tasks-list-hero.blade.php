{{-- Tasks list page hero. Embedded via ListTasks::getHeader(). --}}
@php
    use App\Enums\WorkflowStatus;
    use App\Models\Task;

    $running = Task::whereIn('workflow_status', [
        WorkflowStatus::ConceptRunning,
        WorkflowStatus::ImplementRunning,
    ])->count();

    $waiting = Task::whereIn('workflow_status', [
        WorkflowStatus::ConceptReview,
        WorkflowStatus::ImplementPaused,
        WorkflowStatus::InReview,
        WorkflowStatus::Failed,
    ])->count();

    $total = Task::count();

    $chips = [
        ['label' => __('widgets.hero.chips.run'), 'value' => $running, 'type' => 'run'],
        ['label' => __('widgets.hero.chips.wait'), 'value' => $waiting, 'type' => 'wait'],
        ['label' => __('widgets.hero.chips.ok'), 'value' => $total, 'type' => 'ok'],
    ];
@endphp

{{-- Hidden poll element so Livewire re-renders getHeader() with fresh counts --}}
<div wire:poll.5s class="hidden"></div>

<x-argos.page-hero
    icon="queue-list"
    :title="__('tasks.navigation_label')"
    :sub="__('widgets.hero.tasks_sub')"
    :chips="$chips"
>
    <a href="{{ \App\Support\DocLink::url('tasks') }}" wire:navigate
       class="btn btn-ghost" style="white-space:nowrap;">
        {{ __('navigation.pages.documentation') }}
    </a>
    <a href="{{ \App\Filament\Admin\Resources\TaskResource::getUrl('create') }}"
       class="btn btn-primary" style="white-space:nowrap;">
        {{ __('widgets.hero.new_task') }}
    </a>
</x-argos.page-hero>
