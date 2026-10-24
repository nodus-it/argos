{{-- The setup checklist (WorkspaceOnboarding): one row per step, open ones with their action. --}}
<ol class="ag-onboarding" data-testid="onboarding">
    @foreach ($steps as $step)
        <li @class(['ag-ob-step', 'is-done' => $step['done']]) data-step="{{ $step['key'] }}">
            <span class="ag-ob-mark" aria-hidden="true">@if ($step['done'])<x-ag.icon name="check" :size="13" :stroke="2.4" />@endif</span>
            <span class="ag-ob-label">{{ $step['label'] }}@unless ($step['required'])<span class="ag-muted"> · {{ __('workspaces.onboarding.optional') }}</span>@endunless</span>
            @unless ($step['done'])
                <a class="ag-btn ag-btn-sm ag-btn-soft" href="{{ $step['url'] }}" wire:navigate>{{ $step['action'] }}</a>
            @endunless
        </li>
    @endforeach
</ol>
