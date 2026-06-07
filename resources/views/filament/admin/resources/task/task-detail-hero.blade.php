{{-- Task detail hero rendered by ViewTask::getHeader().
     Contains: breadcrumb teleport, phase icon tile, task name + status badge,
     meta chips, Filament header actions, and (when running) a live log strip.
     NO overflow:hidden on .th-hero — ensures the action-menu dropdown is not clipped. --}}
@php
    /** @var \App\Models\Task $task */
    /** @var \App\Support\Workflow\TaskStage $stage */
    /** @var array $headerActions */
    /** @var array $logTail */
    /** @var array $breadcrumbs */

    $agentLabel = ($task->worker_agent_name_override ?? $task->repoProfile?->worker_agent_name ?? \App\Enums\AgentName::ClaudeCode)->label();

    $allRuns = $task->phaseRuns()->orderBy('iteration')->get();
    $totalCost = $allRuns->sum(fn ($r) => (float) ($r->cost_usd ?? 0));
    $totalTokens = $allRuns->sum(fn ($r) => (int) ($r->input_tokens ?? 0) + (int) ($r->output_tokens ?? 0));

    $currentPhase = $task->current_phase;
    $phaseIcon = $currentPhase?->icon() ?? 'heroicon-m-cpu-chip';
@endphp

{{-- Teleport breadcrumbs into the topbar (matching the vendored header override) --}}
@if ($breadcrumbs)
    <template x-teleport="#argos-topbar-breadcrumbs">
        <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
    </template>
@endif

<div class="th-hero cr-scope">
    <x-argos.cr-bg />

    {{-- Breadcrumb row (visual, inside hero) --}}
    <div class="th-crumbs">
        <a href="{{ \App\Filament\Admin\Resources\TaskResource::getUrl('index') }}">{{ __('tasks.navigation_label') }}</a>
        <span class="sep" aria-hidden="true">›</span>
        <span>{{ __('tasks.view.actions.back_crumb') }}</span>
    </div>

    <div class="th-body">
        {{-- Phase icon tile --}}
        <div class="th-ic" title="{{ $currentPhase?->label() }}">
            @svg($phaseIcon)
        </div>

        {{-- Title + meta --}}
        <div class="th-id">
            <div class="th-title">
                <h2>{{ $task->name }}</h2>
                <x-argos.badge :status="$task->presenter()->badgeStatus()" :label="$task->presenter()->statusLabel()" />
            </div>
            <div class="th-meta">
                {{-- Agent --}}
                <span class="th-mi">
                    @svg('heroicon-m-cpu-chip')
                    <span class="val">{{ $agentLabel }}</span>
                </span>

                {{-- Branch (if available) --}}
                @if ($task->feature_branch)
                    <span class="sep" aria-hidden="true" style="color:var(--cr-border-strong)">·</span>
                    <span class="th-mi">
                        @svg('heroicon-m-code-bracket')
                        <span class="val">{{ $task->feature_branch }}</span>
                    </span>
                @endif

                {{-- Cost / tokens (if any) --}}
                @if ($totalCost > 0)
                    <span class="sep" aria-hidden="true" style="color:var(--cr-border-strong)">·</span>
                    <span class="th-mi">
                        @svg('heroicon-m-currency-dollar')
                        <span class="val">{{ \App\Support\CostFormatter::usd($totalCost) }} · {{ \App\Support\CostFormatter::tokens($totalTokens) }}</span>
                    </span>
                @endif
            </div>
        </div>

        {{-- Action buttons (Filament) — z-index:2, dropdown not clipped --}}
        @if ($headerActions)
            <div class="th-actions">
                <x-filament::actions :actions="$headerActions" />
            </div>
        @endif
    </div>

    {{-- Live working strip (only when running) --}}
    @if ($stage->isRunning())
        <x-argos.task-live-strip :phase="$currentPhase" :lines="$logTail" />
    @endif
</div>
