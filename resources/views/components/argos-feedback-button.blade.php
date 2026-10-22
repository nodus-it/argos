@php
    $issueUrl = \App\Support\FeedbackLink::url(request()->fullUrl());
@endphp

@if (filled($issueUrl))
    <x-filament::button
        tag="a"
        :href="$issueUrl"
        target="_blank"
        icon="heroicon-o-chat-bubble-left-ellipsis"
        color="gray"
        outlined
        size="sm"
        :tooltip="__('common.feedback_button.tooltip')"
        class="fi-argos-feedback-btn"
    >
        {{ __('common.feedback_button.label') }}
    </x-filament::button>
@endif
