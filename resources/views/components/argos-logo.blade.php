<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 148 32" fill="none">
    {{-- Eyelid outline – indigo, thick, always visible --}}
    <path
        d="M 2,16 Q 16,7 30,16 Q 16,25 2,16 Z"
        class="stroke-indigo-500 dark:stroke-indigo-400"
        fill="none"
        stroke-width="2"
        stroke-linejoin="round"
    />

    {{-- Iris – solid indigo fill --}}
    <circle cx="16" cy="16" r="7.5" class="fill-indigo-600 dark:fill-indigo-500"/>

    {{-- Pupil – white for maximum contrast --}}
    <circle cx="16" cy="16" r="3.8" fill="white" opacity="0.92"/>

    {{-- Terminal cursor > inside pupil --}}
    <path
        d="M 14.8,14.5 L 17,16 L 14.8,17.5"
        stroke="#4338ca"
        stroke-width="1.3"
        stroke-linecap="round"
        stroke-linejoin="round"
        fill="none"
    />

    {{-- Iris highlight --}}
    <circle cx="20" cy="12.5" r="1.4" fill="white" opacity="0.35"/>

    {{-- Wordmark --}}
    <text
        x="40"
        y="21"
        font-family="ui-monospace, 'Cascadia Code', 'Source Code Pro', Menlo, Consolas, monospace"
        font-size="16"
        font-weight="700"
        letter-spacing="3.5"
        class="fill-slate-800 dark:fill-slate-100"
    >ARGOS</text>
</svg>
