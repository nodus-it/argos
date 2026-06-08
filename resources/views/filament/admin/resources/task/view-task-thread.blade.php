<x-filament-panels::page>
    @php
        /** @var \App\Models\Task $record */
        $task = $record;

        $allRuns = collect($phaseRuns)->flatten(1);
        $totalCost = $allRuns->sum(fn ($r) => (float) ($r->cost_usd ?? 0));
        $totalTokens = $allRuns->sum(fn ($r) => (int) ($r->input_tokens ?? 0) + (int) ($r->output_tokens ?? 0));
        $agentLabel = ($task->worker_agent_name_override ?? $task->repoProfile?->worker_agent_name ?? \App\Enums\AgentName::ClaudeCode)->label();
        $stackName = $task->workerStackOverride?->name ?? $task->repoProfile?->workerStack?->name ?? config('argos.compose.default_stack');
    @endphp

    {{-- Task name + status badge now render in the page header (getHeading). --}}

    {{-- Status banner: the phase stepper + the single "what is the system doing
         right now" status band, as one unit (M1). --}}
    <div class="fade-in" style="margin-bottom:16px;">
        <x-argos.status-banner
            :state="$banner['state']"
            :title="$banner['title']"
            :hint="$banner['hint']"
            :startedAt="$banner['startedAt']"
            :error="$banner['error']"
            :logsUrl="$banner['logsUrl']"
            :logsLabel="$banner['logsLabel']"
            :rail="$task->presenter()->phaseRail()" />
    </div>

    {{-- Auto-reload while the worker is busy (running or queued). Poll only then
         so review/failed/done states don't reload needlessly (M2). --}}
    @if ($stage->isBusy())
        <div wire:poll.1000ms="poll" class="hidden"></div>
    @endif

    {{-- Meta strip --}}
    <div class="fade-in" style="margin-bottom:20px;">
        <x-argos.meta-strip>
            @if ($task->repoProfile)
                <x-argos.meta-item label="{{ __('tasks.view.labels.repository') }}" :mono="true">{{ $task->repoProfile->name }}</x-argos.meta-item>
            @endif
            @if ($task->feature_branch)
                <x-argos.meta-item label="{{ __('tasks.view.labels.branch') }}" :mono="true" :link="true">{{ $task->feature_branch }}</x-argos.meta-item>
            @endif
            <x-argos.meta-item label="{{ __('tasks.view.labels.agent') }}">{{ $agentLabel }}</x-argos.meta-item>
            <x-argos.meta-item label="{{ __('tasks.view.labels.stack') }}" :mono="true">{{ $stackName }}</x-argos.meta-item>
            @if ($totalCost > 0)
                <x-argos.meta-item label="{{ __('tasks.view.labels.cost') }}" :mono="true">{{ \App\Support\CostFormatter::usd($totalCost) }} · {{ \App\Support\CostFormatter::tokens($totalTokens) }}</x-argos.meta-item>
            @endif

            <x-slot:extra>
                <x-argos.meta-item label="{{ __('tasks.view.labels.base_branch') }}" :mono="true">{{ $task->base_branch ?: '—' }}</x-argos.meta-item>
                <x-argos.meta-item label="{{ __('tasks.view.labels.created') }}">{{ $task->created_at?->format('d.m.Y H:i') }}</x-argos.meta-item>
                @if ($task->pr_url)
                    <x-argos.meta-item label="{{ __('tasks.view.labels.pull_request') }}" :link="true">
                        <a href="{{ $task->pr_url }}" target="_blank" rel="noopener">{{ $task->pr_url }}</a>
                    </x-argos.meta-item>
                @endif
                @if ($task->externalIssueLink)
                    <x-argos.meta-item label="{{ __('tasks.columns.source') }}" :mono="true">
                        {{ $task->externalIssueLink->binding?->external_project_ref }}
                        @if ($task->externalIssueLink->external_url)
                            <a href="{{ $task->externalIssueLink->external_url }}" target="_blank" rel="noopener" class="link">{{ $task->externalIssueLink->external_url }}</a>
                        @endif
                    </x-argos.meta-item>
                @endif
            </x-slot:extra>
        </x-argos.meta-strip>
    </div>

    {{-- Live demo — standalone bar above the thread (status + URL) --}}
    @php
        $demoEnabled = (bool) config('argos.preview.enabled') && (bool) $task->repoProfile?->live_demo_enabled;
        $demoStatus = $demo?->status?->value;
        $demoBadgeCls = $demo?->status ? \App\Support\BadgeClass::for($demo->status->color()) : 'badge-draft';
        $accessMode = $task->effectiveDemoAccessMode();
        $accessBadgeCls = \App\Support\BadgeClass::for($accessMode->color());
        $accessIcon = $accessMode->icon();
    @endphp
    @if ($demo || $demoEnabled)
        <div class="card card-pad demo-bar fade-in" style="margin-bottom:20px;" x-data="{ log: false }">
            <div class="demo-id">
                @svg('heroicon-o-globe-alt')
                <span class="demo-name">{{ __('tasks.view.demo.title') }}</span>
                @if ($demo)
                    <span class="badge {{ $demoBadgeCls }}"><span class="dot"></span>{{ $demo->status->label() }}</span>
                @endif
                <span class="badge {{ $accessBadgeCls }}" title="{{ __('tasks.view.demo.access.heading') }}">
                    @svg($accessIcon)
                    {{ $accessMode->label() }}
                </span>
            </div>

            <div class="demo-meta">
                @if ($demoStatus === 'live' && $demo->url)
                    <a href="{{ $demo->url }}" target="_blank" rel="noopener" class="demo-link">{{ $demo->url }}</a>
                    @if ($demo->ttl_until)
                        <span class="demo-exp">{{ __('tasks.view.demo.expires') }} {{ $demo->ttl_until->diffForHumans() }}</span>
                    @endif
                @elseif ($demoStatus === 'building')
                    <span class="demo-exp">{{ __('tasks.view.demo.building') }}</span>
                @elseif ($demoStatus === 'failed')
                    <span class="demo-exp" style="color:var(--dg-600);">{{ __('tasks.view.demo.failed_hint') }}</span>
                    @if ($demo->build_log)
                        <button type="button" class="link-btn" @click="log = !log" :class="log && 'on'">
                            @svg('heroicon-o-command-line') {{ __('tasks.view.demo.show_log') }}
                        </button>
                    @endif
                @elseif ($demoStatus === 'stopped')
                    <span class="demo-exp">{{ __('tasks.view.demo.stopped_hint') }}</span>
                    @if ($demoEnabled)
                        <button type="button" class="link-btn" wire:click="mountAction('rebuildDemo')">
                            @svg('heroicon-o-play') {{ __('tasks.view.demo.restart') }}
                        </button>
                    @endif
                @else
                    <span class="demo-exp">{{ __('tasks.view.demo.empty_hint') }}</span>
                @endif
            </div>

            @if ($demoStatus === 'failed' && $demo->build_log)
                <div x-show="log" x-cloak class="demo-log">
                    <pre class="mono">{{ \Illuminate\Support\Str::limit($demo->build_log, 8000) }}</pre>
                </div>
            @endif
        </div>
    @endif

    {{-- Chronological thread: one entry per phase iteration, interleaved with
         the feedback that triggered each re-run (M3). Built in ViewTask::buildThread. --}}
    <x-argos.thread class="task-detail">
        @foreach ($thread as $item)
            @if ($item['kind'] === 'created')
                <x-argos.thread-item phase="draft" :done="true" :title="$item['title']"
                    :who="$item['who']" :time="$item['time']">
                    {{ $item['body'] }}
                </x-argos.thread-item>

            @elseif ($item['kind'] === 'feedback')
                <x-argos.thread-item phase="review" state="done" :title="__('tasks.view.thread.feedback_title')"
                    :who="$item['who']" :time="$item['time']">
                    {{ $item['body'] }}
                </x-argos.thread-item>

            @else
                {{-- phase entry --}}
                @php $isCode = in_array($item['phase'], ['implement', 'respond'], true); @endphp
                <x-argos.thread-item x-data="{ panel: null }" :phase="$item['phase']" :state="$item['state']"
                    :title="$item['title']" :who="$item['who']" :time="$item['time']" :cost="$item['cost']">

                    @if ($item['error'])
                        <span class="callout callout-danger" style="display:flex">@svg('heroicon-o-exclamation-triangle') {{ $item['error'] }}</span>
                    @else
                        {{ $item['body'] }}
                    @endif

                    @if ($item['qualityGates'])
                        <div class="feed-actions" style="margin-top:10px;">
                            @foreach ($item['qualityGates'] as $gate => $result)
                                @php
                                    $isFail = in_array($result, ['fail', 'advisory_fail'], true);
                                    $lastKey = $item['qualityGateLastKeys'][$gate] ?? null;
                                    $gateUrl = $lastKey
                                        ? \App\Filament\Admin\Resources\TaskResource::getUrl('quality-gates', ['record' => $record, 'phase' => $item['phase'], 'key' => $lastKey])
                                        : null;
                                @endphp
                                @if ($gateUrl)
                                    <a href="{{ $gateUrl }}" class="badge badge-failed">@svg('heroicon-o-x-mark') {{ strtoupper($gate) }}</a>
                                @else
                                    <span @class(['badge', 'badge-success' => $result === 'pass', 'badge-failed' => $isFail, 'badge-draft' => ! $isFail && $result !== 'pass'])>{{ strtoupper($gate) }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <x-slot:actions>
                        @if ($item['phase'] === 'concept' && $item['conceptHtml'])
                            <button type="button" class="link-btn" :class="panel === 'concept' && 'on'" @click="panel = (panel === 'concept' ? null : 'concept')">
                                @svg('heroicon-o-light-bulb') {{ __('tasks.view.thread.view_concept') }}
                            </button>
                        @endif
                        @if ($isCode && $item['summaryHtml'])
                            <button type="button" class="link-btn" :class="panel === 'summary' && 'on'" @click="panel = (panel === 'summary' ? null : 'summary')">
                                @svg('heroicon-o-bars-3-bottom-left') {{ __('tasks.view.thread.summary') }}
                            </button>
                        @endif
                        @if ($item['showDiff'])
                            <button type="button" class="link-btn" :class="panel === 'diff' && 'on'"
                                @click="panel = (panel === 'diff' ? null : 'diff'); @if (! $diffLoaded) if (panel === 'diff') $wire.loadDiff() @endif">
                                @svg('heroicon-o-document-text') {{ __('tasks.view.thread.diff') }}
                            </button>
                        @endif
                        @if ($item['isLive'] || $item['hasStoredLog'])
                            <button type="button" class="link-btn" :class="panel === 'logs' && 'on'"
                                @click="panel = (panel === 'logs' ? null : 'logs'); @if (! $item['isLive'] && $item['iterationKey']) if (panel === 'logs') $wire.loadLogIteration('{{ $item['phase'] }}', {{ $item['iteration'] }}) @endif">
                                @svg('heroicon-o-command-line') {{ __('tasks.view.thread.logs') }}
                            </button>
                        @endif
                        @if ($isCode && $item['techHtml'])
                            <button type="button" class="link-btn" :class="panel === 'tech' && 'on'" @click="panel = (panel === 'tech' ? null : 'tech')">
                                @svg('heroicon-o-code-bracket') {{ __('tasks.view.thread.technical') }}
                            </button>
                        @endif
                        @if ($item['phase'] === 'push' && $item['prUrl'])
                            <a href="{{ $item['prUrl'] }}" target="_blank" rel="noopener" class="link-btn">
                                @svg('heroicon-o-arrow-top-right-on-square') {{ __('tasks.view.thread.open_pr') }}
                            </a>
                        @endif
                    </x-slot:actions>

                    <x-slot:detail>
                        @if ($item['phase'] === 'concept' && $item['conceptHtml'])
                            <div x-show="panel === 'concept'" x-cloak class="card card-pad prose prose-sm dark:prose-invert max-w-none">
                                {!! $item['conceptHtml'] !!}
                            </div>
                        @endif
                        @if ($isCode && $item['summaryHtml'])
                            <div x-show="panel === 'summary'" x-cloak class="card card-pad prose prose-sm dark:prose-invert max-w-none">
                                {!! $item['summaryHtml'] !!}
                            </div>
                        @endif
                        @if ($item['showDiff'])
                            <div x-show="panel === 'diff'" x-cloak>
                                <div wire:loading wire:target="loadDiff" class="callout callout-info">@svg('heroicon-o-arrow-path') {{ __('tasks.view.diff.loading') }}</div>
                                <div wire:loading.remove wire:target="loadDiff">
                                    @if ($diffError)
                                        <div class="callout callout-warn">@svg('heroicon-o-exclamation-triangle') {{ $diffError }}</div>
                                    @else
                                        <x-argos.diff :files="$diffFiles" />
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if ($item['isLive'] || $item['hasStoredLog'])
                            <div x-show="panel === 'logs'" x-cloak>
                                @if ($item['isLive'])
                                    <x-argos.agent-stream title="worker · {{ $task->feature_branch }}" :events="$liveLog" :live="true" />
                                @else
                                    <x-argos.agent-stream title="worker · {{ $item['phase'] }} v{{ $item['iteration'] }}"
                                        :events="$loadedLogIterations[$item['iterationKey']] ?? []" />
                                @endif
                            </div>
                        @endif
                        @if ($isCode && $item['techHtml'])
                            <div x-show="panel === 'tech'" x-cloak class="card card-pad prose prose-sm dark:prose-invert max-w-none">
                                {!! $item['techHtml'] !!}
                            </div>
                        @endif
                    </x-slot:detail>
                </x-argos.thread-item>
            @endif
        @endforeach
    </x-argos.thread>

    {{-- Respond / advance dock — drives phase progression from the bottom (M4).
         Hidden while the worker is busy; the dock variant comes from TaskStage. --}}
    @php $dock = $stage->dockMode(); @endphp
    @if ($dock !== 'none')
        @php
            $isConceptField = in_array($dock, ['draft', 'concept', 'retry_concept'], true);
            $field = $isConceptField ? 'notes' : 'implementNotes';
            $isReview = in_array($dock, ['concept', 'implement'], true);
            $hint = match ($dock) {
                'draft' => __('tasks.view.dock.draft_hint'),
                'concept' => __('tasks.view.dock.concept_hint'),
                'implement' => __('tasks.view.dock.implement_hint'),
                default => __('tasks.view.dock.retry_hint'),
            };
            $placeholder = $isConceptField
                ? __('tasks.view.dock.concept_placeholder')
                : __('tasks.view.dock.implement_placeholder');
        @endphp
        <x-argos.respond :waiting="$isReview" flag="{{ $hint }}">
            @if ($dock !== 'retry_push')
                <textarea class="respond-ta" rows="2" wire:model="{{ $field }}"
                    placeholder="{{ $placeholder }}"></textarea>
            @endif

            @switch($dock)
                @case('concept')
                    <x-argos.btn variant="secondary" wire:click="saveNotesAndRevise">
                        @svg('heroicon-o-light-bulb') {{ __('tasks.view.dock.update_concept') }}
                    </x-argos.btn>
                    <x-argos.btn variant="primary" wire:click="startPhaseFromDock('implement')">
                        @svg('heroicon-o-code-bracket') {{ __('tasks.view.dock.start_implement') }}
                    </x-argos.btn>
                    @break
                @case('implement')
                    <x-argos.btn variant="secondary" wire:click="saveImplementNotesAndRevise(true)">
                        @svg('heroicon-o-arrow-path') {{ __('tasks.view.dock.refine_implement') }}
                    </x-argos.btn>
                    <x-argos.btn variant="primary" wire:click="startPhaseFromDock('push')">
                        @svg('heroicon-o-arrow-up-tray') {{ __('tasks.view.dock.start_push') }}
                    </x-argos.btn>
                    @break
                @case('draft')
                    <x-argos.btn variant="primary" wire:click="startConceptFromDock">
                        @svg('heroicon-o-light-bulb') {{ __('tasks.view.dock.start_concept') }}
                    </x-argos.btn>
                    @break
                @case('retry_concept')
                    <x-argos.btn variant="primary" wire:click="startConceptFromDock">
                        @svg('heroicon-o-arrow-path') {{ __('tasks.view.dock.retry') }}
                    </x-argos.btn>
                    @break
                @case('retry_implement')
                    <x-argos.btn variant="primary" wire:click="saveImplementNotesAndRevise">
                        @svg('heroicon-o-arrow-path') {{ __('tasks.view.dock.retry') }}
                    </x-argos.btn>
                    @break
                @case('retry_push')
                    <x-argos.btn variant="primary" wire:click="startPhaseFromDock('push')">
                        @svg('heroicon-o-arrow-path') {{ __('tasks.view.dock.retry') }}
                    </x-argos.btn>
                    @break
            @endswitch

            @if ($isReview)
                <x-slot:quick>
                    <span class="chip" x-on:click="$wire.set('{{ $field }}', @js(__('tasks.view.thread.chip_changes_text')))">
                        @svg('heroicon-o-pencil-square') {{ __('tasks.view.thread.chip_changes') }}
                    </span>
                    <span class="chip" x-on:click="$wire.set('{{ $field }}', @js(__('tasks.view.thread.chip_question_text')))">
                        @svg('heroicon-o-question-mark-circle') {{ __('tasks.view.thread.chip_question') }}
                    </span>
                </x-slot:quick>
            @endif
        </x-argos.respond>
    @endif
</x-filament-panels::page>
