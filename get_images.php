<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cacheDir = __DIR__ . '/webp_cache';
$treeCacheFile = __DIR__ . '/.folder_tree_cache.json';
$metaCacheFile = __DIR__ . '/.images_meta_cache.json';

// 缓存过期时间：30 天（前端「清除缓存」按钮会强制刷新，设长无副作用）
// 想彻底不失效可改为 PHP_INT_MAX
$treeCacheTtl = 30 * 24 * 3600;
$metaCacheTtl = 30 * 24 * 3600;

if (!is_dir($cacheDir)) {
    echo json_encode(['error' => 'webp_cache directory not found']);
    exit;
}

/**
 * 递归构建文件夹树（单次遍历），同时累加图片数
 * 只返回文件夹节点：大幅缩小体积、避免 O(n²) 重复扫描
 * 返回: ['children' => [...], 'imageCount' => int]
 */
function buildFolderTree($dir, $baseDir) {
    $children = [];
    $imageCount = 0;
    $items = @scandir($dir);
    if ($items === false) return ['children' => [], 'imageCount' => 0];

    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $fullPath = $dir . '/' . $item;
        if (is_dir($fullPath)) {
            $sub = buildFolderTree($fullPath, $baseDir);
            $imageCount += $sub['imageCount'];
            $children[] = [
                'name' => $item,
                'path' => str_replace($baseDir . '/', '', $fullPath),
                'type' => 'folder',
                'children' => $sub['children'],
                'imageCount' => $sub['imageCount'],
            ];
        } elseif (preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $item)) {
            $imageCount++;
        }
    }
    return ['children' => $children, 'imageCount' => $imageCount];
}

/** 递归检查树里是否还残留文件节点（用于识别旧版缓存，触发重建） */
function treeHasFiles($nodes) {
    foreach ($nodes as $n) {
        if (isset($n['type']) && $n['type'] === 'file') return true;
        if (!empty($n['children']) && treeHasFiles($n['children'])) return true;
    }
    return false;
}

function getAllImages($dir, $baseDir) {
    $images = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;

        $fullPath = $dir . '/' . $item;
        $relativePath = str_replace($baseDir . '/', '', $fullPath);

        if (is_dir($fullPath)) {
            $subImages = getAllImages($fullPath, $baseDir);
            $images = array_merge($images, $subImages);
        } elseif (preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $item)) {
            list($width, $height) = @getimagesize($fullPath);
            $size = filesize($fullPath);
            $sizeFormatted = formatFileSize($size);
            $extension = strtoupper(pathinfo($item, PATHINFO_EXTENSION));

            $images[] = [
                'name' => $item,
                'path' => $relativePath,
                'width' => $width ?: 800,
                'height' => $height ?: 600,
                'size' => $size,
                'sizeFormatted' => $sizeFormatted,
                'format' => $extension,
                'modified' => filemtime($fullPath)
            ];
        }
    }

    return $images;
}

/**
 * 构建轻量文件名索引（搜索专用）
 * 只扫文件名 + filesize，不做 getimagesize，3 万张图也能秒级完成
 * 搜索只需要 name/path/size/format，尺寸留给前端 img.onload 自动校正
 */
function buildNameIndex($dir, $baseDir) {
    $images = [];
    $items = @scandir($dir);
    if ($items === false) return $images;

    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;

        $fullPath = $dir . '/' . $item;

        if (is_dir($fullPath)) {
            $subImages = buildNameIndex($fullPath, $baseDir);
            $images = array_merge($images, $subImages);
        } elseif (preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $item)) {
            $images[] = [
                'name' => $item,
                'path' => str_replace($baseDir . '/', '', $fullPath),
                'size' => @filesize($fullPath),
                'format' => strtoupper(pathinfo($item, PATHINFO_EXTENSION)),
            ];
        }
    }

    return $images;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

// 读取带时间戳的缓存文件（过期返回 null）
function readCacheFile($cacheFile, $ttl) {
    if (!file_exists($cacheFile)) return null;
    $data = json_decode(file_get_contents($cacheFile), true);
    if (!$data || !isset($data['timestamp'])) return null;
    if (time() - $data['timestamp'] > $ttl) return null;
    return $data;
}

// 写入带时间戳的缓存文件
function writeCacheFile($cacheFile, $payload) {
    @file_put_contents($cacheFile, json_encode([
        'timestamp' => time(),
        'data' => $payload
    ]));
}

// 图片元数据缓存（key -> ['timestamp'=>.., 'images'=>..]）
function readMetaCache($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function writeMetaCache($file, $data) {
    @file_put_contents($file, json_encode($data));
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'getTree') {
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';

    if (!$forceRefresh) {
        $cached = readCacheFile($treeCacheFile, $treeCacheTtl);
        // 旧版缓存可能包含文件节点（体积巨大），检测到则自动重建精简版
        if ($cached !== null && isset($cached['data']) && !treeHasFiles($cached['data'])) {
            echo json_encode([
                'success' => true,
                'tree' => $cached['data'],
                'cached' => true,
                'timestamp' => $cached['timestamp']
            ]);
            exit;
        }
    }

    $built = buildFolderTree($cacheDir, $cacheDir);
    $tree = $built['children'];
    writeCacheFile($treeCacheFile, $tree);
    echo json_encode([
        'success' => true,
        'tree' => $tree,
        'cached' => false,
        'timestamp' => time()
    ]);

} elseif ($action === 'getImages') {
    $folderPath = isset($_GET['path']) ? $_GET['path'] : '';
    $targetDir = $cacheDir . ($folderPath ? '/' . $folderPath : '');

    if (!is_dir($targetDir)) {
        echo json_encode(['error' => 'Directory not found']);
        exit;
    }

    // 后端图片元数据缓存（避免每次 getimagesize 全量重扫）
    $cache = readMetaCache($metaCacheFile);
    $key = 'imgs:' . md5($folderPath);
    if (isset($cache[$key]) && time() - $cache[$key]['timestamp'] <= $metaCacheTtl) {
        echo json_encode([
            'success' => true,
            'images' => $cache[$key]['images'],
            'path' => $folderPath,
            'cached' => true,
            'timestamp' => $cache[$key]['timestamp']
        ]);
        exit;
    }

    $images = getAllImages($targetDir, $cacheDir);
    $cache[$key] = ['timestamp' => time(), 'images' => $images];
    writeMetaCache($metaCacheFile, $cache);
    echo json_encode([
        'success' => true,
        'images' => $images,
        'path' => $folderPath,
        'cached' => false,
        'timestamp' => time()
    ]);

} elseif ($action === 'search') {
    $keyword = isset($_GET['keyword']) ? $_GET['keyword'] : '';

    // 轻量文件名索引缓存（只扫文件名，不做 getimagesize）
    $nameIndexFile = __DIR__ . '/.name_index_cache.json';
    $cached = readCacheFile($nameIndexFile, $metaCacheTtl);
    if ($cached === null || !isset($cached['data'])) {
        $allImages = buildNameIndex($cacheDir, $cacheDir);
        writeCacheFile($nameIndexFile, $allImages);
    } else {
        $allImages = $cached['data'];
    }

    if ($keyword) {
        $filtered = array_filter($allImages, function($img) use ($keyword) {
            return stripos($img['name'], $keyword) !== false;
        });
        $allImages = array_values($filtered);
    }

    echo json_encode(['success' => true, 'images' => $allImages]);

} else {
    echo json_encode(['error' => 'Invalid action']);
}
