<x-filament-panels::page>
    {{--
        Three-column docs layout: section/page sidebar · prose content · TOC.
        Collapses to a single column below lg. Content is trusted repo Markdown
        rendered by App\Services\Docs\DocsRenderer (anchors + rewritten links).
    --}}
    <div class="grid grid-cols-1 gap-8 lg:grid-cols-[13rem_minmax(0,1fr)] xl:grid-cols-[13rem_minmax(0,1fr)_12rem]">

        {{-- Sidebar: manifest sections + pages --}}
        <nav class="text-sm lg:border-r lg:border-gray-200 lg:pr-4 dark:lg:border-white/10" aria-label="{{ __('navigation.pages.documentation') }}">
            @foreach ($this->sections() as $section)
                <p class="px-2 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 first:pt-0 dark:text-gray-500">
                    {{ $section['title'] }}
                </p>
                @foreach ($section['pages'] as $page)
                    <a
                        href="{{ route('filament.admin.pages.docs', ['slug' => $page['slug']]) }}"
                        wire:navigate
                        @class([
                            'block rounded-md px-2 py-1.5 transition',
                            'bg-primary-50 font-medium text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $page['slug'] === $docSlug,
                            'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $page['slug'] !== $docSlug,
                        ])
                    >
                        {{ $page['title'] }}
                    </a>
                @endforeach
            @endforeach
        </nav>

        {{-- Content --}}
        <article class="prose prose-sm max-w-none dark:prose-invert prose-headings:scroll-mt-24 prose-pre:bg-gray-950 prose-pre:text-gray-100">
            {!! $html !!}
        </article>

        {{-- On-this-page TOC (xl+) --}}
        @if (filled($toc))
            <aside class="hidden text-sm xl:block">
                <div class="sticky top-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        {{ __('navigation.pages.documentation_on_this_page') }}
                    </p>
                    <ul class="space-y-1">
                        @foreach ($toc as $item)
                            <li @style(['padding-left: 0.75rem' => $item['level'] === 3])>
                                <a href="#{{ $item['slug'] }}" class="block text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
                                    {{ $item['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </aside>
        @endif
    </div>
</x-filament-panels::page>
