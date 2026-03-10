export interface OrcaConfig {
  endpoint?: string;
  permissions?: PermissionConfig;
  sessionId?: string;
  devOnly?: boolean;
}

export type PermissionConfig = {
  readDOM: boolean;
  readConsole: boolean;
  readNetwork: boolean;
  takeScreenshots: boolean;
  accessTerminal: boolean;
  modifyDOM: boolean;
};

export type SessionStatus = 'idle' | 'planning' | 'executing' | 'paused' | 'ended';

export interface Session {
  id: string;
  createdAt: number;
  updatedAt: number;
  status: SessionStatus;
  history: SessionEvent[];
  plan?: Plan;
}

export interface SessionEvent {
  id: string;
  type: 'message' | 'screenshot' | 'plan' | 'execution' | 'terminal' | 'error';
  timestamp: number;
  data: unknown;
}

export interface Plan {
  id: string;
  title: string;
  description: string;
  steps: PlanStep[];
  status: 'draft' | 'approved' | 'executing' | 'completed' | 'failed';
}

export interface PlanStep {
  id: string;
  title: string;
  description: string;
  status: 'pending' | 'running' | 'completed' | 'failed' | 'skipped';
  output?: string;
}

export interface StreamChunk {
  type: 'text' | 'plan' | 'step_update' | 'terminal' | 'error' | 'done';
  data: unknown;
}

export interface PageContext {
  url: string;
  title: string;
  timestamp: number;
  dom?: string;
  consoleLogs?: ConsoleLog[];
  networkRequests?: NetworkRequest[];
  errors?: PageError[];
}

export interface ConsoleLog {
  level: 'log' | 'warn' | 'error' | 'info' | 'debug';
  args: unknown[];
  timestamp: number;
  stack?: string;
}

export interface NetworkRequest {
  url: string;
  method: string;
  status?: number;
  duration?: number;
  timestamp: number;
}

export interface PageError {
  message: string;
  filename?: string;
  lineno?: number;
  colno?: number;
  timestamp: number;
}
