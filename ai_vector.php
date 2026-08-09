<?php
/**
 * ai_vector.php - AI 语义搜索：向量缓存(全量/增量) + AI 搜索接口
 *
 * 复用 sync_webdav.php 的「批次 + session 续传 + 并发锁」模式：
 *   1. Web：scan 建立任务 → 前端轮询 run 逐批处理 → done
 *   2. CLI：php ai_vector.php scan [--full] 一次跑完
 *
 * Actions（Web，除 search/status 外需 admin 登录）：
 *   scan?full=1|0  建立向量化任务（full=1 全量清库重建；默认增量）
 *   run            处理一批（前端轮询）
 *   status         任务状态
 *   cancel         取消任务
 *   clear          清空向量库
 *   search?q=词&top=N  AI 语义搜索（公开）
 *   test?mode=img|txt 测试向量服务连通性（公开）
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/ai_vector_lib.php';

$IS_CLI = (PHP_SAPI === 'cli');
$cfg = ai_config();
$stateFile = __DIR__ . '/.ai_state.json';

// ---------- 状态存取 ----------
function ai_load_state() {
    global $stateFile;
    if (!is_file($stateFile)) return null;
    $s = json_decode((string)@file_get_contents($stateFile), true);
    return is_array($s) ? $s : null;
}
function ai_save_state($s) {
    global $stateFile;
    @file_put_contents($stateFile, json_encode($s, JSON_UNESCAPED_UNICODE));
}

// ---------- Web 鉴权（CLI 跳过；search/status/test 公开） ----------
$publicActions = ['search', 'status', 'test', 'categories'];
$action = $IS_CLI ? 'cli' : ($_GET['action'] ?? 'status');

if (!$IS_CLI) {
    header('Content-Type: application/json; charset=utf-8');
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    @ini_set('memory_limit', '512M');
    if (!in_array($action, $publicActions, true)) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['admin_authed'])) {
            echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// ---------- CLI 命令分发（test/status/clear/search 在 scan 之前处理） ----------
if ($IS_CLI) {
    $cmd = $argv[1] ?? 'scan';
    if ($cmd === 'search') {
        $query = '';
        for ($i = 2; $i < count($argv); $i++) {
            if ($argv[$i] === '--query' && isset($argv[$i + 1])) { $query = $argv[$i + 1]; $i++; }
        }
        if ($query === '') { echo "用法: php ai_vector.php search --query \"关键词\"\n"; exit(1); }
        $res = ai_search_images($cfg, $query, 20);
        if (empty($res['success'])) { echo "❌ " . ($res['error'] ?? '失败') . "\n"; exit(1); }
        echo "🔍 「{$query}」 top " . count($res['images']) . "\n";
        foreach ($res['images'] as $i => $img) {
            printf("  %2d. %.4f  %s\n", $i + 1, $res['scores'][$i] ?? 0, $img['path']);
        }
        exit(0);
    }
    if ($cmd === 'status') {
        $s = ai_load_state();
        echo json_encode($s ?: ['phase' => 'idle'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "已存向量: " . ai_store($cfg)->count() . "\n";
        exit(0);
    }
    if ($cmd === 'clear') {
        ai_store($cfg)->clear();
        echo "✅ 向量库已清空\n";
        exit(0);
    }
    if ($cmd === 'build-index') {
        if (!ai_classify_enabled($cfg)) {
            echo "❌ AI 分类未启用（.env 设置 AI_CLASSIFY_MODE=realtime 或 cache）\n";
            exit(1);
        }
        echo "🔄 生成分类索引（cache 模式）…\n";
        $r = ai_classify_build_index($cfg);
        if (!empty($r['error'])) { echo "❌ " . $r['error'] . "\n"; exit(1); }
        echo "✅ 分类索引完成: {$r['categories']} 类 · {$r['images']} 图 · 新增 {$r['added']} · 移除 {$r['removed']}\n";
        exit(0);
    }
    if ($cmd === 'categories') {
        $cats = ai_categories();
        if (!$cats) { echo "❌ ai_categories.json 为空\n"; exit(1); }
        echo "分类清单 (" . count($cats) . " 类):\n";
        foreach ($cats as $name => $desc) echo "  - {$name}: {$desc}\n";
        exit(0);
    }
    if ($cmd === 'test') {
        $mode = $argv[2] ?? 'img';
        if ($mode === 'txt') {
            $r = ai_txt2vec($cfg, '测试');
            echo $r['ok'] ? "✅ txt2vec dim=" . count($r['vec']) . "\n" : "❌ " . $r['error'] . "\n";
            exit($r['ok'] ? 0 : 1);
        }
        $imgs = ai_scan_images($cfg);
        if (!$imgs) { echo "❌ webp_cache 无图片\n"; exit(1); }
        $first = reset($imgs);
        $r = ai_img2vec($cfg, $first);
        echo $r['ok'] ? "✅ img2vec dim=" . count($r['vec']) . " (image_path: {$first['origPath']})\n" : "❌ " . $r['error'] . "\n";
        exit($r['ok'] ? 0 : 1);
    }
}

// ---------- 测试连通性 ----------
if ($action === 'test') {
    $mode = $_GET['mode'] ?? 'img';
    if ($mode === 'txt') {
        $r = ai_txt2vec($cfg, '测试');
        echo json_encode($r['ok'] ? ['ok' => true, 'dim' => count($r['vec']), 'sample' => array_slice($r['vec'], 0, 3)] : $r, JSON_UNESCAPED_UNICODE);
    } else {
        // 用第一张图片测 path 模式
        $imgs = ai_scan_images($cfg);
        if (count($imgs) === 0) { echo json_encode(['error' => 'webp_cache 无图片'], JSON_UNESCAPED_UNICODE); exit; }
        $first = reset($imgs);
        $r = ai_img2vec($cfg, $first);
        echo json_encode($r['ok']
            ? ['ok' => true, 'dim' => count($r['vec']), 'image' => $first['webpRel'], 'origPath' => $first['origPath'], 'sample' => array_slice($r['vec'], 0, 3)]
            : $r, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- AI 搜索（公开） ----------
if ($action === 'search') {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : (isset($_GET['keyword']) ? trim((string)$_GET['keyword']) : '');
    if ($q === '') { echo json_encode(['success' => false, 'error' => '缺少查询词 q'], JSON_UNESCAPED_UNICODE); exit; }
    $top = isset($_GET['top']) ? (int)$_GET['top'] : $cfg['top_k'];
    echo json_encode(ai_search_images($cfg, $q, $top), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 分类清单（公开，只含名称） ----------
if ($action === 'categories') {
    $cats = ai_categories();
    echo json_encode(['ok' => true, 'categories' => array_map(function ($name) { return ['name' => $name]; }, array_keys($cats))], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 构建分类索引（需要 admin 登录，非公开） ----------
if ($action === 'buildIndex') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_authed'])) {
        echo json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!ai_classify_enabled($cfg)) {
        echo json_encode(['error' => 'AI 分类未启用（.env 的 AI_CLASSIFY_MODE=realtime 或 cache）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 分类索引构建可能较久，提升内存/时间
    @ini_set('memory_limit', '512M');
    @set_time_limit(0);
    $r = ai_classify_build_index($cfg);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 状态 ----------
if ($action === 'status') {
    $s = ai_load_state();
    if (!$s) { echo json_encode(['phase' => 'idle', 'message' => '暂无任务', 'stored' => ai_store($cfg)->count()], JSON_UNESCAPED_UNICODE); exit; }
    $total = count($s['pending'] ?? []);
    $done  = (int)($s['done_idx'] ?? 0);
    $s['pending_total'] = $total;
    $s['progress'] = $total > 0 ? round($done * 100 / $total, 1) : 0;
    $s['stored'] = ai_store($cfg)->count();
    echo json_encode($s, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 取消 ----------
if ($action === 'cancel') {
    $s = ai_load_state();
    if ($s) { $s['phase'] = 'cancelled'; ai_save_state($s); }
    echo json_encode(['ok' => true, 'phase' => 'cancelled'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 清空向量库 ----------
if ($action === 'clear') {
    ai_store($cfg)->clear();
    echo json_encode(['ok' => true, 'stored' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 建立任务 ----------
if ($action === 'scan' || $action === 'cli') {
    $isFull = false;
    if ($IS_CLI) {
        $isFull = in_array('--full', $argv ?? [], true);
    } else {
        $isFull = isset($_GET['full']) && $_GET['full'] === '1';
    }

    $store = ai_store($cfg);
    $imgs = ai_scan_images($cfg);
    $rows = $store->all();

    $pending = [];
    $toRemove = [];
    if ($isFull) {
        // 全量：全部重新向量化（清库由 run 完成首个批次时保证幂等，这里直接清）
        $store->clear();
        foreach ($imgs as $w => $item) $pending[] = $item;
    } else {
        // 增量：新增/变更 才处理；库中已消失的移除
        foreach ($imgs as $w => $item) {
            $old = $rows[$w] ?? null;
            $changed = !$old || (int)$old['size'] !== $item['size'] || (int)$old['mtime'] !== $item['mtime'];
            if ($changed) $pending[] = $item;
        }
        foreach ($rows as $w => $old) {
            if (!isset($imgs[$w])) $toRemove[] = $w;
        }
    }

    $state = [
        'phase' => 'running',
        'message' => '扫描完成，开始向量化…',
        'pending' => $pending,
        'to_remove' => $toRemove,
        'done_idx' => 0,
        'stats' => ['success' => 0, 'fail' => 0, 'skip' => 0, 'removed' => 0],
        'full' => $isFull,
        'started_at' => time(),
        'finished_at' => null,
        'last_run_at' => 0,
    ];
    ai_save_state($state);

    if ($IS_CLI) {
        echo '图片 ' . count($imgs) . " · 待向量化 " . count($pending) . ($isFull ? ' [全量]' : ' [增量]') . (count($toRemove) ? " · 待移除 " . count($toRemove) : '') . "\n";
    } else {
        echo json_encode(['ok' => true, 'phase' => 'running', 'full' => $isFull, 'pending_total' => count($pending), 'removing' => count($toRemove)], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------- 处理一批 ----------
function ai_run_batch($state, $cfg) {
    $store = ai_store($cfg);
    $t0 = microtime(true);
    $budget = $cfg['time_budget'];
    $batch = $cfg['batch_size'];

    // 阶段 1: 向量化（每批最多 AI_BATCH_SIZE 张）
    $processed = 0;
    while (($state['done_idx'] ?? 0) < count($state['pending']) && $processed < $batch && (microtime(true) - $t0) < $budget) {
        $idx = $state['done_idx'];
        $item = $state['pending'][$idx];
        $state['done_idx'] = $idx + 1;
        $processed++;

        $r = ai_img2vec($cfg, $item);
        if (!empty($r['error'])) {
            $state['stats']['fail']++;
            continue;
        }
        $store->upsert($item['webpRel'], [
            'origRel' => $item['origRel'],
            'vec'     => $r['vec'],
            'size'    => $item['size'],
            'mtime'   => $item['mtime'],
        ]);
        $state['stats']['success']++;
    }

    // 阶段 2: 移除已消失的条目（增量删除；每批最多 200 条避免过久）
    if (empty($state['pending']) || ($state['done_idx'] ?? 0) >= count($state['pending'])) {
        $rm = 0;
        while (!empty($state['to_remove']) && $rm < 200 && (microtime(true) - $t0) < $budget) {
            $w = array_shift($state['to_remove']);
            $store->remove($w);
            $state['stats']['removed']++;
            $rm++;
        }
    }

    // 完成判断
    if (($state['done_idx'] ?? 0) >= count($state['pending']) && empty($state['to_remove'])) {
        $state['phase'] = 'done';
        $state['message'] = '向量化完成';
        $state['finished_at'] = time();
    } else {
        $state['message'] = '处理中…';
    }
    return $state;
}

if ($action === 'run') {
    $s = ai_load_state();
    if (!$s) { echo json_encode(['error' => '任务不存在，请先建立任务'], JSON_UNESCAPED_UNICODE); exit; }
    if (($s['phase'] ?? '') === 'done') { echo json_encode(['phase' => 'done', 'message' => '已完成'], JSON_UNESCAPED_UNICODE); exit; }
    if (($s['phase'] ?? '') === 'cancelled') { echo json_encode(['phase' => 'cancelled', 'message' => '已取消'], JSON_UNESCAPED_UNICODE); exit; }
    // 并发锁：10s 内重复 run 视为并发
    $lastRun = (int)($s['last_run_at'] ?? 0);
    if (time() - $lastRun < 10) {
        echo json_encode(['error' => '另一任务正在运行（多个页面同时操作？），请只保留一个页面'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $s['last_run_at'] = time();
    $s = ai_run_batch($s, $cfg);
    ai_save_state($s);
    $total = count($s['pending']);
    $done = (int)$s['done_idx'];
    echo json_encode([
        'phase' => $s['phase'],
        'message' => $s['message'],
        'pending_total' => $total,
        'done_idx' => $done,
        'progress' => $total > 0 ? round($done * 100 / $total, 1) : 0,
        'stats' => $s['stats'],
        'removing_left' => count($s['to_remove']),
        'full' => !empty($s['full']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- CLI（scan 循环跑完） ----------
if ($IS_CLI) {
    $s = ai_load_state();
    if (!$s || ($s['phase'] ?? '') !== 'running') { echo "❌ 无任务状态，请先执行 php ai_vector.php scan [--full]\n"; exit(1); }
    $t0 = microtime(true);
    while ($s && ($s['phase'] ?? '') === 'running') {
        $s = ai_run_batch($s, $cfg);
        ai_save_state($s);
        $total = count($s['pending']);
        $done = (int)$s['done_idx'];
        $pct = $total > 0 ? round($done * 100 / $total, 1) : 100;
        printf("\r  向量化 %d/%d (%.1f%%) · 成功 %d / 失败 %d · 移除 %d · %ds",
            $done, $total, $pct,
            $s['stats']['success'] ?? 0, $s['stats']['fail'] ?? 0, $s['stats']['removed'] ?? 0,
            round(microtime(true) - $t0));
        if (($s['phase'] ?? '') === 'done' || ($s['phase'] ?? '') === 'cancelled') break;
    }
    echo "\n";
    if ($s) {
        echo ($s['phase'] === 'done' ? "✅ 向量化完成: " : "⚠️ 中断: ")
            . "成功 {$s['stats']['success']} · 失败 {$s['stats']['fail']} · 移除 {$s['stats']['removed']}\n";
        echo "库内向量: " . ai_store($cfg)->count() . "\n";
    } else {
        echo "❌ 无任务状态\n";
    }
    exit(0);
}

echo json_encode(['error' => '未知 action'], JSON_UNESCAPED_UNICODE);
