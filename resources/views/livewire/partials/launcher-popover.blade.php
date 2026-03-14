<div
    wire:ignore
    class="absolute right-0 bottom-full mb-2 w-80 rounded-xl border border-zinc-200 bg-white p-4 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
    x-data="{
        annotating: false,
        capturing: false,
        thumbnailUrl: null,
        annotator: null,

        selectedModel: $wire.entangle('model'),
        modelOpen: false,

        get modelLabel() {
            const labels = {
                'claude-haiku-4-5': 'Haiku',
                'claude-sonnet-4-6': 'Sonnet',
                'claude-opus-4-6': 'Opus',
            };
            return labels[this.selectedModel] || 'Model';
        },

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

        {{-- Model picker dropdown --}}
        <div class="flex justify-end">
            <div class="relative">
                <button
                    type="button"
                    x-on:click="modelOpen = !modelOpen"
                    class="flex items-center gap-1 rounded-md px-1.5 py-1 text-zinc-400 transition hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                    title="Model"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                    <span x-text="modelLabel" class="text-[10px] font-medium"></span>
                </button>

                <div
                    x-show="modelOpen"
                    x-on:click.away="modelOpen = false"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute right-0 top-full mt-1 w-36 rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
                    style="z-index: 10;"
                    x-cloak
                >
                    <template x-for="option in [
                        { id: 'claude-haiku-4-5', label: 'Haiku' },
                        { id: 'claude-sonnet-4-6', label: 'Sonnet' },
                        { id: 'claude-opus-4-6', label: 'Opus' },
                    ]" :key="option.id">
                        <button
                            type="button"
                            x-on:click="selectedModel = option.id; modelOpen = false"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs transition hover:bg-zinc-100 dark:hover:bg-zinc-700"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                class="size-3.5 text-blue-500"
                                x-show="selectedModel === option.id"
                            >
                                <path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.739a.75.75 0 0 1 1.04-.208Z" clip-rule="evenodd" />
                            </svg>
                            <span class="size-3.5" x-show="selectedModel !== option.id"></span>
                            <span x-text="option.label" class="text-zinc-700 dark:text-zinc-200"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <template x-if="!annotating">
            <textarea
                wire:model="prompt"
                placeholder="{{ ! empty($this->moduleContext) ? 'Ask about ' . ($this->moduleContext['name'] ?? 'this module') . '...' : 'Describe what you want Claude to do...' }}"
                rows="3"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500"
                x-on:keydown.cmd.enter="submitPlan()"
                x-on:keydown.ctrl.enter="submitPlan()"
                x-init="$nextTick(() => $el.focus())"
            ></textarea>
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
                    @include('orca::partials.icon', ['name' => 'x-mark', 'class' => 'size-3'])
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
                        @include('orca::partials.icon', ['name' => 'chevron-right', 'class' => 'size-3 transition-transform', 'extra' => 'x-bind:class="debugOpen && \'rotate-90\'"'])
                        Context
                    </button>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            x-on:click="copyDebugContext()"
                            class="rounded p-0.5 text-zinc-400 transition hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                            title="Copy context"
                        >
                            <span x-show="!debugCopied">@include('orca::partials.icon', ['name' => 'clipboard-document', 'class' => 'size-3'])</span>
                            <span x-show="debugCopied" x-cloak>@include('orca::partials.icon', ['name' => 'check', 'class' => 'size-3 text-green-400'])</span>
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
                @include('orca::partials.icon', ['name' => 'camera', 'class' => 'size-3.5'])
                Annotate
            </button>

            <div class="flex flex-1 items-center gap-2">
                <button type="submit" class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    @include('orca::partials.icon', ['name' => 'play', 'variant' => 'mini', 'class' => 'size-3.5'])
                    Plan
                </button>
                <button type="button" x-on:click="submitExecute()" class="flex flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    @include('orca::partials.icon', ['name' => 'bolt', 'variant' => 'mini', 'class' => 'size-3.5'])
                    Execute
                </button>
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
                    @include('orca::partials.icon', ['name' => 'scissors', 'class' => 'size-3'])
                    <span>Crop</span>
                </button>
                <div class="flex-1"></div>
                <button
                    type="button"
                    x-on:click="captureScreenshot()"
                    x-bind:disabled="capturing"
                    class="flex items-center gap-1.5 rounded-lg bg-zinc-800 px-2.5 py-1.5 text-xs font-medium text-white transition hover:bg-zinc-700 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    @include('orca::partials.icon', ['name' => 'camera', 'class' => 'size-3.5'])
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
