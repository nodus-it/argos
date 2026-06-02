@props([
    'label' => '',
    'mono' => false,
    'link' => false,
])
{{-- One labelled value inside <x-argos.meta-strip>. §5.6. --}}
<div class="ms-item">
    <span class="ms-k">{{ $label }}</span>
    <span @class(['ms-v', 'mono' => $mono, 'link' => $link])>{{ $slot }}</span>
</div>
