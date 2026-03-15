<div
    data-orca-launcher
    wire:ignore.self
    x-data="{
        orcaSurfaced: false,
        confirmExecute: false,
        scrollToBottom(el) { $nextTick(() => el.scrollTop = el.scrollHeight) },

        // Multi-instance WebTerm state
        openWebtermPanels: @js($webtermSessionIds ?: []),
        webtermUrls: @js($webtermUrls ?? []),
        webtermCommands: @js($webtermCommands ?? []),
        webtermStates: {},
        minimizedPanels: {},
        panelPositions: {},
        topPanelId: null,
        _nextZ: 46,

        init() {
            // Auto-reconnect webterm panels that have URLs but no active connection
            this.$nextTick(() => {
                for (const [sessionId, wsUrl] of Object.entries(this.webtermUrls)) {
                    if (!window._orcaWtInstances?.[sessionId]) {
                        this.initWebTerm(wsUrl, sessionId);
                    }
                }
            });
        },

        initWebTerm(wsUrl, sessionId) {
            if (window._orcaWtInstances?.[sessionId]) {
                try { window._orcaWtInstances[sessionId].dispose(); } catch(e) {}
            }
            window._orcaWtInstances = window._orcaWtInstances || {};
            this.webtermStates = { ...this.webtermStates, [sessionId]: { connected: false, exited: false } };
            if (!this.openWebtermPanels.includes(sessionId)) {
                this.openWebtermPanels = [...this.openWebtermPanels, sessionId];
            }
            this.minimizedPanels = { ...this.minimizedPanels, [sessionId]: false };
            this.focusPanel(sessionId);
            const rootEl = this.$el;
            this.$nextTick(() => {
                const container = document.getElementById('wt-' + sessionId);
                if (!container || !window.OrcaWebTerm) return;
                container.innerHTML = '';
                const wt = new window.OrcaWebTerm();
                wt.onConnected = () => rootEl.dispatchEvent(new CustomEvent('wt-state', { detail: { id: sessionId, connected: true }}));
                wt.onExit = () => rootEl.dispatchEvent(new CustomEvent('wt-state', { detail: { id: sessionId, exited: true }}));
                wt.onError = () => rootEl.dispatchEvent(new CustomEvent('wt-state', { detail: { id: sessionId, error: true }}));
                wt.mount(container);
                wt.connect(wsUrl);
                window._orcaWtInstances[sessionId] = wt;
            });
        },

        closeWebTerm(sessionId) {
            if (window._orcaWtInstances?.[sessionId]) {
                try { window._orcaWtInstances[sessionId].dispose(); } catch(e) {}
                delete window._orcaWtInstances[sessionId];
            }
            const { [sessionId]: _s, ...restStates } = this.webtermStates;
            this.webtermStates = restStates;
            const { [sessionId]: _m, ...restMinimized } = this.minimizedPanels;
            this.minimizedPanels = restMinimized;
            const { [sessionId]: _p, ...restPositions } = this.panelPositions;
            this.panelPositions = restPositions;
            this.openWebtermPanels = this.openWebtermPanels.filter(id => id !== sessionId);
        },

        toggleMinimize(sessionId) {
            const minimized = !this.minimizedPanels[sessionId];
            this.minimizedPanels = { ...this.minimizedPanels, [sessionId]: minimized };
            if (!minimized) this.focusPanel(sessionId);
        },

        focusPanel(sessionId) {
            this.topPanelId = sessionId;
            this._nextZ++;
            if (this._nextZ >= 50) {
                this._nextZ = 46;
                document.querySelectorAll('[id^=wt-panel-]').forEach(el => el.style.zIndex = 45);
            }
            const el = document.getElementById('wt-panel-' + sessionId);
            if (el) el.style.zIndex = this._nextZ;
        },

        startDrag(panelId, event) {
            this.focusPanel(panelId);
            if (event.target.closest('button')) return;
            event.preventDefault();
            const el = document.getElementById('wt-panel-' + panelId);
            if (!el) return;
            const rect = el.getBoundingClientRect();
            const offsetX = event.clientX - rect.left;
            const offsetY = event.clientY - rect.top;
            el.style.left = rect.left + 'px';
            el.style.top = rect.top + 'px';
            el.style.right = 'auto';
            el.style.bottom = 'auto';
            const onMove = (e) => {
                el.style.left = Math.max(0, e.clientX - offsetX) + 'px';
                el.style.top = Math.max(0, e.clientY - offsetY) + 'px';
            };
            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                this.panelPositions[panelId] = {
                    x: parseInt(el.style.left),
                    y: parseInt(el.style.top),
                };
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        getPanelStyle(panelId, idx) {
            const pos = this.panelPositions[panelId];
            if (pos) {
                return `width: 520px; left: ${pos.x}px; top: ${pos.y}px;`;
            }
            let dockedIdx = 0;
            for (let i = 0; i < idx; i++) {
                if (!this.panelPositions[this.openWebtermPanels[i]]) {
                    dockedIdx++;
                }
            }
            return `width: 520px; right: ${dockedIdx * 532 + 12}px; bottom: 48px;`;
        },
    }"
    x-on:orca:webterm-connect.window="if ($event.detail.command) webtermCommands[$event.detail.sessionId] = $event.detail.command; initWebTerm($event.detail.wsUrl, $event.detail.sessionId)"
    x-on:orca:webterm-disconnect.window="closeWebTerm($event.detail.sessionId)"
    x-on:orca:webterm-toggle.window="toggleMinimize($event.detail.sessionId)"
    x-on:wt-state="
        const id = $event.detail.id;
        const cur = webtermStates[id] || {};
        if ($event.detail.connected) { webtermStates = { ...webtermStates, [id]: { ...cur, connected: true } }; }
        if ($event.detail.exited) { webtermStates = { ...webtermStates, [id]: { ...cur, exited: true } }; }
        if ($event.detail.error && !cur.connected) { webtermStates = { ...webtermStates, [id]: { ...cur, exited: true } }; }
    "
    x-on:orca:panel-kill.window="$wire.kill($event.detail.id)"
    x-on:orca:panel-dismiss.window="closeWebTerm($event.detail.id); $wire.dismiss($event.detail.id)"
>
    @if ($sessions->isEmpty() && ! $launcherOpen)
        {{-- Empty state: floating "+" button --}}
        <div class="pointer-events-auto fixed right-4 bottom-4 z-50 flex items-center gap-1.5">
            <x-makedev::skills-container popover-position="above">
            </x-makedev::skills-container>

            <button
                x-on:click="$wire.toggleLauncher(window.location.href)"
                class="flex h-12 w-12 items-center justify-center rounded-full bg-zinc-900 text-white shadow-lg transition hover:bg-zinc-700"
            >
                @include('orca::partials.icon', ['name' => 'plus', 'variant' => 'mini', 'class' => 'size-5'])
            </button>

            @if ($launcherOpen)
                @include('orca::livewire.partials.launcher-popover')
            @endif
        </div>
    @else
        <div class="pointer-events-auto fixed inset-x-0 bottom-0 z-50">
            {{-- Thread Panel --}}
            @if ($expandedSession)
                <div class="relative ml-auto mb-1 mr-3 flex max-h-[60vh] w-full max-w-lg flex-col rounded-xl border border-zinc-700 bg-zinc-900 shadow-2xl" x-show="$wire.expandedSessionId !== ''" x-cloak>

                    @if ($expandedSession)
                    {{-- Thread header --}}
                    <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-2.5">
                        @php
                            $rootPrompt = $expandedSession->parent?->prompt ?? $expandedSession->prompt;
                        @endphp
                        <div class="flex min-w-0 items-center gap-2">
                            @include('orca::livewire.partials.status-dot', ['status' => $expandedSession->status])
                            @if ($expandedSession->isClaude())
                                @include('orca::livewire.partials.permission-badge', ['session' => $expandedSession])
                            @endif
                            @if ($expandedSession->isPoppedOut() && isset($heartbeatData[$expandedSession->id]))
                                @if (isset($heartbeatStale[$expandedSession->id]))
                                    <button
                                        wire:click="resumeSessionInTerminal('{{ $expandedSession->id }}')"
                                        class="flex items-center text-red-400 transition hover:text-red-300"
                                        title="Connection lost — click to resume in Terminal"
                                    >
                                        @include('orca::partials.icon', ['name' => 'signal-slash', 'class' => 'size-3.5'])
                                    </button>
                                @else
                                    <span class="flex items-center" title="Heartbeat active">
                                        @include('orca::partials.icon', ['name' => 'signal', 'class' => 'size-3.5 animate-pulse text-cyan-400'])
                                    </span>
                                @endif
                            @endif
                            <span class="truncate text-sm font-medium text-white">
                                {{ $expandedSession->isClaude() ? Str::limit(rtrim(Str::before($rootPrompt, '# Debug'), " \t\n\r\0\x0B-"), 60) : Str::limit($expandedSession->command, 60) }}
                            </span>
                        </div>
                        <div class="ml-2 flex flex-shrink-0 items-center gap-1">
                            @if ($expandedSession->isClaude())
                                @include('orca::livewire.partials.debug-info', ['session' => $expandedSession])
                            @endif
                            @if ($canPopOut && $expandedSession->isClaude() && $expandedSession->status->isActive() && $expandedSession->status !== \MakeDev\Orca\Enums\OrcaSessionStatus::PoppedOut)
                                <button wire:click="popOutSession('{{ $expandedSession->id }}')" class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-cyan-400" title="Open in Terminal">
                                    @include('orca::partials.icon', ['name' => 'arrow-top-right-on-square', 'class' => 'size-3.5'])
                                </button>
                            @endif
                            @if ($expandedSession->status->isActive() && $expandedSession->status !== \MakeDev\Orca\Enums\OrcaSessionStatus::Pending && $expandedSession->status !== \MakeDev\Orca\Enums\OrcaSessionStatus::PoppedOut)
                                <button wire:click="kill('{{ $expandedSession->id }}')" class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-red-400" title="Kill">
                                    @include('orca::partials.icon', ['name' => 'stop', 'class' => 'size-3.5'])
                                </button>
                            @endif
                            <button wire:click="dismiss('{{ $expandedSession->id }}')" wire:confirm="Delete this session?" class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-red-400" title="Delete">
                                @include('orca::partials.icon', ['name' => 'trash', 'class' => 'size-3.5'])
                            </button>
                            <button wire:click="toggleSession('{{ $expandedSession->id }}')" class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-white" title="Close">
                                @include('orca::partials.icon', ['name' => 'x-mark', 'class' => 'size-3.5'])
                            </button>
                        </div>
                    </div>

                    {{-- Messages area (timer floats inside) --}}
                    @if ($expandedSession->isClaude() && $expandedSession->isPoppedOut())
                        {{-- Terminal.app fallback: screenshot polling (webterm sessions use floating panels) --}}
                            <div
                                class="flex flex-1 flex-col items-center justify-center"
                                x-data="{
                                    loaded: false,
                                    timestamp: Date.now(),
                                    elapsed: '0:00',
                                    init() {
                                        const started = new Date('{{ $expandedSession->created_at->toIso8601String() }}');
                                        const tick = () => {
                                            const secs = Math.floor((Date.now() - started.getTime()) / 1000);
                                            const m = Math.floor(secs / 60);
                                            const s = secs % 60;
                                            this.elapsed = m + ':' + String(s).padStart(2, '0');
                                        };
                                        tick();
                                        this.interval = setInterval(() => {
                                            this.timestamp = Date.now();
                                            tick();
                                        }, 1000);
                                    },
                                    destroy() { clearInterval(this.interval); }
                                }"
                            >
                                {{-- Screenshot thumbnail: overflow-masked, shows top portion --}}
                                <div
                                    x-show="loaded"
                                    wire:click="focusTerminal('{{ $expandedSession->id }}')"
                                    class="relative w-full cursor-pointer overflow-hidden rounded-lg border border-zinc-700 transition hover:border-cyan-500"
                                    style="max-height: 260px;"
                                    title="Click to focus Terminal"
                                >
                                    <img
                                        :src="'{{ route('orca.terminal-screenshot', $expandedSession->id) }}?t=' + timestamp"
                                        alt="Terminal preview"
                                        class="w-full"
                                        x-on:load="loaded = true"
                                        x-on:error="loaded = false"
                                    />
                                    {{-- Elapsed timer overlay --}}
                                    <div class="pointer-events-none absolute top-1.5 right-1.5 z-10 rounded-full bg-red-600/90 px-2 py-0.5 font-mono text-[10px] tabular-nums text-white" x-text="elapsed"></div>
                                    {{-- Fade-out gradient at bottom edge --}}
                                    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-zinc-900 to-transparent"></div>
                                </div>

                                {{-- Fallback: pulsing dot + text (shown until screenshot loads) --}}
                                <template x-if="!loaded">
                                    <div class="flex flex-col items-center gap-3 py-4">
                                        @if (isset($heartbeatStale[$expandedSession->id]))
                                            @include('orca::partials.icon', ['name' => 'signal-slash', 'variant' => 'mini', 'class' => 'size-5 text-red-400'])
                                            <span class="text-sm font-medium text-red-400">Connection Lost</span>
                                            <button
                                                wire:click="resumeSessionInTerminal('{{ $expandedSession->id }}')"
                                                class="rounded bg-red-500/20 px-3 py-1 text-xs font-medium text-red-300 transition hover:bg-red-500/30"
                                            >
                                                Resume in Terminal
                                            </button>
                                        @else
                                            <span class="relative flex h-3 w-3">
                                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-cyan-400 opacity-75"></span>
                                                <span class="relative inline-flex h-3 w-3 rounded-full bg-cyan-500"></span>
                                            </span>
                                            <span class="text-sm font-medium text-cyan-400">Running in Terminal</span>
                                            <span class="text-xs text-zinc-500">Screenshot will appear shortly...</span>
                                        @endif
                                    </div>
                                </template>

                                {{-- Focus terminal link --}}
                                @if (isset($heartbeatStale[$expandedSession->id]))
                                    <button
                                        wire:click="resumeSessionInTerminal('{{ $expandedSession->id }}')"
                                        class="mt-1 text-xs text-red-400 transition hover:text-red-300"
                                    >
                                        Resume in Terminal
                                    </button>
                                @else
                                    <button
                                        wire:click="focusTerminal('{{ $expandedSession->id }}')"
                                        class="mt-1 text-xs text-zinc-500 transition hover:text-cyan-400"
                                    >
                                        Focus Terminal
                                    </button>
                                @endif
                            </div>
                    @elseif ($expandedSession->isClaude())
                        <div
                            class="flex-1 space-y-1.5 overflow-y-auto p-3 text-xs text-zinc-300"
                            x-init="scrollToBottom($el)"
                            x-effect="scrollToBottom($el)"
                        >
                            @php
                                $allMessages = $expandedSession->parent
                                    ? $expandedSession->parent->messages->concat($expandedSession->messages)->sortBy('created_at')->values()
                                    : $expandedSession->messages;

                                $lastExitPlanModeId = $allMessages
                                    ->where('type', 'tool_use')
                                    ->filter(fn ($m) => ($m->metadata['tool'] ?? '') === 'ExitPlanMode')
                                    ->last()?->id;

                                $lastToolUseId = $allMessages->where('type', 'tool_use')->last()?->id;

                                $hasVisibleMessages = $allMessages->contains(fn ($m) =>
                                    ($m->type !== 'tool_use' || $m->id === $lastExitPlanModeId)
                                    && ! ($m->type === 'system' && blank($m->content['text'] ?? ''))
                                );
                            @endphp

                            @if (! $hasVisibleMessages && $expandedSession->status->isActive())
                                <div
                                    class="py-4 text-center text-zinc-500 italic"
                                    x-data="{
                                        phrases: [
                                            'Sending the first sonar ping...',
                                            'The first ripple leaves the pod...',
                                            'Breaking the surface with our opening move...',
                                            'The hunt begins with a single click...',
                                            'Casting the first signal into open water...',
                                            'The pod makes its first move...',
                                            'Opening the swim lane...',
                                            'First breach underway...',
                                            'Launching the first pass through the current...',
                                            'Setting the pod in motion...',
                                        ],
                                        current: '',
                                        init() {
                                            this.current = this.phrases[Math.floor(Math.random() * this.phrases.length)];
                                            this.interval = setInterval(() => {
                                                this.current = this.phrases[Math.floor(Math.random() * this.phrases.length)];
                                            }, 3000);
                                        },
                                        destroy() { clearInterval(this.interval) },
                                    }"
                                    x-text="current"
                                ></div>
                            @endif

                            {{-- Surfacing message: shown once when first real output arrives, then fades out --}}
                            @if ($hasVisibleMessages)
                                <template x-if="!orcaSurfaced">
                                    <div
                                        x-init="orcaSurfaced = true; setTimeout(() => $el.remove(), 2700)"
                                        x-data="{ visible: true }"
                                        x-show="visible"
                                        x-transition:leave="transition ease-in duration-700"
                                        x-transition:leave-start="opacity-100"
                                        x-transition:leave-end="opacity-0"
                                        x-effect="setTimeout(() => visible = false, 2000)"
                                        class="py-1 text-center text-zinc-500 italic"
                                    >An orca is surfacing...</div>
                                </template>
                            @endif

                            @forelse ($allMessages as $message)
                                @if ($message->type === 'system' && blank($message->content['text'] ?? ''))
                                    @continue
                                @endif

                                {{-- Tool use messages: render inline phrase at chronological position --}}
                                @if ($message->type === 'tool_use')
                                    {{-- Active tool: spinning indicator at its chronological position --}}
                                    @if ($message->id === $lastToolUseId && $currentToolPhrase && $expandedSession->status->isActive())
                                        <div class="flex items-center gap-1.5 text-blue-400">
                                            @include('orca::partials.icon', ['name' => 'cog-6-tooth', 'class' => 'size-3 animate-spin'])
                                            <span class="font-medium italic">{{ $currentToolPhrase }}</span>
                                        </div>
                                    @endif

                                    @continue
                                @endif

                                @if ($message->direction === 'inbound')
                                    {{-- User message --}}
                                    <div class="flex justify-end">
                                        <div class="max-w-[80%] rounded-lg bg-zinc-700 px-3 py-1.5 text-zinc-100">
                                            {{ $message->content['text'] ?? '' }}
                                        </div>
                                    </div>
                                @else
                                    @switch($message->type)
                                        @case('text')
                                            <div class="orca-markdown">{!! Str::markdown($message->content['text'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                                            @break
                                        @case('question')
                                            <div class="mt-1 rounded border border-amber-500/30 bg-amber-500/10 p-2 text-amber-300">
                                                {{ $message->content['text'] ?? '' }}
                                            </div>
                                            @break
                                        @case('permission')
                                            <div class="mt-1 rounded border border-amber-500/30 bg-amber-500/10 p-2 text-amber-300">
                                                <span class="font-semibold">Permission:</span> {{ $message->content['text'] ?? '' }}
                                            </div>
                                            @break
                                        @case('error')
                                            <div class="text-red-400">{{ $message->content['text'] ?? '' }}</div>
                                            @break
                                        @case('system')
                                            <div class="text-zinc-500 italic">{{ $message->content['text'] ?? '' }}</div>
                                            @break
                                    @endswitch
                                @endif
                            @empty
                            @endforelse

                        </div>

                        {{-- Input area --}}
                        @if ($expandedSession->status->isActive() && $expandedSession->status !== \MakeDev\Orca\Enums\OrcaSessionStatus::Pending)
                            <div class="border-t border-zinc-700 px-3 py-2">
                                @php
                                    $lastMessage = $allMessages->last();
                                    $interactionType = $lastMessage?->metadata['interaction_type'] ?? null;
                                    $canExecute = $lastExitPlanModeId && ! $expandedSession->isSkipPermissions();
                                @endphp

                                @if ($expandedSession->isAwaitingInput() && $interactionType === 'permission')
                                    <div class="mb-2 flex items-center gap-2">
                                        <button wire:click="approvePermission('{{ $expandedSession->id }}')" class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                                            Approve
                                        </button>
                                        <button wire:click="denyPermission('{{ $expandedSession->id }}')" class="rounded-lg px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                            Deny
                                        </button>
                                    </div>
                                @endif

                                <div
                                    x-data="{
                                        annotating: false,
                                        capturing: false,
                                        thumbnailUrl: null,
                                        annotator: null,
                                        annotateMode: 'click',

                                        startAnnotation(mode = 'click') {
                                            if (!this.annotator) {
                                                this.annotator = new window.OrcaAnnotator();
                                            }
                                            this.annotateMode = mode;
                                            this.annotator.enable(mode);
                                            this.annotating = true;
                                        },

                                        async captureScreenshot() {
                                            if (!this.annotator) return;
                                            this.capturing = true;

                                            try {
                                                const blob = await this.annotator.capture();
                                                this.annotator.disable();
                                                this.annotating = false;

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
                                                $wire.set('sessionScreenshots.{{ $expandedSession->id }}', data.path);

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
                                            $wire.clearSessionScreenshot('{{ $expandedSession->id }}');
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
                                    @screenshot-cleared.window="if (thumbnailUrl) { URL.revokeObjectURL(thumbnailUrl); thumbnailUrl = null; }"
                                >
                                    {{-- Annotation mode indicator --}}
                                    <div x-show="annotating" x-cloak class="mb-2 space-y-1">
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
                                                class="flex items-center gap-1 rounded bg-white px-2 py-1 text-xs font-medium text-zinc-900 transition hover:bg-zinc-200 disabled:opacity-50"
                                            >
                                                @include('orca::partials.icon', ['name' => 'camera', 'class' => 'size-3'])
                                                <span x-show="!capturing">Capture</span>
                                                <span x-show="capturing">Saving...</span>
                                            </button>
                                            <button
                                                type="button"
                                                x-on:click="cancelAnnotation()"
                                                class="rounded px-2 py-1 text-xs font-medium text-zinc-400 transition hover:bg-zinc-800 hover:text-zinc-200"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Screenshot thumbnail preview --}}
                                    <template x-if="thumbnailUrl">
                                        <div class="relative mb-2 inline-block">
                                            <img
                                                :src="thumbnailUrl"
                                                alt="Screenshot"
                                                class="h-12 rounded border border-zinc-600 object-cover"
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

                                    <form wire:submit="respond('{{ $expandedSession->id }}')" class="flex items-center gap-2">
                                        <div class="relative flex-1">
                                            <input
                                                wire:model="sessionInputs.{{ $expandedSession->id }}"
                                                placeholder="Type a message..."
                                                autocomplete="off"
                                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500"
                                            />
                                            <button type="button" x-on:click="startAnnotation('click')" x-show="!thumbnailUrl && !annotating" class="absolute top-1/2 right-2 -translate-y-1/2 text-zinc-400 transition hover:text-cyan-400">
                                                @include('orca::partials.icon', ['name' => 'camera', 'class' => 'size-3.5'])
                                            </button>
                                        </div>
                                        <button type="submit" class="rounded-lg bg-zinc-900 p-1.5 text-white transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                                            @include('orca::partials.icon', ['name' => 'paper-airplane', 'variant' => 'mini', 'class' => 'size-4'])
                                        </button>
                                        @if ($canExecute)
                                            <button
                                                x-on:click="confirmExecute = true"
                                                class="shrink-0 rounded bg-amber-500 px-2.5 py-1.5 text-xs font-semibold text-black transition hover:bg-amber-400"
                                            >
                                                Execute
                                            </button>
                                        @endif
                                    </form>
                                </div>
                            </div>
                        @elseif ($expandedSession->status->isTerminal() && $lastExitPlanModeId && ! $expandedSession->isSkipPermissions())
                            {{-- Terminal plan session: show Execute in footer --}}
                            <div class="border-t border-zinc-700 px-3 py-2">
                                <button
                                    wire:click="resumeSessionInTerminal('{{ $expandedSession->id }}')"
                                    class="w-full rounded bg-amber-500 px-2.5 py-1.5 text-xs font-semibold text-black transition hover:bg-amber-400"
                                >
                                    Execute
                                </button>
                            </div>
                        @endif
                    @else
                        {{-- Command session: raw output --}}
                        <div
                            class="flex-1 overflow-y-auto p-3 font-mono text-xs whitespace-pre-wrap text-zinc-300"
                            x-init="scrollToBottom($el)"
                            x-effect="scrollToBottom($el)"
                        >{{ $expandedSession->output ?? '' }}</div>
                    @endif

                    {{-- DOS-style confirm modal --}}
                    <div
                        x-show="confirmExecute"
                        x-trap="confirmExecute"
                        x-on:keydown.escape.window="confirmExecute = false"
                        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60"
                        style="display: none;"
                    >
                        <div class="w-72 border-2 border-zinc-400 bg-zinc-800 font-mono text-xs text-zinc-200 shadow-[4px_4px_0_#000]">
                            <div class="border-b border-zinc-600 bg-zinc-700 px-3 py-1 font-bold tracking-wide text-white">
                                CONFIRM EXECUTE
                            </div>
                            <div class="px-3 py-3 leading-relaxed">
                                Kill Plan session and resume<br>with full permissions?
                            </div>
                            <div class="flex justify-end gap-2 border-t border-zinc-600 px-3 py-2">
                                <button
                                    x-on:click="confirmExecute = false"
                                    class="border border-zinc-500 bg-zinc-700 px-4 py-1 font-mono text-xs text-zinc-300 hover:bg-zinc-600"
                                >
                                    [ Cancel ]
                                </button>
                                <button
                                    x-on:click="confirmExecute = false; $wire.resumeSessionInTerminal('{{ $expandedSession?->id }}')"
                                    class="border border-amber-500 bg-amber-500 px-4 py-1 font-mono text-xs font-bold text-black hover:bg-amber-400"
                                >
                                    [ OK ]
                                </button>
                            </div>
                        </div>
                    </div>
                    @endif

                </div>

            @endif

            {{-- Taskbar strip --}}
            <div class="flex items-center gap-1.5 border-t border-zinc-200 bg-white/80 px-3 py-1.5 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/80" @if ($pollInterval) wire:poll.{{ $pollInterval }} @endif>
                {{-- WebTerm pills --}}
                <div class="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-auto">
                    @foreach ($sessions as $session)
                        @if (in_array($session->id, $webtermSessionIds))
                            <div wire:key="pill-{{ $session->id }}">
                                <button
                                    x-on:click="toggleMinimize('{{ $session->id }}')"
                                    class="group relative flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                                    :class="minimizedPanels['{{ $session->id }}']
                                        ? 'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800'
                                        : 'bg-cyan-500/10 text-cyan-600 hover:bg-cyan-500/20 dark:text-cyan-400'"
                                >
                                    @include('orca::partials.icon', ['name' => 'command-line', 'class' => 'size-3'])
                                    <span class="max-w-[120px] truncate">
                                        {{ Str::limit(rtrim(Str::before($session->parent?->prompt ?? $session->prompt, '# Debug'), " \t\n\r\0\x0B-"), 20) }}
                                    </span>
                                    <span
                                        x-on:click.stop="toggleMinimize('{{ $session->id }}')"
                                        class="ml-0.5 hidden rounded p-0.5 hover:bg-zinc-200 group-hover:inline-flex dark:hover:bg-zinc-700"
                                        title="Minimize"
                                    >
                                        @include('orca::partials.icon', ['name' => 'x-mark', 'class' => 'size-3'])
                                    </span>
                                </button>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Launcher button --}}
                <div class="flex shrink-0 items-center gap-1">
                    <div class="relative">
                        <button
                            x-on:click="$wire.toggleLauncher(window.location.href)"
                            @class([
                                'flex h-8 w-8 items-center justify-center rounded-lg transition',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $launcherOpen,
                                'text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800' => ! $launcherOpen,
                            ])
                        >
                            @include('orca::partials.icon', ['name' => 'plus', 'variant' => 'mini', 'class' => 'size-4'])
                        </button>

                        @if ($launcherOpen)
                            @include('orca::livewire.partials.launcher-popover')
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Floating WebTerm panels — teleported outside Livewire's DOM to avoid morph conflicts --}}
    <template x-teleport="body">
        <template x-for="(panelId, idx) in openWebtermPanels" :key="panelId">
            <div
                :id="'wt-panel-' + panelId"
                class="fixed z-[45] flex flex-col rounded-xl border border-zinc-700 bg-zinc-900 shadow-2xl"
                :style="getPanelStyle(panelId, idx)"
                x-on:mousedown="focusPanel(panelId)"
                :class="{ 'hidden': minimizedPanels[panelId] }"
            >
                {{-- Draggable panel header --}}
                <div
                    class="flex cursor-move items-center justify-between border-b border-zinc-700 px-3 py-2 select-none"
                    x-on:mousedown="startDrag(panelId, $event)"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="rounded bg-cyan-500/20 px-1.5 py-0.5 font-mono text-[10px] font-bold tracking-wider text-cyan-400">TERMINAL</span>
                        <button
                            class="cursor-pointer text-zinc-500 transition hover:text-cyan-400"
                            :title="webtermCommands[panelId] || 'claude'"
                            x-on:click.stop="navigator.clipboard.writeText(webtermCommands[panelId] || 'claude')"
                        >
                            @include('orca::partials.icon', ['name' => 'command-line', 'class' => 'size-3.5'])
                        </button>
                        <span class="truncate text-xs font-medium text-white" x-text="'Session ' + panelId.substring(0, 8)"></span>
                    </div>
                    <div class="ml-2 flex flex-shrink-0 items-center gap-1">
                        <button
                            x-on:click="toggleMinimize(panelId)"
                            class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                            title="Minimize"
                        >
                            @include('orca::partials.icon', ['name' => 'minus', 'variant' => 'mini', 'class' => 'size-3.5'])
                        </button>
                        <button
                            x-on:click="window.dispatchEvent(new CustomEvent('orca:panel-kill', { detail: { id: panelId } }))"
                            class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-red-400"
                            title="Kill session"
                        >
                            @include('orca::partials.icon', ['name' => 'stop', 'class' => 'size-3.5'])
                        </button>
                        <button
                            x-on:click="toggleMinimize(panelId)"
                            class="rounded p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                            title="Minimize"
                        >
                            @include('orca::partials.icon', ['name' => 'x-mark', 'class' => 'size-3.5'])
                        </button>
                    </div>
                </div>

                {{-- Connection status overlays --}}
                <div
                    x-show="!webtermStates[panelId]?.connected && !webtermStates[panelId]?.exited"
                    class="flex items-center justify-center gap-2 border-b border-zinc-700 px-3 py-2"
                >
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-cyan-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
                    </span>
                    <span class="text-xs text-cyan-400">Connecting...</span>
                </div>
                <div
                    x-show="webtermStates[panelId]?.exited"
                    class="flex items-center justify-center gap-2 border-b border-zinc-700 px-3 py-2"
                >
                    <span class="text-xs text-zinc-400">Session ended</span>
                </div>

                {{-- Terminal container --}}
                <div :id="'wt-' + panelId" class="flex-1 overflow-hidden" style="min-height: 320px;"></div>
            </div>
        </template>
    </template>

<style>
    .orca-markdown code {
        border-radius: 0.25rem;
        background-color: rgb(63 63 70);
        padding: 0.125rem 0.25rem;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 11px;
        color: rgb(252 211 77);
    }
    .orca-markdown pre {
        margin: 0.375rem 0;
        overflow-x: auto;
        border-radius: 0.25rem;
        background-color: rgb(39 39 42);
        padding: 0.5rem;
    }
    .orca-markdown pre code {
        background-color: transparent;
        padding: 0;
        color: rgb(212 212 216);
    }
    .orca-markdown p {
        margin-bottom: 0.25rem;
    }
    .orca-markdown p:last-child {
        margin-bottom: 0;
    }
</style>

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                if (response.status === 419) {
                    preventDefault()
                    window.location.reload()
                }
            })
        })
    })
</script>
</div>
