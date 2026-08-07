/**
 * MasonryGrid - 瀑布流图片网格
 */
import { eventBus, EVENTS } from '../core/EventBus.js';
import { appStore } from '../core/Store.js';
import { api } from '../services/ApiService.js';
import { debounce, getColumns, formatSize, getOriginalName, escapeHtml, generateId } from '../utils/helpers.js';
import { ReadmeDisplay } from './ReadmeDisplay.js';

export class MasonryGrid {
  #vpPadding = 12;

  constructor() {
    this.$scroll = document.getElementById('masonryScroll');
    this.$spacer = document.getElementById('masonrySpacer');
    this.$viewport = document.getElementById('masonryViewport');
    this.$count = document.getElementById('imageCount');

    this.layout = [];
    this.rendered = new Map();
    this.actualHeights = new Map();
    this.pendingUpdates = new Set();
    this.pathToItem = new Map(); // path -> layout item 索引，避免 O(n) 查找
    this.updateTimer = null;
    this.raf = false;

    this.readmeDisplay = new ReadmeDisplay();
    this.readmeEl = null;
    this.searchMode = false;
    this.bindEvents();
  }

  bindEvents() {
    appStore.subscribe('filteredImages', (imgs) => {
      console.log('[MasonryGrid] filteredImages changed:', imgs?.length || 0);
      this.clear();
      this.actualHeights.clear();
      this.reload(imgs);
    });

    appStore.subscribe('zoom', () => this.recalc());

    this.$scroll.addEventListener('scroll', () => this.scheduleRender());

    window.addEventListener('resize', debounce(() => {
      if (appStore.get('filteredImages').length) this.recalc();
    }, 200));

    eventBus.on(EVENTS.QUICK_INSERT, (img) => this.quickInsert(img));
  }

  /** 加载文件夹对应的 README */
  async loadReadmeForFolder(folderPath) {
    // 同步清理旧 README：立即把它从布局中移除，避免滞留到新文件夹
    if (this.readmeEl) {
      this.readmeEl = null;
      if (this.layout.some(it => it.isReadme)) this.recalc();
    }
    if (!folderPath) return;

    const el = await this.readmeDisplay.loadReadme(folderPath);
    // 竞态保护：等待期间可能已切换到别的文件夹，丢弃过期结果
    if (this.readmeDisplay.currentPath !== folderPath) return;
    this.readmeEl = el;
    if (this.readmeEl) this.recalc();
  }

  reload(images) {
    if (!images.length) {
      this.$viewport.innerHTML = '<div class="status-msg">📭 空文件夹</div>';
      this.$count.textContent = '0 张';
      this.$spacer.style.height = '0px';
      return;
    }
    this.$count.textContent = `${images.length} 张`;
    this.$viewport.innerHTML = '';
    this.recalc();
  }

  /** 兼容旧调用 */
  setImages(images) { this.reload(images); }

  /** 设置搜索模式：搜索时隐藏 README 卡片，退出时恢复 */
  setSearchMode(on) {
    if (this.searchMode === on) return;
    this.searchMode = on;
    if (appStore.get('filteredImages').length) this.recalc();
  }

  /** 显示/隐藏加载状态 */
  setLoading(loading) {
    if (loading) {
      this.$viewport.innerHTML = '<div class="loading-overlay"><div class="spinner"></div><div>加载中...</div></div>';
    }
  }

  recalc() {
    const images = appStore.get('filteredImages');
    if (!images.length) { 
      this.layout = []; 
      this.pathToItem.clear();
      appStore.set('layout', []); 
      this.$spacer.style.height = '0px'; 
      this.clear(); 
      return; 
    }

    const cols = getColumns(this.$scroll.clientWidth, appStore.get('zoom'));
    const cw = this.$scroll.clientWidth;
    const gap = 8;
    const contentTopPadding = 10; // 内容顶部间距
    const cardW = (cw - this.#vpPadding * 2 - gap * (cols - 1)) / cols;

    const colHeights = new Array(cols).fill(contentTopPadding);
    const newLayout = [];

    // 如果有 README，先放第一个位置（跨列），固定高度 200px；搜索模式下不显示
    let startIndex = 0;
    const README_HEIGHT = 200;
    if (this.readmeEl && !this.searchMode) {
      newLayout.push({
        index: -1,
        col: 0,
        top: contentTopPadding,
        left: this.#vpPadding,
        w: cw - this.#vpPadding * 2,
        h: README_HEIGHT,
        img: null,
        isReadme: true
      });
      // README 占据所有列
      for (let c = 0; c < cols; c++) colHeights[c] = README_HEIGHT + gap + contentTopPadding;
      startIndex = 0;
    }

    for (let i = startIndex; i < images.length; i++) {
      const img = images[i];
      const cardH = this.actualHeights.get(img.path) ||
        (img.width && img.height ? cardW / (img.width / img.height) : cardW * 0.75);

      let minCol = 0;
      for (let c = 1; c < cols; c++) if (colHeights[c] < colHeights[minCol]) minCol = c;

      newLayout.push({
        index: i,
        col: minCol,
        top: colHeights[minCol],
        left: this.#vpPadding + minCol * (cardW + gap),
        w: cardW,
        h: cardH,
        img
      });

      colHeights[minCol] += cardH + gap;
    }

    this.layout = newLayout;
    // 重建 path -> item 索引
    this.pathToItem.clear();
    for (const it of newLayout) {
      if (it.img) this.pathToItem.set(it.img.path, it);
    }
    appStore.set('layout', newLayout);
    this.$spacer.style.height = Math.max(...colHeights, 0) + 'px';
    this.clear();
    this.renderVisible();
  }

  clear() {
    this.rendered.forEach(el => el.remove());
    this.rendered.clear();
    this.$viewport.innerHTML = '';
    // 重新插入 README（如果有）
    if (this.readmeEl) {
      this.$viewport.appendChild(this.readmeEl);
    }
  }

  renderVisible() {
    if (!this.layout.length) return;

    const st = this.$scroll.scrollTop;
    const vh = this.$scroll.clientHeight;
    const buf = vh * 2;
    const vStart = st - buf, vEnd = st + vh + buf;

    const need = new Set();
    for (let i = 0; i < this.layout.length; i++) {
      const it = this.layout[i];
      if (it.top + it.h >= vStart && it.top <= vEnd) need.add(i);
    }

    const relBuf = vh * 3;
    const rStart = st - relBuf, rEnd = st + vh + relBuf;
    const toDel = [];
    for (const [idx, el] of this.rendered) {
      const it = this.layout[idx];
      if (it && (it.top + it.h < rStart || it.top > rEnd)) toDel.push([idx, el]);
    }
    toDel.forEach(([idx, el]) => { el.remove(); this.rendered.delete(idx); });

    const frag = document.createDocumentFragment();
    for (const i of need) {
      if (!this.rendered.has(i)) {
        const card = this.createCard(this.layout[i], i);
        frag.appendChild(card);
        this.rendered.set(i, card);
      }
    }
    if (frag.children.length) this.$viewport.appendChild(frag);
  }

  createCard(item, idx) {
    const { left, top, w, h, img, isReadme } = item;
    
    // README 卡片
    if (isReadme) {
      const card = this.readmeEl;
      card.style.cssText = `left:${left}px;top:${top}px;width:${w}px;height:${h}px;`;
      card.className = 'readme-card';
      return card;
    }

    // 普通图片卡片
    const card = document.createElement('div');
    card.className = 'pic-card';
    card.style.cssText = `left:${left}px;top:${top}px;width:${w}px;height:${h}px;`;

    card.onclick = () => {
      console.log('[MasonryGrid] Card clicked, quickImportEnabled:', appStore.get('quickImportEnabled'), 'isConnected:', appStore.get('isConnected'));
      if (appStore.get('quickImportEnabled') && appStore.get('isConnected')) {
        eventBus.emit(EVENTS.QUICK_INSERT, img);
      } else {
        eventBus.emit(EVENTS.LIGHTBOX_OPEN, idx);
      }
    };

    const skel = document.createElement('div');
    skel.className = 'pic-skeleton';
    card.appendChild(skel);

    const imgEl = document.createElement('img');
    imgEl.loading = 'lazy';
    imgEl.src = api.getThumbnailUrl(img.path);
    imgEl.onload = () => {
      skel.style.display = 'none';
      imgEl.classList.add('loaded');
      const nw = imgEl.naturalWidth, nh = imgEl.naturalHeight;
      if (nw && nh) {
        const dh = (nh / nw) * w;
        if (dh > 0 && Math.abs(dh - h) > 5) this.scheduleHeightUpdate(img.path, dh);
      }
    };
    imgEl.onerror = () => { skel.style.display = 'none'; imgEl.classList.add('loaded'); };
    card.appendChild(imgEl);

    const ov = document.createElement('div');
    ov.className = 'pic-overlay';
    ov.innerHTML = `<div class="pic-name">${escapeHtml(img.name)}</div><div class="pic-meta">${img.format || ''} ${img.sizeFormatted || ''}</div>`;
    card.appendChild(ov);

    return card;
  }

  scheduleHeightUpdate(path, h) {
    if (this.actualHeights.get(path) === h) return;
    this.actualHeights.set(path, h);
    this.pendingUpdates.add(path);
    if (this.updateTimer) clearTimeout(this.updateTimer);
    if (this.pendingUpdates.size >= 10) this.flushHeightUpdates();
    else this.updateTimer = setTimeout(() => this.flushHeightUpdates(), 100);
  }

  flushHeightUpdates() {
    if (this.updateTimer) clearTimeout(this.updateTimer);
    if (!this.pendingUpdates.size) return;
    let changed = false;
    for (const p of this.pendingUpdates) {
      const item = this.pathToItem.get(p);
      if (item && Math.abs(this.actualHeights.get(p) - item.h) > 5) { changed = true; break; }
    }
    if (changed) {
      const st = this.$scroll.scrollTop;
      this.recalc();
      requestAnimationFrame(() => this.$scroll.scrollTop = st);
    }
    this.pendingUpdates.clear();
  }

  async quickInsert(img) {
    const payload = {
      id: generateId(),
      imageUrl: api.getOriginalUrl(img.path),
      fileName: img.originalName || img.name,
      relativeTo: 'Tile', floor: 0
    };
    try {
      await api.insertDecoration(payload, appStore.get('port'));
      eventBus.emit(EVENTS.TOAST_SHOW, { message: `⚡ 已插入 "${img.name}"`, type: 'success' });
    } catch (e) {
      eventBus.emit(EVENTS.TOAST_SHOW, { message: `❌ 插入失败: ${e.message}`, type: 'error' });
    }
  }

  scheduleRender() {
    if (!this.raf) {
      this.raf = true;
      requestAnimationFrame(() => { this.raf = false; this.renderVisible(); });
    }
  }
}