<?php
/**
 * test_webdav.php - 独立 WebDAV 目录结构测试脚本
 * =================================================
 * 用途：验证远程 WebDAV 能否正常连接、认证、并获取目录结构（PROPFIND）。
 *       为后续「WebDAV 同步」功能做前置检查（列出目录树是同步的第一步）。
 *
 * ⚠️ 已知坑：.env 的 WEBDAV_BASE_URL 可能含空格/中文，直接请求会失败！
 *    本脚本会自动对 URL 做百分号编码（每段 rawurlencode）。
 *    若同步/代理代码里直接拼接未编码 URL，也会遇到同样问题。
 *
 * 用法：
 *   CLI:   php test_webdav.php            # 默认 depth=1，只列直接子项
 *          php test_webdav.php inf         # 递归列出全部目录树（慎用，目录多时很慢）
 *          php test_webdav.php 2           # 递归深度 2 层
 *   浏览器: test_webdav.php?depth=1        # 网页模式
 *          test_webdav.php?depth=inf
 *          test_webdav.php?path=子目录      # 指定从某个子目录开始列
 *
 * 输出：连接/认证/DAV能力/目录条目统计 + 条目明细（类型、大小、修改时间）
 */

require_once __DIR__ . '/env.php';
$env = loadEnv();

$remoteBase = $env['WEBDAV_BASE_URL'] ?? '';
$webdavUser = $env['WEBDAV_USERNAME'] ?? '';
$webdavPass = $env['WEBDAV_PASSWORD'] ?? '';

// ---------- 参数 ----------
$isCli   = (PHP_SAPI === 'cli');
$depth   = $isCli ? ($argv[1] ?? '1') : ($_GET['depth'] ?? '1');
$subPath = $isCli ? ($argv[2] ?? '')   : ($_GET['path'] ?? '');

$depth = strtolower(trim($depth));
if (!in_array($depth, ['0', '1', '2', '3', 'inf', 'infinity'], true)) {
    $depth = '1';
}
$davDepth = ($depth === 'inf' || $depth === 'infinity') ? 'infinity' : $depth;

// ---------- URL 编码（关键！）----------
// .env 里可能是未编码 URL（含空格/中文），逐段 rawurlencode 才能请求成功
function encodeWebDavUrl($url, $extraPath = '') {
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return $url;
    }
    $path = $parts['path'] ?? '';
    if ($extraPath !== '') {
        $path = rtrim($path, '/') . '/' . ltrim($extraPath, '/');
    }
    $segs = [];
    foreach (explode('/', $path) as $seg) {
        // 只有含合法百分号序列(%xx)才视为已编码，原样保留；字面 % 仍需编码，避免二次编码/漏编码
        $segs[] = preg_match('/%(?:[0-9A-Fa-f]{2})/', $seg) ? $seg : rawurlencode($seg);
    }
    $encPath = implode('/', $segs);

    $enc = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) $enc .= ':' . $parts['port'];
    $enc .= $encPath;
    if (isset($parts['query']))  $enc .= '?' . $parts['query'];
    if (isset($parts['fragment'])) $enc .= '#' . $parts['fragment'];
    return $enc;
}

$encodedBase = encodeWebDavUrl($remoteBase, $subPath);

// ---------- 输出辅助 ----------
function out($s) { global $isCli; echo $isCli ? $s . "\n" : nl2br(htmlspecialchars($s)) . "<br>\n"; }
function hr()    { global $isCli; echo $isCli ? str_repeat('-', 60) . "\n" : "<hr>\n"; }

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font:13px/1.6 monospace;background:#111;color:#eee;padding:16px;border-radius:8px'>\n";
}

out("=== WebDAV 目录结构测试 ===");
out("原始URL: $remoteBase");
out("编码URL: $encodedBase");
out("用户: $webdavUser");
out("深度: $davDepth" . ($subPath !== '' ? " (子路径: $subPath)" : ''));
out("时间: " . date('Y-m-d H:i:s'));
if (preg_match('/[\x80-\xff\s]/', $remoteBase)) {
    out("[提示] 原始 URL 含中文/空格，已自动编码——若你的同步代码直接拼接未编码 URL 会失败！");
}
hr();

if ($remoteBase === '') {
    out("[错误] WEBDAV_BASE_URL 未配置，请检查 .env");
    exit(1);
}

// ---------- 1. OPTIONS：探测 WebDAV 能力 ----------
out("[1/3] OPTIONS 探测 DAV 能力 ...");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $encodedBase,
    CURLOPT_CUSTOMREQUEST => 'OPTIONS',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $webdavUser . ':' . $webdavPass,
    CURLOPT_HEADER => true,
]);
$resp   = curl_exec($ch);
$errno  = curl_errno($ch);
$errstr = curl_error($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$hdr = substr((string)$resp, 0, $headerSize);
curl_close($ch);

if ($errno) {
    out("[失败] 无法连接 WebDAV: ($errno) $errstr");
    out("  → 检查网络、防火墙、端口 443 是否可达、域名解析");
    exit(1);
}
out("  HTTP $code");
foreach (explode("\r\n", $hdr) as $h) {
    if (stripos($h, 'DAV:') === 0 || stripos($h, 'Allow:') === 0 || stripos($h, 'Server:') === 0) {
        out("  " . trim($h));
    }
}
if ($code === 401 || $code === 403) {
    out("[失败] 认证被拒绝 ($code)。请检查 WEBDAV_USERNAME / WEBDAV_PASSWORD");
    exit(1);
}
if ($code >= 400) {
    out("[警告] OPTIONS 返回 $code（部分 WebDAV 服务器不支持 OPTIONS，继续尝试 PROPFIND）");
}
hr();

// ---------- 2. PROPFIND：获取目录结构 ----------
out("[2/3] PROPFIND 获取目录结构 (Depth: $davDepth) ...");
$propfindBody = '<?xml version="1.0" encoding="utf-8"?>' .
    '<d:propfind xmlns:d="DAV:">' .
    '<d:prop>' .
    '<d:resourcetype/>' .
    '<d:displayname/>' .
    '<d:getcontentlength/>' .
    '<d:getlastmodified/>' .
    '</d:prop>' .
    '</d:propfind>';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $encodedBase,
    CURLOPT_CUSTOMREQUEST => 'PROPFIND',
    CURLOPT_HTTPHEADER => [
        'Depth: ' . $davDepth,
        'Content-Type: application/xml; charset=utf-8',
    ],
    CURLOPT_POSTFIELDS => $propfindBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $webdavUser . ':' . $webdavPass,
]);
$resp   = curl_exec($ch);
$errno  = curl_errno($ch);
$errstr = curl_error($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) {
    out("[失败] PROPFIND 请求出错: ($errno) $errstr");
    exit(1);
}
out("  HTTP $code");

if ($code !== 207) {
    out("[失败] 预期 HTTP 207 (Multi-Status)，实际 $code");
    if ($code === 401 || $code === 403) out("  → 认证失败：用户名/密码错误，或该账号无此目录权限");
    elseif ($code === 404)              out("  → 路径不存在：请检查 WEBDAV_BASE_URL 是否写对（含空格/中文需 URL 编码）");
    elseif ($code === 405)              out("  → 服务器不支持 PROPFIND，可能不是 WebDAV 服务");
    else                                out("  → 响应前 300 字符: " . htmlspecialchars(substr($resp, 0, 300)));
    exit(1);
}
hr();

// ---------- 3. 解析 multistatus XML ----------
out("[3/3] 解析响应 ...");
$xml = @simplexml_load_string($resp);
if ($xml === false) {
    out("[失败] 响应不是合法 XML（可能被反代/防火墙改包，或返回了登录页/错误页）");
    out("  前 500 字符: " . htmlspecialchars(substr($resp, 0, 500)));
    exit(1);
}

// 兼容大小写前缀：服务器可能返回 <D:response>（大写）或 <d:response>（小写），
// 命名空间 URI 都是 DAV:，用 xpath 按 URI 匹配即可
$xml->registerXPathNamespace('dav', 'DAV:');
$responses = $xml->xpath('//dav:response');

// 兼容命名空间取值：部分服务器返回大写 <D:xxx>，SimpleXML 直接 ->href 可能取不到，
// 用 children('DAV:') 兜底（也可直接按 children 访问）
function davVal($node, $name) {
    if (isset($node->{$name})) return trim((string)$node->{$name});
    $c = $node->children('DAV:');
    if (isset($c->{$name})) return trim((string)$c->{$name});
    return '';
}
function davProp($propstat, $propName) {
    if (isset($propstat->prop->{$propName})) return $propstat->prop->{$propName};
    $c = $propstat->children('DAV:')->prop;
    if ($c !== null && isset($c->{$propName})) return $c->{$propName};
    return null;
}

$total = count($responses);
out("  共返回 $total 个条目");

if ($total === 0) {
    out("[失败] 目录为空或解析不到任何条目");
    out("  前 500 字符: " . htmlspecialchars(substr($resp, 0, 500)));
    exit(1);
}

// 按类型统计（resourcetype 里含 collection 即目录）
$dirCount = $fileCount = 0;
$dirs = []; $files = [];
foreach ($responses as $r) {
    $href = davVal($r, 'href');
    $propstat = isset($r->propstat) ? $r->propstat : $r->children('DAV:')->propstat;
    if ($propstat === null) continue;

    $rtNode = davProp($propstat, 'resourcetype');
    $rtChildren = ($rtNode !== null) ? $rtNode->children('DAV:') : null;
    $isDir = ($rtNode !== null && (isset($rtNode->collection) || ($rtChildren !== null && isset($rtChildren->collection))));

    $name = davProp($propstat, 'displayname');
    if ($name !== null && trim((string)$name) !== '') {
        $name = trim((string)$name);
    } else {
        $name = rawurldecode(basename(rtrim($href, '/'))) ?: $href;
    }
    $size = davProp($propstat, 'getcontentlength');
    $size = ($size !== null) ? trim((string)$size) : '';
    $mtime = davProp($propstat, 'getlastmodified');
    $mtime = ($mtime !== null) ? trim((string)$mtime) : '';

    $item = [
        'name'  => $name,
        'href'  => $href,
        'size'  => $size !== '' ? number_format((float)$size) : '-',
        'mtime' => $mtime !== '' ? $mtime : '-',
    ];
    if ($isDir) { $dirCount++; $dirs[] = $item; }
    else        { $fileCount++; $files[] = $item; }
}

out("  目录: $dirCount 个 | 文件: $fileCount 个");
hr();

// 输出目录明细
if ($dirCount > 0) {
    out("[目录]");
    foreach ($dirs as $d) {
        out("  📁 {$d['name']}\t{$d['mtime']}\t{$d['href']}");
    }
}
if ($fileCount > 0) {
    out("[文件]");
    foreach ($files as $f) {
        out("  🖼 {$f['name']}\t{$f['size']} B\t{$f['mtime']}");
    }
}
hr();

// ---------- 结论 ----------
$ok = ($dirCount + $fileCount) > 0;
out($ok
    ? "[结论] ✅ WebDAV 连接、认证、目录列举全部正常"
    : "[结论] ⚠️ 连接正常但未取到任何目录条目"
);
if ($depth !== '1') {
    out("提示: 当前 depth=$depth，共 " . ($dirCount + $fileCount) . " 个条目（含递归子项）");
} else {
    out("提示: 若需递归看子目录，用 depth=inf（CLI: php test_webdav.php inf）");
}

if (!$isCli) echo "</pre>";
exit($ok ? 0 : 1);
