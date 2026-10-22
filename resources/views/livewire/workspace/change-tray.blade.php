{{-- Action bar above the composer: changes, to-dos, Check gates · Review · Commit · Push (& PR).
     The next step is the primary button: commit while something is uncommitted, then push. --}}
<div x-on:argos-turn-completed.window="$wire.$refresh()" x-on:argos-workspace-changed.window="$wire.$refresh()" x-on:argos-gates-updated.window="$wire.$refresh()">
    @if ($show)
        <div class="ag-tray" role="region" aria-label="{{ __('workspaces.tray.label') }}">
            <div class="ag-tray-row">
                @if ($hasChanges)
                    <x-ag.icon name="file" :size="16" class="ag-muted" />
                    <span class="ag-tray-files">{{ trans_choice('workspaces.tray.files', $count, ['count' => $count]) }}</span>
                    <span class="mono ag-add">+{{ $additions }}</span><span class="mono ag-del">−{{ $deletions }}</span>
                    <span class="ag-muted ag-tray-gates">· {{ $gates }}</span>
                @elseif ($ahead > 0)
                    <x-ag.icon name="arrow-up" :size="16" class="ag-muted" />
                    <span class="ag-tray-files">{{ trans_choice('workspaces.tray.unpushed', $ahead, ['count' => $ahead]) }}</span>
                @else
                    <x-ag.icon name="alert" :size="16" class="ag-muted" />
                    <span class="ag-tray-files">{{ trans_choice('workspaces.tray.todo_count', count($todos), ['count' => count($todos)]) }}</span>
                @endif
                <span class="ag-spacer"></span>
                @if ($hasChanges && $canOperate)
                    <button type="button" class="ag-btn ag-btn-sm ag-btn-surface" x-on:click="$dispatch('workspace-run-gates')">{{ __('workspaces.tray.check') }}</button>
                @endif
                @if ($hasChanges)
                    <button type="button" class="ag-btn ag-btn-sm ag-btn-surface" x-on:click="window.dispatchEvent(new CustomEvent('review-open', { detail: { file: null } }))">{{ __('workspaces.tray.review') }}</button>
                @endif
                @if ($canShip && $uncommitted > 0)
                    <button type="button" class="ag-btn ag-btn-sm ag-btn-primary" wire:click="$dispatch('workspace-ship', { step: 'commit' })" data-testid="tray-commit">
                        <x-ag.icon name="check" :size="14" :stroke="2" /> {{ __('workspaces.ship.commit') }}
                    </button>
                @endif
                @if ($canPush)
                    <button type="button" @class(['ag-btn ag-btn-sm', 'ag-btn-primary' => $uncommitted === 0, 'ag-btn-surface' => $uncommitted > 0])
                            wire:click="$dispatch('workspace-ship', { step: 'push' })" data-testid="tray-push"
                            @if ($ahead > 0) title="{{ trans_choice('workspaces.tray.unpushed', $ahead, ['count' => $ahead]) }}" @endif>
                        <x-ag.icon name="{{ $hasPr ? 'arrow-up' : 'git-pr' }}" :size="14" :stroke="2" /> {{ __($hasPr ? 'workspaces.ship.push' : 'workspaces.ship.push_pr') }}
                    </button>
                @endif
            </div>
            @if ($todos !== [])
                <ul class="ag-tray-todos">
                    @foreach ($todos as $todo)
                        <li @class(['ag-tray-todo', 'is-'.$todo['kind']])>
                            <span class="ag-tray-todo-dot" aria-hidden="true"></span>
                            <span class="ag-tray-todo-label">{{ $todo['label'] }}</span>
                            @if (isset($todo['gate']))
                                <button type="button" class="ag-text-btn" x-on:click="$dispatch('gate-detail-open', { taskId: @js($taskId), gate: @js($todo['gate']) })">{{ $todo['action'] }}</button>
                            @else
                                <button type="button" class="ag-text-btn" wire:click="$dispatch('{{ $todo['event'] }}')" wire:loading.attr="disabled">{{ $todo['action'] }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
