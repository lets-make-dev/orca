<div
    wire:ignore
    class="absolute right-0 bottom-full mb-2 w-80 rounded-xl border border-zinc-200 bg-white p-4 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
    x-data="{
        annotating: false,
        capturing: false,
        thumbnailUrl: null,
        annotator: null,

        debugOpen: false,
        debugCopied: false,
        debugChecked: {
            sourceUrl: true,
            route: true,
            handler: true,
            views: true,
            authUser: true,
        },
        debugValues: @js($launcherDebugContext),

        annotateMode: 'click',

        startAnnotation(mode = 'click') {
            if (!this.annotator) {
                this.annotator = new window.OrcaAnnotator();
            }
            this.annotateMode = mode;
            this.annotator.enable(mode);
            this.annotating = true;
        },

        async uploadScreenshotBlob(blob) {
            const form = new FormData();
            form.append('screenshot', blob, 'screenshot.png');

            const response = await fetch('{{ route('orca.screenshot.store') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                },
                body: form,
            });

            if (!response.ok) throw new Error('Upload failed');

            const data = await response.json();
            $wire.set('screenshotPath', data.path);

            this.thumbnailUrl = URL.createObjectURL(blob);
        },

        async captureScreenshot() {
            if (!this.annotator) return;
            this.capturing = true;

            try {
                const blob = await this.annotator.capture();
                this.annotator.disable();
                this.annotating = false;

                await this.uploadScreenshotBlob(blob);
            } catch (e) {
                console.error('Screenshot capture failed:', e);
            } finally {
                this.capturing = false;
            }
        },

        cancelAnnotation() {
            if (this.annotator) {
                this.annotator.disable();
            }
            this.annotating = false;
        },

        removeScreenshot() {
            $wire.clearScreenshot();
            if (this.thumbnailUrl) {
                URL.revokeObjectURL(this.thumbnailUrl);
                this.thumbnailUrl = null;
            }
        },

        buildDebugContext() {
            if (!this.debugValues) return '';
            const labels = {
                sourceUrl: 'Source URL',
                route: 'Route',
                handler: 'Handler',
                views: 'Views',
                authUser: 'Auth User',
            };
            let parts = [];
            for (const [key, label] of Object.entries(labels)) {
                if (this.debugChecked[key] && this.debugValues[key]) {
                    parts.push(`## ${label}\n${this.debugValues[key]}`);
                }
            }
            return parts.length ? '# Debug Context\n\n' + parts.join('\n\n') : '';
        },

        copyDebugContext() {
            const text = this.buildDebugContext();
            if (text) {
                navigator.clipboard.writeText(text);
                this.debugCopied = true;
                setTimeout(() => this.debugCopied = false, 2000);
            }
        },

        submitPlan() {
            $wire.launchClaudeTerminal();
        },

        submitExecute() {
            $wire.launchClaudeTerminalExecute();
        },

        destroy() {
            if (this.annotator) {
                this.annotator.disable();
            }
            if (this.thumbnailUrl) {
                URL.revokeObjectURL(this.thumbnailUrl);
            }
        },
    }"
    x-on:orca-annotator-cancelled.window="annotating = false"
>
    <form x-on:submit.prevent="submitPlan()" class="space-y-2">
        {{-- Module context indicator --}}
        @if (! empty($this->moduleContext))
            <div class="flex items-center gap-1.5 rounded-lg bg-blue-500/10 px-2.5 py-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-3.5 shrink-0 text-blue-400">
                    <path fill-rule="evenodd" d="M15 8A7 7 0 1 1 1 8a7 7 0 0 1 14 0m-5-2a2 2 0 1 1-4 0 2 2 0 0 1 4 0m-2 8c1.653 0 3.156-.627 4.243-1.596A5 5 0 0 0 8 10a5 5 0 0 0-4.243 2.404A6.97 6.97 0 0 0 8 14" clip-rule="evenodd" />
                </svg>
                <span class="flex-1 text-xs font-medium text-blue-400">{{ $this->moduleContext['name'] ?? 'Module' }}</span>
                <button
                    type="button"
                    wire:click="$set('moduleContext', [])"
                    class="rounded p-0.5 text-blue-400/60 transition hover:text-blue-300"
                    title="Remove module context"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-3">
                        <path d="M5.28 4.22a.75.75 0 0 0-1.06 1.06L6.94 8l-2.72 2.72a.75.75 0 1 0 1.06 1.06L8 9.06l2.72 2.72a.75.75 0 1 0 1.06-1.06L9.06 8l2.72-2.72a.75.75 0 0 0-1.06-1.06L8 6.94z" />
                    </svg>
                </button>
            </div>
        @endif

        <template x-if="!annotating">
            <flux:textarea
                wire:model="prompt"
                placeholder="{{ ! empty($this->moduleContext) ? 'Ask about ' . ($this->moduleContext['name'] ?? 'this module') . '...' : 'Describe what you want Claude to do...' }}"
                rows="3"
                class="text-xs"
                x-on:keydown.cmd.enter="submitPlan()"
                x-on:keydown.ctrl.enter="submitPlan()"
                x-init="$nextTick(() => $el.focus())"
            />
        </template>

        {{-- Screenshot thumbnail preview --}}
        <template x-if="thumbnailUrl">
            <div class="relative inline-block">
                <img
                    :src="thumbnailUrl"
                    alt="Screenshot"
                    class="h-16 rounded border border-zinc-600 object-cover"
                />
                <button
                    type="button"
                    x-on:click="removeScreenshot()"
                    class="absolute -top-1.5 -right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-zinc-700 text-zinc-300 transition hover:bg-red-600 hover:text-white"
                >
                    <flux:icon name="x-mark" variant="micro" class="size-3" />
                </button>
            </div>
        </template>

        {{-- Debug context section --}}
        @if ($launcherDebugContext)
            <div class="border-t border-zinc-200 pt-2 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        x-on:click="debugOpen = !debugOpen"
                        class="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wider text-zinc-400 transition hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                    >
                        <flux:icon name="chevron-right" variant="micro" class="size-3 transition-transform" x-bind:class="debugOpen && 'rotate-90'" />
                        Context
                    </button>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            x-on:click="copyDebugContext()"
                            class="rounded p-0.5 text-zinc-400 transition hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                            title="Copy context"
                        >
                            <span x-show="!debugCopied"><flux:icon name="clipboard-document" variant="micro" class="size-3" /></span>
                            <span x-show="debugCopied" x-cloak><flux:icon name="check" variant="micro" class="size-3 text-green-400" /></span>
                        </button>
                    </div>
                </div>

                <div x-show="debugOpen" x-collapse class="mt-1.5 space-y-1">
                    {{-- Source URL --}}
                    <label class="flex items-center gap-1.5 text-[10px]">
                        <input type="checkbox" x-model="debugChecked.sourceUrl" class="size-3 rounded border-zinc-300 text-blue-500 focus:ring-0 focus:ring-offset-0 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="shrink-0 font-medium text-zinc-500 dark:text-zinc-600">URL</span>
                        <span class="truncate text-zinc-600 dark:text-zinc-400">{{ $launcherDebugContext['sourceUrl'] }}</span>
                    </label>

                    {{-- Route --}}
                    <label class="flex items-center gap-1.5 text-[10px]">
                        <input type="checkbox" x-model="debugChecked.route" class="size-3 rounded border-zinc-300 text-blue-500 focus:ring-0 focus:ring-offset-0 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="shrink-0 font-medium text-zinc-500 dark:text-zinc-600">Route</span>
                        <span class="truncate text-zinc-600 dark:text-zinc-400">{{ $launcherDebugContext['route'] }}</span>
                    </label>

                    {{-- Handler --}}
                    <label class="flex items-center gap-1.5 text-[10px]">
                        <input type="checkbox" x-model="debugChecked.handler" class="size-3 rounded border-zinc-300 text-blue-500 focus:ring-0 focus:ring-offset-0 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="shrink-0 font-medium text-zinc-500 dark:text-zinc-600">Handler</span>
                        <span class="truncate text-zinc-600 dark:text-zinc-400" title="{{ $launcherDebugContext['handler'] }}">{{ $launcherDebugContext['handler'] }}</span>
                    </label>

                    {{-- Views --}}
                    <label class="flex items-center gap-1.5 text-[10px]">
                        <input type="checkbox" x-model="debugChecked.views" class="size-3 rounded border-zinc-300 text-blue-500 focus:ring-0 focus:ring-offset-0 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="shrink-0 font-medium text-zinc-500 dark:text-zinc-600">Views</span>
                        <span class="truncate text-zinc-600 dark:text-zinc-400" title="{{ $launcherDebugContext['views'] }}">{{ Str::limit($launcherDebugContext['views'], 40) }}</span>
                    </label>

                    {{-- Auth User --}}
                    <label class="flex items-center gap-1.5 text-[10px]">
                        <input type="checkbox" x-model="debugChecked.authUser" class="size-3 rounded border-zinc-300 text-blue-500 focus:ring-0 focus:ring-offset-0 dark:border-zinc-600 dark:bg-zinc-800" />
                        <span class="shrink-0 font-medium text-zinc-500 dark:text-zinc-600">User</span>
                        <span class="truncate text-zinc-600 dark:text-zinc-400">{{ $launcherDebugContext['authUser'] }}</span>
                    </label>
                </div>
            </div>
        @endif

        {{-- Annotation controls --}}
        <div class="flex items-center gap-2" x-show="!annotating">
            <button
                type="button"
                x-on:click="startAnnotation('click')"
                x-show="!thumbnailUrl"
                class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
            >
                <flux:icon name="camera" variant="micro" class="size-3.5" />
                Annotate
            </button>

            <div class="flex flex-1 items-center gap-2">
                <flux:button type="submit" size="sm" variant="primary" icon="play" class="flex-1">
                    Plan
                </flux:button>
                <flux:button type="button" size="sm" variant="ghost" icon="bolt" x-on:click="submitExecute()" class="flex-1">
                    Execute
                </flux:button>
            </div>
        </div>

        {{-- Active annotation mode --}}
        <div x-show="annotating" class="space-y-1.5">
            <span class="block text-xs text-amber-400 italic" x-text="annotateMode === 'crop' ? 'Drag to select an area...' : 'Click an element or select text...'"></span>
            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    x-on:click="startAnnotation(annotateMode === 'crop' ? 'click' : 'crop')"
                    class="flex items-center gap-1 rounded px-1.5 py-1 text-[10px] font-medium transition"
                    x-bind:class="annotateMode === 'crop'
                        ? 'bg-amber-500/15 text-amber-400'
                        : 'text-zinc-400 hover:text-zinc-200'"
                    title="Toggle crop mode"
                >
                    <flux:icon name="scissors" variant="micro" class="size-3" />
                    <span>Crop</span>
                </button>
                <div class="flex-1"></div>
                <button
                    type="button"
                    x-on:click="captureScreenshot()"
                    x-bind:disabled="capturing"
                    class="flex items-center gap-1.5 rounded-lg bg-zinc-800 px-2.5 py-1.5 text-xs font-medium text-white transition hover:bg-zinc-700 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    <flux:icon name="camera" variant="micro" class="size-3.5" />
                    <span x-show="!capturing">Capture</span>
                    <span x-show="capturing">Saving...</span>
                </button>
                <button
                    type="button"
                    x-on:click="cancelAnnotation()"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                >
                    Cancel
                </button>
            </div>
        </div>
    </form>
</div>
