{{-- Change tray above the composer (only with uncommitted changes). --}}
<div x-on:argos-turn-completed.window="$wire.$refresh()" x-on:argos-workspace-changed.window="$wire.$refresh()" x-on:argos-gates-updated.window="$wire.$refresh()">
    @if ($show)
        <div class="wf-tray" role="status">
            <x-wf.icon name="file" :size="16" class="wf-muted" />
            <span class="wf-tray-files">{{ trans_choice('workspaces.tray.files', $count, ['count' => $count]) }}</span>
            <span class="mono wf-add">+{{ $additions }}</span><span class="mono wf-del">−{{ $deletions }}</span>
            <span class="wf-muted wf-tray-gates">· {{ $gates }}</span>
            <span class="wf-spacer"></span>
            @if ($canOperate)
                <button type="button" class="wf-btn wf-btn-sm wf-btn-surface" x-on:click="$dispatch('workspace-run-gates')">{{ __('workspaces.tray.check') }}</button>
            @endif
            <button type="button" class="wf-btn wf-btn-sm wf-btn-surface" x-on:click="window.dispatchEvent(new CustomEvent('review-open', { detail: { file: null } }))">{{ __('workspaces.tray.review') }}</button>
        </div>
    @endif
</div>
