/**
 * ImageService - 图片预加载和缓存（LRU，上限 50 张）
 */
import { api } from './ApiService.js';

const originalCache = new Map();
const MAX_CACHE = 50;

export class ImageService {
  /** 预加载原图 */
  async preloadOriginal(webpPath) {
    const url = api.getOriginalUrl(webpPath);
    if (originalCache.has(url)) {
      const cached = originalCache.get(url);
      // 命中即提升为最近使用
      originalCache.delete(url);
      originalCache.set(url, cached);
      return cached instanceof Promise ? cached : Promise.resolve(cached);
    }

    const promise = new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => { originalCache.set(url, img); this.trim(); resolve(img); };
      img.onerror = () => { originalCache.set(url, null); this.trim(); reject(new Error('加载失败')); };
      img.src = url;
    });

    originalCache.set(url, promise);
    this.trim();
    return promise;
  }

  /** LRU 淘汰：超过上限时移除最久未用的项 */
  trim() {
    while (originalCache.size > MAX_CACHE) {
      const oldestKey = originalCache.keys().next().value;
      if (oldestKey === undefined) break;
      originalCache.delete(oldestKey);
    }
  }

  /** 清除原图缓存 */
  clearCache() { originalCache.clear(); }
}

export const imageService = new ImageService();