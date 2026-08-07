/**
 * EventBus - 简单发布/订阅，组件间解耦
 */
class EventBus {
  #events = new Map();

  /** 订阅 */
  on(event, callback) {
    if (!this.#events.has(event)) this.#events.set(event, new Set());
    this.#events.get(event).add(callback);
    return () => this.off(event, callback);
  }

  /** 单次订阅 */
  once(event, callback) {
    const wrapper = (...args) => {
      this.off(event, wrapper);
      callback(...args);
    };
    return this.on(event, wrapper);
  }

  /** 取消订阅 */
  off(event, callback) {
    this.#events.get(event)?.delete(callback);
  }

  /** 发布 */
  emit(event, ...args) {
    console.log('[EventBus] emit:', event, args);
    this.#events.get(event)?.forEach(cb => {
      try { cb(...args); } catch (e) { console.error('[EventBus]', event, e); }
    });
  }

  /** 清空 */
  clear() { this.#events.clear(); }
}

export const eventBus = new EventBus();

// 事件名常量
export const EVENTS = {
  CONNECTION_CHANGED: 'connection:changed',
  THEME_CHANGED: 'theme:changed',
  FOLDER_SELECTED: 'folder:selected',
  FOLDER_TREE_LOADED: 'folder:treeLoaded',
  IMAGES_LOADED: 'images:loaded',
  LIGHTBOX_OPEN: 'lightbox:open',
  LIGHTBOX_CLOSE: 'lightbox:close',
  LIGHTBOX_NAV: 'lightbox:nav',
  TOAST_SHOW: 'toast:show',
  QUICK_INSERT: 'quick:insert',
  QUICK_IMPORT_TOGGLE: 'quickimport:toggle',
  ZOOM_CHANGED: 'zoom:changed',
  SEARCH: 'search',
};