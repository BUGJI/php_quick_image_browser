<?php
/**
 * serve_original.php - 原图 WebDAV 代理
 * 将请求转发到远程 WebDAV 服务器（带认证）
 * 配置从 .env 读取（见 .env.example），未配置时使用下方默认值兜底
 */

require_once __DIR__ . '/env.php';
$env = loadEnv();

// ========== 配置区 ==========
// 全部从 .env 读取（见 .env.example），不在代码里写死任何凭据
$remoteBase = $env['WEBDAV_BASE_URL'] ?? '';    // 远程 WebDAV 地址（必填）
$webdavUsername = $env['WEBDAV_USERNAME'] ?? ''; // WebDAV 用户名（必填）
$webdavPassword = $env['WEBDAV_PASSWORD'] ?? ''; // WebDAV 密码（必填）
if ($remoteBase === '' || $webdavUsername === '' || $webdavPassword === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'serve_original.php: 缺少配置，请复制 .env.example 为 .env 并填写 WEBDAV_BASE_URL / WEBDAV_USERNAME / WEBDAV_PASSWORD';
    exit;
}
// ============================

/**
 * URL 编码辅助（关键！）
 * .env 的 WEBDAV_BASE_URL 可能含空格/中文，
 * 直接拼接未编码 URL 会导致 WebDAV 请求失败。
 * 逐段 rawurlencode；已含合法 %xx 编码的段原样保留，避免二次编码。
 */
function ensureUrlEncoded($seg) {
    // 只有含合法百分号序列(%xx)才视为已编码；字面 %（如 "100%效果.png"）仍需编码
    if (preg_match('/%(?:[0-9A-Fa-f]{2})/', $seg)) {
        return $seg;
    }
    return rawurlencode($seg);
}

/** 编码完整 URL（含可选 path），仅编码路径部分，保留 scheme/host/port */
function encodeWebDavUrl($base, $extraPath = '') {
    $parts = parse_url($base);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return rtrim($base, '/') . ($extraPath !== '' ? '/' . ltrim($extraPath, '/') : '');
    }
    $path = $parts['path'] ?? '';
    if ($extraPath !== '') {
        $path = rtrim($path, '/') . '/' . ltrim($extraPath, '/');
    }
    $segs = [];
    foreach (explode('/', $path) as $seg) {
        $segs[] = ensureUrlEncoded($seg);
    }
    $encPath = implode('/', $segs);
    $enc = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) $enc .= ':' . $parts['port'];
    $enc .= $encPath;
    if (isset($parts['query']))    $enc .= '?' . $parts['query'];
    if (isset($parts['fragment'])) $enc .= '#' . $parts['fragment'];
    return $enc;
}

// 允许跨域
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$path = isset($_GET['path']) ? $_GET['path'] : '';

if (empty($path)) {
    header('HTTP/1.0 400 Bad Request');
    echo 'Missing path parameter';
    exit;
}

// 安全检查：防止目录遍历（基于 URL 解码后的值判断，避免 %2e%2e 绕过）
$decodedPath = rawurldecode($path);
$decodedPath = ltrim($decodedPath, '/');
if (strpos($decodedPath, '..') !== false) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// 拼接目标 URL（base 和 path 都做安全编码，避免中文/空格导致请求失败）
$targetUrl = encodeWebDavUrl($remoteBase, $decodedPath);

// 支持 Range 请求（视频/大图分片加载）
$headers = [];
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

// ========== WebDAV 认证 ==========
// 优先使用服务端配置的凭据（Basic Auth）
$authHeader = 'Authorization: Basic ' . base64_encode($webdavUsername . ':' . $webdavPassword);
$headers[] = $authHeader;

// 如果客户端也传了 Authorization，可以选择覆盖或保留服务端的
// 这里保留服务端配置的凭据，忽略客户端传来的
// ================================

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => true, // 需要获取响应头
    CURLOPT_SSL_VERIFYPEER => false, // 如果是自签名证书
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $webdavUsername . ':' . $webdavPassword,
]);

// 执行请求
$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($errno) {
    curl_close($ch);
    header('HTTP/1.0 502 Bad Gateway');
    echo "Proxy error: $error";
    exit;
}

// 分离头和体（必须在 curl_close 之前读取信息）
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headerStr = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

// 转发关键响应头
foreach (explode("\r\n", $headerStr) as $h) {
    if (stripos($h, 'Content-Type:') === 0 ||
        stripos($h, 'Content-Length:') === 0 ||
        stripos($h, 'Accept-Ranges:') === 0 ||
        stripos($h, 'Content-Range:') === 0 ||
        stripos($h, 'Cache-Control:') === 0 ||
        stripos($h, 'ETag:') === 0 ||
        stripos($h, 'Last-Modified:') === 0) {
        header($h);
    }
}

// 如果远程返回 206 Partial Content，保持状态码
if ($httpCode === 206) {
    http_response_code(206);
} else {
    http_response_code($httpCode);
}

// 输出内容
echo $body;