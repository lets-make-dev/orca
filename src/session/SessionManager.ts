import type { Session, SessionEvent, SessionStatus } from '../types';

const STORAGE_KEY = 'orca_sessions';

type StatusChangeListener = (session: Session) => void;

export class SessionManager {
  private sessions: Map<string, Session> = new Map();
  private currentSessionId: string | null = null;
  private listeners: StatusChangeListener[] = [];

  constructor() {
    this.loadFromStorage();
  }

  createSession(): Session {
    const session: Session = {
      id: this.generateId(),
      createdAt: Date.now(),
      updatedAt: Date.now(),
      status: 'idle',
      history: [],
    };
    this.sessions.set(session.id, session);
    this.currentSessionId = session.id;
    this.saveToStorage();
    this.emit(session);
    return session;
  }

  resumeSession(id: string): Session | null {
    const session = this.sessions.get(id);
    if (!session) return null;
    if (session.status === 'ended') {
      session.status = 'idle';
      session.updatedAt = Date.now();
    }
    this.currentSessionId = id;
    this.saveToStorage();
    this.emit(session);
    return session;
  }

  pauseSession(): Session | null {
    const session = this.getCurrentSession();
    if (!session) return null;
    session.status = 'paused';
    session.updatedAt = Date.now();
    this.saveToStorage();
    this.emit(session);
    return session;
  }

  endSession(): Session | null {
    const session = this.getCurrentSession();
    if (!session) return null;
    session.status = 'ended';
    session.updatedAt = Date.now();
    this.currentSessionId = null;
    this.saveToStorage();
    this.emit(session);
    return session;
  }

  addEvent(event: Omit<SessionEvent, 'id' | 'timestamp'>): SessionEvent | null {
    const session = this.getCurrentSession();
    if (!session) return null;
    const fullEvent: SessionEvent = {
      ...event,
      id: this.generateId(),
      timestamp: Date.now(),
    };
    session.history.push(fullEvent);
    session.updatedAt = Date.now();
    this.saveToStorage();
    return fullEvent;
  }

  setStatus(status: SessionStatus): void {
    const session = this.getCurrentSession();
    if (!session) return;
    session.status = status;
    session.updatedAt = Date.now();
    this.saveToStorage();
    this.emit(session);
  }

  getCurrentSession(): Session | null {
    if (!this.currentSessionId) return null;
    return this.sessions.get(this.currentSessionId) ?? null;
  }

  getSessions(): Session[] {
    return Array.from(this.sessions.values()).sort((a, b) => b.createdAt - a.createdAt);
  }

  clearSessions(): void {
    this.sessions.clear();
    this.currentSessionId = null;
    this.saveToStorage();
  }

  onStatusChange(listener: StatusChangeListener): () => void {
    this.listeners.push(listener);
    return () => {
      this.listeners = this.listeners.filter((l) => l !== listener);
    };
  }

  private emit(session: Session): void {
    this.listeners.forEach((l) => l(session));
  }

  private generateId(): string {
    return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
  }

  private loadFromStorage(): void {
    try {
      if (typeof localStorage === 'undefined') return;
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const data = JSON.parse(raw) as Session[];
      data.forEach((s) => this.sessions.set(s.id, s));
    } catch {
      // ignore
    }
  }

  private saveToStorage(): void {
    try {
      if (typeof localStorage === 'undefined') return;
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.getSessions()));
    } catch {
      // ignore
    }
  }
}
