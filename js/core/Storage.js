/**
 * Storage - localStorage 统一封装（带过期、前缀、错误处理、配额处理、LRU淘汰）
 */
import { CONFIG, STORAGE_KEYS } from './Config.js';

class StorageManager {
  #prefix = CONFIG.STORAGE_KEY;

  #key(key) { return this.#prefix + key; }

  /** 写入（自动加时间戳，配额超限时自动清理旧缓存重试） */
  set(key, data, ttl = null) {
    try {
      const payload = { data, timestamp: Date.now(), ttl };
      const json = JSON.stringify(payload);
      localStorage.setItem(this.#key(key), json);
      return true;
    } catch (e) {
      // 配额超限：清理过期项 + LRU 淘汰最旧项重试
      if (e.name === 'QuotaExceededError' || e.code === 22) {
        console.warn('[Storage] Quota exceeded, cleaning...');
        this.cleanExpired();
        this.evictLRU(3); // 淘汰 3 个最旧项
        try {
          const payload = { data, timestamp: Date.now(), ttl };
          localStorage.setItem(this.#key(key), JSON.stringify(payload));
          return true;
        } catch (e2) {
          console.warn('[Storage] Still quota exceeded, skipping cache for', key);
          return false;
        }
      }
      console.warn('[Storage] set failed', key, e);
      return false;
    }
  }

  /** 读取（自动过期清理） */
  get(key) {
    try {
      const raw = localStorage.getItem(this.#key(key));
      if (!raw) return null;
      const { data, timestamp, ttl } = JSON.parse(raw);
      if (ttl && Date.now() - timestamp > ttl) {
        this.remove(key);
        return null;
      }
      // 更新访问时间用于 LRU
      this.updateAccess(key);
      return data;
    } catch (e) {
      console.warn('[Storage] get failed', key, e);
      return null;
    }
  }

  /** 删除 */
  remove(key) { 
    localStorage.removeItem(this.#key(key));
    localStorage.removeItem(this.#key(key) + '_access');
  }

  /** 更新访问时间（LRU） */
  updateAccess(key) {
    try {
      localStorage.setItem(this.#key(key) + '_access', Date.now().toString());
    } catch {}
  }

  /** LRU 淘汰最旧项 */
  evictLRU(count = 5) {
    const entries = [];
    Object.keys(localStorage).forEach(k => {
      if (k.startsWith(this.#prefix) && k.endsWith('_access')) {
        const ts = parseInt(localStorage.getItem(k), 10);
        if (!isNaN(ts)) entries.push({ key: k.replace('_access', ''), ts });
      }
    });
    entries.sort((a, b) => a.ts - b.ts);
    entries.slice(0, count).forEach(e => {
      localStorage.removeItem(e.key);
      localStorage.removeItem(e.key + '_access');
    });
  }

  /** 清理过期项 */
  cleanExpired() {
    const now = Date.now();
    Object.keys(localStorage).forEach(k => {
      if (!k.startsWith(this.#prefix)) return;
      if (k.endsWith('_access')) return;
      try {
        const { timestamp, ttl } = JSON.parse(localStorage.getItem(k));
        if (ttl && now - timestamp > ttl) {
          localStorage.removeItem(k);
          localStorage.removeItem(k + '_access');
        }
      } catch { localStorage.removeItem(k); }
    });
  }

  /** 清空所有前缀 */
  clearAll() {
    Object.keys(localStorage).forEach(k => {
      if (k.startsWith(this.#prefix)) localStorage.removeItem(k);
    });
  }

  /** 获取所有 key */
  keys() {
    return Object.keys(localStorage).filter(k => k.startsWith(this.#prefix) && !k.endsWith('_access')).map(k => k.slice(this.#prefix.length));
  }
}

export const Storage = new StorageManager();