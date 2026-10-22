@php
    use App\Enums\ApprovalKind;
    use App\Enums\ApprovalStatus;
    use App\Enums\PermissionMode;
    use App\Support\TaskEventFormatter as Fmt;

    /** @var \App\Models\TaskApproval $approval */
    $pending = $approval->status === ApprovalStatus::Pending;
    $tool = (string) ($p['tool_name'] ?? $approval->tool_name);
    $title = match (true) {
        $kind === ApprovalKind::Plan => __('workspaces.approval.plan_title'),
        $kind === ApprovalKind::Question => __('workspaces.approval.question_title'),
        $tool === 'Bash' => __('workspaces.approval.command_title'),
        in_array($tool, ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'], true) => __('workspaces.approval.edit_title'),
        (string) ($p['title'] ?? '') !== '' => (string) $p['title'],
        default => __('workspaces.approval.tool_title', ['tool' => $p['display_name'] ?? $tool]),
    };
    $canRemember = is_array($p['suggestions'] ?? null) && $p['suggestions'] !== [];
    $questions = $kind === ApprovalKind::Question && is_array($input['questions'] ?? null) ? $input['questions'] : [];
@endphp

<div @class(['ws-approval', 'ag-approval', 'is-pending' => $pending]) wire:key="approval-{{ $approval->id }}"
     x-data="{ reason: '', feedback: '', rejecting: false, nextMode: 'acceptEdits', answers: {}, other: {} }">
    <div class="ws-approval-head ag-approval-head">
        <span class="ag-approval-icon" aria-hidden="true">
            @if ($kind === ApprovalKind::Plan) @svg('heroicon-o-map') @elseif ($kind === ApprovalKind::Question) @svg('heroicon-o-question-mark-circle') @else <x-ag.icon name="shield" :size="14" :stroke="2" /> @endif
        </span>
        <span class="ws-approval-title">{{ $title }}</span>
        @unless ($pending)
            <span class="badge {{ \App\Support\BadgeClass::for($approval->status->color()) }}">
                {{ $approval->resolver
                    ? __('workspaces.approval.resolved_by', ['status' => $approval->status->label(), 'name' => $approval->resolver->name])
                    : $approval->status->label() }}
            </span>
        @endunless
    </div>

    @if ($kind === ApprovalKind::Plan)
        <div class="ws-md prose prose-sm">{{ Fmt::markdown((string) ($input['plan'] ?? '')) }}</div>
    @elseif ($kind === ApprovalKind::Question)
        @foreach ($questions as $qi => $question)
            @php $qText = (string) ($question['question'] ?? ''); @endphp
            <div class="ws-question">
                <div class="ws-question-text">{{ $qText }}</div>
                @foreach (($question['options'] ?? []) as $option)
                    <label class="ws-option">
                        <input type="radio" name="q-{{ $approval->id }}-{{ $qi }}" value="{{ $option['label'] ?? '' }}"
                               x-on:change="answers[@js($qText)] = $event.target.value" @disabled(! $pending || ! $canWrite)>
                        <span>{{ $option['label'] ?? '' }}</span>
                        @if (($option['description'] ?? '') !== '') <small class="muted">{{ $option['description'] }}</small> @endif
                    </label>
                @endforeach
                @if ($pending && $canWrite)
                    <input type="text" class="ws-input" placeholder="{{ __('workspaces.approval.other') }}"
                           x-on:input="answers[@js($qText)] = $event.target.value">
                @endif
            </div>
        @endforeach
    @else
        <div class="ws-approval-body">
            @if ($tool === 'Bash')
                <div class="ag-approval-cmd mono">{{ $input['command'] ?? '' }}</div>
            @else
                <div class="ag-approval-cmd mono">@if (! in_array($tool, ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'], true)){{ $tool }} · @endif{{ Fmt::toolSummary($tool, $input) }}</div>
            @endif
            @php
                $note = (string) (($p['description'] ?? '') !== '' ? $p['description'] : ($p['decision_reason'] ?? ''));
                $shown = Fmt::toolSummary($tool, $input);
            @endphp
            {{-- Claude Code's description often just repeats the path or command. --}}
            @if ($note !== '' && trim(str_replace('/workspace/', '', $note)) !== $shown && $note !== ($input['command'] ?? null))
                <p class="muted">{{ $note }}</p>
            @endif
            @if (isset($input['old_string']) || isset($input['new_string']) || isset($input['content']))
                <pre class="ws-diff mono">@if (isset($input['old_string']))<span class="del">{{ \Illuminate\Support\Str::limit((string) $input['old_string'], 1500) }}</span>
@endif<span class="add">{{ \Illuminate\Support\Str::limit((string) ($input['new_string'] ?? $input['content'] ?? ''), 1500) }}</span></pre>
            @endif
        </div>
    @endif

    @if ($pending && $canWrite)
        <div class="ws-approval-actions">
            @if ($kind === ApprovalKind::Plan)
                <template x-if="!rejecting">
                    <div class="ws-row">
                        @if ($approval->task->auto_state !== null)
                            {{-- An automatic task implements without anyone to answer approvals. --}}
                            <span class="muted">{{ __('workspaces.approval.auto_next_mode') }}</span>
                        @else
                            <label class="ws-inline">
                                <span class="muted">{{ __('workspaces.approval.next_mode') }}</span>
                                <select class="ws-select ws-select-sm" x-model="nextMode">
                                    <option value="{{ PermissionMode::AcceptEdits->value }}">{{ PermissionMode::AcceptEdits->label() }}</option>
                                    <option value="{{ PermissionMode::Default->value }}">{{ PermissionMode::Default->label() }}</option>
                                </select>
                            </label>
                        @endif
                        <button type="button" class="ag-btn ag-btn-ink" x-on:click="$wire.resolveApproval(@js($approval->id), 'allow', null, null, nextMode)">{{ __('workspaces.approval.accept_plan') }}</button>
                        <button type="button" class="ag-btn" x-on:click="rejecting = true">{{ __('workspaces.approval.reject_plan') }}</button>
                    </div>
                </template>
                <template x-if="rejecting">
                    <div class="ws-col">
                        <textarea class="respond-ta" rows="2" x-model="feedback" placeholder="{{ __('workspaces.approval.feedback') }}"></textarea>
                        <div class="ws-row">
                            <button type="button" class="ag-btn ag-btn-ink" x-on:click="$wire.resolveApproval(@js($approval->id), 'deny', feedback)">{{ __('workspaces.approval.deny') }}</button>
                            <button type="button" class="ag-btn" x-on:click="rejecting = false">{{ __('workspaces.review.cancel') }}</button>
                        </div>
                    </div>
                </template>
            @elseif ($kind === ApprovalKind::Question)
                <div class="ws-row">
                    <button type="button" class="ag-btn ag-btn-ink" x-on:click="$wire.resolveApproval(@js($approval->id), 'allow', null, answers)">{{ __('workspaces.approval.answer') }}</button>
                    <button type="button" class="ag-btn" x-on:click="$wire.resolveApproval(@js($approval->id), 'deny')">{{ __('workspaces.approval.deny') }}</button>
                </div>
            @else
                <div class="ag-row" x-show="!rejecting">
                    <button type="button" class="ag-btn ag-btn-ink" x-on:click="$wire.resolveApproval(@js($approval->id), 'allow')">{{ __('workspaces.approval.allow') }}</button>
                    @if ($canRemember)
                        <button type="button" class="ag-btn" x-on:click="$wire.resolveApproval(@js($approval->id), 'always')">{{ __('workspaces.approval.always') }}</button>
                    @endif
                    <button type="button" class="ag-btn" x-on:click="rejecting = true; $nextTick(() => $refs.reason.focus())">{{ __('workspaces.approval.deny') }}</button>
                </div>
                <div class="ag-row" x-show="rejecting" x-cloak>
                    <input type="text" class="ag-input" x-ref="reason" x-model="reason" placeholder="{{ __('workspaces.approval.reason') }}"
                           x-on:keydown.enter.prevent="$wire.resolveApproval(@js($approval->id), 'deny', reason)">
                    <button type="button" class="ag-btn ag-btn-ink" x-on:click="$wire.resolveApproval(@js($approval->id), 'deny', reason)">{{ __('workspaces.approval.deny') }}</button>
                    <button type="button" class="ag-btn" x-on:click="rejecting = false">{{ __('workspaces.review.cancel') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
