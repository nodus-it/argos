{{-- "Lens" brand mark — eye + terminal cursor. Strokes/fills use the accent
     token; the pupil uses --surface so it adapts to light/dark automatically.
     See docs/design/argos/ARGOS_REDESIGN.md §4. --}}
<span class="brand" style="height:1.75rem;">
    <svg class="eye" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"
         style="width:1.75rem;height:1.75rem;">
        <path d="M3 20S9.5 9 20 9s17 11 17 11-6.5 11-17 11S3 20 3 20Z"
              stroke="var(--accent)" stroke-width="2.4" fill="none"/>
        <circle cx="20" cy="20" r="8.4" fill="var(--accent)"/>
        <circle cx="20" cy="20" r="6" fill="var(--surface)"/>
        <path d="M17.4 17 20 20l-2.6 3"
              stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        <rect x="21.4" y="21.6" width="2.6" height="1.9" rx=".4" fill="var(--accent)"/>
    </svg>
    <span class="word">ARGOS</span>
</span>
