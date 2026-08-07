/**
 * Lightbox - 大图预览灯箱
 */
import { eventBus, EVENTS } from '../core/EventBus.js';
import { appStore } from '../core/Store.js';
import { api } from '../services/ApiService.js';
import { imageService } from '../services/ImageService.js';
import { generateId, escapeHtml, formatSize, getOriginalName } from '../utils/helpers.js';

export class Lightbox {
  constructor() {
    this.$el = document.getElementById('lightbox');
    this.$img = document.getElementById('lightboxImg');
    this.$container = document.getElementById('lightboxContainer');
    this.$info = document.getElementById('lightboxInfo');
    this.$badge = document.getElementById('lightboxBadge');
    this.$loading = document.getElementById('lightboxLoadingToast');
    this.$insertBtn = document.getElementById('insertBtn');
    this.$hint = document.getElementById('zoomHint');

    this.scale = 1;
    this.tx = 0;
    this.ty = 0;
    this.panning = false;
    this.sx = 0;
    this.sy = 0;
    this.openSeq = 0;

    this.bindEvents();
  }

  bindEvents() {
    eventBus.on(EVENTS.LIGHTBOX_OPEN, (idx) => {
      console.log('[Lightbox] LIGHTBOX_OPEN event received, idx:', idx);
      this.open(idx);
    });
    eventBus.on(EVENTS.LIGHTBOX_CLOSE, () => this.close());
    eventBus.on(EVENTS.LIGHTBOX_NAV, (dir) => this.nav(dir));

    this.$insertBtn.onclick = () => this.insert();

    this.$container.addEventListener('wheel', (e) => this.onWheel(e), { passive: false });
    this.$container.addEventListener('mousedown', (e) => this.onPanStart(e));
    window.addEventListener('mousemove', (e) => this.onPanMove(e));
    window.addEventListener('mouseup', () => this.onPanEnd());
    this.$img.addEventListener('dblclick', () => this.resetTransform());

    this.$el.addEventListener('click', (e) => { if (e.target === this.$el) this.close(); });

    document.addEventListener('keydown', (e) => {
      if (!this.$el.classList.contains('active')) return;
      if (e.key === 'Escape') this.close();
      if (e.key === 'ArrowLeft') this.nav(-1);
      if (e.key === 'ArrowRight') this.nav(1);
    });
  }

  async open(idx) {
    const layout = appStore.get('layout');
    const item = layout[idx];
    if (!item) return;

    const seq = ++this.openSeq; // 本次打开序号,用于丢弃过期响应
    appStore.setMultiple({ lightboxActive: true, lightboxIndex: idx, lightboxImage: item.img });
    this.$el.classList.add('active');
    this.resetTransform();

    const img = item.img;
    this.$loading.style.display = 'flex';
    this.$badge.textContent = '📷 加载原图中...';
    this.$img.src = api.getThumbnailUrl(img.path);
    this.$info.textContent = `${img.name} ${img.format || ''} ${img.sizeFormatted || ''} | 加载原图...`;

    try {
      const original = await imageService.preloadOriginal(img.path);
      if (seq !== this.openSeq) return; // 已切换到其他图片,丢弃过期结果
      if (original && original.src) {
        this.$img.src = original.src;
        this.$info.textContent = `${img.originalName || img.name} ${img.format || ''} ${img.sizeFormatted || ''} | ✓ 原图`;
        this.$badge.textContent = '✨ 原图模式';
      } else throw new Error('原图加载失败');
    } catch {
      if (seq !== this.openSeq) return;
      this.$info.textContent = `${img.name} | 原图失败，显示缩略图`;
      this.$badge.textContent = '⚠️ 缩略图模式';
    } finally {
      if (seq === this.openSeq) this.$loading.style.display = 'none';
    }

    // 预加载相邻
    if (idx > 0) imageService.preloadOriginal(layout[idx - 1].img.path).catch(() => {});
    if (idx < layout.length - 1) imageService.preloadOriginal(layout[idx + 1].img.path).catch(() => {});
  }

  close() {
    this.$el.classList.remove('active');
    this.$img.src = '';
    this.resetTransform();
    appStore.setMultiple({ lightboxActive: false, lightboxIndex: -1, lightboxImage: null });
  }

  nav(dir) {
    const layout = appStore.get('layout');
    const cur = appStore.get('lightboxIndex');
    const next = cur + dir;
    if (next >= 0 && next < layout.length) this.open(next);
  }

  applyTransform() {
    this.$img.style.transform = `translate(${this.tx}px, ${this.ty}px) scale(${this.scale})`;
  }

  onWheel(e) {
    e.preventDefault();
    const delta = e.deltaY > 0 ? -0.1 : 0.1;
    const ns = Math.min(4, Math.max(0.5, this.scale + delta));
    if (ns !== this.scale) {
      this.scale = ns;
      if (this.scale <= 1.01) { this.tx = 0; this.ty = 0; }
      this.applyTransform();
      this.showHint();
    }
  }

  onPanStart(e) {
    if (this.scale <= 1) return;
    this.panning = true;
    this.sx = e.clientX;
    this.sy = e.clientY;
    this.$container.style.cursor = 'grabbing';
  }

  onPanMove(e) {
    if (!this.panning) return;
    this.tx += e.clientX - this.sx;
    this.ty += e.clientY - this.sy;
    this.sx = e.clientX;
    this.sy = e.clientY;
    this.applyTransform();
  }

  onPanEnd() {
    this.panning = false;
    this.$container.style.cursor = 'grab';
  }

  resetTransform() {
    this.scale = 1;
    this.tx = 0;
    this.ty = 0;
    this.applyTransform();
  }

  showHint() {
    this.$hint.style.opacity = '1';
    clearTimeout(this.hintTimer);
    this.hintTimer = setTimeout(() => this.$hint.style.opacity = '0', 800);
  }

  async insert() {
    const img = appStore.get('lightboxImage');
    if (!img || !appStore.get('isConnected')) {
      eventBus.emit(EVENTS.TOAST_SHOW, { message: '未连接服务', type: 'error' });
      return;
    }

    const payload = {
      id: generateId(),
      imageUrl: api.getOriginalUrl(img.path),
      fileName: img.originalName || img.name,
      relativeTo: 'Tile', floor: 0
    };

    this.$insertBtn.disabled = true;
    this.$insertBtn.innerHTML = '<span class="icon">⏳</span> 插入中...';

    try {
      await api.insertDecoration(payload, appStore.get('port'));
      eventBus.emit(EVENTS.TOAST_SHOW, { message: `✅ 已插入 "${img.name}"`, type: 'success' });
    } catch (e) {
      eventBus.emit(EVENTS.TOAST_SHOW, { message: `❌ 插入失败: ${e.message}`, type: 'error' });
    } finally {
      this.$insertBtn.disabled = false;
      this.$insertBtn.innerHTML = '<span class="icon">✨</span> 插入到谱面';
    }
  }
}