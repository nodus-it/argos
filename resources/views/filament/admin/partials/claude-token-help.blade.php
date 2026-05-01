<div class="mt-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 overflow-hidden">
    <div class="flex items-center gap-2 px-4 py-3 border-b border-amber-200 dark:border-amber-800">
        <x-heroicon-o-information-circle class="h-4 w-4 text-amber-600 dark:text-amber-400 flex-shrink-0" />
        <span class="text-sm font-semibold text-amber-800 dark:text-amber-300">So richtest du den Token ein</span>
    </div>
    <div class="px-4 py-4 space-y-4 text-sm text-amber-900 dark:text-amber-200">

        <div class="flex gap-3">
            <span class="flex h-5 w-5 mt-0.5 items-center justify-center rounded-full bg-amber-200 dark:bg-amber-800 text-amber-700 dark:text-amber-300 text-xs font-bold flex-shrink-0">1</span>
            <div>
                <p class="font-medium">Token generieren — öffnet Browser für OAuth-Flow</p>
                <pre class="mt-1.5 rounded bg-amber-100 dark:bg-amber-900 px-3 py-2 text-xs font-mono text-amber-800 dark:text-amber-200 overflow-x-auto">claude setup-token</pre>
                <p class="mt-1.5 text-xs text-amber-700 dark:text-amber-400">Nach Bestätigung im Browser wird der Token direkt im Terminal ausgegeben.</p>
            </div>
        </div>

        <div class="flex gap-3">
            <span class="flex h-5 w-5 mt-0.5 items-center justify-center rounded-full bg-amber-200 dark:bg-amber-800 text-amber-700 dark:text-amber-300 text-xs font-bold flex-shrink-0">2</span>
            <div>
                <p class="font-medium">Token in <code class="bg-amber-100 dark:bg-amber-900 px-1 rounded">.env</code> eintragen</p>
                <pre class="mt-1.5 rounded bg-amber-100 dark:bg-amber-900 px-3 py-2 text-xs font-mono text-amber-800 dark:text-amber-200 overflow-x-auto">CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-...</pre>
            </div>
        </div>

        <div class="flex gap-3">
            <span class="flex h-5 w-5 mt-0.5 items-center justify-center rounded-full bg-amber-200 dark:bg-amber-800 text-amber-700 dark:text-amber-300 text-xs font-bold flex-shrink-0">3</span>
            <div>
                <p class="font-medium">Config neu laden</p>
                <pre class="mt-1.5 rounded bg-amber-100 dark:bg-amber-900 px-3 py-2 text-xs font-mono text-amber-800 dark:text-amber-200 overflow-x-auto">php artisan config:clear</pre>
            </div>
        </div>

        <div class="rounded bg-amber-100 dark:bg-amber-900 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
            <strong>Hinweis:</strong> Der Token läuft nach einigen Wochen ab. Einfach <code class="bg-amber-50 dark:bg-amber-950 px-1 rounded">claude setup-token</code> erneut ausführen und den neuen Token in <code class="bg-amber-50 dark:bg-amber-950 px-1 rounded">.env</code> eintragen.
        </div>

    </div>
</div>
