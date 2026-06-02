<x-filament-panels::page>
    @php
        /** @var \App\Models\Task $record */
        $task = $record;
        $ws = $task->workflow_status->value;
        $lastRun = fn (string $p) => ($phaseRuns[$p] ?? collect())->last();
        $conceptRun = $lastRun('concept');
        $implementRun = $lastRun('implement');
        $pushRun = $lastRun('push');
        $isDone = fn ($run) => $run?->status?->value === 'completed';
        $waitingConcept = $ws === 'concept_review';
        $waitingImplement = in_array($ws, ['implement_completed', 'implement_paused', 'in_review'], true);
        $cost = fn ($run) => $run?->cost_usd ? '$'.number_format((float) $run->cost_usd, 2) : null;
    @endphp

    {{-- Task name + status badge now render in the page header (getHeading). --}}

    {{-- Paused banner (turn-limit) --}}
    @if ($implementRun?->status?->value === 'paused')
        <div class="callout callout-warn" style="margin-bottom:16px;">
            @svg('heroicon-o-pause-circle')
            <div>
                <strong>{{ __('tasks.view.implement.paused_title') }}</strong>
                <div style="margin-top:2px;">{{ __('tasks.view.implement.paused_resume_hint') }}</div>
            </div>
        </div>
    @endif

    {{-- Phase rail + meta strip --}}
    <div class="fade-in" style="margin-bottom:20px;">
        <div class="card card-pad" style="margin-bottom:12px;">
            <x-argos.phase-rail :rail="$task->phaseRail()" :current="$task->displayStatusLabel()" />
        </div>
        <x-argos.meta-strip>
            @if ($task->repoProfile)
                <x-argos.meta-item label="{{ __('tasks.columns.project') }}">{{ $task->repoProfile->name }}</x-argos.meta-item>
            @endif
            @if ($task->feature_branch)
                <x-argos.meta-item label="Branch" :mono="true" :link="true">{{ $task->feature_branch }}</x-argos.meta-item>
            @endif
            @if ($task->pr_url)
                <x-argos.meta-item label="PR" :link="true">
                    <a href="{{ $task->pr_url }}" target="_blank" rel="noopener">Pull Request</a>
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
        </x-argos.meta-strip>
    </div>

    {{-- Chronological thread --}}
    <x-argos.thread class="task-detail">
        {{-- Task created --}}
        <x-argos.thread-item phase="draft" :done="true" title="{{ __('tasks.view.thread.created') }}"
            who="Du" time="{{ $task->created_at?->diffForHumans() }}">
            {{ \Illuminate\Support\Str::limit($task->description ?? '', 400) }}
        </x-argos.thread-item>

        {{-- Concept --}}
        @if ($conceptRun || $conceptHtml || $waitingConcept)
            <div x-data="{ open: false }">
                <x-argos.thread-item phase="concept" :done="$isDone($conceptRun)"
                    title="{{ __('tasks.view.thread.concept') }}" who="Claude Code"
                    time="{{ $conceptRun?->finished_at?->diffForHumans() }}" :cost="$cost($conceptRun)">
                    @if ($conceptError)
                        <span class="callout callout-warn" style="display:flex">@svg('heroicon-o-exclamation-triangle') {{ \Illuminate\Support\Str::limit($conceptError, 300) }}</span>
                    @else
                        {{ __('tasks.view.thread.concept_body') }}
                    @endif

                    @if ($conceptHtml)
                        <x-slot:actions>
                            <button type="button" class="link-btn" :class="open && 'on'" @click="open = !open">
                                @svg('heroicon-o-light-bulb') {{ __('tasks.view.thread.view_concept') }}
                            </button>
                        </x-slot:actions>
                        <x-slot:detail>
                            <div x-show="open" x-cloak class="card card-pad prose prose-sm dark:prose-invert max-w-none">
                                {!! $conceptHtml !!}
                            </div>
                        </x-slot:detail>
                    @endif
                </x-argos.thread-item>
            </div>
        @endif

        {{-- Implementation --}}
        @if ($implementRun || $implementSummaryNontechnicalHtml)
            <div x-data="{ panel: null }">
                <x-argos.thread-item phase="implement" :done="$isDone($implementRun)"
                    title="{{ __('tasks.view.thread.implement') }}" who="Claude Code"
                    time="{{ $implementRun?->finished_at?->diffForHumans() }}" :cost="$cost($implementRun)">
                    @if ($implementSummaryNontechnicalHtml)
                        <span class="prose prose-sm dark:prose-invert max-w-none">{!! $implementSummaryNontechnicalHtml !!}</span>
                    @else
                        {{ __('tasks.view.thread.implement_body') }}
                    @endif

                    @if ($implementQualityGates)
                        <div class="feed-actions" style="margin-top:10px;">
                            @foreach ($implementQualityGates as $gate => $result)
                                @php
                                    $isFail = in_array($result, ['fail', 'advisory_fail'], true);
                                    $matches = array_values(array_filter(
                                        $implementQualityGateLogKeys ?? [],
                                        fn (string $k): bool => $k === $gate || str_starts_with($k, $gate.'.')
                                    ));
                                    sort($matches);
                                    $lastKey = $isFail && $matches !== [] ? end($matches) : null;
                                    $gateUrl = $lastKey
                                        ? \App\Filament\Admin\Resources\TaskResource::getUrl('quality-gates', ['record' => $record, 'phase' => 'implement', 'key' => $lastKey])
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
                        <button type="button" class="link-btn" :class="panel === 'diff' && 'on'"
                            @click="panel = (panel === 'diff' ? null : 'diff'); @if (! $diffLoaded) if (panel === 'diff') $wire.loadDiff() @endif">
                            @svg('heroicon-o-document-text') {{ __('tasks.view.thread.diff') }}
                        </button>
                        @if (count($implementLog))
                            <button type="button" class="link-btn" :class="panel === 'logs' && 'on'" @click="panel = (panel === 'logs' ? null : 'logs')">
                                @svg('heroicon-o-command-line') {{ __('tasks.view.thread.logs') }}
                            </button>
                        @endif
                        @if ($implementSummaryTechnicalHtml)
                            <button type="button" class="link-btn" :class="panel === 'tech' && 'on'" @click="panel = (panel === 'tech' ? null : 'tech')">
                                @svg('heroicon-o-code-bracket') {{ __('tasks.view.thread.technical') }}
                            </button>
                        @endif
                    </x-slot:actions>

                    <x-slot:detail>
                        <div x-show="panel === 'diff'" x-cloak>
                            <div wire:loading wire:target="loadDiff" class="callout callout-info">@svg('heroicon-o-arrow-path') {{ __('tasks.view.diff.loading') }}</div>
                            <div wire:loading.remove wire:target="loadDiff"><x-argos.diff :files="$diffFiles" /></div>
                        </div>
                        <div x-show="panel === 'logs'" x-cloak>
                            <x-argos.terminal title="worker · {{ $task->feature_branch }}" :lines="$implementLog" />
                        </div>
                        @if ($implementSummaryTechnicalHtml)
                            <div x-show="panel === 'tech'" x-cloak class="card card-pad prose prose-sm dark:prose-invert max-w-none">
                                {!! $implementSummaryTechnicalHtml !!}
                            </div>
                        @endif
                    </x-slot:detail>
                </x-argos.thread-item>
            </div>
        @endif

        {{-- Push / PR --}}
        @if ($task->pr_url)
            <x-argos.thread-item phase="push" :done="true" title="{{ __('tasks.view.thread.push') }}"
                time="{{ $pushRun?->finished_at?->diffForHumans() }}">
                <a href="{{ $task->pr_url }}" target="_blank" rel="noopener" class="link-btn">
                    @svg('heroicon-o-arrow-top-right-on-square') {{ __('tasks.view.thread.open_pr') }}
                </a>
            </x-argos.thread-item>
        @endif
    </x-argos.thread>

    {{-- Respond composer (maps to the phase awaiting feedback) --}}
    @if ($waitingConcept || $waitingImplement)
        @php $field = $waitingConcept ? 'notes' : 'implementNotes'; @endphp
        <x-argos.respond :waiting="true" flag="{{ __('tasks.view.thread.waiting_flag') }}">
            <textarea class="respond-ta" rows="2"
                wire:model="{{ $field }}"
                placeholder="{{ __('tasks.view.feedback.concept_placeholder') }}"></textarea>
            <x-argos.btn variant="primary" wire:click="{{ $waitingConcept ? 'saveNotesAndRevise' : 'saveImplementNotesAndRevise' }}">
                @svg('heroicon-o-paper-airplane') {{ __('tasks.view.thread.send') }}
            </x-argos.btn>

            <x-slot:quick>
                <span class="chip" x-on:click="$wire.set('{{ $field }}', @js(__('tasks.view.thread.chip_changes_text')))">
                    @svg('heroicon-o-pencil-square') {{ __('tasks.view.thread.chip_changes') }}
                </span>
                @if ($waitingImplement)
                    <span class="chip" wire:click="mountAction('markCompleted')">
                        @svg('heroicon-o-check-circle') {{ __('tasks.view.thread.chip_approve') }}
                    </span>
                @endif
                <span class="chip" x-on:click="$wire.set('{{ $field }}', @js(__('tasks.view.thread.chip_question_text')))">
                    @svg('heroicon-o-question-mark-circle') {{ __('tasks.view.thread.chip_question') }}
                </span>
            </x-slot:quick>
        </x-argos.respond>
    @endif
</x-filament-panels::page>
