@php
    $rootSession = $session->parent ?? $session;
    $debugPrompt = $rootSession->prompt;
    $debugScreenshot = $rootSession->screenshot_path;
    $debugSourceUrl = $rootSession->source_url;
    $debugRouteHandler = $rootSession->route_handler;
    $debugRouteHandlerType = $rootSession->route_handler_type;
    $debugRouteName = $rootSession->route_name;
    $debugUserEmail = $rootSession->user_email;
    $debugUserId = $rootSession->user_id;
    $debugPreviewUrl = $rootSession->previewUrl();

    $resolver = app(\MakeDev\Orca\Services\RouteResolver::class);
    $debugHandlerFile = $resolver->resolveHandlerFile($debugRouteHandler);
    $debugViewFiles = $resolver->resolveViewFiles($debugRouteHandler, $debugSourceUrl);
@endphp

<div
    x-data="{ open: false }"
    class="relative"
>
    <button
        x-on:click="open = !open"
        class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-zinc-200"
        title="Debug Info"
    >
        <flux:icon name="document-text" variant="micro" class="size-3.5" />
    </button>

    <div
        x-show="open"
        x-on:click.outside="open = false"
        x-on:keydown.escape.window="open = false"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="scale-95 opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="scale-100 opacity-100"
        x-transition:leave-end="scale-95 opacity-0"
        class="absolute right-0 bottom-full z-50 mb-1 w-80 rounded-lg border border-zinc-700 bg-zinc-800 p-3 shadow-xl"
        style="display: none;"
    >
        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Debug Info</h4>

        <div class="space-y-2.5">
            {{-- Initial Prompt --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Prompt</span>
                <div class="mt-0.5 max-h-24 overflow-y-auto rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] leading-relaxed text-zinc-300">{{ $debugPrompt ?? 'N/A' }}</div>
            </div>

            {{-- Source URL --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Source URL</span>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300" title="{{ $debugSourceUrl }}">{{ $debugSourceUrl ?? 'N/A' }}</div>
            </div>

            {{-- Screenshot Path --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Screenshot</span>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300" title="{{ $debugScreenshot }}">{{ $debugScreenshot ?? 'None' }}</div>
            </div>

            {{-- Route --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Route</span>
                <div class="mt-0.5 rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300">
                    @if ($debugRouteHandler)
                        <span class="text-zinc-500">{{ $debugRouteHandlerType }}:</span>
                        <span title="{{ $debugRouteHandler }}">{{ class_basename(Str::before($debugRouteHandler, '@')) }}{{ Str::contains($debugRouteHandler, '@') ? '@' . Str::after($debugRouteHandler, '@') : '' }}</span>
                        @if ($debugRouteName)
                            <span class="ml-1 text-zinc-500">({{ $debugRouteName }})</span>
                        @endif
                    @else
                        N/A
                    @endif
                </div>
            </div>

            {{-- Handler --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Handler</span>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300" title="{{ $debugHandlerFile }}">{{ $debugHandlerFile ?? 'N/A' }}</div>
            </div>

            {{-- Views --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Views</span>
                <div class="mt-0.5 rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300">
                    @forelse ($debugViewFiles as $viewFile)
                        <div class="truncate" title="{{ $viewFile }}">{{ $viewFile }}</div>
                    @empty
                        N/A
                    @endforelse
                </div>
            </div>

            {{-- Auth User --}}
            <div>
                <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Auth User</span>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300">
                    @if ($debugUserId)
                        {{ $debugUserEmail }} <span class="text-zinc-500">(#{{ $debugUserId }})</span>
                    @else
                        Guest
                    @endif
                </div>
            </div>

            {{-- Preview --}}
            @if ($debugPreviewUrl)
                <div>
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Preview</span>
                    <div class="mt-0.5">
                        <a href="{{ $debugPreviewUrl }}" target="_blank" class="inline-flex items-center gap-1 rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-blue-400 hover:text-blue-300">
                            <flux:icon name="arrow-top-right-on-square" variant="micro" class="size-3" />
                            Open as user
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
