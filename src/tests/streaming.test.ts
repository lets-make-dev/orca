import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { StreamingClient } from '../streaming/StreamingClient';

describe('StreamingClient', () => {
  let client: StreamingClient;

  beforeEach(() => {
    client = new StreamingClient();
    vi.useFakeTimers();
  });

  afterEach(() => {
    client.disconnect();
    vi.useRealTimers();
  });

  it('can register onChunk callback', () => {
    const cb = vi.fn();
    const unsub = client.onChunk(cb);
    expect(typeof unsub).toBe('function');
  });

  it('unsubscribe removes the callback', async () => {
    const cb = vi.fn();
    const unsub = client.onChunk(cb);
    unsub();
    client.connectMock();
    await vi.runAllTimersAsync();
    expect(cb).not.toHaveBeenCalled();
  });

  it('connectMock emits text chunk and done', async () => {
    const chunks: unknown[] = [];
    client.onChunk((c) => chunks.push(c));
    client.connectMock();
    await vi.runAllTimersAsync();
    expect(chunks.length).toBeGreaterThan(0);
  });

  it('disconnect clears connection', async () => {
    const cb = vi.fn();
    client.onChunk(cb);
    client.connectMock();
    client.disconnect();
    await vi.runAllTimersAsync();
    expect(() => client.disconnect()).not.toThrow();
  });

  it('onChunk can be called multiple times', async () => {
    const cb1 = vi.fn();
    const cb2 = vi.fn();
    client.onChunk(cb1);
    client.onChunk(cb2);
    client.connectMock();
    await vi.runAllTimersAsync();
    expect(cb1).toHaveBeenCalled();
    expect(cb2).toHaveBeenCalled();
  });
});
