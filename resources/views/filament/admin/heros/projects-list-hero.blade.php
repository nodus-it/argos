{{-- Projects (RepoProfiles) list page hero. Embedded via ListRepoProfiles::getHeader(). --}}
@php
    use App\Enums\WorkflowStatus;
    use App\Models\RepoProfile;
    use App\Models\Task;

    $totalProjects = RepoProfile::count();
    $activeTasks = Task::whereNotIn('workflow_status', [
        WorkflowStatus::Completed,
    ])->count();

    $chips = [
        ['label' => __('widgets.hero.chips.projects'), 'value' => $totalProjects, 'type' => 'ok'],
        ['label' => __('widgets.hero.chips.active_tasks'), 'value' => $activeTasks, 'type' => 'run'],
    ];
@endphp

{{-- Hidden poll element so Livewire re-renders getHeader() with fresh counts --}}
<div wire:poll.5s class="hidden"></div>

<x-argos.page-hero
    icon="folder"
    :title="__('projects.navigation_label')"
    :sub="__('widgets.hero.projects_sub')"
    :chips="$chips"
>
    <a href="{{ \App\Filament\Admin\Resources\RepoProfileResource::getUrl('create') }}"
       class="btn btn-primary" style="white-space:nowrap;">
        {{ __('widgets.hero.new_project') }}
    </a>
</x-argos.page-hero>
