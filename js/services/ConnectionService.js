/**
 * ConnectionService - 本地 ADOFAI 服务连接管理
 */
import { eventBus, EVENTS } from '../core/EventBus.js';
import { appStore } from '../core/Store.js';
import { api } from './ApiService.js';
import { Storage } from '../core/Storage.js';
import { STORAGE_KEYS } from '../core/Config.js';

const HEALTH_INTERVAL = 5000;
let healthTimer = null;

export const connection = {
  /** 连接服务 */
  async connect(port) {
    const p = parseInt(port);
    if (isNaN(p) || p < 1 || p > 65535) {
      eventBus.emit(EVENTS.TOAST_SHOW, { message: '请输入有效端口 (1-65535)', type: 'error' });
      return;
    }

    appStore.set('isConnecting', true);
    this.updateUI('connecting', '检查中...');

    try {
      const res = await api.healthCheck(p);
      if (res.connected) {
        appStore.setMultiple({
          isConnected: true,
          connectionInfo: { ...res, port: p },
          port: p,
          isConnecting: false,
        });
        Storage.set(STORAGE_KEYS.LAST_PORT, p);
        eventBus.emit(EVENTS.TOAST_SHOW, { message: `✅ 连接成功 v${res.modVersion || '?'}`, type: 'success' });
        eventBus.emit(EVENTS.CONNECTION_CHANGED, true);
        this.startHealthCheck(p);
        this.updateUI('online', `已连接 v${res.modVersion || '?'}`);
      } else {
        throw new Error('服务返回未连接');
      }
    } catch (e) {
      appStore.set('isConnecting', false);
      eventBus.emit(EVENTS.TOAST_SHOW, { message: `❌ 连接失败: ${e.message}`, type: 'error' });
      this.updateUI('offline', '离线');
      eventBus.emit(EVENTS.CONNECTION_CHANGED, false);
    }
  },

  /** 断开连接 */
  disconnect() {
    this.stopHealthCheck();
    appStore.setMultiple({ isConnected: false, connectionInfo: null, isConnecting: false });
    eventBus.emit(EVENTS.CONNECTION_CHANGED, false);
    eventBus.emit(EVENTS.TOAST_SHOW, { message: '🔌 已断开', type: 'info' });
  },

  /** 开始健康检查 */
  startHealthCheck(port) {
    this.stopHealthCheck();
    this.check(port);
    healthTimer = setInterval(() => this.check(port), HEALTH_INTERVAL);
  },

  /** 单次检查 */
  async check(port) {
    if (!appStore.get('isConnected')) return;
    try {
      const res = await api.healthCheck(port);
      appStore.set('connectionInfo', { ...res, port });
      if (!res.connected) this.autoDisconnect('服务端报告未连接');
    } catch (e) {
      if (appStore.get('isConnected')) this.autoDisconnect(e.message);
    }
  },

  /** 自动断开 */
  autoDisconnect(reason) {
    console.warn('[AutoDisconnect]', reason);
    this.disconnect();
    eventBus.emit(EVENTS.TOAST_SHOW, { message: `⚠️ ${reason}`, type: 'error' });
  },

  stopHealthCheck() {
    if (healthTimer) { clearInterval(healthTimer); healthTimer = null; }
  },

  getLastPort() { return Storage.get(STORAGE_KEYS.LAST_PORT) || 8080; },

  updateUI(state, text) {
    const dot = document.getElementById('statusDot');
    const txt = document.getElementById('statusText');
    const btn = document.getElementById('connectBtn');
    const port = document.getElementById('portInput');
    dot.className = 'status-dot ' + (state === 'connecting' ? 'checking' : state);
    txt.textContent = text;
    if (state === 'connecting') { btn.disabled = true; port.disabled = true; }
    else { btn.disabled = false; port.disabled = false; }
  },
};