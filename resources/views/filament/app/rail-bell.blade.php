{{-- Bell trigger in the icon rail; the modal is Filament's. --}}
<button type="button" class="ag-rail-btn ag-rail-bell"
        aria-label="{{ $unreadNotificationsCount ? __('workspaces.panel.notifications_unread', ['count' => $unreadNotificationsCount]) : __('workspaces.panel.notifications') }}"
        title="{{ __('workspaces.panel.notifications') }}">
    <x-ag.icon name="bell" :size="20" />
    @if ($unreadNotificationsCount)
        <span class="ag-rail-badge" aria-hidden="true"></span>
    @endif
</button>
