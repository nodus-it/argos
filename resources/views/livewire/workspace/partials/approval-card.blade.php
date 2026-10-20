@php
    use App\Enums\ApprovalKind;
    use App\Enums\ApprovalStatus;
    use App\Enums\PermissionMode;
    use App\Support\ThreadEventFormatter as Fmt;

    /** @var \App\Models\ThreadApproval $approval */
    $pending = $approval->status === ApprovalStatus::Pending;
    $tool = (string) ($p['tool_name'] ?? $approval->tool_name);
    $title = match ($kind) {
        ApprovalKind::Plan => __('workspaces.approval.plan_title'),
        ApprovalKind::Question => __('workspaces.approval.question_title'),
        default => (string) ($p['title'] ?? '') !== '' ? (string) $p['title'] : __('workspaces.approval.tool_title', ['tool' => $p['display_name'] ?? $tool]),
    };
    $questions = $kind === ApprovalKind::Question && is_array($input['questions'] ?? null) ? $input['questions'] : [];
@endphp

<div @class(['ws-approval', 'card', 'is-pending' => $pending]) wire:key="approval-{{ $approval->id }}"
     x-data="{ reason: '', feedback: '', rejecting: false, nextMode: 'acceptEdits', answers: {}, other: {} }">
    <div class="ws-approval-head">
        @svg($kind === ApprovalKind::Plan ? 'heroicon-o-map' : ($kind === ApprovalKind::Question ? 'heroicon-o-question-mark-circle' : 'heroicon-o-shield-exclamation'))
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
            <span class="name">{{ $tool }}</span>
            <span class="summary mono">{{ Fmt::toolSummary($tool, $input) }}</span>
            @if (($p['description'] ?? '') !== '' || ($p['decision_reason'] ?? '') !== '')
                <p class="muted">{{ $p['description'] ?? $p['decision_reason'] }}</p>
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
                        <label class="ws-inline">
                            <span class="muted">{{ __('workspaces.approval.next_mode') }}</span>
                            <select class="ws-select ws-select-sm" x-model="nextMode">
                                <option value="{{ PermissionMode::AcceptEdits->value }}">{{ PermissionMode::AcceptEdits->label() }}</option>
                                <option value="{{ PermissionMode::Default->value }}">{{ PermissionMode::Default->label() }}</option>
                            </select>
                        </label>
                        <button type="button" class="btn btn-success btn-sm" x-on:click="$wire.resolveApproval(@js($approval->id), 'allow', null, null, nextMode)">{{ __('workspaces.approval.accept_plan') }}</button>
                        <button type="button" class="btn btn-secondary btn-sm" x-on:click="rejecting = true">{{ __('workspaces.approval.reject_plan') }}</button>
                    </div>
                </template>
                <template x-if="rejecting">
                    <div class="ws-col">
                        <textarea class="respond-ta" rows="2" x-model="feedback" placeholder="{{ __('workspaces.approval.feedback') }}"></textarea>
                        <div class="ws-row">
                            <button type="button" class="btn btn-danger btn-sm" x-on:click="$wire.resolveApproval(@js($approval->id), 'deny', feedback)">{{ __('workspaces.approval.deny') }}</button>
                        </div>
                    </div>
                </template>
            @elseif ($kind === ApprovalKind::Question)
                <div class="ws-row">
                    <button type="button" class="btn btn-primary btn-sm" x-on:click="$wire.resolveApproval(@js($approval->id), 'allow', null, answers)">{{ __('workspaces.approval.answer') }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="$wire.resolveApproval(@js($approval->id), 'deny')">{{ __('workspaces.approval.deny') }}</button>
                </div>
            @else
                <div class="ws-row">
                    <input type="text" class="ws-input" x-model="reason" placeholder="{{ __('workspaces.approval.reason') }}">
                    <button type="button" class="btn btn-success btn-sm" x-on:click="$wire.resolveApproval(@js($approval->id), 'allow')">{{ __('workspaces.approval.allow') }}</button>
                    <button type="button" class="btn btn-danger btn-sm" x-on:click="$wire.resolveApproval(@js($approval->id), 'deny', reason)">{{ __('workspaces.approval.deny') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
