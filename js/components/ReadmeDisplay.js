/**
 * ReadmeDisplay - 显示 README 内容组件
 */
export class ReadmeDisplay {
  constructor() {
    this.currentPath = '';
    this.readmeEl = null;
  }

  /** 检查并加载 README，返回 DOM 元素或 null */
  async loadReadme(folderPath) {
    if (this.currentPath === folderPath) return this.readmeEl;
    this.currentPath = folderPath;
    this.readmeEl = null;

    try {
      const res = await fetch(`get_readme.php?path=${encodeURIComponent(folderPath)}`);
      const data = await res.json();

      // 竞态保护：等待期间可能已切换到别的文件夹，丢弃过期结果
      if (this.currentPath !== folderPath) return null;

      if (data.hasReadme) {
        this.readmeEl = this.createReadmeElement(data.content, data.type);
        return this.readmeEl;
      } else {
        return null;
      }
    } catch (e) {
      console.warn('[ReadmeDisplay] 加载失败:', e);
      return null;
    }
  }

  /** 创建 README DOM 元素（包含外层包装器以支持内部滚动） */
  createReadmeElement(content, type) {
    // 外层包装器 - 负责定位、内边距和整体样式
    const wrapper = document.createElement('div');
    wrapper.className = 'readme-card';
    wrapper.style.cssText = `
      width: 100%;
      box-sizing: border-box;
      padding: 12px;  /* 外层内边距 */
    `;

    // 内容区 - 支持内部滚动
    const contentEl = document.createElement('div');
    contentEl.className = 'readme-card-content';
    contentEl.style.cssText = `
      width: 100%;
      box-sizing: border-box;
      background: var(--bg-tertiary);
      border-radius: var(--radius-md);
      max-height: 60vh;
      overflow-y: auto;
    `;

    if (type === 'md') {
      contentEl.innerHTML = this.renderMarkdown(content);
    } else {
      contentEl.innerHTML = `<pre style="white-space: pre-wrap; word-wrap: break-word; font-family: inherit; margin: 0;">${this.escapeHtml(content)}</pre>`;
    }

    wrapper.appendChild(contentEl);
    return wrapper;
  }

  /** 简单的 Markdown 渲染 */
  renderMarkdown(md) {
    return md
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/^### (.*$)/gm, '<h3 style="margin: 0; font-size: 1.15em;">$1</h3>')
      .replace(/^## (.*$)/gm, '<h2 style="margin: 0; font-size: 1.35em;">$1</h2>')
      .replace(/^# (.*$)/gm, '<h1 style="margin: 0; font-size: 1.6em;">$1</h1>')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre style="background: var(--bg-primary); padding: 16px; border-radius: 6px; overflow-x: auto; margin: 14px 0;"><code>$2</code></pre>')
      .replace(/`(.+?)`/g, '<code style="background: var(--bg-primary); padding: 3px 6px; border-radius: 4px; font-family: monospace;">$1</code>')
      .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank" style="color: var(--accent);">$1</a>')
      .replace(/!\[(.+?)\]\((.+?)\)/g, '<img src="$2" alt="$1" style="max-width: 100%; height: auto; border-radius: 6px; margin: 12px 0;">')
      .replace(/^---$/gm, '<hr style="border: none; border-top: 1px solid var(--border-color); margin: 20px 0;">')
      .replace(/^\- (.*$)/gm, '<li style="margin: 6px 0;">$1</li>')
      .replace(/(<li.*<\/li>)/s, '<ul style="margin: 12px 0; padding-left: 24px;">$1</ul>')
      .replace(/^([^<].*[^>])$/gm, '<p style="margin: 10px 0; line-height: 1.7;">$1</p>');
  }

  escapeHtml(str) {
    return str.replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>');
  }
}