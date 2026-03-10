import { describe, it, expect, beforeEach, vi } from 'vitest';
import { PermissionManager } from '../permissions/PermissionManager';

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

describe('PermissionManager', () => {
  let manager: PermissionManager;

  beforeEach(() => {
    localStorageMock.clear();
    manager = new PermissionManager();
  });

  it('starts with all permissions denied', () => {
    const perms = manager.getPermissions();
    expect(perms.readDOM).toBe(false);
    expect(perms.readConsole).toBe(false);
    expect(perms.accessTerminal).toBe(false);
  });

  it('hasPermission returns false by default', () => {
    expect(manager.hasPermission('readDOM')).toBe(false);
  });

  it('updatePermissions grants specific permissions', () => {
    manager.updatePermissions({ readDOM: true, readConsole: true });
    expect(manager.hasPermission('readDOM')).toBe(true);
    expect(manager.hasPermission('readConsole')).toBe(true);
    expect(manager.hasPermission('readNetwork')).toBe(false);
  });

  it('requestPermission grants when user confirms', async () => {
    vi.stubGlobal('confirm', () => true);
    const result = await manager.requestPermission('takeScreenshots');
    expect(result).toBe(true);
    expect(manager.hasPermission('takeScreenshots')).toBe(true);
    vi.unstubAllGlobals();
  });

  it('requestPermission denies when user cancels', async () => {
    vi.stubGlobal('confirm', () => false);
    const result = await manager.requestPermission('modifyDOM');
    expect(result).toBe(false);
    expect(manager.hasPermission('modifyDOM')).toBe(false);
    vi.unstubAllGlobals();
  });

  it('requestPermission skips confirm if already granted', async () => {
    manager.updatePermissions({ readNetwork: true });
    const confirmSpy = vi.fn(() => true);
    vi.stubGlobal('confirm', confirmSpy);
    const result = await manager.requestPermission('readNetwork');
    expect(result).toBe(true);
    expect(confirmSpy).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });

  it('resetPermissions revokes all permissions', () => {
    manager.updatePermissions({ readDOM: true });
    manager.resetPermissions();
    expect(manager.hasPermission('readDOM')).toBe(false);
  });
});
