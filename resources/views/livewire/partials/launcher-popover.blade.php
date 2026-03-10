<div
    class="absolute right-0 bottom-full mb-2 w-80 rounded-xl border border-zinc-200 bg-white p-4 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
    x-data="{
        annotating: false,
        capturing: false,
        thumbnailUrl: null,
        annotator: null,

        startAnnotation() {
            if (!this.annotator) {
                this.annotator = new window.OrcaAnnotator();
            }
            this.annotator.enable();
            this.annotating = true;
        },

        async captureScreenshot() {
            if (!this.annotator) return;
            this.capturing = true;

            try {
                const blob = await this.annotator.capture();
                this.annotator.disable();
                this.annotating = false;

                // Upload to server
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

                // Show thumbnail
                this.thumbnailUrl = URL.createObjectURL(blob);
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
    x-init="$wire.set('sourceUrl', window.location.href)"
>
    <form wire:submit="launchClaude" class="space-y-2">
        <flux:textarea
            wire:model="prompt"
            placeholder="Describe what you want Claude to do..."
            rows="3"
            class="text-xs"
            x-on:keydown.cmd.enter="$wire.launchClaude()"
            x-on:keydown.ctrl.enter="$wire.launchClaude()"
            x-init="$nextTick(() => $el.focus())"
        />

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

        {{-- Annotation controls --}}
        <div class="flex items-center gap-2" x-show="!annotating">
            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                icon="camera"
                x-on:click="startAnnotation()"
                x-show="!thumbnailUrl"
            >
                Annotate
            </flux:button>

            <div class="flex flex-1 items-center gap-2">
                <flux:button type="submit" size="sm" variant="primary" icon="play" class="flex-1">
                    Plan
                </flux:button>
                <flux:button type="button" size="sm" variant="ghost" icon="bolt" wire:click="launchClaudeExecute" class="flex-1">
                    Execute
                </flux:button>
            </div>
        </div>

        {{-- Active annotation mode --}}
        <div x-show="annotating" class="flex items-center gap-2">
            <span class="text-xs text-amber-400 italic">Click an element or select text...</span>
            <flux:button
                type="button"
                size="sm"
                variant="primary"
                icon="camera"
                x-on:click="captureScreenshot()"
                x-bind:disabled="capturing"
            >
                <span x-show="!capturing">Capture</span>
                <span x-show="capturing">Saving...</span>
            </flux:button>
            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                x-on:click="cancelAnnotation()"
            >
                Cancel
            </flux:button>
        </div>
    </form>
</div>
