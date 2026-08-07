/**
 * Main - 应用入口
 */
import { App } from './app.js';
import { initToast, showToast } from './utils/helpers.js';

document.addEventListener('DOMContentLoaded', () => {
  initToast();
  window.showToast = showToast; // 兼容旧调用
  window.app = new App();
});