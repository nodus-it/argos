{{-- An outdated comment: Claude changed its lines after it was written. --}}
<p class="wf-dnote-hint">{{ __('workspaces.review.stale_hint') }}</p>
<div class="wf-row wf-dnote-actions">
    <button type="button" class="wf-btn wf-btn-sm wf-btn-surface" wire:click="sendAnyway(@js($comment->id))">{{ __('workspaces.review.send_anyway') }}</button>
    @if ($comment->side === 'new')
        <button type="button" class="wf-btn wf-btn-sm wf-btn-surface" wire:click="moveComment(@js($comment->id))">{{ __('workspaces.review.move') }}</button>
    @endif
    <button type="button" class="wf-text-btn wf-muted" wire:click="discardComment(@js($comment->id))">{{ __('workspaces.review.discard') }}</button>
</div>
