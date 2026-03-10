import { describe, it, expect, beforeEach, vi } from 'vitest';
import { SessionManager } from '../session/SessionManager';

const localStorageMock = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: (key: string) => store[key] ?? null,
    setItem: (key: string, value: string) => { store[key] = value; },
    removeItem: (key: string) => { delete store[key]; },
    clear: () => { store = {}; },
  };
})();
Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock, writable: true });

describe('SessionManager', () => {
  let manager: SessionManager;

  beforeEach(() => {
    localStorageMock.clear();
    manager = new SessionManager();
  });

  it('creates a session', () => {
    const session = manager.createSession();
    expect(session.id).toBeTruthy();
    expect(session.status).toBe('idle');
    expect(session.history).toHaveLength(0);
  });

  it('resumes an existing session', () => {
    const session = manager.createSession();
    manager.endSession();
    const resumed = manager.resumeSession(session.id);
    expect(resumed).not.toBeNull();
    expect(resumed!.id).toBe(session.id);
  });

  it('pauses the current session', () => {
    manager.createSession();
    const paused = manager.pauseSession();
    expect(paused?.status).toBe('paused');
  });

  it('ends the current session', () => {
    manager.createSession();
    const ended = manager.endSession();
    expect(ended?.status).toBe('ended');
    expect(manager.getCurrentSession()).toBeNull();
  });

  it('adds events to the current session', () => {
    manager.createSession();
    const event = manager.addEvent({ type: 'message', data: { text: 'hello' } });
    expect(event).not.toBeNull();
    expect(event!.type).toBe('message');
    expect(manager.getCurrentSession()!.history).toHaveLength(1);
  });

  it('getSessions returns all sessions', () => {
    manager.createSession();
    manager.endSession();
    manager.createSession();
    expect(manager.getSessions()).toHaveLength(2);
  });

  it('clearSessions removes all sessions', () => {
    manager.createSession();
    manager.clearSessions();
    expect(manager.getSessions()).toHaveLength(0);
  });

  it('emits status change events', () => {
    const listener = vi.fn();
    manager.onStatusChange(listener);
    manager.createSession();
    expect(listener).toHaveBeenCalledOnce();
  });
});
