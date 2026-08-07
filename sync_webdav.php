<?php
/**
 * sync_webdav.php - WebDAV 同步引擎
 * =====================================================
 * 把远程 WebDAV 原图同步到本地 webp_cache：
 *   - 图片 (png/jpg/jpeg/webp)：下载 → 等比缩放（宽 > max_width 时）→ 压缩 webp（quality）
 *     → 命名 xxx.原扩展.webp（与现有 webp_cache 命名一致）
 *   - gif：直接复制保留动画，命名 xxx.gif（不压缩，避免丢动画）
 *   - md/txt 等非图片：原样复制，保留原名
 *   - 增量：本地已存在 + 远程 mtime 未变 → 跳过
 *   - 黑名单目录/文件：.seekMeta/.seekTrash/@eaDir/#recycle/.thumbnails/Thumbs.db/.DS_Store
 *
 * 运行方式：
 *   CLI（推荐，一次跑完，不受 max_execution_time 限制）：
 *     php sync_webdav.php
 *   Web（admin 面板前端轮询分批，单请求 < 45s 安全）：
 *     sync_webdav.php?action=list     # 开始：建立任务并扫描第一批目录
 *     sync_webdav.php?action=run      # 处理一批（前端循环调用直到 done）
 *     sync_webdav.php?action=status   # 查询进度
 *     sync_webdav.php?action=cancel   # 取消
 *     sync_webdav.php?action=config   # GET 获取配置 / POST 保存配置
 *
 * 配置优先级：.sync_config.json (admin 面板保存) > .env > 内置默认
 *   .env: SYNC_WHITELIST / SYNC_BLACKLIST / SYNC_QUALITY / SYNC_MAX_WIDTH / SYNC_BATCH_SIZE
 */

require_once __DIR__ . '/env.php';
$env = loadEnv();

// JSON API 模式下把 PHP fatal 转成 JSON 返回，前端能显示具体错误而不是"请求失败"
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
            }
            echo json_encode(['error' => 'PHP Fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']], JSON_UNESCAPED_UNICODE);
        }
    });
}

$IS_CLI = (PHP_SAPI === 'cli');

$remoteBase = $env['WEBDAV_BASE_URL'] ?? '';
$wdUser     = $env['WEBDAV_USERNAME'] ?? '';
$wdPass     = $env['WEBDAV_PASSWORD'] ?? '';
$cacheDir   = __DIR__ . '/webp_cache';
$stateFile  = __DIR__ . '/.sync_state.json';
$manifestFile = __DIR__ . '/.sync_manifest.json';
$configFile = __DIR__ . '/.sync_config.json';

// ---------- 配置（优先级: .sync_config.json > .env > 默认） ----------
$DEFAULTS = [
    'whitelist' => 'png,jpg,jpeg,webp,gif,md,txt',
    'blacklist' => '.seekMeta,.seekTrash,@eaDir,#recycle,.thumbnails,Thumbs.db,.DS_Store',
    'quality'   => '30',
    'max_width' => '300',
    'batch_size'=> '50',
];

function loadConfig() {
    global $env, $configFile, $DEFAULTS;
    $cfg = $DEFAULTS;
    // 1. .env 覆盖默认
    $envMap = [
        'whitelist' => 'SYNC_WHITELIST',
        'blacklist' => 'SYNC_BLACKLIST',
        'quality'   => 'SYNC_QUALITY',
        'max_width' => 'SYNC_MAX_WIDTH',
        'batch_size'=> 'SYNC_BATCH_SIZE',
    ];
    foreach ($envMap as $k => $ek) {
        if (isset($env[$ek]) && $env[$ek] !== '' && $env[$ek] !== null) $cfg[$k] = $env[$ek];
    }
    // 2. .sync_config.json 覆盖一切（admin 面板保存）
    if (file_exists($configFile)) {
        $j = json_decode((string)@file_get_contents($configFile), true);
        if (is_array($j)) {
            foreach ($cfg as $k => $v) {
                if (isset($j[$k]) && $j[$k] !== '') $cfg[$k] = (string)$j[$k];
            }
            // blacklist_dirs: 树勾选产生的「路径黑名单」（数组），仅在 config json 里保存
            if (isset($j['blacklist_dirs']) && is_array($j['blacklist_dirs'])) {
                $cfg['blacklist_dirs'] = $j['blacklist_dirs'];
            }
        }
    }
    if (!isset($cfg['blacklist_dirs'])) $cfg['blacklist_dirs'] = [];
    return $cfg;
}

function saveConfigToFile($cfg) {
    global $configFile;
    $clean = [
        'whitelist' => $cfg['whitelist'],
        'blacklist' => $cfg['blacklist'],
        'quality'   => (string)(int)$cfg['quality'],
        'max_width' => (string)(int)$cfg['max_width'],
        'batch_size'=> (string)(int)$cfg['batch_size'],
    ];
    // 路径黑名单（树勾选）独立保存为数组
    $dirs = $cfg['blacklist_dirs'] ?? [];
    if (is_string($dirs)) $dirs = json_decode($dirs, true);
    if (!is_array($dirs)) $dirs = [];
    $cleanDirs = [];
    foreach ($dirs as $d) {
        $d = trim((string)$d);
        if ($d !== '') $cleanDirs[] = $d;
    }
    $clean['blacklist_dirs'] = $cleanDirs;
    return @file_put_contents($configFile, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$cfg = loadConfig();
$WHITELIST = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $cfg['whitelist'])))));
$BLACKLIST = array_values(array_filter(array_map('trim', explode(',', $cfg['blacklist']))));
$BLACKLIST_DIRS = $cfg['blacklist_dirs'];
$QUALITY   = max(1, min(100, (int)$cfg['quality']));
$MAX_WIDTH = max(16, (int)$cfg['max_width']);
$BATCH_SIZE= max(1, (int)$cfg['batch_size']);
$TIME_BUDGET = 40;   // 单批最长处理秒数（Web 模式 300s 内留足余量）
$SCAN_DIRS_PER_BATCH = 20; // 单批最多扫描的远程目录数
$FULL_MODE = false;  // true=全量（忽略 manifest 增量判断）

// ---------- URL 编码（与 test_webdav.php / serve_original.php 一致） ----------
function ensureUrlEncoded($seg) {
    return preg_match('/%(?:[0-9A-Fa-f]{2})/', $seg) ? $seg : rawurlencode($seg);
}
function encodeWebDavUrl($base, $extraPath = '') {
    $parts = parse_url($base);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return rtrim($base, '/') . ($extraPath !== '' ? '/' . ltrim($extraPath, '/') : '');
    }
    $path = $parts['path'] ?? '';
    if ($extraPath !== '') $path = rtrim($path, '/') . '/' . ltrim($extraPath, '/');
    $segs = [];
    foreach (explode('/', $path) as $seg) $segs[] = ensureUrlEncoded($seg);
    $enc = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) $enc .= ':' . $parts['port'];
    $enc .= implode('/', $segs);
    if (isset($parts['query'])) $enc .= '?' . $parts['query'];
    if (isset($parts['fragment'])) $enc .= '#' . $parts['fragment'];
    return $enc;
}

/** href → 规范化本地路径比较串（取 path 部分并保证尾部 /；兼容绝对/相对 href） */
function hrefToPath($href) {
    $dec = rawurldecode($href);
    $p = parse_url($dec, PHP_URL_PATH);
    if ($p !== null) $dec = $p;
    if (substr($dec, -1) !== '/') $dec .= '/';
    return $dec;
}

// ---------- 状态文件 ----------
function loadState() {
    global $stateFile;
    if (!file_exists($stateFile)) return null;
    $j = json_decode((string)@file_get_contents($stateFile), true);
    return is_array($j) ? $j : null;
}
function saveState($s) {
    global $stateFile;
    $s['updated_at'] = time();
    return @file_put_contents($stateFile, json_encode($s, JSON_UNESCAPED_UNICODE)) !== false;
}

// ---------- WebDAV PROPFIND ----------
function davPropfind($url) {
    global $wdUser, $wdPass;
    $body = '<?xml version="1.0" encoding="utf-8"?>' .
        '<d:propfind xmlns:d="DAV:"><d:prop>' .
        '<d:resourcetype/><d:displayname/><d:getcontentlength/><d:getlastmodified/>' .
        '</d:prop></d:propfind>';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'PROPFIND',
        CURLOPT_HTTPHEADER => ['Depth: 1', 'Content-Type: application/xml; charset=utf-8'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $wdUser . ':' . $wdPass,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 207 || $resp === false) return false;
    return $resp;
}

/** 解析 PROPFIND 207 响应 → ['dirs'=>[href...], 'files'=>[ ['name','href','mtime','size'] ...]] */
function parsePropfind($xml) {
    $doc = @simplexml_load_string($xml);
    if ($doc === false) return null;
    // 注意：registerXPathNamespace 只对当前对象生效，xpath() 返回的子元素
    // 不继承注册，必须对每个子元素（$r、$ps）也注册，否则 dav: 前缀报
    // "Undefined namespace prefix"（服务器返回大写 D: 前缀时尤其常见）
    $doc->registerXPathNamespace('dav', 'DAV:');
    $responses = $doc->xpath('//dav:response');
    $dirs = []; $files = [];
    foreach ($responses as $r) {
        $r->registerXPathNamespace('dav', 'DAV:');
        $href = '';
        $hrefNodes = $r->xpath('dav:href');
        if ($hrefNodes && count($hrefNodes) > 0) $href = trim((string)$hrefNodes[0]);
        if ($href === '') continue;

        $isDir = false;
        $name = rawurldecode(basename(rtrim($href, '/')));
        $mtime = ''; $size = '';
        $propstats = $r->xpath('dav:propstat');
        foreach ($propstats as $ps) {
            $ps->registerXPathNamespace('dav', 'DAV:');
            $status = '';
            $st = $ps->xpath('dav:status');
            if ($st && count($st) > 0) $status = trim((string)$st[0]);
            if (stripos($status, '200') === false) continue;
            $rt = $ps->xpath('dav:prop/dav:resourcetype/dav:collection');
            if ($rt && count($rt) > 0) $isDir = true;
            $dn = $ps->xpath('dav:prop/dav:displayname');
            if ($dn && count($dn) > 0 && trim((string)$dn[0]) !== '') $name = trim((string)$dn[0]);
            $ml = $ps->xpath('dav:prop/dav:getlastmodified');
            if ($ml && count($ml) > 0) $mtime = trim((string)$ml[0]);
            $cl = $ps->xpath('dav:prop/dav:getcontentlength');
            if ($cl && count($cl) > 0) $size = trim((string)$cl[0]);
        }
        if ($isDir) $dirs[] = $href;
        else $files[] = ['name' => $name, 'href' => $href, 'mtime' => $mtime, 'size' => (int)$size];
    }
    return ['dirs' => $dirs, 'files' => $files];
}

// ---------- 下载文件 ----------
// 流式写入临时文件：大图原文件可能几十 MB，CURLOPT_RETURNTRANSFER 会把整个
// 文件放 PHP 内存（叠加 GD 解码内存易超 memory_limit 触发 fatal）
function downloadFile($url, $dest) {
    global $wdUser, $wdPass;
    $fp = @fopen($dest, 'wb');
    if ($fp === false) return ['ok' => false, 'error' => '本地临时文件创建失败'];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $wdUser . ':' . $wdPass,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $code < 200 || $code >= 300) {
        @unlink($dest);
        return ['ok' => false, 'error' => "HTTP $code" . ($err ? " ($err)" : '')];
    }
    return ['ok' => true];
}

// ---------- 压缩为 webp（等比缩放 + quality） ----------
// 全部 GD 调用加 @ + 返回值检查：任何失败都返回 false（记 fail 继续），
// 绝不能抛 TypeError 导致整批 fatal（imagecreatetruecolor 等返回 false 时
// 若继续传给下一个 GD 函数，PHP 8 会 TypeError → HTTP 500）
function compressToWebp($srcPath, $destPath, $quality, $maxWidth) {
    $info = @getimagesize($srcPath);
    if (!$info) return false;
    $w = $info[0]; $h = $info[1];
    switch ($info[2]) {
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($srcPath);  break;
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($srcPath); break;
        case IMAGETYPE_WEBP: $img = @imagecreatefromwebp($srcPath); break;
        default: return false;
    }
    if (!$img) return false;

    if ($w > $maxWidth) {
        $nh = max(1, (int)round($h * $maxWidth / $w));
        $dst = @imagecreatetruecolor($maxWidth, $nh);
        if (!$dst) { @imagedestroy($img); return false; }
        @imagealphablending($dst, false);
        @imagesavealpha($dst, true);
        $trans = @imagecolorallocatealpha($dst, 0, 0, 0, 127);
        @imagefill($dst, 0, 0, $trans);
        @imagecopyresampled($dst, $img, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
        @imagedestroy($img);
        $img = $dst;
    }
    $ok = @imagewebp($img, $destPath, $quality);
    @imagedestroy($img);
    return $ok;
}

// ---------- 计算本地目标文件名 ----------
function localTargetName($fileName, $ext) {
    if ($ext === 'gif') return $fileName;            // gif 直接复制保留动画
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) return $fileName . '.webp'; // 图片压缩 → xxx.原扩展.webp
    return $fileName;                                 // md/txt 等原样复制
}

// ---------- 判断是否应跳过（增量） ----------
// 注意：运行期在 runBatch 内联实现（需同时读 manifest 与本地文件），
// 这里不再单独保留函数。

// ---------- Web 鉴权（CLI 跳过） ----------
function checkWebAuth() {
    global $IS_CLI;
    if ($IS_CLI) return true;
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['admin_authed']);
}

// ============================================================
// 主逻辑
// ============================================================
if (!$IS_CLI) {
    header('Content-Type: application/json; charset=utf-8');
    // JSON API 不允许任何 PHP 输出混入响应体（线上 display_errors=On 会污染 JSON）
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    // GD 解码高清原图需要较多内存（4000px PNG ≈ 64MB+），尽力提高限制（共享主机可能禁止）
    @ini_set('memory_limit', '512M');
    if (!checkWebAuth()) { echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE); exit; }
}

$action = $IS_CLI ? 'cli' : ($_GET['action'] ?? 'status');

if ($action === 'config') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$IS_CLI) {
        $new = loadConfig();
        foreach (['whitelist', 'blacklist', 'quality', 'max_width', 'batch_size'] as $k) {
            if (isset($_POST[$k])) $new[$k] = trim((string)$_POST[$k]);
        }
        // 树勾选路径黑名单（JSON 数组字符串或逗号分隔）
        if (isset($_POST['blacklist_dirs'])) {
            $raw = trim((string)$_POST['blacklist_dirs']);
            if ($raw === '') $new['blacklist_dirs'] = [];
            else {
                $dec = json_decode($raw, true);
                $new['blacklist_dirs'] = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $raw)));
            }
        }
        if (saveConfigToFile($new)) { $cfg = $new; echo json_encode(['ok' => true, 'config' => $new], JSON_UNESCAPED_UNICODE); }
        else echo json_encode(['error' => '配置保存失败（目录不可写？）'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true, 'config' => loadConfig()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'tree') {
    // 返回远程目录树的一层（懒加载：path 为空=根）
    $treePath = isset($_GET['path']) ? trim((string)$_GET['path']) : '';
    if ($treePath !== '' && strpos($treePath, '..') !== false) {
        echo json_encode(['error' => '非法路径'], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($remoteBase === '') {
        echo json_encode(['error' => 'WEBDAV_BASE_URL 未配置'], JSON_UNESCAPED_UNICODE); exit;
    }
    $xml = davPropfind(encodeWebDavUrl($remoteBase, $treePath));
    if ($xml === false) {
        echo json_encode(['error' => '远程目录不可达'], JSON_UNESCAPED_UNICODE); exit;
    }
    $parsed = parsePropfind($xml);
    if ($parsed === null) {
        echo json_encode(['error' => '远程响应解析失败'], JSON_UNESCAPED_UNICODE); exit;
    }

    // 自身路径（PROPFIND depth=1 会返回请求资源自身）
    $selfDec = hrefToPath(encodeWebDavUrl($remoteBase, $treePath));

    $dirs = [];
    foreach ($parsed['dirs'] as $subHref) {
        if (hrefToPath($subHref) === $selfDec) continue; // 跳过自身
        $name = rawurldecode(basename(rtrim($subHref, '/')));
        $rel = ($treePath === '') ? $name : $treePath . '/' . $name;
        $dirs[] = [
            'name' => $name,
            'path' => $rel,
            // 名称黑名单 OR 路径黑名单
            'blacklisted' => in_array($name, $BLACKLIST, true) || in_array($rel, $BLACKLIST_DIRS, true),
            'byName' => in_array($name, $BLACKLIST, true),
            'byPath' => in_array($rel, $BLACKLIST_DIRS, true),
        ];
    }
    // 该层白名单文件数（供显示）
    $fileCount = 0;
    foreach ($parsed['files'] as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $WHITELIST, true)) $fileCount++;
    }
    echo json_encode(['ok' => true, 'path' => $treePath, 'dirs' => $dirs, 'file_count' => $fileCount], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'status') {
    $s = loadState();
    if (!$s) { echo json_encode(['phase' => 'idle', 'message' => '暂无任务'], JSON_UNESCAPED_UNICODE); exit; }
    $total = count($s['pending'] ?? []);
    $done  = $s['done_idx'] ?? 0;
    $s['pending_total'] = $total;
    $s['progress'] = $total > 0 ? round($done * 100 / $total, 1) : 0;
    echo json_encode($s, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cancel') {
    $s = loadState();
    if ($s) { $s['phase'] = 'cancelled'; saveState($s); }
    echo json_encode(['ok' => true, 'phase' => 'cancelled'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list' || $action === 'cli') {
    // 建立任务：重置状态，根目录入队
    if ($remoteBase === '') {
        $msg = 'WEBDAV_BASE_URL 未配置';
        if ($IS_CLI) { echo $msg . "\n"; exit(1); }
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit;
    }
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
        if (!is_dir($cacheDir)) {
            $msg = "webp_cache 目录创建失败: $cacheDir";
            if ($IS_CLI) { echo $msg . "\n"; exit(1); }
            echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    $rootHref = rawurldecode(parse_url($remoteBase, PHP_URL_PATH) ?? '/');
    if (substr($rootHref, -1) !== '/') $rootHref .= '/';
    // 全量模式：Web ?full=1，CLI --full
    $isFull = false;
    if ($IS_CLI) {
        $isFull = in_array('--full', $argv ?? [], true);
    } else {
        $isFull = isset($_GET['full']) && $_GET['full'] === '1';
    }
    $state = [
        'phase' => 'running',
        'message' => '扫描目录中…',
        'dir_queue' => [['href' => $rootHref, 'rel' => '']],
        'pending' => [],
        'done_idx' => 0,
        'stats' => ['success' => 0, 'skip' => 0, 'fail' => 0, 'bytes' => 0],
        'scanned_dirs' => 0,
        'started_at' => time(),
        'finished_at' => null,
        'full' => $isFull,
    ];
    saveState($state);
    if ($IS_CLI) {
        echo "开始同步: $remoteBase" . ($isFull ? " [全量]" : " [增量]") . "\n";
    } else {
        echo json_encode(['ok' => true, 'phase' => 'running', 'message' => '任务已建立', 'full' => $isFull], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------- 处理一批（Web run 或 CLI 循环调用） ----------
function runBatch($state) {
    global $WHITELIST, $BLACKLIST, $BLACKLIST_DIRS, $QUALITY, $MAX_WIDTH, $BATCH_SIZE, $TIME_BUDGET, $SCAN_DIRS_PER_BATCH;
    global $cacheDir, $remoteBase, $manifestFile;

    $startTime = microtime(true);
    $isFull = !empty($state['full']);

    // 阶段 1: 扫描远程目录（每批最多 SCAN_DIRS_PER_BATCH 个）
    $scanned = 0;
    while (!empty($state['dir_queue']) && $scanned < $SCAN_DIRS_PER_BATCH && (microtime(true) - $startTime) < $TIME_BUDGET) {
        $dir = array_shift($state['dir_queue']);
        $href = $dir['href']; $rel = $dir['rel'];
        $url = encodeWebDavUrl($remoteBase, ltrim($rel, '/'));
        $xml = davPropfind($url);
        $state['scanned_dirs'] = ($state['scanned_dirs'] ?? 0) + 1;
        if ($xml === false) {
            // 单个目录失败不中断，记录后继续
            $state['stats']['fail'] = ($state['stats']['fail'] ?? 0) + 1;
            continue;
        }
        $parsed = parsePropfind($xml);
        if ($parsed === null) continue;

        // PROPFIND depth=1 会把「请求资源自身」也返回（根目录），必须跳过，
        // 否则会产生 xx/xx/xx 无限递归路径
        $dirSelf = hrefToPath($url);

        foreach ($parsed['dirs'] as $subHref) {
            if (hrefToPath($subHref) === $dirSelf) continue; // 跳过自身
            $subName = rawurldecode(basename(rtrim($subHref, '/')));
            $subRel = ($rel === '') ? $subName : $rel . '/' . $subName;
            // 黑名单：目录名命中（如 .seekMeta）或 完整路径命中（树勾选）
            if (in_array($subName, $BLACKLIST, true) || in_array($subRel, $BLACKLIST_DIRS, true)) continue;
            $state['dir_queue'][] = ['href' => $subHref, 'rel' => $subRel];
        }
        foreach ($parsed['files'] as $f) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $WHITELIST, true)) continue;   // 白名单过滤
            if (in_array($f['name'], $BLACKLIST, true)) continue; // 黑名单文件（Thumbs.db 等）
            $fileRel = ($rel === '') ? $f['name'] : $rel . '/' . $f['name'];
            $state['pending'][] = [
                'rel' => $fileRel,
                'name' => $f['name'],
                'ext' => $ext,
                'mtime' => $f['mtime'],
                'size' => $f['size'],
            ];
        }
        $scanned++;
    }

    // 阶段 2: 处理待下载文件（每批最多 BATCH_SIZE 个）
    $processed = 0;
    while (($state['done_idx'] ?? 0) < count($state['pending']) && $processed < $BATCH_SIZE && (microtime(true) - $startTime) < $TIME_BUDGET) {
        $idx = $state['done_idx'];
        $item = $state['pending'][$idx];
        $state['done_idx'] = $idx + 1;
        $processed++;

        $localDir = $cacheDir . '/' . dirname($item['rel']);
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
            if (!is_dir($localDir)) { $state['stats']['fail']++; continue; }
        }
        $targetName = localTargetName($item['name'], $item['ext']);
        $targetPath = $cacheDir . '/' . dirname($item['rel']) . '/' . $targetName;

        // 增量跳过（全量模式忽略 manifest）
        $mf = file_exists($manifestFile) ? json_decode((string)@file_get_contents($manifestFile), true) : [];
        if (!is_array($mf)) $mf = [];
        $already = !$isFull && isset($mf[$item['rel']]);
        $mtimeSame = $already && ($mf[$item['rel']]['mtime'] ?? '') === $item['mtime'];
        if ($already && $mtimeSame && file_exists($targetPath)) {
            $state['stats']['skip']++;
            continue;
        }

        // 下载到临时文件
        $tmp = $cacheDir . '/.sync_tmp_' . bin2hex(random_bytes(6));
        $url = encodeWebDavUrl($remoteBase, ltrim($item['rel'], '/'));
        $dl = downloadFile($url, $tmp);
        if (!$dl['ok']) {
            @unlink($tmp);
            $state['stats']['fail']++;
            continue;
        }

        $bytes = (int)@filesize($tmp);
        $okWrite = false;
        $isImage = in_array($item['ext'], ['png', 'jpg', 'jpeg', 'webp'], true);
        if ($isImage) {
            $okWrite = compressToWebp($tmp, $targetPath, $QUALITY, $MAX_WIDTH);
        } else {
            // gif / md / txt：直接移动
            $okWrite = @rename($tmp, $targetPath);
        }
        @unlink($tmp);

        if (!$okWrite) {
            $state['stats']['fail']++;
            continue;
        }
        // 记录 manifest
        $mf[$item['rel']] = ['mtime' => $item['mtime'], 'size' => $item['size'], 'local' => $targetName];
        @file_put_contents($manifestFile, json_encode($mf, JSON_UNESCAPED_UNICODE));
        $state['stats']['success']++;
        $state['stats']['bytes'] += $bytes;
    }

    // 完成判断
    if (empty($state['dir_queue']) && ($state['done_idx'] ?? 0) >= count($state['pending'])) {
        $state['phase'] = 'done';
        $state['message'] = '同步完成';
        $state['finished_at'] = time();
    } else {
        $state['message'] = '处理中…';
    }
    return $state;
}

// ---------- 分发 ----------
if ($action === 'run') {
    $s = loadState();
    if (!$s) { echo json_encode(['error' => '任务不存在，请先开始同步'], JSON_UNESCAPED_UNICODE); exit; }
    if (($s['phase'] ?? '') === 'done') { echo json_encode(['phase' => 'done', 'message' => '已完成'], JSON_UNESCAPED_UNICODE); exit; }
    if (($s['phase'] ?? '') === 'cancelled') { echo json_encode(['phase' => 'cancelled'], JSON_UNESCAPED_UNICODE); exit; }
    // 并发锁：正常轮询间隔约 40s（一批处理完才发下一个请求）；
    // 若 10s 内又来一次 run，说明有第二个页面/请求在并发跑，拒绝并提示
    $lastRun = (int)($s['last_run_at'] ?? 0);
    if (time() - $lastRun < 10) {
        echo json_encode(['error' => '另一同步任务正在运行（多个页面同时同步？），请只保留一个页面'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $s['last_run_at'] = time();
    $s = runBatch($s);
    saveState($s);
    $total = count($s['pending']);
    $done = $s['done_idx'];
    echo json_encode([
        'phase' => $s['phase'],
        'message' => $s['message'],
        'pending_total' => $total,
        'done_idx' => $done,
        'progress' => $total > 0 ? round($done * 100 / $total, 1) : 0,
        'stats' => $s['stats'],
        'scanned_dirs' => $s['scanned_dirs'],
        'full' => !empty($s['full']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($IS_CLI) {
    // CLI 全量循环
    $s = loadState();
    $totalAll = 0;
    $t0 = microtime(true);
    while ($s && ($s['phase'] ?? '') === 'running') {
        $s = runBatch($s);
        saveState($s);
        $totalAll = count($s['pending']);
        $done = $s['done_idx'] ?? 0;
        $pct = $totalAll > 0 ? round($done * 100 / $totalAll, 1) : 0;
        $el = round(microtime(true) - $t0);
        printf("\r  已扫描目录 %d · 待处理 %d · 已完成 %d (%.1f%%) · 成功 %d / 跳过 %d / 失败 %d · %ds",
            $s['scanned_dirs'] ?? 0, $totalAll, $done, $pct,
            $s['stats']['success'] ?? 0, $s['stats']['skip'] ?? 0, $s['stats']['fail'] ?? 0, $el);
        if (($s['phase'] ?? '') === 'done') break;
        if (($s['phase'] ?? '') === 'cancelled') break;
    }
    echo "\n";
    if ($s) {
        echo ($s['phase'] === 'done' ? "✅ 同步完成: " : "⚠️ 同步中断: ")
            . "成功 {$s['stats']['success']} · 跳过 {$s['stats']['skip']} · 失败 {$s['stats']['fail']} · 下载 " . round(($s['stats']['bytes'] ?? 0)/1048576, 1) . " MB\n";
    } else {
        echo "❌ 无任务状态\n";
    }
    exit(0);
}

// 未知 action
echo json_encode(['error' => '未知 action'], JSON_UNESCAPED_UNICODE);
