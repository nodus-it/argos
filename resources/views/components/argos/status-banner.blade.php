@props([
    'state' => 'draft',   // running | queued | waiting | paused | failed | done | draft
    'title' => '',
    'hint' => null,        // secondary line (e.g. "respond below" / resume hint)
    'startedAt' => null,   // epoch seconds — renders a live mm:ss timer (running only)
    'error' => null,       // error text to surface (failed only)
    'logsUrl' => null,     // optional link (e.g. logs / quality gates)
    'logsLabel' => null,
    'rail' => null,        // optional phase stepper: list of ['phase','state','label']
])
{{--
    The single "what is the system doing right now" header. When a phase stepper
    (`rail`) is passed, it renders as one unit: the stepper on top, a coloured
    status band (<x-argos.status-line>) below — so the workflow position and the
    current state read together. Without a rail it degrades to the bare status
    line. Driven by App\Support\Workflow\TaskStage. Workflow M1.
--}}
@if ($rail)
    <div {{ $attributes->merge(['class' => 'workflow-banner']) }}>
        <div class="wb-rail">
            <x-argos.phase-rail :rail="$rail" />
        </div>
        <x-argos.status-line
            :state="$state"
            :title="$title"
            :hint="$hint"
            :startedAt="$startedAt"
            :error="$error"
            :logsUrl="$logsUrl"
            :logsLabel="$logsLabel"
            :flush="true" />
    </div>
@else
    <x-argos.status-line
        :state="$state"
        :title="$title"
        :hint="$hint"
        :startedAt="$startedAt"
        :error="$error"
        :logsUrl="$logsUrl"
        :logsLabel="$logsLabel"
        {{ $attributes }} />
@endif
