import type { PageContext, ConsoleLog, NetworkRequest, PageError } from '../types';

type ConsoleLevelKey = 'log' | 'warn' | 'error' | 'info' | 'debug';

export class PageContextCapture {
  private consoleLogs: ConsoleLog[] = [];
  private networkRequests: NetworkRequest[] = [];
  private errors: PageError[] = [];
  private originalConsoleMethods: Partial<Record<ConsoleLevelKey, (...args: unknown[]) => void>> = {};
  private originalFetch: typeof window.fetch | null = null;
  private originalXHROpen: typeof XMLHttpRequest.prototype.open | null = null;
  private running = false;

  start(): void {
    if (this.running) return;
    this.running = true;
    this.interceptConsole();
    this.interceptFetch();
    this.interceptXHR();
    this.interceptErrors();
  }

  stop(): void {
    if (!this.running) return;
    this.running = false;
    this.restoreConsole();
    this.restoreFetch();
  }

  getContext(): PageContext {
    return {
      url: typeof window !== 'undefined' ? window.location.href : '',
      title: typeof document !== 'undefined' ? document.title : '',
      timestamp: Date.now(),
      dom: this.serializeDOM(),
      consoleLogs: [...this.consoleLogs],
      networkRequests: [...this.networkRequests],
      errors: [...this.errors],
    };
  }

  clearLogs(): void {
    this.consoleLogs = [];
    this.networkRequests = [];
    this.errors = [];
  }

  private interceptConsole(): void {
    const levels: ConsoleLevelKey[] = ['log', 'warn', 'error', 'info', 'debug'];
    levels.forEach((level) => {
      this.originalConsoleMethods[level] = console[level].bind(console);
      console[level] = (...args: unknown[]) => {
        this.consoleLogs.push({ level, args, timestamp: Date.now() });
        if (this.originalConsoleMethods[level]) {
          this.originalConsoleMethods[level]!(...args);
        }
      };
    });
  }

  private restoreConsole(): void {
    const levels: ConsoleLevelKey[] = ['log', 'warn', 'error', 'info', 'debug'];
    levels.forEach((level) => {
      if (this.originalConsoleMethods[level]) {
        console[level] = this.originalConsoleMethods[level]!;
        delete this.originalConsoleMethods[level];
      }
    });
  }

  private interceptFetch(): void {
    if (typeof window === 'undefined' || !window.fetch) return;
    this.originalFetch = window.fetch.bind(window);
    const self = this;
    window.fetch = async function (input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : (input as Request).url;
      const method = init?.method ?? (typeof input !== 'string' && !(input instanceof URL) ? (input as Request).method : 'GET');
      const start = Date.now();
      const entry: NetworkRequest = { url, method, timestamp: start };
      self.networkRequests.push(entry);
      try {
        const response = await self.originalFetch!(input, init);
        entry.status = response.status;
        entry.duration = Date.now() - start;
        return response;
      } catch (err) {
        entry.duration = Date.now() - start;
        throw err;
      }
    };
  }

  private restoreFetch(): void {
    if (this.originalFetch && typeof window !== 'undefined') {
      window.fetch = this.originalFetch;
      this.originalFetch = null;
    }
  }

  private interceptXHR(): void {
    if (typeof XMLHttpRequest === 'undefined') return;
    const self = this;
    this.originalXHROpen = XMLHttpRequest.prototype.open;
    // Use a unified open signature that covers both overloads, with an explicit
    // `this: XMLHttpRequest` parameter so the function body is properly typed.
    type XHROpenFn = (this: XMLHttpRequest, method: string, url: string | URL, async?: boolean, username?: string | null, password?: string | null) => void;
    (XMLHttpRequest.prototype as unknown as { open: XHROpenFn }).open = function (
      this: XMLHttpRequest,
      method: string,
      url: string | URL,
      async: boolean = true,
      username?: string | null,
      password?: string | null
    ) {
      const urlStr = url instanceof URL ? url.href : String(url);
      const start = Date.now();
      const entry: NetworkRequest = { url: urlStr, method, timestamp: start };
      self.networkRequests.push(entry);
      this.addEventListener('loadend', () => {
        entry.status = this.status;
        entry.duration = Date.now() - start;
      });
      return self.originalXHROpen!.call(this, method, url, async, username, password);
    };
  }

  private interceptErrors(): void {
    if (typeof window === 'undefined') return;
    window.addEventListener('error', (event) => {
      this.errors.push({
        message: event.message,
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        timestamp: Date.now(),
      });
    });
  }

  private serializeDOM(): string {
    if (typeof document === 'undefined') return '';
    try {
      return document.documentElement.outerHTML.slice(0, 50000);
    } catch {
      return '';
    }
  }
}
