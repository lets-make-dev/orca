@switch($status)
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::Running)
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::AwaitingInput)
        <span class="relative flex h-2 w-2 flex-shrink-0">
            <span @class([
                'absolute inline-flex h-full w-full animate-ping rounded-full opacity-75',
                'bg-lime-400' => $status === \MakeDev\Orca\Enums\OrcaSessionStatus::Running,
                'bg-amber-400' => $status === \MakeDev\Orca\Enums\OrcaSessionStatus::AwaitingInput,
            ])></span>
            <span @class([
                'relative inline-flex h-2 w-2 rounded-full',
                'bg-lime-500' => $status === \MakeDev\Orca\Enums\OrcaSessionStatus::Running,
                'bg-amber-500' => $status === \MakeDev\Orca\Enums\OrcaSessionStatus::AwaitingInput,
            ])></span>
        </span>
        @break
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::Pending)
        <span class="h-2 w-2 flex-shrink-0 rounded-full bg-zinc-400"></span>
        @break
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::Completed)
        <span class="h-2 w-2 flex-shrink-0 rounded-full bg-zinc-500"></span>
        @break
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::Failed)
        <span class="h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
        @break
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::Cancelled)
        <span class="h-2 w-2 flex-shrink-0 rounded-full bg-zinc-500"></span>
        @break
    @case(\MakeDev\Orca\Enums\OrcaSessionStatus::PoppedOut)
        <span class="relative flex h-2 w-2 flex-shrink-0">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-cyan-400 opacity-75"></span>
            <span class="relative inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
        </span>
        @break
@endswitch
