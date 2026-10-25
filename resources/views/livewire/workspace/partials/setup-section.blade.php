{{-- The setup checklist in the sidebar: open at the top while a required step is open, folded at the end after. --}}
<section class="ag-section" id="ctx-setup" x-data="{ ...argosFold(@js($open ? 'setup' : 'setup-done'), @js($open)) }" :class="{ 'is-folded': !open }">
    <h2 class="ag-section-h"><button type="button" class="ag-section-title ag-fold-btn" x-on:click="toggleFold()" :aria-expanded="open"><x-ag.icon name="chevron-right" :size="12" :stroke="2.4" class="ag-fold-chev" />{{ __('workspaces.onboarding.section') }} · {{ collect($steps)->where('done', true)->count() }}/{{ count($steps) }}</button></h2>
    @include('livewire.workspace.partials.onboarding', ['steps' => $steps])
</section>
