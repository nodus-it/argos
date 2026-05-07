@php
    $sourceUrl = rtrim((string) config('argos.source_url'), '/');
    $issueUrl = $sourceUrl !== '' ? $sourceUrl.'/issues/new/choose' : null;
@endphp

@if (filled($issueUrl))
    <x-filament::button
        tag="a"
        :href="$issueUrl"
        target="_blank"
        icon="heroicon-o-chat-bubble-left-ellipsis"
        color="primary"
        size="sm"
        :tooltip="__('common.feedback_button.tooltip')"
        class="fi-argos-feedback-btn"
    >
        {{ __('common.feedback_button.label') }}
    </x-filament::button>
@endif
