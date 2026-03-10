import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { PageContextCapture } from '../context/PageContextCapture';

describe('PageContextCapture', () => {
  let capture: PageContextCapture;

  beforeEach(() => {
    capture = new PageContextCapture();
  });

  afterEach(() => {
    capture.stop();
  });

  it('getContext returns url and title', () => {
    const ctx = capture.getContext();
    expect(ctx.url).toBeDefined();
    expect(ctx.title).toBeDefined();
    expect(ctx.timestamp).toBeGreaterThan(0);
  });

  it('start/stop does not throw', () => {
    expect(() => capture.start()).not.toThrow();
    expect(() => capture.stop()).not.toThrow();
  });

  it('intercepts console.log after start', () => {
    capture.start();
    console.log('test message', 42);
    const ctx = capture.getContext();
    const found = ctx.consoleLogs?.find((l) => l.level === 'log' && l.args.includes('test message'));
    expect(found).toBeDefined();
  });

  it('intercepts console.warn after start', () => {
    capture.start();
    console.warn('warn message');
    const ctx = capture.getContext();
    const found = ctx.consoleLogs?.find((l) => l.level === 'warn');
    expect(found).toBeDefined();
  });

  it('does not intercept console after stop', () => {
    capture.start();
    capture.stop();
    const beforeCount = capture.getContext().consoleLogs?.length ?? 0;
    console.log('after stop');
    const afterCount = capture.getContext().consoleLogs?.length ?? 0;
    expect(afterCount).toBe(beforeCount);
  });

  it('clearLogs resets captured data', () => {
    capture.start();
    console.log('will be cleared');
    capture.clearLogs();
    expect(capture.getContext().consoleLogs).toHaveLength(0);
  });

  it('getContext includes consoleLogs array', () => {
    capture.start();
    const ctx = capture.getContext();
    expect(Array.isArray(ctx.consoleLogs)).toBe(true);
    expect(Array.isArray(ctx.networkRequests)).toBe(true);
    expect(Array.isArray(ctx.errors)).toBe(true);
  });
});
