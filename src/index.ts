import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import type { OrcaConfig, Session } from './types';
import { SessionManager } from './session/SessionManager';
import { PermissionManager } from './permissions/PermissionManager';
import { StreamingClient } from './streaming/StreamingClient';
import { PageContextCapture } from './context/PageContextCapture';
import { PlanningEngine } from './planning/PlanningEngine';
import { TerminalBridge } from './terminal/TerminalBridge';
import { OrcaButton } from './widget/OrcaButton';
import { OrcaPanel } from './widget/OrcaPanel';

export type { OrcaConfig } from './types';

interface AppProps {
  config: OrcaConfig;
  sessionManager: SessionManager;
  permissionManager: PermissionManager;
  streamingClient: StreamingClient;
  contextCapture: PageContextCapture;
  planningEngine: PlanningEngine;
  terminalBridge: TerminalBridge;
}

function OrcaApp({
  config,
  sessionManager,
  permissionManager,
  streamingClient,
  contextCapture,
  planningEngine,
  terminalBridge,
}: AppProps) {
  const [open, setOpen] = React.useState(false);
  const [hasActive, setHasActive] = React.useState(false);

  React.useEffect(() => {
    const unsub = sessionManager.onStatusChange((s) => {
      setHasActive(s.status !== 'idle' && s.status !== 'ended');
    });
    return unsub;
  }, [sessionManager]);

  return React.createElement(
    React.Fragment,
    null,
    React.createElement(OrcaButton, {
      isOpen: open,
      hasActiveSession: hasActive,
      onClick: () => setOpen((o) => !o),
    }),
    open
      ? React.createElement(OrcaPanel, {
          sessionManager,
          permissionManager,
          streamingClient,
          contextCapture,
          planningEngine,
          terminalBridge,
          endpoint: config.endpoint,
          onClose: () => setOpen(false),
        })
      : null
  );
}

export class OrcaWidget {
  private config: OrcaConfig;
  private container: HTMLElement | null = null;
  private root: Root | null = null;
  private sessionManager: SessionManager;
  private permissionManager: PermissionManager;
  private streamingClient: StreamingClient;
  private contextCapture: PageContextCapture;
  private planningEngine: PlanningEngine;
  private terminalBridge: TerminalBridge;

  constructor(config: OrcaConfig = {}) {
    this.config = { devOnly: true, ...config };
    this.sessionManager = new SessionManager();
    this.permissionManager = new PermissionManager(config.permissions);
    this.streamingClient = new StreamingClient();
    this.contextCapture = new PageContextCapture();
    this.planningEngine = new PlanningEngine();
    this.terminalBridge = new TerminalBridge();

    if (config.sessionId) {
      this.sessionManager.resumeSession(config.sessionId);
    }
  }

  mount(target?: HTMLElement): void {
    if (this.config.devOnly && !this.isDevMode()) return;
    if (this.root) return;

    this.contextCapture.start();
    this.container = document.createElement('div');
    this.container.id = 'orca-widget-root';
    (target ?? document.body).appendChild(this.container);

    this.root = createRoot(this.container);
    this.root.render(
      React.createElement(OrcaApp, {
        config: this.config,
        sessionManager: this.sessionManager,
        permissionManager: this.permissionManager,
        streamingClient: this.streamingClient,
        contextCapture: this.contextCapture,
        planningEngine: this.planningEngine,
        terminalBridge: this.terminalBridge,
      })
    );
  }

  unmount(): void {
    this.contextCapture.stop();
    this.streamingClient.disconnect();
    this.terminalBridge.disconnect();
    if (this.root) {
      this.root.unmount();
      this.root = null;
    }
    if (this.container) {
      this.container.remove();
      this.container = null;
    }
  }

  getSession(): Session | null {
    return this.sessionManager.getCurrentSession();
  }

  private isDevMode(): boolean {
    try {
      return (
        typeof window !== 'undefined' &&
        (window.location.hostname === 'localhost' ||
          window.location.hostname === '127.0.0.1' ||
          window.location.hostname.endsWith('.local') ||
          (import.meta as { env?: { DEV?: boolean } }).env?.DEV === true)
      );
    } catch {
      return false;
    }
  }
}

// Auto-mount
if (typeof document !== 'undefined') {
  const script = document.currentScript as HTMLScriptElement | null;
  if (script?.dataset.autoMount !== undefined) {
    const widget = new OrcaWidget({ devOnly: false });
    document.addEventListener('DOMContentLoaded', () => widget.mount());
  }
}

export default OrcaWidget;
