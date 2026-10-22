{{-- Action bar above the composer: changes, to-dos, Check gates · Review · Commit & PR. --}}
<div x-on:argos-turn-completed.window="$wire.$refresh()" x-on:argos-workspace-changed.window="$wire.$refresh()" x-on:argos-gates-updated.window="$wire.$refresh()">
    @if ($show)
        <div class="wf-tray" role="region" aria-label="{{ __('workspaces.tray.label') }}">
            <div class="wf-tray-row">
                @if ($hasChanges)
                    <x-wf.icon name="file" :size="16" class="wf-muted" />
                    <span class="wf-tray-files">{{ trans_choice('workspaces.tray.files', $count, ['count' => $count]) }}</span>
                    <span class="mono wf-add">+{{ $additions }}</span><span class="mono wf-del">−{{ $deletions }}</span>
                    <span class="wf-muted wf-tray-gates">· {{ $gates }}</span>
                @elseif ($ahead > 0)
                    <x-wf.icon name="arrow-up" :size="16" class="wf-muted" />
                    <span class="wf-tray-files">{{ trans_choice('workspaces.tray.unpushed', $ahead, ['count' => $ahead]) }}</span>
                @else
                    <x-wf.icon name="alert" :size="16" class="wf-muted" />
                    <span class="wf-tray-files">{{ trans_choice('workspaces.tray.todo_count', count($todos), ['count' => count($todos)]) }}</span>
                @endif
                <span class="wf-spacer"></span>
                @if ($hasChanges && $canOperate)
                    <button type="button" class="wf-btn wf-btn-sm wf-btn-surface" x-on:click="$dispatch('workspace-run-gates')">{{ __('workspaces.tray.check') }}</button>
                @endif
                @if ($hasChanges)
                    <button type="button" class="wf-btn wf-btn-sm wf-btn-surface" x-on:click="window.dispatchEvent(new CustomEvent('review-open', { detail: { file: null } }))">{{ __('workspaces.tray.review') }}</button>
                @endif
                @if ($canShip)
                    <button type="button" class="wf-btn wf-btn-sm wf-btn-primary" wire:click="$dispatch('workspace-ship')" data-testid="tray-ship">
                        <x-wf.icon name="git-pr" :size="14" :stroke="2" /> {{ __('workspaces.ship.action') }}
                    </button>
                @endif
            </div>
            @if ($todos !== [])
                <ul class="wf-tray-todos">
                    @foreach ($todos as $todo)
                        <li @class(['wf-tray-todo', 'is-'.$todo['kind']])>
                            <span class="wf-tray-todo-dot" aria-hidden="true"></span>
                            <span class="wf-tray-todo-label">{{ $todo['label'] }}</span>
                            @if (isset($todo['gate']))
                                <button type="button" class="wf-text-btn" x-on:click="$dispatch('gate-detail-open', { taskId: @js($taskId), gate: @js($todo['gate']) })">{{ $todo['action'] }}</button>
                            @else
                                <button type="button" class="wf-text-btn" wire:click="$dispatch('{{ $todo['event'] }}')" wire:loading.attr="disabled">{{ $todo['action'] }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
