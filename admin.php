<?php
/**
 * admin.php - Admin 管理面板
 * 功能：
 *   1. 查看/管理后端缓存（目录树、图片元数据、文件名搜索索引）
 *   2. 强制重建/清空缓存（前端「清除缓存」只清 localStorage，
 *      后端 .images_meta_cache.json 和 .name_index_cache.json 之前没有强制入口）
 *   3. 未来扩展位：WebDAV 同步任务等
 *
 * 鉴权：ADMIN_TOKEN 在 .env 中配置（见 .env.example）
 * 访问：http://你的地址/admin.php
 */

session_start();

require_once __DIR__ . '/env.php';
$env = loadEnv();
$adminToken = $env['ADMIN_TOKEN'] ?? '';
$cacheDir = __DIR__ . '/webp_cache';

// 未配置 token 时直接拒绝（安全兜底）
if ($adminToken === '' || $adminToken === 'change_me_to_a_long_random_string') {
    $fatal = '未配置 ADMIN_TOKEN，请在 .env 中设置一个强随机口令（参考 .env.example）。';
}

// ---------- 处理登录 / 登出 ----------
$loginError = '';
$isLogin = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        if (hash_equals($adminToken, $_POST['token'] ?? '')) {
            $_SESSION['admin_authed'] = true;
            $isLogin = true;
        } else {
            $loginError = '口令错误';
        }
    } elseif ($_POST['action'] === 'logout') {
        unset($_SESSION['admin_authed']);
    }
}

$authed = !empty($_SESSION['admin_authed']);

// ---------- 缓存状态 ----------
$cacheFiles = [
    '.folder_tree_cache.json'   => '目录树缓存（getTree）',
    '.images_meta_cache.json'   => '图片元数据缓存（getImages）',
    '.name_index_cache.json'    => '文件名搜索索引（search）',
];

/** 构建缓存状态数组（所有键均有默认值，避免 undefined key） */
function buildCacheStats($cacheFiles) {
    $stats = [];
    foreach ($cacheFiles as $file => $desc) {
        $path = __DIR__ . '/' . $file;
        $stat = ['file' => $file, 'desc' => $desc, 'exists' => false, 'size' => 0, 'mtime' => null, 'age' => null, 'valid' => false];
        if (is_file($path)) {
            $stat['exists'] = true;
            $stat['size'] = filesize($path);
            $stat['mtime'] = filemtime($path);
            $raw = @file_get_contents($path);
            $decoded = json_decode($raw, true);
            // 顶层带 timestamp 的是目录树/搜索索引（writeCacheFile 格式）；
            // 否则可能是元数据缓存（key => ['timestamp'=>.., 'images'=>..] 映射表），
            // 取其中最新条目的 timestamp 作为整体新鲜度，若仍取不到则回退到文件 mtime。
            $ts = null;
            if (is_array($decoded)) {
                if (isset($decoded['timestamp']) && is_int($decoded['timestamp'])) {
                    $ts = $decoded['timestamp'];
                } else {
                    foreach ($decoded as $v) {
                        if (is_array($v) && isset($v['timestamp']) && is_int($v['timestamp'])) {
                            $ts = max($ts ?? 0, $v['timestamp']);
                        }
                    }
                }
            }
            if ($ts === null) $ts = filemtime($path);
            $stat['age'] = $ts ? time() - $ts : null;
            $stat['valid'] = $ts !== null && (time() - $ts) <= 30 * 24 * 3600;
        }
        $stats[] = $stat;
    }
    return $stats;
}

$cacheStats = buildCacheStats($cacheFiles);

// ---------- 图片统计 ----------
$imageCount = 0;
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $f->getFilename())) $imageCount++;
        if ($imageCount >= 100000) break;
    }
}

// ---------- 处理操作 ----------
$actionMsg = '';
$actionOk = false;
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !in_array($_POST['action'], ['login', 'logout'], true)) {
    $act = $_POST['action'];
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        $actionMsg = 'CSRF 校验失败，请刷新页面重试';
    } else {
        switch ($act) {
            case 'clear_tree':
                @unlink(__DIR__ . '/.folder_tree_cache.json');
                $actionMsg = '目录树缓存已清空（下次加载目录时自动重建）';
                $actionOk = true;
                break;
            case 'clear_meta':
                @unlink(__DIR__ . '/.images_meta_cache.json');
                $actionMsg = '图片元数据缓存已清空（下次访问文件夹时自动重建）';
                $actionOk = true;
                break;
            case 'clear_name':
                @unlink(__DIR__ . '/.name_index_cache.json');
                $actionMsg = '文件名搜索索引已清空（下次搜索时自动重建）';
                $actionOk = true;
                break;
            case 'clear_all':
                foreach (array_keys($cacheFiles) as $f) @unlink(__DIR__ . '/' . $f);
                $actionMsg = '所有后端缓存已清空';
                $actionOk = true;
                break;
            case 'rebuild_tree':
                // 强制重建目录树：清缓存后调 getTree&refresh=true 立即重建
                @unlink(__DIR__ . '/.folder_tree_cache.json');
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                $resp = @file_get_contents($base . '/get_images.php?action=getTree&refresh=true');
                $actionMsg = $resp !== false ? '目录树已强制重建' : '缓存已清空（但自动重建请求失败，请手动刷新页面触发）';
                $actionOk = $resp !== false;
                break;
        }
        // 操作后刷新状态
        $cacheStats = buildCacheStats($cacheFiles);
    }
}

// CSRF token
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrfToken = $_SESSION['csrf'];

// ---------- 工具函数 ----------
function fmtSize($b) {
    if ($b >= 1048576) return number_format($b / 1048576, 2) . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
}
function fmtTime($t) { return $t ? date('Y-m-d H:i:s', $t) : '--'; }
function fmtAge($sec) {
    if ($sec === null) return '--';
    if ($sec < 60) return $sec . ' 秒前';
    if ($sec < 3600) return floor($sec / 60) . ' 分钟前';
    if ($sec < 86400) return floor($sec / 3600) . ' 小时前';
    return floor($sec / 86400) . ' 天前';
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin · 图片浏览器</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;min-height:100vh;}
h1{font-size:22px;margin:0 0 8px;}
.sub{color:#94a3b8;font-size:14px;margin-bottom:24px;}
.card{background:#1e293b;border-radius:12px;padding:20px;max-width:860px;margin-bottom:16px;}
.card h2{font-size:16px;margin:0 0 16px;color:#f1f5f9;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #334155;vertical-align:middle;}
th{color:#94a3b8;font-weight:600;font-size:12px;}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;}
.badge.ok{background:#14532d;color:#86efac;}
.badge.expired{background:#7f1d1d;color:#fca5a5;}
.badge.none{background:#334155;color:#cbd5e1;}
.btn{display:inline-block;padding:8px 14px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;text-decoration:none;margin:4px 6px 4px 0;}
.btn-primary{background:#2563eb;color:#fff;}
.btn-danger{background:#dc2626;color:#fff;}
.btn-ghost{background:#334155;color:#e2e8f0;}
.btn:hover{opacity:.85;}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;}
.msg.ok{background:#14532d;color:#86efac;}
.msg.err{background:#7f1d1d;color:#fca5a5;}
.msg.warn{background:#78350f;color:#fcd34d;}
.login-box{max-width:360px;margin:80px auto;background:#1e293b;border-radius:12px;padding:28px;}
.login-box h2{margin:0 0 16px;font-size:18px;}
.login-box input{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;margin-bottom:12px;font-size:14px;}
.login-box button{width:100%;padding:10px;border-radius:8px;border:none;background:#2563eb;color:#fff;font-size:14px;font-weight:600;cursor:pointer;}
.muted{color:#64748b;font-size:12px;margin-top:8px;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:8px;}
.stat-box{background:#0f172a;border-radius:10px;padding:14px;text-align:center;}
.stat-box .num{font-size:22px;font-weight:700;color:#60a5fa;}
.stat-box .lbl{font-size:12px;color:#94a3b8;margin-top:4px;}
.tip{background:#1e3a5f;border-radius:8px;padding:12px 14px;font-size:13px;color:#93c5fd;margin-top:14px;line-height:1.6;}
/* ---- WebDAV 同步卡片 ---- */
.sync-progress{background:#0f172a;border-radius:10px;padding:14px;margin:10px 0;}
.sync-bar{height:10px;background:#334155;border-radius:999px;overflow:hidden;margin:8px 0;}
.sync-bar>div{height:100%;background:linear-gradient(90deg,#2563eb,#22c55e);width:0%;transition:width .3s;}
.sync-stats{display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:#94a3b8;margin-top:8px;}
.sync-stats b{color:#e2e8f0;}
.sync-form label{display:block;font-size:13px;color:#94a3b8;margin:10px 0 4px;}
.sync-form input[type=text]{width:100%;box-sizing:border-box;padding:8px 10px;border-radius:8px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font-size:13px;font-family:monospace;}
.sync-form .row2{display:flex;gap:12px;}
.sync-form .row2>div{flex:1;}
.sync-log{background:#0f172a;border-radius:8px;padding:10px 12px;font-size:12px;font-family:monospace;color:#94a3b8;margin-top:10px;max-height:160px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;}
.sync-msg{display:none;padding:10px 14px;border-radius:8px;font-size:14px;margin-top:10px;}
.sync-msg.show{display:block;}
/* ---- 左右布局 + 远程目录树 ---- */
.layout{display:flex;gap:20px;align-items:flex-start;max-width:1240px;margin:0 auto;}
.sidebar{flex:0 0 340px;position:sticky;top:20px;}
.main{flex:1;min-width:0;}
.sidebar .card{max-width:none;}
.tree-toolbar{display:flex;gap:8px;margin-bottom:10px;align-items:center;}
.tree-toolbar .btn{padding:6px 10px;font-size:12px;margin:0;}
.tree-box{background:#0f172a;border-radius:8px;padding:10px;max-height:520px;overflow-y:auto;font-size:13px;}
.tree-node{margin-left:14px;border-left:1px solid #1e293b;padding-left:6px;}
.tree-row{display:flex;align-items:center;gap:6px;padding:3px 4px;border-radius:6px;cursor:pointer;white-space:nowrap;}
.tree-row:hover{background:#1e293b;}
.tree-toggle{width:14px;flex-shrink:0;color:#64748b;font-size:11px;text-align:center;user-select:none;}
.tree-check{flex-shrink:0;accent-color:#dc2626;cursor:pointer;}
.tree-icon{flex-shrink:0;}
.tree-name{overflow:hidden;text-overflow:ellipsis;}
.tree-badge{flex-shrink:0;font-size:11px;background:#7f1d1d;color:#fca5a5;padding:0 5px;border-radius:999px;display:none;}
.tree-badge.show{display:inline;}
.tree-count{flex-shrink:0;font-size:11px;color:#475569;}
.tree-children{display:none;}
.tree-node.open>.tree-children{display:block;}
.tree-loading{color:#475569;font-size:12px;padding:4px 10px;}
.tree-empty{color:#475569;font-size:12px;padding:4px 10px;}
.tree-root{font-weight:600;color:#e2e8f0;}
</style>
</head>
<body>

<?php if (isset($fatal)): ?>
<div class="card" style="max-width:560px;margin:80px auto;">
    <h2>⚠️ 配置不完整</h2>
    <p><?= h($fatal) ?></p>
    <p class="muted">在 .env 中设置 ADMIN_TOKEN 后刷新本页即可。</p>
</div>

<?php elseif (!$authed): ?>
<div class="login-box">
    <h2>🔒 Admin 登录</h2>
    <?php if ($loginError): ?><div class="msg err"><?= h($loginError) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="password" name="token" placeholder="管理口令" autofocus>
        <button type="submit">登 录</button>
    </form>
    <div class="muted">口令配置在 .env 的 ADMIN_TOKEN</div>
</div>

<?php else: ?>

<div class="layout">
<div class="sidebar">
    <div class="card">
        <h2>📂 远程 WebDAV 目录</h2>
        <div class="muted" style="margin-bottom:8px;">勾选目录加入黑名单（同步时跳过）</div>
        <div class="tree-toolbar">
            <button class="btn btn-ghost" id="tree_refresh" type="button">🔄 刷新</button>
            <button class="btn btn-primary" id="tree_save" type="button">💾 保存黑名单</button>
        </div>
        <div class="tree-box" id="tree_box">
            <div class="tree-loading">加载中…</div>
        </div>
        <div class="muted" style="margin-top:10px;">
            黑名单规则：<strong>名称匹配</strong>（任意层级同名，如 <code>.seekMeta</code>）+ <strong>路径匹配</strong>（树勾选的完整路径）
        </div>
    </div>
</div>
<div class="main">

<h1>🛠️ Admin 面板</h1>
<div class="sub">图片浏览器 · 后端缓存管理 &amp; WebDAV 同步</div>

<?php if ($actionMsg): ?>
<div class="msg <?= $actionOk ? 'ok' : 'err' ?>"><?= h($actionMsg) ?></div>
<?php endif; ?>

<div class="card" style="max-width:860px;">
    <h2>📊 状态总览</h2>
    <div class="stat-grid">
        <div class="stat-box"><div class="num"><?= number_format($imageCount) ?></div><div class="lbl">图片总数</div></div>
        <div class="stat-box"><div class="num"><?= count(array_filter($cacheStats, fn($s) => $s['exists'] && $s['valid'])) ?></div><div class="lbl">有效缓存</div></div>
        <div class="stat-box"><div class="num"><?= count(array_filter($cacheStats, fn($s) => !$s['exists'])) ?></div><div class="lbl">缺失缓存</div></div>
        <div class="stat-box"><div class="num"><?= PHP_VERSION ?></div><div class="lbl">PHP 版本</div></div>
    </div>
</div>

<div class="card">
    <h2>🗃️ 缓存状态</h2>
    <table>
        <tr><th>缓存</th><th>状态</th><th>大小</th><th>生成时间</th><th>更新时间</th></tr>
        <?php foreach ($cacheStats as $s): ?>
        <tr>
            <td><strong><?= h($s['file']) ?></strong><br><span class="muted"><?= h($s['desc']) ?></span></td>
            <td>
                <?php if (!$s['exists']): ?>
                    <span class="badge none">未生成</span>
                <?php elseif ($s['valid']): ?>
                    <span class="badge ok">有效</span>
                <?php else: ?>
                    <span class="badge expired">已过期</span>
                <?php endif; ?>
            </td>
            <td><?= $s['exists'] ? fmtSize($s['size']) : '--' ?></td>
            <td><?= fmtTime($s['mtime'] ?? null) ?></td>
            <td><?= fmtAge($s['age']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="tip">
        💡 <strong>缓存机制：</strong>前端「清除缓存」只清 localStorage，后端缓存默认 30 天 TTL。
        当你修改/增删了 webp_cache 里的图片后，用下方按钮强制重建对应缓存即可立即生效。
    </div>
</div>

<div class="card">
    <h2>⚙️ 缓存操作</h2>
    <form method="post" style="display:inline" onsubmit="return confirm('确定强制重建目录树？')">
        <input type="hidden" name="action" value="rebuild_tree">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-primary">🔄 强制重建目录树</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('确定清空图片元数据缓存？')">
        <input type="hidden" name="action" value="clear_meta">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-ghost">🗑️ 清空图片元数据缓存</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('确定清空文件名搜索索引？')">
        <input type="hidden" name="action" value="clear_name">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-ghost">🗑️ 清空搜索索引</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('确定清空全部后端缓存？')">
        <input type="hidden" name="action" value="clear_all">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <button type="submit" class="btn btn-danger">🧹 清空全部</button>
    </form>
</div>

<div class="card">
    <h2>🔄 WebDAV 同步任务</h2>
    <div class="muted" style="margin-bottom:10px;">
        从远程 WebDAV（<code><?= h($env['WEBDAV_BASE_URL'] ?? '(未配置)') ?></code>）拉取图片 → 等比缩放 → 压缩 webp 存入 <code>webp_cache</code>。
        图片命名 <code>xxx.png.webp</code>（原文件名+后缀+.webp），gif/md/txt 原样复制。
    </div>

    <div class="sync-form">
        <div class="row2">
            <div>
                <label>白名单扩展名（逗号分隔）</label>
                <input type="text" id="sync_whitelist" placeholder="png,jpg,jpeg,webp,gif,md,txt">
            </div>
            <div>
                <label>黑名单目录/文件（逗号分隔）</label>
                <input type="text" id="sync_blacklist" placeholder=".seekMeta,.seekTrash,@eaDir,#recycle,.thumbnails,Thumbs.db,.DS_Store">
            </div>
        </div>
        <div class="row2">
            <div>
                <label>压缩质量 (1-100)</label>
                <input type="text" id="sync_quality" placeholder="30">
            </div>
            <div>
                <label>最大宽度 px（超出等比缩放）</label>
                <input type="text" id="sync_max_width" placeholder="300">
            </div>
        </div>
        <div style="margin-top:12px;">
            <button class="btn btn-primary" id="btn_save_cfg">💾 保存配置</button>
            <button class="btn btn-primary" id="btn_start">▶ 增量同步</button>
            <button class="btn btn-danger" id="btn_full">⏺ 全量同步</button>
            <button class="btn btn-danger" id="btn_cancel" style="display:none;">⏹ 取消</button>
        </div>
    </div>

    <div class="sync-msg" id="sync_msg"></div>

    <div class="sync-progress" id="sync_progress" style="display:none;">
        <div style="display:flex;justify-content:space-between;font-size:13px;color:#94a3b8;">
            <span id="sync_phase">初始化…</span>
            <span id="sync_pct">0%</span>
        </div>
        <div class="sync-bar"><div id="sync_bar"></div></div>
        <div class="sync-stats">
            <span>📁 已扫目录 <b id="sync_dirs">0</b></span>
            <span>✅ 成功 <b id="sync_ok">0</b></span>
            <span>⏭️ 跳过 <b id="sync_skip">0</b></span>
            <span>❌ 失败 <b id="sync_fail">0</b></span>
            <span>📦 已下载 <b id="sync_bytes">0</b></span>
        </div>
        <div class="sync-log" id="sync_log"></div>
    </div>

    <div class="tip">
        💡 <strong>同步说明：</strong>同步采用<strong>前端分批轮询</strong>方式（受限于服务器禁用后台进程），
        <strong>转换期间请勿关闭本页面</strong>。中途刷新/关闭可稍后重新打开本页点「▶ 增量同步」继续（自动断点续传）。
        <br>🔹 <strong>增量同步</strong>：只拉取新增/变化的文件（推荐日常使用）。
        <br>🔹 <strong>全量同步</strong>：忽略已同步记录，重新下载压缩全部文件（首次部署或想重压时用，耗时最长）。
        <br>🔹 也可 SSH 执行 <code>php sync_webdav.php</code>（增量）或 <code>php sync_webdav.php --full</code>（全量）一次性跑完。
    </div>
</div>

<script>
(function () {
    const $ = id => document.getElementById(id);
    let running = false, timer = null;

    // ---- 加载配置 & 初始状态 ----
    fetch('sync_webdav.php?action=config').then(r => r.json()).then(d => {
        if (!d.error && d.config) {
            $('sync_whitelist').value = d.config.whitelist || '';
            $('sync_blacklist').value = d.config.blacklist || '';
            $('sync_quality').value = d.config.quality || '30';
            $('sync_max_width').value = d.config.max_width || '300';
        }
    });
    refreshStatus();

    function setMsg(text, ok) {
        const el = $('sync_msg');
        el.textContent = text;
        el.className = 'sync-msg show ' + (ok ? 'msg ok' : 'msg err');
    }

    function logLine(text) {
        const el = $('sync_log');
        el.textContent = new Date().toLocaleTimeString() + '  ' + text + '\n' + el.textContent;
    }

    function fmtBytes(b) {
        if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
        if (b >= 1024) return (b/1024).toFixed(1) + ' KB';
        return b + ' B';
    }

    // ---- 保存配置 ----
    $('btn_save_cfg').addEventListener('click', () => {
        const body = new URLSearchParams();
        body.append('whitelist', $('sync_whitelist').value);
        body.append('blacklist', $('sync_blacklist').value);
        body.append('quality', $('sync_quality').value);
        body.append('max_width', $('sync_max_width').value);
        if (treeReady) body.append('blacklist_dirs', JSON.stringify([...treeSelected]));
        fetch('sync_webdav.php?action=config', {method: 'POST', body})
            .then(r => r.json()).then(d => {
                if (d.ok) { setMsg('✅ 配置已保存', true); logLine('配置已保存'); }
                else setMsg('❌ ' + (d.error || '保存失败'), false);
            });
    });

    // ---- 开始 / 继续 ----
    function startSync(full) {
        if (running) return;
        running = true;
        $('btn_start').disabled = true;
        $('btn_full').disabled = true;
        $('btn_cancel').style.display = '';
        $('sync_progress').style.display = '';
        $('sync_log').textContent = '';
        logLine((full ? '全量同步' : '增量同步') + '任务启动…');
        fetch('sync_webdav.php?action=list' + (full ? '&full=1' : ''))
            .then(r => r.json()).then(d => {
                if (d.error) { setMsg('❌ ' + d.error, false); stop(); return; }
                logLine('任务已建立，开始处理');
                pump();
            });
    }

    $('btn_start').addEventListener('click', () => startSync(false));
    $('btn_full').addEventListener('click', () => {
        if (running) return;
        if (!confirm('⚠️ 全量同步会忽略已同步记录，重新下载压缩全部文件（3 万图预计 30~90 分钟）。\n确定继续吗？')) return;
        startSync(true);
    });

    $('btn_cancel').addEventListener('click', () => {
        fetch('sync_webdav.php?action=cancel').then(() => {
            logLine('已请求取消，等待当前批次结束…');
            setTimeout(() => { if (timer) { clearTimeout(timer); timer = null; } refreshStatus(); }, 2000);
        });
    });

    // ---- 分批轮询 ----
    function pump() {
        if (!running) return;
        fetch('sync_webdav.php?action=run')
            .then(r => r.json()).then(d => {
                if (d.error) { setMsg('❌ ' + d.error, false); stop(); return; }
                updateUI(d);
                if (d.phase === 'done') {
                    setMsg('✅ 同步完成！建议点击上方「🔄 强制重建目录树」刷新网站缓存', true);
                    logLine('同步完成');
                    stop();
                    refreshStatus();
                    return;
                }
                if (d.phase === 'cancelled') {
                    setMsg('⏹ 已取消', false);
                    stop();
                    refreshStatus();
                    return;
                }
                timer = setTimeout(pump, 400);
            }).catch(() => {
                logLine('请求失败，3 秒后重试…');
                timer = setTimeout(pump, 3000);
            });
    }

    function updateUI(d) {
        $('sync_phase').textContent = d.phase === 'done' ? '完成' : (d.message || '处理中…');
        $('sync_pct').textContent = (d.progress || 0) + '%';
        $('sync_bar').style.width = (d.progress || 0) + '%';
        $('sync_dirs').textContent = d.scanned_dirs || 0;
        $('sync_ok').textContent = (d.stats && d.stats.success) || 0;
        $('sync_skip').textContent = (d.stats && d.stats.skip) || 0;
        $('sync_fail').textContent = (d.stats && d.stats.fail) || 0;
        $('sync_bytes').textContent = fmtBytes((d.stats && d.stats.bytes) || 0);
        if (d.pending_total > 0 && d.done_idx > 0 && d.done_idx % 20 === 0) {
            logLine('进度 ' + d.done_idx + '/' + d.pending_total);
        }
    }

    function refreshStatus() {
        fetch('sync_webdav.php?action=status').then(r => r.json()).then(d => {
            if (!d.error && d.phase && d.phase !== 'idle') {
                $('sync_progress').style.display = '';
                updateUI(d);
                if (d.phase === 'running') {
                    // 页面刷新后发现任务还在跑：接续
                    if (!running) { running = true; $('btn_start').disabled = true; $('btn_full').disabled = true; $('btn_cancel').style.display = ''; pump(); }
                } else if (d.phase === 'done') {
                    $('btn_start').disabled = false; $('btn_full').disabled = false; $('btn_cancel').style.display = 'none';
                } else if (d.phase === 'cancelled') {
                    $('btn_start').disabled = false; $('btn_full').disabled = false; $('btn_cancel').style.display = 'none';
                }
            }
        });
    }

    function stop() {
        running = false;
        $('btn_start').disabled = false;
        $('btn_full').disabled = false;
        $('btn_cancel').style.display = 'none';
        if (timer) { clearTimeout(timer); timer = null; }
    }

    // ================= 远程目录树（黑名单勾选） =================
    const treeBox = $('tree_box');
    const treeSelected = new Set();   // 当前勾选的路径黑名单（待保存）
    const treeLoaded = new Set();     // 已加载过的目录 path（避免重复请求）
    let treeBusy = false;
    let treeReady = false;            // 树的 config 是否已加载完（避免误清空黑名单）

    // 从后端 config 加载已有路径黑名单
    fetch('sync_webdav.php?action=config').then(r => r.json()).then(d => {
        if (!d.error && Array.isArray(d.config.blacklist_dirs)) {
            d.config.blacklist_dirs.forEach(p => treeSelected.add(p));
        }
        treeReady = true;
        loadTreeLevel('', treeBox, true);
    });

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // 加载某个目录层；container 为 .tree-children 或根 .tree-box
    function loadTreeLevel(path, container, isRoot) {
        if (treeBusy) return;
        treeBusy = true;
        if (!isRoot) container.innerHTML = '<div class="tree-loading">加载中…</div>';
        fetch('sync_webdav.php?action=tree&path=' + encodeURIComponent(path))
            .then(r => r.json()).then(d => {
                treeBusy = false;
                if (d.error) { container.innerHTML = '<div class="tree-empty">❌ ' + esc(d.error) + '</div>'; return; }
                if (isRoot) {
                    treeLoaded.clear();
                    container.innerHTML = '';
                } else {
                    treeLoaded.add(path);
                }
                if (!d.dirs || d.dirs.length === 0) {
                    container.innerHTML = '<div class="tree-empty">（无子目录）</div>';
                    return;
                }
                d.dirs.forEach(dir => container.appendChild(buildNode(dir)));
            }).catch(() => {
                treeBusy = false;
                container.innerHTML = '<div class="tree-empty">请求失败</div>';
            });
    }

    function buildNode(dir) {
        const node = document.createElement('div');
        node.className = 'tree-node';
        const row = document.createElement('div');
        row.className = 'tree-row';
        row.title = dir.path;

        const toggle = document.createElement('span');
        toggle.className = 'tree-toggle';
        toggle.textContent = '▸';

        const check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'tree-check';
        if (dir.byName) {
            // 名称黑名单由文本框全局控制，树里只读展示
            check.checked = true;
            check.disabled = true;
            check.title = '该目录由「名称黑名单」排除（可在同步配置里修改）';
        } else {
            check.checked = treeSelected.has(dir.path);
            check.addEventListener('change', () => {
                if (check.checked) treeSelected.add(dir.path);
                else treeSelected.delete(dir.path);
                icon.textContent = check.checked ? '🚫' : '📁';
                badge.classList.toggle('show', check.checked);
            });
        }

        const icon = document.createElement('span');
        icon.className = 'tree-icon';
        icon.textContent = (check.checked || dir.byName) ? '🚫' : '📁';

        const name = document.createElement('span');
        name.className = 'tree-name';
        name.textContent = dir.name;

        const badge = document.createElement('span');
        badge.className = 'tree-badge' + (check.checked || dir.byName ? ' show' : '');
        badge.textContent = dir.byName ? '名称' : '路径';
        badge.title = dir.byName ? '按名称匹配（文本框黑名单）' : '按路径匹配（树勾选）';

        const count = document.createElement('span');
        count.className = 'tree-count';
        if (dir.file_count > 0) count.textContent = dir.file_count + '图';

        row.appendChild(toggle);
        row.appendChild(check);
        row.appendChild(icon);
        row.appendChild(name);
        row.appendChild(badge);
        row.appendChild(count);

        // 懒加载：点击行展开/收起
        const children = document.createElement('div');
        children.className = 'tree-children';
        row.addEventListener('click', (e) => {
            if (e.target === check) return; // checkbox 单独处理
            if (node.classList.contains('open')) {
                node.classList.remove('open');
                toggle.textContent = '▸';
                return;
            }
            node.classList.add('open');
            toggle.textContent = '▾';
            if (!treeLoaded.has(dir.path) && !children.dataset.loaded) {
                children.dataset.loaded = '1';
                loadTreeLevel(dir.path, children);
            }
        });

        node.appendChild(row);
        node.appendChild(children);
        return node;
    }

    // 刷新：清空重建根
    $('tree_refresh').addEventListener('click', () => {
        if (treeBusy) return;
        treeBox.innerHTML = '<div class="tree-loading">加载中…</div>';
        treeLoaded.clear();
        loadTreeLevel('', treeBox, true);
    });

    // 保存黑名单（独立按钮，也可用右上「保存配置」）
    $('tree_save').addEventListener('click', () => {
        if (!treeReady) { setMsg('⚠️ 目录树尚未加载完成，请稍候', false); return; }
        const body = new URLSearchParams();
        body.append('whitelist', $('sync_whitelist').value);
        body.append('blacklist', $('sync_blacklist').value);
        body.append('quality', $('sync_quality').value);
        body.append('max_width', $('sync_max_width').value);
        body.append('blacklist_dirs', JSON.stringify([...treeSelected]));
        fetch('sync_webdav.php?action=config', {method: 'POST', body})
            .then(r => r.json()).then(d => {
                if (d.ok) { setMsg('✅ 黑名单已保存', true); logLine('黑名单已保存：' + treeSelected.size + ' 条路径'); }
                else setMsg('❌ ' + (d.error || '保存失败'), false);
            });
    });
})();
</script>

<div class="card" style="text-align:right;">
    <form method="post" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn btn-ghost">退出登录</button>
    </form>
</div>

</div><!-- /main -->
</div><!-- /layout -->
<?php endif; ?>
</body>
</html>
