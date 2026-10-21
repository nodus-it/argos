{{-- Bell trigger in the icon rail; the modal is Filament's. --}}
<button type="button" class="wf-rail-btn wf-rail-bell"
        aria-label="{{ $unreadNotificationsCount ? __('workspaces.panel.notifications_unread', ['count' => $unreadNotificationsCount]) : __('workspaces.panel.notifications') }}"
        title="{{ __('workspaces.panel.notifications') }}">
    <x-wf.icon name="bell" :size="20" />
    @if ($unreadNotificationsCount)
        <span class="wf-rail-badge" aria-hidden="true"></span>
    @endif
</button>
