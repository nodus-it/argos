<x-filament-panels::page>
    <div class="space-y-6">
        @php $accounts = $this->getConnectedAccounts(); @endphp

        <x-help-hint tkey="help.oauth.overview" tone="info" />

        @foreach (\App\Filament\App\Clusters\Account\Pages\ConnectedAccounts::PROVIDERS as $provider)
            @include('filament.app.account.provider-section', ['provider' => $provider, 'account' => $accounts[$provider] ?? null, 'configured' => $this->isConfigured($provider)])
        @endforeach
    </div>
</x-filament-panels::page>
