import type { PermissionConfig } from '../types';

const STORAGE_KEY = 'orca_permissions';

const DEFAULT_PERMISSIONS: PermissionConfig = {
  readDOM: false,
  readConsole: false,
  readNetwork: false,
  takeScreenshots: false,
  accessTerminal: false,
  modifyDOM: false,
};

export type PermissionKey = keyof PermissionConfig;

export class PermissionManager {
  private permissions: PermissionConfig;

  constructor(initial?: Partial<PermissionConfig>) {
    this.permissions = { ...DEFAULT_PERMISSIONS, ...this.loadFromStorage(), ...initial };
  }

  hasPermission(key: PermissionKey): boolean {
    return this.permissions[key];
  }

  async requestPermission(key: PermissionKey): Promise<boolean> {
    if (this.permissions[key]) return true;
    const labels: Record<PermissionKey, string> = {
      readDOM: 'Read page DOM',
      readConsole: 'Read console logs',
      readNetwork: 'Read network requests',
      takeScreenshots: 'Take screenshots',
      accessTerminal: 'Access terminal',
      modifyDOM: 'Modify page DOM',
    };
    const granted = confirm(`Orca requests permission to: ${labels[key]}. Allow?`);
    if (granted) {
      this.permissions[key] = true;
      this.saveToStorage();
    }
    return granted;
  }

  getPermissions(): PermissionConfig {
    return { ...this.permissions };
  }

  updatePermissions(partial: Partial<PermissionConfig>): void {
    this.permissions = { ...this.permissions, ...partial };
    this.saveToStorage();
  }

  resetPermissions(): void {
    this.permissions = { ...DEFAULT_PERMISSIONS };
    this.saveToStorage();
  }

  private loadFromStorage(): Partial<PermissionConfig> {
    try {
      if (typeof localStorage === 'undefined') return {};
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return {};
      return JSON.parse(raw) as Partial<PermissionConfig>;
    } catch {
      return {};
    }
  }

  private saveToStorage(): void {
    try {
      if (typeof localStorage === 'undefined') return;
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.permissions));
    } catch {
      // ignore
    }
  }
}
