import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import { WebLinksAddon } from '@xterm/addon-web-links';
import '@xterm/xterm/css/xterm.css';

class OrcaWebTerm {
    constructor() {
        this.terminal = null;
        this.fitAddon = null;
        this.socket = null;
        this.resizeObserver = null;
        this.container = null;

        // Callbacks
        this.onConnected = null;
        this.onExit = null;
        this.onError = null;
    }

    mount(container) {
        this.container = container;

        this.terminal = new Terminal({
            cursorBlink: true,
            cursorStyle: 'bar',
            fontSize: 13,
            fontFamily: 'ui-monospace, "SF Mono", Menlo, Monaco, "Cascadia Code", monospace',
            theme: {
                background: '#18181b', // zinc-900
                foreground: '#d4d4d8', // zinc-300
                cursor: '#22d3ee',     // cyan-400
                selectionBackground: '#3f3f46', // zinc-700
                black: '#18181b',
                red: '#f87171',
                green: '#4ade80',
                yellow: '#facc15',
                blue: '#60a5fa',
                magenta: '#c084fc',
                cyan: '#22d3ee',
                white: '#d4d4d8',
                brightBlack: '#52525b',
                brightRed: '#fca5a5',
                brightGreen: '#86efac',
                brightYellow: '#fde047',
                brightBlue: '#93c5fd',
                brightMagenta: '#d8b4fe',
                brightCyan: '#67e8f9',
                brightWhite: '#fafafa',
            },
            allowProposedApi: true,
            scrollback: 5000,
        });

        this.fitAddon = new FitAddon();
        this.terminal.loadAddon(this.fitAddon);
        this.terminal.loadAddon(new WebLinksAddon());

        this.terminal.open(container);

        // Initial fit
        requestAnimationFrame(() => {
            this.fitAddon.fit();
        });

        // Watch for container resizes
        this.resizeObserver = new ResizeObserver(() => {
            requestAnimationFrame(() => {
                if (this.fitAddon && this.terminal) {
                    this.fitAddon.fit();
                }
            });
        });
        this.resizeObserver.observe(container);

        // Send resize events to server
        this.terminal.onResize(({ cols, rows }) => {
            if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                this.socket.send(JSON.stringify({ type: 'resize', cols, rows }));
            }
        });
    }

    connect(wsUrl) {
        if (this.socket) {
            this.socket.close();
        }

        this.socket = new WebSocket(wsUrl);

        this.socket.onopen = () => {
            // Focus terminal once connected
            if (this.terminal) {
                this.terminal.focus();
            }
        };

        this.socket.onmessage = (event) => {
            if (!event.data) return;
            let data;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            switch (data.type) {
                case 'connected':
                    if (this.onConnected) this.onConnected(data.session_id);
                    // Send initial size
                    if (this.terminal) {
                        this.socket.send(JSON.stringify({
                            type: 'resize',
                            cols: this.terminal.cols,
                            rows: this.terminal.rows,
                        }));
                    }
                    break;

                case 'output':
                    if (this.terminal) {
                        this.terminal.write(data.data);
                    }
                    break;

                case 'exit':
                    if (this.onExit) this.onExit(data.code);
                    break;

                case 'error':
                    if (this.onError) this.onError(data.message);
                    break;
            }
        };

        this.socket.onclose = () => {
            // Connection closed
        };

        this.socket.onerror = (err) => {
            if (this.onError) this.onError('WebSocket connection failed');
        };

        // Bridge terminal input to WebSocket
        if (this.terminal) {
            this.terminal.onData((data) => {
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.socket.send(JSON.stringify({ type: 'input', data }));
                }
            });
        }
    }

    focus() {
        if (this.terminal) {
            this.terminal.focus();
        }
    }

    dispose() {
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }

        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }

        if (this.terminal) {
            try {
                this.terminal.dispose();
            } catch (e) {
                // Addons may already be in an inconsistent state (e.g., DOM container
                // removed by Livewire morph). Safe to ignore — we null out below.
            }
            this.terminal = null;
        }

        this.fitAddon = null;
        this.container = null;
    }
}

window.OrcaWebTerm = OrcaWebTerm;
