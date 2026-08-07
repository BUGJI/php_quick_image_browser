/**
 * Sidebar - 文件夹树组件
 */
import { eventBus, EVENTS } from '../core/EventBus.js';
import { appStore } from '../core/Store.js';
import { Storage } from '../core/Storage.js';
import { STORAGE_KEYS, CACHE_TTL } from '../core/Config.js';
import { formatSize } from '../utils/helpers.js';

export class Sidebar {
  constructor() {
    this.$tree = document.getElementById('folderTree');
    this.$filter = document.getElementById('folderFilter');
    this.bindEvents();
    
    // 监听折叠状态变化
    appStore.subscribe('collapsedFolders', (set) => this.updateCollapsed(set));
  }

  bindEvents() {
    eventBus.on(EVENTS.FOLDER_TREE_LOADED, (tree) => this.render(tree));
  }

/** 渲染目录树 */
  render(tree) {
    if (!tree?.length) {
      this.$tree.innerHTML = '<div class="status-msg">📭 暂无文件夹</div>';
      return;
    }
    this.$tree.innerHTML = '';
    tree.forEach(node => { if (node.type === 'folder') this.$tree.appendChild(this.renderNode(node)); });
    // 初始渲染后应用折叠状态
    this.updateCollapsed(appStore.get('collapsedFolders'));
  }

  renderNode(node, level = 0) {
    const path = node.path;
    const hasChildren = node.children?.length > 0;
    const isCollapsed = appStore.get('collapsedFolders').has(path);

    const wrapper = document.createElement('div');
    wrapper.className = 'folder-wrapper';

    const item = document.createElement('div');
    item.className = 'folder-item';
    item.style.paddingLeft = `${8 + level * 16}px`;
    item.dataset.path = path;
    item.dataset.name = node.name;

    if (hasChildren) {
      const btn = document.createElement('span');
      btn.className = 'folder-toggle-btn';
      btn.dataset.path = path;
      btn.textContent = isCollapsed ? '▶' : '▼';
      btn.onclick = e => { e.stopPropagation(); this.toggle(path); };
      item.appendChild(btn);
    } else {
      const sp = document.createElement('span');
      sp.style.width = '20px';
      sp.style.display = 'inline-block';
      item.appendChild(sp);
    }

    const icon = document.createElement('span');
    icon.className = 'folder-icon';
    icon.textContent = hasChildren ? '📁' : '📂';
    item.appendChild(icon);

    const name = document.createElement('span');
    name.className = 'folder-name';
    name.textContent = node.name;
    item.appendChild(name);

    const stats = document.createElement('span');
    stats.className = 'folder-stats';
    stats.textContent = node.imageCount || 0;
    item.appendChild(stats);

    item.onclick = () => eventBus.emit(EVENTS.FOLDER_SELECTED, { path, name: node.name });

    wrapper.appendChild(item);

    if (hasChildren) {
      const childrenDiv = document.createElement('div');
      childrenDiv.className = `folder-children ${isCollapsed ? 'collapsed' : ''}`;
      childrenDiv.dataset.parent = path;
      node.children.forEach(ch => {
        if (ch.type === 'folder') {
          const childEl = this.renderNode(ch, level + 1);
          if (childEl) childrenDiv.appendChild(childEl);
        }
      });
      wrapper.appendChild(childrenDiv);
    }

    return wrapper;
  }

/** 切换展开/折叠 */
  toggle(path) {
    const set = new Set(appStore.get('collapsedFolders'));
    set.has(path) ? set.delete(path) : set.add(path);
    appStore.set('collapsedFolders', set);
  }

  /** 更新折叠状态 */
  updateCollapsed(set) {
    this.$tree.querySelectorAll('.folder-children').forEach(el => {
      const p = el.dataset.parent;
      const shouldCollapse = set.has(p);
      el.classList.toggle('collapsed', shouldCollapse);
      const btn = this.$tree.querySelector(`.folder-toggle-btn[data-path="${p}"]`);
      if (btn) btn.textContent = shouldCollapse ? '▶' : '▼';
    });
  }

  /** 更新选中高亮 */
  updateActive(path) {
    this.$tree.querySelectorAll('.folder-item').forEach(el => {
      el.classList.toggle('active', el.dataset.path === path);
    });
  }

  /** 筛选 */
  filter() {
    const q = this.$filter.value.toLowerCase();
    if (!q) {
      // 清空筛选：恢复全部显示并按持久化折叠状态
      this.$tree.querySelectorAll('.folder-wrapper').forEach(w => { w.style.display = ''; });
      this.updateCollapsed(appStore.get('collapsedFolders'));
      return;
    }
    const wrappers = this.$tree.querySelectorAll('.folder-wrapper');
    // 先找出命中的节点（自身名字包含关键词）
    const matched = new Set();
    wrappers.forEach(w => {
      const n = w.querySelector('.folder-item')?.dataset.name?.toLowerCase() || '';
      if (n.includes(q)) matched.add(w);
    });
    // 命中的节点要保留其所有祖先（显示 + 展开），这样深层文件夹能搜到
    const visible = new Set(matched);
    matched.forEach(w => {
      let p = w.parentElement;
      while (p && p.classList.contains('folder-wrapper')) {
        visible.add(p);
        p.classList.remove('collapsed'); // 临时展开祖先
        const childrenDiv = p.querySelector(':scope > .folder-children');
        if (childrenDiv) childrenDiv.classList.remove('collapsed');
        p = p.parentElement;
      }
    });
    wrappers.forEach(w => {
      w.style.display = visible.has(w) ? '' : 'none';
    });
  }
}