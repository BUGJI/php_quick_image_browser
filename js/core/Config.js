/**
 * Config - 统一配置常量
 */
export const CONFIG = {
  API_BASE: '',
  // 原图服务：改用 PHP WebDAV 代理
  ORIGINAL_IMAGE_ENDPOINT: 'serve_original.php',
  // 站点域名（用于生成原图绝对 URL，留空则自动检测）
  SITE_BASE_URL: '',
  ENDPOINTS: {
    TREE: 'get_images.php?action=getTree',
    IMAGES: 'get_images.php?action=getImages',
    SEARCH: 'get_images.php?action=search',
  },
  HEALTH_CHECK_INTERVAL: 5000,
  // 缓存过期时间：30 天（点击「清除缓存」可随时强制刷新）
  CACHE_TTL: {
    TREE: 30 * 24 * 60 * 60 * 1000,
    IMAGES: 30 * 24 * 60 * 60 * 1000,
  },
  STORAGE_KEY: 'img_browser_',
  PRELOAD_SCREENS: 2,
};

export const STORAGE_KEYS = {
  FOLDER_TREE: 'folder_tree',
  FOLDER_IMAGES_PREFIX: 'folder_images_',
  COLLAPSED_FOLDERS: 'collapsed_folders',
  THEME: 'theme',
  LAST_PORT: 'last_port',
  CACHE_UPDATED_AT: 'cache_updated_at',
  AI_SEARCH: 'ai_search_enabled',
};

export const CACHE_TTL = CONFIG.CACHE_TTL;

/** 缩略图 URL */
export function getThumbnailUrl(path) {
  const encoded = path.split('/').map(encodeURIComponent).join('/');
  return `${CONFIG.API_BASE}serve_image.php?path=${encoded}`;
}

/** 原图 URL - 现在通过 PHP WebDAV 代理，生成绝对 URL */
export function getOriginalUrl(webpPath) {
  let p = webpPath;
  if (p.toLowerCase().endsWith('.webp')) p = p.slice(0, -5);
  const encoded = p.split('/').map(encodeURIComponent).join('/');
  // 使用完整路径（包含当前页面所在目录）
  const base = CONFIG.SITE_BASE_URL || new URL('.', window.location.href).href;
  return `${base}${CONFIG.API_BASE}${CONFIG.ORIGINAL_IMAGE_ENDPOINT}?path=${encoded}`;
}

/** 插入接口 URL */
export function getInsertUrl(port) {
  return `http://127.0.0.1:${port}/insert`;
}