{{-- Asks this browser for permission to show workspace notifications. --}}
<div x-data="{ state: ('Notification' in window) ? Notification.permission : 'denied' }" class="ws-browser-notifications">
    <span class="fi-fo-field-wrp-label">{{ __('workspaces.notifications.browser') }}</span>
    <div x-show="state === 'default'">
        <button type="button" class="btn btn-secondary btn-sm"
                x-on:click="Notification.requestPermission().then((p) => state = p)">
            @svg('heroicon-o-bell-alert') {{ __('workspaces.notifications.browser_enable') }}
        </button>
    </div>
    <p class="muted" x-show="state === 'granted'" x-cloak>{{ __('workspaces.notifications.browser_enabled') }}</p>
    <p class="muted" x-show="state === 'denied'" x-cloak>{{ __('workspaces.notifications.browser_denied') }}</p>
</div>
