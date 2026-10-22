@php
    /** @var \App\Models\TaskEvent $event */
    /** @var array<string, mixed> $p */
@endphp
{{-- A system notice (preparation, hand-over, PR changes, setup test, …);
     what a person did carries their picture. --}}
@php $noticeAuthor = $event->author_user_id !== null ? (($authors ?? collect())[$event->author_user_id] ?? null) : null; @endphp
<div class="ws-notice ag-notice" wire:key="ev-{{ $event->id }}">
    @if ($noticeAuthor !== null)<x-ag.avatar :user="$noticeAuthor" :class="'ag-avatar-xs ag-notice-avatar'" />@endif
    {{ match ($p['kind'] ?? '') {
        'interrupted' => __('workspaces.events.interrupted', ['name' => $p['author_name'] ?? '']),
        'permission_mode' => __('workspaces.events.permission_mode', ['name' => $p['author_name'] ?? '', 'mode' => \App\Enums\PermissionMode::tryFrom((string) ($p['mode'] ?? ''))?->label() ?? '']),
        'model' => __('workspaces.events.model', ['name' => $p['author_name'] ?? '', 'model' => $p['model'] ?? '']),
        'shipped' => __('workspaces.events.shipped', ['name' => $p['author_name'] ?? '']),
        'committed' => __('workspaces.events.committed', ['name' => $p['author_name'] ?? '', 'subject' => $p['subject'] ?? '']),
        'restored' => __('workspaces.events.restored', ['name' => $p['author_name'] ?? '', 'excerpt' => $p['excerpt'] ?? '']),
        'checkpoint' => __('workspaces.events.checkpoint', ['name' => $p['author_name'] ?? '', 'label' => ($p['label'] ?? '') !== '' ? $p['label'] : '—']),
        'prepare_step' => in_array($p['step'] ?? '', ['worktree', 'setup'], true) ? __('workspaces.events.prepare_step.'.$p['step']) : '',
        'prepared' => ($p['setup'] ?? '') === 'failed' ? __('workspaces.events.prepared_setup_failed') : __('workspaces.events.prepared'),
        'prepare_failed' => __('workspaces.events.prepare_failed', ['message' => $p['message'] ?? '']),
        'invited' => __('workspaces.events.invited', ['name' => $p['author_name'] ?? '', 'member' => $p['member_name'] ?? '']),
        'uninvited' => __('workspaces.events.uninvited', ['name' => $p['author_name'] ?? '', 'member' => $p['member_name'] ?? '']),
        'transferred' => __('workspaces.events.transferred', ['name' => $p['author_name'] ?? '', 'owner' => $p['owner_name'] ?? '']),
        'cancelled' => __('workspaces.events.cancelled', ['name' => $p['author_name'] ?? '']),
        'ticket_linked' => __('workspaces.events.ticket_linked', ['name' => $p['author_name'] ?? '', 'ticket' => $p['ticket'] ?? '']),
        'ticket_created' => __('workspaces.events.ticket_created', ['name' => $p['author_name'] ?? '', 'ticket' => $p['ticket'] ?? '']),
        'ticket_comment' => __('workspaces.events.ticket_comment', ['name' => $p['author_name'] ?? '']),
        'pr_ready', 'auto_merge_on', 'auto_merge_off' => __('workspaces.events.'.$p['kind'], ['name' => $p['author_name'] ?? '']),
        'auto_merge_unavailable', 'pr_checks_failed', 'pr_checks_passed', 'pr_changes_requested', 'pr_approved', 'pr_merged', 'pr_closed' => __('workspaces.events.'.$p['kind']),
        'setup_testing', 'setup_passed', 'setup_failed' => __('workspaces.events.'.$p['kind']),
        default => '',
    } }}
    @if (in_array($p['kind'] ?? '', ['setup_passed', 'setup_failed'], true) && ! empty($p['steps']))
        <ul class="ag-setup-steps">
            @foreach ($p['steps'] as $step)
                <li @class(['is-ok' => $step['ok'], 'is-fail' => ! $step['ok']])>{{ $step['ok'] ? '✓' : '✗' }} {{ __('workspaces.setup.steps.'.$step['step']) }}</li>
            @endforeach
        </ul>
    @endif
    @if (($p['kind'] ?? '') === 'prepare_failed' && $canWrite && $task->status === \App\Enums\TaskStatus::Preparing)
        <button type="button" class="ag-link" wire:click="retryPreparation">{{ __('workspaces.events.prepare_retry') }}</button>
    @endif
    @if (($p['kind'] ?? '') === 'ticket_comment')
        <blockquote class="ag-ticket-comment">{{ $p['body'] ?? '' }}@if (! empty($p['url'])) <a class="ag-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">↗</a>@endif</blockquote>
    @endif
    @if (str_starts_with((string) ($p['kind'] ?? ''), 'pr_') && ! empty($p['url']))
        <a class="ag-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">↗</a>
    @endif
    @if (($p['kind'] ?? '') === 'ticket_created' && ! empty($p['url']))
        <a class="ag-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['url'] }}</a>
    @endif
    @if (($p['kind'] ?? '') === 'prepared' && ($p['log'] ?? '') !== '')
        <details class="ag-notice-log">
            <summary>{{ __('workspaces.events.prepared_log') }}</summary>
            <pre class="ag-log mono">{{ $p['log'] }}</pre>
        </details>
    @endif
    @if (in_array($p['kind'] ?? '', ['restored', 'checkpoint'], true) && $canWrite && ! empty($p['checkpoint_id']))
        <button type="button" class="ag-link" wire:click="previewRestore('{{ $p['checkpoint_id'] }}')">
            {{ ($p['kind'] ?? '') === 'restored' ? __('workspaces.checkpoints.undo_restore') : __('workspaces.checkpoints.reset_here') }}
        </button>
    @endif
    @if (($p['kind'] ?? '') === 'shipped' && ($p['url'] ?? '') !== '')
        <a class="ag-link" href="{{ $p['url'] }}" target="_blank" rel="noopener">{{ $p['url'] }}</a>
    @endif
</div>
