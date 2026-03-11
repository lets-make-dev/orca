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

    $debugRouteDisplay = 'N/A';
    if ($debugRouteHandler) {
        $debugRouteDisplay = $debugRouteHandlerType . ': ' . class_basename(Str::before($debugRouteHandler, '@'));
        if (Str::contains($debugRouteHandler, '@')) {
            $debugRouteDisplay .= '@' . Str::after($debugRouteHandler, '@');
        }
        if ($debugRouteName) {
            $debugRouteDisplay .= ' (' . $debugRouteName . ')';
        }
    }
    $debugUserDisplay = $debugUserId ? $debugUserEmail . ' (#' . $debugUserId . ')' : 'Guest';
    $debugViewsDisplay = implode("\n", $debugViewFiles) ?: 'N/A';
@endphp

<div
    x-data="{
        open: false,
        copied: false,
        checked: {
            prompt: true,
            sourceUrl: true,
            screenshot: false,
            route: true,
            handler: true,
            views: true,
            authUser: true,
        },
        copyDebug() {
            const container = this.$refs.debugCopySource;
            let parts = ['# Debug Info'];
            container.querySelectorAll('[data-debug-field]').forEach(el => {
                if (this.checked[el.dataset.debugField]) {
                    parts.push(el.textContent.trim());
                }
            });
            navigator.clipboard.writeText(parts.join('\n\n'));
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },
    }"
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
        <div class="mb-2 flex items-center justify-between">
            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Debug Info</h4>
            <button
                x-on:click="copyDebug()"
                class="rounded p-1 text-zinc-500 transition hover:bg-zinc-700 hover:text-zinc-300"
                title="Copy checked fields"
            >
                <span x-show="!copied"><flux:icon name="clipboard-document" variant="micro" class="size-3.5" /></span>
                <span x-show="copied" x-cloak><flux:icon name="check" variant="micro" class="size-3.5 text-green-400" /></span>
            </button>
        </div>

        <div class="space-y-2.5">
            {{-- Prompt --}}
            <div>
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.prompt" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Prompt</span>
                </label>
                <div class="mt-0.5 max-h-24 overflow-y-auto rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] leading-relaxed text-zinc-300">{{ $debugPrompt ?? 'N/A' }}</div>
            </div>

            {{-- Source URL --}}
            <div>
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.sourceUrl" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Source URL</span>
                </label>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300" title="{{ $debugSourceUrl }}">{{ $debugSourceUrl ?? 'N/A' }}</div>
            </div>

            {{-- Screenshot --}}
            <div>
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.screenshot" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Screenshot</span>
                </label>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300" title="{{ $debugScreenshot }}">{{ $debugScreenshot ?? 'None' }}</div>
            </div>

            {{-- Route --}}
            <div>
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.route" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Route</span>
                </label>
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
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.handler" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Handler</span>
                </label>
                <div class="mt-0.5 truncate rounded bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-300" title="{{ $debugHandlerFile }}">{{ $debugHandlerFile ?? 'N/A' }}</div>
            </div>

            {{-- Views --}}
            <div>
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.views" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Views</span>
                </label>
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
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="checked.authUser" class="size-3 rounded border-zinc-600 bg-zinc-800 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">Auth User</span>
                </label>
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

        {{-- Hidden markdown source for clipboard copy --}}
        @include('orca::livewire.partials.debug-copy')
    </div>
</div>
