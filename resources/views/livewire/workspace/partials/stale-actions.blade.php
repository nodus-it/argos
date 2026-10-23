{{-- An outdated comment: Claude changed its lines after it was written. --}}
<p class="ag-dnote-hint">{{ __('workspaces.review.stale_hint') }}</p>
<div class="ag-row ag-dnote-actions">
    <button type="button" class="ag-btn ag-btn-sm ag-btn-surface" wire:click="sendAnyway(@js($comment->id))">{{ __('workspaces.review.send_anyway') }}</button>
    @if ($comment->side === 'new')
        <button type="button" class="ag-btn ag-btn-sm ag-btn-surface" wire:click="moveComment(@js($comment->id))">{{ __('workspaces.review.move') }}</button>
    @endif
    <button type="button" class="ag-text-btn ag-muted" wire:click="discardComment(@js($comment->id))">{{ __('workspaces.review.discard') }}</button>
</div>
