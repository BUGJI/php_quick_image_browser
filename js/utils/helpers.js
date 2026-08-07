/**
 * 工具函数
 */

/** 防抖 */
export function debounce(fn, wait = 300) {
  let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
}

/** 节流 */
export function throttle(fn, limit = 16) {
  let inThrottle; return (...args) => { if (!inThrottle) { fn(...args); inThrottle = true; setTimeout(() => inThrottle = false, limit); } };
}

/** 格式化文件大小 */
export function formatSize(bytes) {
  if (!bytes) return '0 B';
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
  return bytes + ' B';
}

/** 转义 HTML */
export function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/[&<>]/g, m => ({ '&': '&', '<': '<', '>': '>' }[m]));
}

/** 生成 UUID 简易版 */
export function generateId() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

/** 获取列数 */
export function getColumns(width, zoom) {
  let base = width >= 1800 ? 5 : width >= 1400 ? 4 : width >= 1000 ? 3 : width >= 700 ? 2 : 1;
  return Math.max(1, Math.round(base * zoom));
}

/** 获取原始文件名（去 .webp） */
export function getOriginalName(name) {
  if (!name) return '';
  return name.toLowerCase().endsWith('.webp') ? name.slice(0, -5) : name;
}

/** 格式化时间戳（秒）为可读时间，如 2026-08-07 20:30 */
export function formatDateTime(ts) {
  if (!ts) return '--';
  const d = new Date(ts * 1000);
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** 显示 Toast */
let $toast;
export function initToast() { $toast = document.getElementById('toast'); }
export function showToast(msg, type = 'success') {
  if (!$toast) return;
  $toast.textContent = msg;
  $toast.className = `toast toast-${type} show`;
  setTimeout(() => $toast.classList.remove('show'), 2500);
}