@props([
    'icon',
    'title',
    'sub' => null,
    'chips' => [],
])
{{--
    Slim page hero for list pages (Tasks, Projects).
    Props:
      icon   — heroicon suffix, e.g. "queue-list" or "folder"
      title  — page title string
      sub    — optional subtitle
      chips  — array of ['label' => '', 'value' => 0, 'type' => 'run|wait|ok']
    Default slot: action button (right side).
--}}
<div class="ph-hero cr-scope">
    <x-argos.cr-bg />

    <div class="ph-ic">
        @svg('heroicon-o-' . $icon)
    </div>

    <div class="ph-txt">
        <h2>{{ $title }}</h2>
        @if ($sub)
            <p class="sub">{{ $sub }}</p>
        @endif
        <div class="ph-meta">
            @foreach ($chips as $chip)
                <span class="ph-chip d-{{ $chip['type'] ?? 'ok' }}">
                    @if (($chip['type'] ?? '') !== 'ok')
                        <span class="dot" aria-hidden="true"></span>
                    @endif
                    <span class="num">{{ $chip['value'] }}</span>
                    {{ $chip['label'] }}
                </span>
            @endforeach
        </div>
    </div>

    @if ($slot->isNotEmpty())
        <div class="ph-act">
            {{ $slot }}
        </div>
    @endif
</div>
