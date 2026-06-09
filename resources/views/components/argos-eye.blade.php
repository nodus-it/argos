{{-- "Lens" brand mark — eye + terminal cursor, sized via the `size` prop.
     Strokes/fills use the accent token; the pupil uses --surface. Inlined (not
     a blade-icon component) because the panel runs DisableBladeIconComponents.
     See ARGOS_LOGIN.md §4. --}}
@props(['size' => 40])
<svg {{ $attributes->merge(['class' => 'argos-eye']) }} viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"
     style="width:{{ $size }}px;height:{{ $size }}px" aria-hidden="true">
    <path d="M3 20S9.5 9 20 9s17 11 17 11-6.5 11-17 11S3 20 3 20Z"
          stroke="var(--accent)" stroke-width="2.4" fill="none"/>
    <circle cx="20" cy="20" r="8.4" fill="var(--accent)"/>
    <circle cx="20" cy="20" r="6" fill="var(--surface)"/>
    <path d="M17.4 17 20 20l-2.6 3"
          stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
    <rect x="21.4" y="21.6" width="2.6" height="1.9" rx=".4" fill="var(--accent)"/>
</svg>
