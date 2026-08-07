/**
 * Store - 简易响应式状态管理
 */
import { eventBus, EVENTS } from './EventBus.js';

class Store {
  #state = {};
  #listeners = new Map();

  constructor(initial = {}) { this.#state = { ...initial }; }

  /** 读取 */
  get(key) { return this.#state[key]; }

  /** 写入（触发更新） */
  set(key, value) {
    const old = this.#state[key];
    if (old === value) return;
    this.#state[key] = value;
    this.#notify(key, value, old);
  }

  /** 批量写入 */
  setMultiple(obj) {
    Object.entries(obj).forEach(([k, v]) => {
      if (this.#state[k] !== v) this.#state[k] = v;
    });
    Object.keys(obj).forEach(k => this.#notify(k, this.#state[k]));
  }

  /** 订阅 */
  subscribe(key, callback) {
    if (!this.#listeners.has(key)) this.#listeners.set(key, new Set());
    this.#listeners.get(key).add(callback);
    return () => this.unsubscribe(key, callback);
  }

  unsubscribe(key, callback) { this.#listeners.get(key)?.delete(callback); }

  #notify(key, value, old) {
    this.#listeners.get(key)?.forEach(cb => { try { cb(value, old); } catch (e) { console.error('[Store]', key, e); } });
    eventBus.emit(EVENTS.THEME_CHANGED, key, value, old); // 通用变更事件
  }

  /** 重置 */
  reset() { this.#state = {}; this.#listeners.clear(); }
}

export const appStore = new Store({
  // UI State
  theme: 'dark',
  sidebarOpen: window.innerWidth <= 768,
  zoom: 1,
  // Data
  folderTree: [],
  collapsedFolders: new Set(),
  currentFolderPath: '',
  currentFolderName: '',
  images: [],
  filteredImages: [],
  searchKeyword: '',
  // Connection
  isConnected: false,
  isConnecting: false,
  port: 8080,
  connectionInfo: null,
  quickImportEnabled: false,
  // Lightbox
  lightboxOpen: false,
  lightboxIndex: -1,
  currentLightboxImg: null,
  lightboxScale: 1,
  lightboxTranslate: { x: 0, y: 0 },
  // Layout
  layout: [],
  actualHeights: new Map(),
  renderedCards: new Map(),
  pendingHeightUpdates: new Set(),
});