@php
    $isPoppedOut = $session->isPoppedOut();
    $label = $isPoppedOut ? 'Terminal' : $session->permissionLabel();
    $isExecute = $session->isSkipPermissions();
@endphp

<span @class([
    'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase leading-none',
    'bg-cyan-500/20 text-cyan-400' => $isPoppedOut,
    'bg-blue-500/20 text-blue-400' => ! $isPoppedOut && ! $isExecute,
    'bg-amber-500/20 text-amber-400' => ! $isPoppedOut && $isExecute,
])>
    {{ $label }}
</span>
