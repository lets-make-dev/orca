type OutputCallback = (data: string) => void;

export class TerminalBridge {
  private ws: WebSocket | null = null;
  private outputCallbacks: OutputCallback[] = [];
  private pendingInput: string[] = [];
  private connected = false;

  connect(wsUrl: string): void {
    this.disconnect();
    try {
      const socket = new WebSocket(wsUrl);
      this.ws = socket;

      socket.onopen = () => {
        this.connected = true;
        this.pendingInput.forEach((data) => socket.send(data));
        this.pendingInput = [];
        this.emitOutput('\r\n[Terminal connected]\r\n');
      };

      socket.onmessage = (event) => {
        const data = typeof event.data === 'string' ? event.data : '[binary data]';
        this.emitOutput(data);
      };

      socket.onclose = () => {
        this.connected = false;
        this.ws = null;
        this.emitOutput('\r\n[Terminal disconnected]\r\n');
      };

      socket.onerror = () => {
        this.emitOutput('\r\n[Terminal connection error]\r\n');
      };
    } catch (err) {
      this.emitOutput(`\r\n[Failed to connect: ${String(err)}]\r\n`);
    }
  }

  disconnect(): void {
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
    this.connected = false;
    this.pendingInput = [];
  }

  sendInput(data: string): void {
    if (this.ws && this.connected) {
      this.ws.send(data);
    } else {
      this.pendingInput.push(data);
    }
  }

  onOutput(callback: OutputCallback): () => void {
    this.outputCallbacks.push(callback);
    return () => {
      this.outputCallbacks = this.outputCallbacks.filter((c) => c !== callback);
    };
  }

  isConnected(): boolean {
    return this.connected;
  }

  private emitOutput(data: string): void {
    this.outputCallbacks.forEach((cb) => cb(data));
  }
}
