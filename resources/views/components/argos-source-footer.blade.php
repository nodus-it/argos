@php
    $sourceUrl = config('argos.source_url');
@endphp

<div class="fi-argos-source-footer mt-6 mb-4 px-6 text-center text-xs text-gray-500 dark:text-gray-400">
    {!! __('common.source_footer', [
        'license' => '<a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener" class="underline hover:text-gray-700 dark:hover:text-gray-200">AGPL-3.0</a>',
        'source' => '<a href="'.e($sourceUrl).'" target="_blank" rel="noopener" class="underline hover:text-gray-700 dark:hover:text-gray-200">'.e(__('common.source_link_label')).'</a>',
    ]) !!}
</div>
