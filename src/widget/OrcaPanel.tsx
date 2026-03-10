import React, { useState, useEffect, useRef, useCallback } from 'react';
import type { Session, Plan, PlanStep, PageContext } from '../types';
import { SessionManager } from '../session/SessionManager';
import { PermissionManager } from '../permissions/PermissionManager';
import { StreamingClient } from '../streaming/StreamingClient';
import { PageContextCapture } from '../context/PageContextCapture';
import { PlanningEngine } from '../planning/PlanningEngine';
import { TerminalBridge } from '../terminal/TerminalBridge';
import { captureScreenshot } from '../screenshot/capture';
import { Annotator } from '../screenshot/Annotator';
import './widget.css';

type TabId = 'chat' | 'plan' | 'context' | 'screenshot' | 'terminal' | 'session';

interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
  id: string;
}

interface OrcaPanelProps {
  sessionManager: SessionManager;
  permissionManager: PermissionManager;
  streamingClient: StreamingClient;
  contextCapture: PageContextCapture;
  planningEngine: PlanningEngine;
  terminalBridge: TerminalBridge;
  endpoint?: string;
  onClose: () => void;
}

export const OrcaPanel: React.FC<OrcaPanelProps> = ({
  sessionManager,
  permissionManager,
  streamingClient,
  contextCapture,
  planningEngine,
  terminalBridge,
  endpoint,
  onClose,
}) => {
  const [activeTab, setActiveTab] = useState<TabId>('chat');
  const [minimized, setMinimized] = useState(false);
  const [session, setSession] = useState<Session | null>(sessionManager.getCurrentSession());
  const [chatMessages, setChatMessages] = useState<ChatMessage[]>([]);
  const [chatInput, setChatInput] = useState('');
  const [streaming, setStreaming] = useState(false);
  const [plan, setPlan] = useState<Plan | null>(null);
  const [pageContext, setPageContext] = useState<PageContext | null>(null);
  const [screenshot, setScreenshot] = useState<string | null>(null);
  const [terminalOutput, setTerminalOutput] = useState('Welcome to Orca Terminal\r\n$ ');
  const [terminalInput, setTerminalInput] = useState('');
  const [terminalWsUrl, setTerminalWsUrl] = useState('');
  const [sessions, setSessions] = useState<Session[]>(sessionManager.getSessions());
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [dragging, setDragging] = useState(false);
  const dragStart = useRef<{ mx: number; my: number; px: number; py: number } | null>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const chatBottomRef = useRef<HTMLDivElement>(null);
  const terminalRef = useRef<HTMLDivElement>(null);

  // Session listener
  useEffect(() => {
    const unsub = sessionManager.onStatusChange((s) => {
      setSession({ ...s });
      setSessions(sessionManager.getSessions());
    });
    return unsub;
  }, [sessionManager]);

  // Streaming listener
  useEffect(() => {
    const unsub = streamingClient.onChunk((chunk) => {
      if (chunk.type === 'text') {
        setChatMessages((prev) => {
          const last = prev[prev.length - 1];
          if (last && last.role === 'assistant') {
            return [...prev.slice(0, -1), { ...last, content: last.content + String(chunk.data) }];
          }
          return [...prev, { role: 'assistant', content: String(chunk.data), id: String(Date.now()) }];
        });
      } else if (chunk.type === 'done') {
        setStreaming(false);
      } else if (chunk.type === 'error') {
        setStreaming(false);
        setChatMessages((prev) => [...prev, { role: 'assistant', content: `Error: ${String(chunk.data)}`, id: String(Date.now()) }]);
      }
    });
    return unsub;
  }, [streamingClient]);

  // Terminal output listener
  useEffect(() => {
    const unsub = terminalBridge.onOutput((data) => {
      setTerminalOutput((prev) => prev + data);
      setTimeout(() => {
        if (terminalRef.current) terminalRef.current.scrollTop = terminalRef.current.scrollHeight;
      }, 0);
    });
    return unsub;
  }, [terminalBridge]);

  // Auto-scroll chat
  useEffect(() => {
    chatBottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [chatMessages]);

  // Dragging
  const onHeaderMouseDown = useCallback((e: React.MouseEvent) => {
    if ((e.target as HTMLElement).closest('button')) return;
    setDragging(true);
    dragStart.current = { mx: e.clientX, my: e.clientY, px: position.x, py: position.y };
  }, [position]);

  useEffect(() => {
    if (!dragging) return;
    function onMove(e: MouseEvent) {
      if (!dragStart.current) return;
      setPosition({ x: dragStart.current.px + e.clientX - dragStart.current.mx, y: dragStart.current.py + e.clientY - dragStart.current.my });
    }
    function onUp() { setDragging(false); }
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    return () => { window.removeEventListener('mousemove', onMove); window.removeEventListener('mouseup', onUp); };
  }, [dragging]);

  function ensureSession(): Session {
    let s = sessionManager.getCurrentSession();
    if (!s) s = sessionManager.createSession();
    setSession({ ...s });
    return s;
  }

  async function sendMessage() {
    const text = chatInput.trim();
    if (!text || streaming) return;
    setChatInput('');
    const s = ensureSession();
    setChatMessages((prev) => [...prev, { role: 'user', content: text, id: String(Date.now()) }]);
    sessionManager.addEvent({ type: 'message', data: { role: 'user', content: text } });
    setStreaming(true);
    if (endpoint) {
      streamingClient.connect(endpoint, s.id);
    } else {
      streamingClient.connectMock();
    }
  }

  async function refreshContext() {
    if (!permissionManager.hasPermission('readDOM') || !permissionManager.hasPermission('readConsole')) {
      await permissionManager.requestPermission('readDOM');
      await permissionManager.requestPermission('readConsole');
      await permissionManager.requestPermission('readNetwork');
    }
    const ctx = contextCapture.getContext();
    setPageContext(ctx);
  }

  async function takeScreenshot() {
    if (!permissionManager.hasPermission('takeScreenshots')) {
      const granted = await permissionManager.requestPermission('takeScreenshots');
      if (!granted) return;
    }
    const dataUrl = await captureScreenshot();
    setScreenshot(dataUrl);
    sessionManager.addEvent({ type: 'screenshot', data: dataUrl });
    setActiveTab('screenshot');
  }

  function createDemoPlan() {
    const newPlan = planningEngine.createPlan('Demo Task', 'A demonstration plan', [
      { title: 'Analyze page', description: 'Capture and analyze the current page context' },
      { title: 'Generate code', description: 'Generate the required code changes' },
      { title: 'Apply changes', description: 'Apply changes to the page' },
    ]);
    setPlan(newPlan);
    setActiveTab('plan');
    ensureSession();
    sessionManager.addEvent({ type: 'plan', data: newPlan });
  }

  async function approvePlan() {
    if (!plan) return;
    const approved = planningEngine.approvePlan(plan);
    sessionManager.setStatus('executing');
    await planningEngine.startExecution(approved, (_planId, step: PlanStep) => {
      setPlan((prev) => {
        if (!prev) return prev;
        return { ...prev, steps: prev.steps.map((s) => (s.id === step.id ? step : s)) };
      });
    });
    setPlan(planningEngine.getPlan(approved.id) ?? approved);
    sessionManager.setStatus('idle');
  }

  function rejectPlan() {
    if (!plan) return;
    planningEngine.rejectPlan(plan);
    setPlan(null);
  }

  async function connectTerminal() {
    if (!terminalWsUrl) return;
    if (!permissionManager.hasPermission('accessTerminal')) {
      const granted = await permissionManager.requestPermission('accessTerminal');
      if (!granted) return;
    }
    terminalBridge.connect(terminalWsUrl);
  }

  function sendTerminalInput(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Enter') {
      terminalBridge.sendInput(terminalInput + '\n');
      setTerminalOutput((prev) => prev + terminalInput + '\n');
      setTerminalInput('');
    }
  }

  const tabs: Array<{ id: TabId; label: string }> = [
    { id: 'chat', label: '💬 Chat' },
    { id: 'plan', label: '📋 Plan' },
    { id: 'context', label: '🔍 Context' },
    { id: 'screenshot', label: '📸 Screenshot' },
    { id: 'terminal', label: '🖥️ Terminal' },
    { id: 'session', label: '📁 Session' },
  ];

  const panelStyle: React.CSSProperties = {
    transform: `translate(${position.x}px, ${position.y}px)`,
  };

  return (
    <div
      ref={panelRef}
      className={`orca-panel ${minimized ? 'minimized' : ''}`}
      style={panelStyle}
    >
      {/* Header */}
      <div className="orca-panel-header" onMouseDown={onHeaderMouseDown}>
        <span className="orca-panel-header-title">🐋 Orca</span>
        {session && (
          <span className={`orca-panel-header-status ${session.status}`}>{session.status}</span>
        )}
        <button className="orca-btn-icon" onClick={() => setMinimized((m) => !m)} title={minimized ? 'Restore' : 'Minimize'}>
          {minimized ? '▲' : '▼'}
        </button>
        <button className="orca-btn-icon" onClick={onClose} title="Close">✕</button>
      </div>

      {!minimized && (
        <>
          {/* Tabs */}
          <div className="orca-tabs">
            {tabs.map((t) => (
              <button
                key={t.id}
                className={`orca-tab ${activeTab === t.id ? 'active' : ''}`}
                onClick={() => setActiveTab(t.id)}
              >
                {t.label}
              </button>
            ))}
          </div>

          {/* Tab content */}
          <div className="orca-tab-content">
            {/* CHAT TAB */}
            {activeTab === 'chat' && (
              <>
                <div className="orca-chat-messages">
                  {chatMessages.length === 0 && (
                    <div style={{ color: '#555', fontSize: 12, textAlign: 'center', marginTop: 20 }}>
                      Start a conversation with Orca AI
                    </div>
                  )}
                  {chatMessages.map((msg) => (
                    <div key={msg.id} className={`orca-chat-bubble ${msg.role}`}>
                      {msg.content}
                    </div>
                  ))}
                  {streaming && (
                    <div className="orca-chat-bubble assistant" style={{ opacity: 0.7 }}>
                      ⠋ Thinking...
                    </div>
                  )}
                  <div ref={chatBottomRef} />
                </div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <button className="orca-btn-secondary" onClick={createDemoPlan}>+ Create Plan</button>
                  <button className="orca-btn-secondary" onClick={() => void takeScreenshot()}>📸 Screenshot</button>
                  <button className="orca-btn-secondary" onClick={() => void refreshContext()}>🔍 Context</button>
                </div>
                <div className="orca-chat-input-row">
                  <textarea
                    className="orca-chat-input"
                    value={chatInput}
                    onChange={(e) => setChatInput(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void sendMessage(); } }}
                    placeholder="Ask Orca... (Enter to send, Shift+Enter for newline)"
                    rows={2}
                  />
                  <button className="orca-btn-primary" onClick={() => void sendMessage()} disabled={streaming}>
                    Send
                  </button>
                </div>
              </>
            )}

            {/* PLAN TAB */}
            {activeTab === 'plan' && (
              <>
                {!plan ? (
                  <div style={{ color: '#555', textAlign: 'center', marginTop: 20, fontSize: 12 }}>
                    No active plan. Create one from the Chat tab.
                    <br /><br />
                    <button className="orca-btn-secondary" onClick={createDemoPlan}>Create Demo Plan</button>
                  </div>
                ) : (
                  <>
                    <div>
                      <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 4 }}>{plan.title}</div>
                      <div style={{ fontSize: 12, color: '#888', marginBottom: 8 }}>{plan.description}</div>
                      <span className={`orca-step-status ${plan.status}`}>{plan.status}</span>
                    </div>
                    {plan.steps.map((step) => (
                      <div key={step.id} className="orca-plan-step">
                        <div className="orca-plan-step-header">
                          <span className={`orca-step-status ${step.status}`}>{step.status}</span>
                          <span style={{ fontWeight: 600, fontSize: 13 }}>{step.title}</span>
                        </div>
                        <div className="orca-plan-step-desc">{step.description}</div>
                        {step.output && <div className="orca-plan-step-output">{step.output}</div>}
                      </div>
                    ))}
                    {plan.status === 'draft' && (
                      <div style={{ display: 'flex', gap: 8 }}>
                        <button className="orca-btn-primary" onClick={() => void approvePlan()}>✓ Approve</button>
                        <button className="orca-btn-danger" onClick={rejectPlan}>✗ Reject</button>
                      </div>
                    )}
                  </>
                )}
              </>
            )}

            {/* CONTEXT TAB */}
            {activeTab === 'context' && (
              <>
                <button className="orca-btn-secondary" onClick={() => void refreshContext()}>🔄 Refresh Context</button>
                {!pageContext ? (
                  <div style={{ color: '#555', fontSize: 12, textAlign: 'center' }}>Click Refresh to capture page context</div>
                ) : (
                  <>
                    <div className="orca-context-section">
                      <h4>Page Info</h4>
                      <div style={{ fontSize: 12 }}>
                        <div><strong>URL:</strong> {pageContext.url}</div>
                        <div><strong>Title:</strong> {pageContext.title}</div>
                        <div><strong>Captured:</strong> {new Date(pageContext.timestamp).toLocaleTimeString()}</div>
                      </div>
                    </div>
                    {pageContext.consoleLogs && pageContext.consoleLogs.length > 0 && (
                      <div className="orca-context-section">
                        <h4>Console Logs ({pageContext.consoleLogs.length})</h4>
                        {pageContext.consoleLogs.slice(-10).map((log, i) => (
                          <div key={i} className={`orca-log-entry ${log.level}`}>
                            [{log.level}] {log.args.map((a) => String(a)).join(' ')}
                          </div>
                        ))}
                      </div>
                    )}
                    {pageContext.networkRequests && pageContext.networkRequests.length > 0 && (
                      <div className="orca-context-section">
                        <h4>Network ({pageContext.networkRequests.length})</h4>
                        {pageContext.networkRequests.slice(-10).map((req, i) => (
                          <div key={i} className="orca-network-entry">
                            <span className="orca-method">{req.method}</span>
                            <span className={req.status && req.status >= 400 ? 'orca-status-err' : 'orca-status-ok'}>
                              {req.status ?? '...'}
                            </span>
                            <span style={{ color: '#888', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 200 }}>{req.url}</span>
                          </div>
                        ))}
                      </div>
                    )}
                    {pageContext.dom && (
                      <div className="orca-context-section">
                        <h4>DOM Preview</h4>
                        <div className="orca-dom-preview">{pageContext.dom.slice(0, 500)}...</div>
                      </div>
                    )}
                  </>
                )}
              </>
            )}

            {/* SCREENSHOT TAB */}
            {activeTab === 'screenshot' && (
              <>
                <button className="orca-btn-secondary" onClick={() => void takeScreenshot()}>📸 Capture Screenshot</button>
                {screenshot ? (
                  <Annotator
                    imageDataUrl={screenshot}
                    onSave={(dataUrl) => {
                      sessionManager.addEvent({ type: 'screenshot', data: dataUrl });
                    }}
                  />
                ) : (
                  <div style={{ color: '#555', fontSize: 12, textAlign: 'center', marginTop: 20 }}>
                    No screenshot captured yet
                  </div>
                )}
              </>
            )}

            {/* TERMINAL TAB */}
            {activeTab === 'terminal' && (
              <>
                <div style={{ display: 'flex', gap: 8 }}>
                  <input
                    style={{ flex: 1, background: '#12122a', border: '1px solid #3a3a6a', borderRadius: 6, color: '#e0e0f0', padding: '6px 10px', fontSize: 12, outline: 'none' }}
                    placeholder="WebSocket URL (ws://...)"
                    value={terminalWsUrl}
                    onChange={(e) => setTerminalWsUrl(e.target.value)}
                  />
                  <button className="orca-btn-secondary" onClick={() => void connectTerminal()}>Connect</button>
                  <button className="orca-btn-secondary" onClick={() => terminalBridge.disconnect()}>Disconnect</button>
                </div>
                <div className="orca-terminal" ref={terminalRef}>{terminalOutput}</div>
                <div className="orca-terminal-input-row">
                  <span className="orca-terminal-prompt">$ </span>
                  <input
                    className="orca-terminal-input"
                    value={terminalInput}
                    onChange={(e) => setTerminalInput(e.target.value)}
                    onKeyDown={sendTerminalInput}
                    placeholder="Type command..."
                  />
                </div>
              </>
            )}

            {/* SESSION TAB */}
            {activeTab === 'session' && (
              <>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <button className="orca-btn-primary" onClick={() => { const s = sessionManager.createSession(); setSession({ ...s }); }}>+ New Session</button>
                  {session && session.status !== 'paused' && (
                    <button className="orca-btn-secondary" onClick={() => sessionManager.pauseSession()}>⏸ Pause</button>
                  )}
                  {session && (
                    <button className="orca-btn-danger" onClick={() => sessionManager.endSession()}>⏹ End</button>
                  )}
                </div>
                {sessions.length === 0 ? (
                  <div style={{ color: '#555', fontSize: 12, textAlign: 'center' }}>No sessions yet</div>
                ) : (
                  sessions.map((s) => (
                    <div
                      key={s.id}
                      className={`orca-session-item ${session?.id === s.id ? 'current' : ''}`}
                      onClick={() => sessionManager.resumeSession(s.id)}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span style={{ fontFamily: 'monospace', fontSize: 11, color: '#666' }}>{s.id.slice(0, 16)}...</span>
                        <span className={`orca-step-status ${s.status}`}>{s.status}</span>
                      </div>
                      <div className="orca-session-meta">
                        {new Date(s.createdAt).toLocaleString()} · {s.history.length} events
                      </div>
                    </div>
                  ))
                )}
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
};
