@php
    $sourceUrl = rtrim((string) config('argos.source_url'), '/');
    $version = (string) config('argos.version');

    // A real release (SemVer) links to its GitHub release; a stage build
    // (`stage-<date>-<sha>`) links to the exact commit; anything else to the
    // repo root — so the footer link never 404s on non-release versions.
    if (preg_match('/^\d+\.\d+\.\d+(-[\w.]+)?$/', $version)) {
        $releaseUrl = $sourceUrl.'/releases/tag/'.rawurlencode($version);
    } elseif (preg_match('/-([0-9a-f]{7,40})$/', $version, $m)) {
        $releaseUrl = $sourceUrl.'/commit/'.$m[1];
    } else {
        $releaseUrl = $sourceUrl;
    }
@endphp

<div class="fi-argos-source-footer mt-6 mb-4 px-6 text-center text-xs text-gray-500 dark:text-gray-400">
    {!! __('common.source_footer', [
        'version' => '<a href="'.e($releaseUrl).'" target="_blank" rel="noopener" class="underline hover:text-gray-700 dark:hover:text-gray-200">v'.e($version).'</a>',
        'license' => '<a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener" class="underline hover:text-gray-700 dark:hover:text-gray-200">AGPL-3.0</a>',
        'source' => '<a href="'.e($sourceUrl).'" target="_blank" rel="noopener" class="underline hover:text-gray-700 dark:hover:text-gray-200">'.e(__('common.source_link_label')).'</a>',
    ]) !!}
</div>
