@php
    use App\Filament\Workspaces\Clusters\Account\Resources\AgentCredentialResource;

    /** @var \Illuminate\Support\Collection<int, \App\Models\AgentCredential> $agents */
@endphp
{{-- The user's own Claude subscriptions (agent credentials). Everybody may
     manage their own agents in the admin panel's agent list. --}}
<div class="argos-own-agents">
    @if ($agents->isEmpty())
        <p class="argos-own-agents-empty">{{ __('common.profile.agents_empty') }}</p>
    @else
        <ul class="argos-own-agents-list" role="list">
            @foreach ($agents as $agent)
                <li class="argos-own-agents-row" wire:key="own-agent-{{ $agent->id }}">
                    <a href="{{ AgentCredentialResource::getUrl('edit', ['record' => $agent], panel: 'workspaces') }}" class="argos-own-agents-name">{{ $agent->name }}</a>
                    <span class="argos-own-agents-meta">
                        @if ($agent->isUsageLimited())
                            <x-filament::badge color="warning">{{ __('common.profile.agents_limited', ['time' => $agent->usage_limited_until?->diffForHumans()]) }}</x-filament::badge>
                        @endif
                        <x-filament::badge :color="$agent->status->color()">{{ $agent->status->label() }}</x-filament::badge>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
    <div class="argos-own-agents-actions">
        <x-filament::button tag="a" :href="AgentCredentialResource::getUrl('create', panel: 'workspaces')" icon="heroicon-o-plus" size="sm">
            {{ __('common.profile.agents_add') }}
        </x-filament::button>
        <x-filament::button tag="a" :href="AgentCredentialResource::getUrl('index', panel: 'workspaces')" color="gray" size="sm">
            {{ __('common.profile.agents_manage') }}
        </x-filament::button>
    </div>
</div>
