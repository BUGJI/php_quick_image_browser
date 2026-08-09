# PHP 快速图片浏览器

一个基于 **PHP + 原生 JavaScript** 的高性能 Web 图片浏览器,面向**数万张图片**的大型素材库。瀑布流虚拟滚动、递归目录树、全局搜索、灯箱预览、WebDAV 同步压缩,开箱即用。

---

## ✨ 核心功能

### 🖼️ 高性能图片浏览
- **虚拟化瀑布流布局** —— 仅渲染可视区域图片,轻松应对数万张图片
- **懒加载 + 骨架屏** —— 缩略图按需加载,加载中显示占位动画
- **自动高度修正** —— 图片加载完成后自动校正卡片高度,防止布局跳动
- **可调缩放 (0.5x ~ 7x)** —— 滑块实时调整缩略图大小,布局自动重排
- **文件夹 README 预览** —— 自动识别并渲染文件夹下的 `README.md/txt`,支持 Markdown 渲染

### 📁 智能目录树
- **递归文件夹树** —— 递归扫描 `webp_cache` 目录,显示层级结构
- **图片计数** —— 每个文件夹显示包含图片数量(含子文件夹)
- **折叠/展开持久化** —— 折叠状态保存到 `localStorage`,刷新保持
- **文件夹筛选** —— 实时搜索过滤文件夹名称
- **一键刷新/清除缓存** —— 强制重新扫描目录,清理本地缓存

### 🔍 图片搜索
- **全局模糊搜索** —— 按文件名搜索所有文件夹图片
- **实时防抖** —— 300ms 防抖,输入即搜
- **结果计数** —— 显示搜索结果数量
- **🤖 AI 语义搜索** —— 搜索框右侧开关,按**内容含义**搜图(如「夕阳」「猫咪」「red dress」)。需先在管理面板建立向量缓存(见下节)

### 🔍 灯箱预览
- **全屏查看** —— ESC/点击背景/关闭按钮关闭
- **键盘导航** ←/→ 切换,双击重置缩放
- **鼠标滚轮缩放** —— 以鼠标为中心缩放 (0.25x ~ 10x)
- **拖拽平移** —— 按住鼠标拖动查看大图
- **原图加载** —— 点击「原图模式」通过 PHP WebDAV 代理加载高清原图
- **图片信息** —— 显示文件名、尺寸、格式、大小、修改时间

### 🎨 交互体验
- **亮/暗主题切换** —— 一键切换,状态持久化
- **响应式布局** —— 侧边栏移动端抽屉式打开,桌面端固定
- **Toast 提示** —— 操作反馈(成功/错误/信息),自动消失
- **键盘快捷键** —— ESC 关灯箱,←/→ 切图

### 🔗 可选扩展
- **自定义 HTTP 接口联动** —— 通过 HTTP API 连接本地编辑器/工具(如 ADOFAI 谱面编辑器),开启后点击缩略图直接插入素材;灯箱内也可手动插入,支持自定义 ID、楼层、相对对象
- **端口记忆** —— 自动记忆上次连接端口,5 秒心跳检测连接状态

---

## 🔄 WebDAV 同步

将远程 WebDAV 原图同步压缩到本地 `webp_cache`(命名 `xxx.原扩展.webp`,与浏览器读取一致)。

### Admin 面板左侧:远程目录树
- 懒加载展示远程 WebDAV 目录结构(展开某层时才 PROPFIND 该层)
- 勾选目录 → 加入**路径黑名单**(存 `.sync_config.json` 的 `blacklist_dirs`),同步时跳过该完整路径
- 与文本框的**名称黑名单**(任意层级同名,如 `.seekMeta`)叠加生效

### 增量 / 全量
| 模式 | 行为 | 使用场景 |
|------|------|----------|
| **增量** | 只拉取新增/变化文件(manifest 比对 mtime) | 日常增量更新 |
| **全量** | 忽略 manifest,重新下载压缩全部 | 首次部署 / 想重压全部 |

> 受服务器禁用后台进程限制,Web 面板同步采用前端分批轮询,**转换期间请勿关闭页面**;中途关闭可重开页面点「增量同步」断点续传。

### 压缩策略
- 图片(png/jpg/jpeg/webp):下载 → 等比缩放(宽 > max_width 时)→ 压缩 webp → 命名 `xxx.原扩展.webp`
- gif:直接复制保留动画(转 webp 会丢动画)
- md/txt 等:原样复制

---

## 🤖 AI 语义搜索

按图片**内容**搜索(而非文件名)。依赖 fnOS AI 管理器(飞牛相册同款 CLIP 模型)的 HTTP 服务,
图文映射到同一 1024 维向量空间,搜索时把查询词向量化后做余弦相似度排序。

### 原理(模式 1:NAS 本地路径,最经济)
```
webp_cache 里的 xxx.png.webp
  → 去掉 .webp 后缀得到原图相对路径 xxx.png
  → 拼接 AI_IMAGE_ROOT(/volX/1000/Resources/share/图片素材)
  → 传给向量服务 img2vec(image_path) 直接读 NAS 本地文件,零传输成本
```

### 使用流程
1. `.env` 配置 AI_* 项(见 `.env.example`),打开 `AI_SEARCH_ENABLED=true`
2. 管理面板 `admin.php` → 「🤖 AI 语义搜索 · 向量缓存」→ 点「▶ 增量建向量」(首次用「⏺ 全量建向量」)
3. 建完后网站搜索框右侧打开 **🤖AI搜索** 开关,输入关键词即可
4. 也可 SSH 执行 `php ai_vector.php scan`(增量)/ `php ai_vector.php scan --full`(全量)

### 向量存储
- `AI_STORAGE=json`:本地 JSON 文件(`.ai_vectors.json`),适合单机/小库/测试
- `AI_STORAGE=mysql`:云端 MySQL(生产)。表 `ai_vectors`(webp_rel 唯一键 + LONGTEXT 向量),首次运行自动建表

### 三种图片输入模式(`AI_IMAGE_MODE`)
| 模式 | 说明 | 适用 |
|------|------|------|
| `path` | 传 NAS 本地原图绝对路径(推荐) | 向量服务与图片同机/NAS 环境 |
| `url` | 传图片公网 URL(需服务端开启 image_url 白名单) | 服务端可访问公网 |
| `base64` | 传图片内容 | 最兼容但流量大、慢 |

---

## 🏗️ 技术架构

### 后端
| 文件 | 功能 |
|------|------|
| `get_images.php` | 目录树扫描、图片列表获取、全局搜索、缓存管理 |
| `serve_image.php` | 缩略图代理(读取本地 webp_cache 文件转发) |
| `serve_original.php` | 原图代理(去除 .webp 后缀,转发远程 WebDAV 原始格式,配置在 .env) |
| `get_readme.php` | 文件夹 README 读取(支持 md/txt) |
| `check_environment.php` | 环境检查工具(依赖/扩展/权限),检查完建议删除 |
| `admin.php` | Admin 管理面板(缓存状态 + WebDAV 同步任务 + 远程目录黑名单 + AI 向量缓存) |
| `sync_webdav.php` | WebDAV 同步引擎(远程原图 → 本地 webp_cache,增量/全量) |
| `ai_vector.php` | AI 向量缓存引擎 + 搜索接口(全量/增量建向量,CLI/Web 双模式) |
| `ai_vector_lib.php` | AI 公共库(配置/双存储/路径反推/向量服务调用/余弦搜索) |
| `env.php` | 轻量 .env 加载器 |
| `.env` / `.env.example` | 环境配置(WebDAV 凭据、ADMIN_TOKEN),复制 example 为 .env 填写 |
| `.htaccess` | 拦截 .env、缓存文件的直接访问(Apache 环境) |
| `.gitignore` | 忽略 .env、运行时缓存等,防止误提交 |

### 前端
```
js/
├── main.js                 # 入口
├── app.js                  # App 主控制器
├── core/
│   ├── Config.js           # 配置常量
│   ├── EventBus.js         # 事件总线
│   ├── Store.js            # 状态管理
│   └── Storage.js          # localStorage 封装(TTL/LRU)
├── services/
│   ├── ApiService.js       # 统一 API 调用
│   ├── ConnectionService.js# 可选:编辑器连接/心跳
│   └── ImageService.js     # 原图预加载
├── components/
│   ├── Sidebar.js          # 侧边栏目录树
│   ├── MasonryGrid.js      # 瀑布流网格
│   ├── Lightbox.js         # 灯箱预览
│   └── ReadmeDisplay.js    # README 渲染
└── utils/helpers.js        # 工具函数
```

---

## 🚀 快速开始

### 环境要求
- **PHP 7.4+**(需开启 `curl`、`gd` 扩展;WebDAV 同步需 `simplexml`)
- 现代浏览器(支持 ES Modules、IntersectionObserver、AbortController)

### 部署
```bash
# 1. 复制环境配置并填写
cp .env.example .env
# 2. 编辑 .env:填入 WebDAV 凭据、ADMIN_TOKEN(强随机口令)
# 3. 将项目放入 PHP 服务器目录(如 Apache/Nginx/PHP 内置服务器)
# 4. 确保 webp_cache 目录存在且包含图片(结构见下)
# 5. 启动 PHP 服务
php -S localhost:8080 -t .
# 6. 浏览器打开 http://localhost:8080
# 7.(可选)运行环境检查 http://localhost:8080/check_environment.php,完成后删除该文件
# 8.(可选)管理面板 http://localhost:8080/admin.php(需 .env 中 ADMIN_TOKEN)
```

### 目录结构要求
```
image_browser/
├── webp_cache/              # 图片根目录(必须存在)
│   ├── folder1/
│   │   ├── image1.webp
│   │   ├── image2.png
│   │   └── README.md        # 可选:文件夹说明
│   └── folder2/
│       └── subfolder/
│           └── image3.jpg
├── get_images.php
├── serve_image.php
├── serve_original.php
├── get_readme.php
├── index.html
├── js/
└── css/
```

### 可选:编辑器 HTTP 接口
若启用「快速导入」类联动,编辑器需在本地暴露 HTTP 服务:

| 接口 | 方法 | 说明 |
|------|------|------|
| `/health` | GET | 返回 `{ connected: true, editorReady: true, ... }` |
| `/insert` | POST | 接收 `{ id, imageUrl, fileName, relativeTo, floor }`,插入素材 |

不启用该功能则完全无需配置,浏览器纯看图。

---

## ⌨️ 快捷键

| 按键 | 功能 |
|------|------|
| `ESC` | 关闭灯箱 / 关闭移动端侧边栏 |
| `←` / `→` | 灯箱切换上/下一张 |
| `滚轮` | 灯箱缩放(以鼠标为中心) |
| `双击图片` | 重置灯箱缩放/位置 |

---

## ⚙️ 配置说明

`js/core/Config.js` 中可调整:
```js
export const CONFIG = {
  API_BASE: '',                    // API 基础路径(同源留空)
  ORIGINAL_IMAGE_ENDPOINT: 'serve_original.php',
  ENDPOINTS: {
    TREE: 'get_images.php?action=getTree',
    IMAGES: 'get_images.php?action=getImages',
    SEARCH: 'get_images.php?action=search',
  },
  CACHE_TTL: {
    TREE: 30 * 24 * 60 * 60 * 1000,  // 目录树缓存 30 天
    IMAGES: 30 * 24 * 60 * 60 * 1000, // 图片列表缓存 30 天
  },
};
```

`.env` 关键项:

| 变量 | 说明 |
|------|------|
| `WEBDAV_BASE_URL` | 原图代理的远程 WebDAV 根目录(含空格/中文会自动编码) |
| `WEBDAV_USERNAME` / `WEBDAV_PASSWORD` | WebDAV 凭据 |
| `ADMIN_TOKEN` | Admin 面板管理口令 |
| `SYNC_WHITELIST` / `SYNC_BLACKLIST` | 同步白/黑名单(优先级低于 admin 保存的配置) |
| `SYNC_QUALITY` / `SYNC_MAX_WIDTH` / `SYNC_BATCH_SIZE` | 压缩质量 / 最大宽度 / 每批数量 |
| `AI_SEARCH_ENABLED` | AI 搜索总开关(true/false) |
| `AI_BASE_URL` | 向量服务地址(本机 `http://127.0.0.1:46091`;云端经 FRP 映射后填映射地址) |
| `AI_API_KEY` | 向量服务 API Key(Bearer 鉴权,可选) |
| `AI_IMAGE_MODE` | 图片向量输入模式:`path`(默认,最省)/ `url` / `base64` |
| `AI_IMAGE_ROOT` | NAS 原图根目录绝对路径(path 模式拼接用,如 `/vol4/1000/Resources/share/图片素材`) |
| `AI_DIM` | 向量维度(默认 1024) |
| `AI_IMAGE_EXTS` | 参与向量化的扩展名(默认 `png,jpg,jpeg,webp,gif`) |
| `AI_STORAGE` | 向量存储:`json`(本地文件,测试)/ `mysql`(云端,生产) |
| `AI_VECTOR_FILE` | JSON 模式存储文件(默认 `.ai_vectors.json`) |
| `AI_MYSQL_HOST/PORT/DB/USER/PASS` | MySQL 模式连接信息 |
| `AI_BATCH_SIZE` | 每批向量化图片数(前端轮询,默认 10) |

---

## 📦 缓存机制

| 缓存项 | Key 前缀 | TTL | 存储位置 | 备注 |
|--------|----------|-----|----------|------|
| 目录树 | `folder_tree` | 30 天 | localStorage | 超 500KB 不缓存 |
| 文件夹图片 | `folder_images_{path}` | 30 天 | localStorage | 超 500KB 不缓存 |
| 后端目录树缓存 | `.folder_tree_cache.json` | 30 天 | 服务器文件 | 单次遍历、仅文件夹节点 |
| 后端图片元数据缓存 | `.images_meta_cache.json` | 30 天 | 服务器文件 | 避免重复 getimagesize |
| 折叠状态 | `collapsed_folders` | 永久 | localStorage | Set 序列化(首次默认全折叠) |
| 主题 | `theme` | 永久 | localStorage | 'light'/'dark' |
| 端口 | `last_port` | 永久 | localStorage | 上次连接端口(可选扩展) |
| 缓存更新时间 | `cache_updated_at` | 30 天 | localStorage | 底部「清除缓存」旁显示 |
| 原图预加载 | - | 会话期 | Memory (Map) | LRU 最大 50 张 |

> 存储配额接近时自动清理过期项(`Storage.cleanExpired()`),不做 LRU 淘汰以保护折叠状态。

---

## 🐛 常见问题

**Q: 图片不显示/404?**  
A: 检查 `webp_cache` 目录权限、PHP `serve_image.php` 路径解析、`open_basedir` 限制。

**Q: 缩略图模糊?**  
A: 缩略图由 `serve_image.php` 直接转发原文件(WebDAV),非压缩生成。如需压缩可先跑 WebDAV 同步(admin 面板)生成 webp 缩略图。

**Q: 原图加载失败?**  
A: 确认 `.env` 的 WebDAV 凭据正确;路径含空格/中文时脚本会自动百分号编码,无需手动转义。

**Q: WebDAV 同步偶发失败?**  
A: 同步采用前端分批轮询,单请求需在 300s 内完成;请只保留一个 admin 页面,转换期间勿关闭页面。

**Q: 想换端口/域名?**  
A: 修改 `Config.js` 中 `API_BASE`;跨域需配置 PHP `Access-Control-Allow-Origin`。

---

## 📄 许可证
MIT License — 仅供学习交流。
