<?php
$imagePath = isset($_GET['path']) ? $_GET['path'] : '';
$cacheDir = __DIR__ . '/webp_cache';

if (empty($imagePath)) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$realPath = realpath($cacheDir . '/' . $imagePath);
$cacheReal = realpath($cacheDir);

if ($realPath === false || strpos($realPath, $cacheReal) !== 0) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

if (!file_exists($realPath)) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'webp' => 'image/webp',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif'
];

$mimeType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: public, max-age=86400');

readfile($realPath);
?>