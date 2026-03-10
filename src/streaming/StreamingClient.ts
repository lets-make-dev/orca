import type { StreamChunk } from '../types';

type ChunkCallback = (chunk: StreamChunk) => void;

export class StreamingClient {
  private eventSource: EventSource | null = null;
  private chunkCallbacks: ChunkCallback[] = [];
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private mockMode = false;

  connect(url: string, sessionId: string): void {
    this.disconnect();
    this.mockMode = false;
    this.reconnectAttempts = 0;
    this.openConnection(url, sessionId);
  }

  connectMock(): void {
    this.disconnect();
    this.mockMode = true;
    const self = this;
    setTimeout(() => {
      if (!self.mockMode) return;
      self.emit({ type: 'text', data: 'Mock AI response: Hello from Orca mock mode!' });
      setTimeout(() => {
        if (!self.mockMode) return;
        self.emit({ type: 'done', data: null });
      }, 500);
    }, 200);
  }

  disconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
    this.mockMode = false;
  }

  onChunk(callback: ChunkCallback): () => void {
    this.chunkCallbacks.push(callback);
    return () => {
      this.chunkCallbacks = this.chunkCallbacks.filter((c) => c !== callback);
    };
  }

  private openConnection(url: string, sessionId: string): void {
    const fullUrl = `${url}/stream?sessionId=${encodeURIComponent(sessionId)}`;
    try {
      const es = new EventSource(fullUrl);
      this.eventSource = es;

      es.onmessage = (event) => {
        this.reconnectAttempts = 0;
        try {
          const chunk = JSON.parse(event.data as string) as StreamChunk;
          this.emit(chunk);
        } catch {
          this.emit({ type: 'text', data: event.data });
        }
      };

      es.onerror = () => {
        es.close();
        this.eventSource = null;
        this.scheduleReconnect(url, sessionId);
      };
    } catch {
      this.scheduleReconnect(url, sessionId);
    }
  }

  private scheduleReconnect(url: string, sessionId: string): void {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      this.emit({ type: 'error', data: 'Max reconnect attempts reached' });
      return;
    }
    const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), 30000);
    this.reconnectAttempts++;
    this.reconnectTimer = setTimeout(() => {
      this.openConnection(url, sessionId);
    }, delay);
  }

  private emit(chunk: StreamChunk): void {
    this.chunkCallbacks.forEach((cb) => cb(chunk));
  }
}
