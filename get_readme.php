<?php
/**
 * get_readme.php - 获取文件夹下的 README 文件内容
 * 用法: get_readme.php?path=relative/path/to/folder
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 配置：webp_cache 根目录
$WEB_CACHE_ROOT = __DIR__ . '/webp_cache';

// 只允许 GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$requestPath = isset($_GET['path']) ? $_GET['path'] : '';
if (empty($requestPath)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing path parameter']);
    exit;
}

// 安全检查：防止目录遍历
$requestPath = urldecode($requestPath);
$requestPath = preg_replace('#/+#', '/', $requestPath);
$requestPath = trim($requestPath, '/');

if (strpos($requestPath, '..') !== false || strpos($requestPath, '\\') !== false) {
    http_response_code(403);
    echo json_encode(['error' => 'Path traversal not allowed']);
    exit;
}

// 构建完整路径
$fullPath = realpath($WEB_CACHE_ROOT);
if ($fullPath === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Cache root not found']);
    exit;
}

$targetDir = $fullPath . '/' . $requestPath;
$targetDir = realpath($targetDir);

if ($targetDir === false || strpos($targetDir, $fullPath) !== 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Folder not found']);
    exit;
}

// 检查 README 文件（优先 markdown）
$readmeFiles = ['README.md', 'readme.md', 'README.txt', 'readme.txt', 'README', 'readme'];
$readmeContent = '';
$readmeType = '';
$readmeFile = '';

foreach ($readmeFiles as $file) {
    $filePath = $targetDir . '/' . $file;
    if (file_exists($filePath) && is_file($filePath)) {
        $readmeContent = file_get_contents($filePath);
        $readmeType = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($readmeType === '') $readmeType = 'txt';
        $readmeFile = $file;
        break;
    }
}

if (empty($readmeContent)) {
    echo json_encode(['hasReadme' => false]);
    exit;
}

echo json_encode([
    'hasReadme' => true,
    'type' => $readmeType,
    'content' => $readmeContent,
    'file' => $readmeFile
], JSON_UNESCAPED_UNICODE);