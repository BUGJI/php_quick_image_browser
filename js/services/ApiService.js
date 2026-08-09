/**
 * ApiService - 统一后端 API 调用
 */
import { CONFIG, getThumbnailUrl, getOriginalUrl, getInsertUrl } from '../core/Config.js';
import { Storage } from '../core/Storage.js';
import { STORAGE_KEYS, CACHE_TTL } from '../core/Config.js';

const MAX_CACHE_SIZE = 500 * 1024; // 500KB 单项缓存限制（树精简后可装入）

function estimateSize(obj) {
  return JSON.stringify(obj).length;
}

export class ApiService {
  /** 通用 GET */
  async #fetch(url, options = {}) {
    const res = await fetch(url, { 
      ...options, 
      headers: { 'Accept': 'application/json', ...options.headers } 
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  /** 获取目录树 */
  async getTree(force = false) {
    if (!force) {
      const cached = Storage.get(STORAGE_KEYS.FOLDER_TREE);
      if (cached?.length) return cached;
    }
    const data = await this.#fetch(CONFIG.API_BASE + CONFIG.ENDPOINTS.TREE + (force ? '&refresh=true' : ''));
    if (data.success && data.tree) {
      const size = estimateSize(data.tree);
      if (size < MAX_CACHE_SIZE) {
        Storage.set(STORAGE_KEYS.FOLDER_TREE, data.tree, CACHE_TTL.TREE);
      } else {
        console.warn('[ApiService] Tree too large to cache:', size, 'bytes');
      }
      // 记录目录树缓存更新时间（后端缓存文件生成时间，秒级）
      const ts = data.timestamp || Math.floor(Date.now() / 1000);
      Storage.set(STORAGE_KEYS.CACHE_UPDATED_AT, ts, CACHE_TTL.TREE);
      return data.tree;
    }
    throw new Error('获取目录树失败: ' + (data.error || '未知错误'));
  }

  /** 获取文件夹图片 */
  async getImages(folderPath, signal) {
    const cacheKey = STORAGE_KEYS.FOLDER_IMAGES_PREFIX + folderPath;
    const cached = Storage.get(cacheKey);
    if (cached) return cached;

    const url = `${CONFIG.API_BASE}${CONFIG.ENDPOINTS.IMAGES}&path=${encodeURIComponent(folderPath)}`;
    const data = await this.#fetch(url, { signal });
    if (data.success && data.images) {
      const size = estimateSize(data.images);
      if (size < MAX_CACHE_SIZE) {
        Storage.set(cacheKey, data.images, CACHE_TTL.IMAGES);
      } else {
        console.warn('[ApiService] Images too large to cache:', size, 'bytes');
      }
      return data.images;
    }
    throw new Error('加载图片失败: ' + (data.error || '未知错误'));
  }

  /** 搜索图片（useAi=true 走 AI 语义搜索，否则文件名搜索） */
  async search(keyword, useAi = false) {
    const url = `${CONFIG.API_BASE}${CONFIG.ENDPOINTS.SEARCH}&keyword=${encodeURIComponent(keyword)}${useAi ? '&ai=1' : ''}`;
    const data = await this.#fetch(url);
    if (!data.success) throw new Error(data.error || '搜索失败');
    return data.images;
  }

  /** 健康检查 */
  async healthCheck(port) {
    const res = await fetch(`http://127.0.0.1:${port}/health`, {
      signal: AbortSignal.timeout(3000),
    });
    return res.ok ? res.json() : { connected: false };
  }

  /** 插入装饰 */
  async insertDecoration(payload, port) {
    const res = await fetch(getInsertUrl(port), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  /** 缩略图 URL */
  getThumbnailUrl(path) { return getThumbnailUrl(path); }

  /** 原图 URL */
  getOriginalUrl(path) { return getOriginalUrl(path); }
}

/** 单例 */
export const api = new ApiService();