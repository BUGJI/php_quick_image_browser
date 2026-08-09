<?php
/**
 * ai_vector_lib.php - AI 语义搜索公共库
 *
 * 职责：
 *   - 读取 .env 的 AI_* 配置
 *   - 向量存储抽象：JSON 文件(本地/测试) / MySQL(生产)
 *   - 扫描 webp_cache 图片 + 反推 NAS 原图路径（模式1：image_path）
 *   - 调用 fn-ai-model 向量服务（img2vec / txt2vec，Bearer 鉴权）
 *   - 余弦相似度搜索，返回与 get_images.php 兼容的结果格式
 *
 * 被 ai_vector.php（CLI/Web 入口）与 get_images.php（AI 搜索）复用。
 * 本文件不输出任何内容，只定义函数。
 */

// 依赖 .env 加载器（幂等；ai_vector.php 已 require 也不影响）
require_once __DIR__ . '/env.php';

// ============================================================
// 配置
// ============================================================
function ai_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $env = function_exists('loadEnv') ? loadEnv() : [];
    $cfg = [
        'enabled'   => ($env['AI_SEARCH_ENABLED'] ?? '') === 'true',
        'base_url'  => rtrim((string)($env['AI_BASE_URL'] ?? 'http://127.0.0.1:46091'), '/'),
        'api_key'   => (string)($env['AI_API_KEY'] ?? ''),
        'image_mode'=> (string)($env['AI_IMAGE_MODE'] ?? 'path'),      // path|url|base64
        'image_root'=> rtrim((string)($env['AI_IMAGE_ROOT'] ?? ''), '/'),
        'dim'       => max(1, (int)($env['AI_DIM'] ?? 1024)),
        'exts'      => array_filter(array_map('trim', explode(',', (string)($env['AI_IMAGE_EXTS'] ?? 'png,jpg,jpeg,webp,gif')))),
        'storage'   => (string)($env['AI_STORAGE'] ?? 'json'),          // json|mysql
        'vector_file'=> (string)($env['AI_VECTOR_FILE'] ?? '.ai_vectors.json'),
        'mysql'     => [
            'host' => (string)($env['AI_MYSQL_HOST'] ?? ''),
            'port' => (int)($env['AI_MYSQL_PORT'] ?? 3306),
            'db'   => (string)($env['AI_MYSQL_DB'] ?? ''),
            'user' => (string)($env['AI_MYSQL_USER'] ?? ''),
            'pass' => (string)($env['AI_MYSQL_PASS'] ?? ''),
        ],
        'batch_size'=> max(1, (int)($env['AI_BATCH_SIZE'] ?? 10)),
        'time_budget'=> 45,   // 每批最长秒数（Web 轮询模式）
        'top_k'     => 50,    // 默认返回条数
        'cache_dir' => __DIR__ . '/webp_cache',
        // AI 分类（三档：off=不启用 / realtime=实时 / cache=预生成索引）
        'classify_mode'  => (string)($env['AI_CLASSIFY_MODE'] ?? 'off'),
        'classify_top'   => max(1, (int)($env['AI_CLASSIFY_TOP'] ?? 200)),
        'classify_min_score' => (float)($env['AI_CLASSIFY_MIN_SCORE'] ?? 0),
    ];
    return $cfg;
}

/** 读取 AI 分类类别清单（ai_categories.json）：['类别名' => '英文描述', ...] */
function ai_categories() {
    $f = __DIR__ . '/ai_categories.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

// ============================================================
// 查询词向量缓存（避免同一关键词反复调 txt2vec 触发频率限制）
// ============================================================
function ai_query_vec_cache_file() { return __DIR__ . '/.ai_query_cache.json'; }

function ai_query_vec_cache_get($q) {
    $f = ai_query_vec_cache_file();
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return (is_array($d) && isset($d[md5($q)])) ? $d[md5($q)] : null;
}

function ai_query_vec_cache_put($q, $vec) {
    $f = ai_query_vec_cache_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    if (!is_array($d)) $d = [];
    $d[md5($q)] = $vec;
    // 限制条数，防止无限增长（上限 500 条）
    if (count($d) > 500) $d = array_slice($d, -500, 500, true);
    @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE));
}

/**
 * txt2vec 带查询缓存：命中直接返回，未命中调服务并缓存。
 * 分类类别的描述向量也可用此缓存（同一类别只调一次 txt2vec）。
 */
function ai_txt2vec_cached($cfg, $text) {
    $cached = ai_query_vec_cache_get($text);
    if ($cached) return ['ok' => true, 'vec' => $cached, 'cached' => true];
    $r = ai_txt2vec($cfg, $text);
    if (!empty($r['ok'])) ai_query_vec_cache_put($text, $r['vec']);
    return $r;
}

// 向量化参与判断：webp_cache 里的文件是否属于图片
function ai_is_image_file($name, $cfg) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    // 图片压缩产物形如 xxx.png.webp / xxx.jpg.webp；原 webp 图片为 xxx.webp.webp；
    // gif 原样复制（xxx.gif）。仅处理这些图片扩展名，md/txt 跳过。
    return in_array($ext, $cfg['exts'], true) || (substr($name, -5) === '.webp' && in_array('webp', $cfg['exts'], true));
}

/**
 * 反推原图相对路径（相对 AI_IMAGE_ROOT）
 * webp 缓存命名 xxx.原扩展.webp（图片压缩）；gif/md/txt 原样。
 * 规则：仅去掉末尾一个 .webp 后缀。
 */
function ai_orig_rel($webpRel) {
    if (substr($webpRel, -5) === '.webp') {
        return substr($webpRel, 0, -5);
    }
    return $webpRel;
}

// ============================================================
// 存储抽象
// ============================================================
/**
 * 行结构：['origRel'=>string, 'vec'=>float[], 'size'=>int, 'mtime'=>int]
 * 键：webpRel（webp_cache 相对路径）
 */
class AiVectorStoreJson {
    private $file;
    public function __construct($file) { $this->file = $file; }
    private function read() {
        if (!is_file($this->file)) return ['timestamp' => 0, 'dim' => 0, 'vectors' => []];
        $d = json_decode((string)@file_get_contents($this->file), true);
        return is_array($d) ? $d : ['timestamp' => 0, 'dim' => 0, 'vectors' => []];
    }
    public function all() { return $this->read()['vectors'] ?? []; }
    public function get($key) { $d = $this->read(); return $d['vectors'][$key] ?? null; }
    public function topByCosine($qvec, $topK) {
        $rows = $this->read()['vectors'] ?? [];
        $top = [];
        foreach ($rows as $webpRel => $row) {
            $score = ai_cosine($qvec, $row['vec']);
            $top[] = ['webpRel' => $webpRel, 'origRel' => $row['origRel'], 'score' => $score, 'size' => (int)($row['size'] ?? 0)];
        }
        usort($top, function ($a, $b) { return $b['score'] <=> $a['score']; });
        return array_slice($top, 0, max(1, (int)$topK));
    }
    public function upsert($key, $row) {
        $d = $this->read();
        $d['vectors'][$key] = $row;
        $d['timestamp'] = time();
        $this->write($d);
    }
    public function remove($key) {
        $d = $this->read();
        unset($d['vectors'][$key]);
        $d['timestamp'] = time();
        $this->write($d);
    }
    public function clear() {
        $this->write(['timestamp' => time(), 'dim' => 0, 'vectors' => []]);
    }
    public function count() { return count($this->read()['vectors'] ?? []); }
    private function write($d) {
        @file_put_contents($this->file, json_encode($d, JSON_UNESCAPED_UNICODE));
    }
}

class AiVectorStoreMysql {
    private $pdo;
    public function __construct($cfg) {
        $m = $cfg['mysql'];
        if (!class_exists('PDO')) {
            throw new Exception('AI_STORAGE=mysql 但 PHP 缺少 PDO 扩展');
        }
        if ($m['host'] === '' || $m['db'] === '' || $m['user'] === '') {
            throw new Exception('AI_MYSQL_HOST/DB/USER 未配置');
        }
        $dsn = 'mysql:host=' . $m['host'] . ';port=' . $m['port'] . ';dbname=' . $m['db'] . ';charset=utf8mb4';
        $this->pdo = new PDO($dsn, $m['user'], $m['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->ensureTable();
    }
    private function ensureTable() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ai_vectors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            webp_rel VARCHAR(512) NOT NULL UNIQUE,
            orig_rel VARCHAR(1024) NOT NULL,
            dim INT NOT NULL DEFAULT 0,
            vec LONGTEXT NOT NULL,
            size BIGINT NOT NULL DEFAULT 0,
            mtime BIGINT NOT NULL DEFAULT 0,
            updated_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function all() {
        $out = [];
        $st = $this->pdo->query('SELECT webp_rel, orig_rel, vec, size, mtime FROM ai_vectors');
        foreach ($st as $r) {
            $vec = json_decode($r['vec'], true);
            if (!is_array($vec)) continue;
            $out[$r['webp_rel']] = ['origRel' => $r['orig_rel'], 'vec' => $vec, 'size' => (int)$r['size'], 'mtime' => (int)$r['mtime']];
        }
        return $out;
    }
    /**
     * top-K 余弦搜索（分批游标读取，避免全表加载撑爆内存）。
     * 返回 [['webpRel'=>, 'origRel'=>, 'score'=>, 'size'=>], ...] 按 score 降序。
     */
    public function topByCosine($qvec, $topK) {
        $top = [];
        $lastId = 0;
        $batch = 500;
        while (true) {
            $st = $this->pdo->prepare('SELECT id, webp_rel, orig_rel, vec, size, mtime FROM ai_vectors WHERE id > ? ORDER BY id ASC LIMIT ' . (int)$batch);
            $st->execute([$lastId]);
            $rows = $st->fetchAll();
            if (!$rows) break;
            foreach ($rows as $r) {
                $lastId = (int)$r['id'];
                $vec = json_decode($r['vec'], true);
                if (!is_array($vec)) continue;
                $score = ai_cosine($qvec, $vec);
                $top[] = ['webpRel' => $r['webp_rel'], 'origRel' => $r['orig_rel'], 'score' => $score, 'size' => (int)$r['size']];
            }
            if (count($rows) < $batch) break;
        }
        usort($top, function ($a, $b) { return $b['score'] <=> $a['score']; });
        return array_slice($top, 0, max(1, (int)$topK));
    }
    public function get($key) {
        $st = $this->pdo->prepare('SELECT webp_rel, orig_rel, vec, size, mtime FROM ai_vectors WHERE webp_rel = ?');
        $st->execute([$key]);
        $r = $st->fetch();
        if (!$r) return null;
        $vec = json_decode($r['vec'], true);
        return is_array($vec) ? ['origRel' => $r['orig_rel'], 'vec' => $vec, 'size' => (int)$r['size'], 'mtime' => (int)$r['mtime']] : null;
    }
    public function upsert($key, $row) {
        $st = $this->pdo->prepare(
            'INSERT INTO ai_vectors (webp_rel, orig_rel, dim, vec, size, mtime, updated_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE orig_rel=VALUES(orig_rel), dim=VALUES(dim), vec=VALUES(vec), size=VALUES(size), mtime=VALUES(mtime), updated_at=VALUES(updated_at)'
        );
        $st->execute([$key, $row['origRel'], count($row['vec']), json_encode($row['vec'], JSON_UNESCAPED_UNICODE), (int)$row['size'], (int)$row['mtime'], time()]);
    }
    public function remove($key) {
        $st = $this->pdo->prepare('DELETE FROM ai_vectors WHERE webp_rel = ?');
        $st->execute([$key]);
    }
    public function clear() {
        $this->pdo->exec('DELETE FROM ai_vectors');
    }
    public function count() {
        return (int)$this->pdo->query('SELECT COUNT(*) c FROM ai_vectors')->fetch()['c'];
    }
}

function ai_store($cfg) {
    static $store = null;
    if ($store !== null) return $store;
    if ($cfg['storage'] === 'mysql') {
        $store = new AiVectorStoreMysql($cfg);
    } else {
        $store = new AiVectorStoreJson(__DIR__ . '/' . $cfg['vector_file']);
    }
    return $store;
}

// ============================================================
// 扫描 webp_cache + 反推原图路径
// ============================================================
/**
 * 递归扫描 webp_cache，返回图片列表
 * 每条：['webpRel'=>相对路径, 'origRel'=>反推原图相对路径, 'origPath'=>AI_IMAGE_ROOT 拼接,
 *        'size'=>webp 文件字节, 'mtime'=>webp 文件 mtime, 'name'=>文件名, 'ext'=>扩展名]
 */
function ai_scan_images($cfg) {
    $root = $cfg['cache_dir'];
    $out = [];
    if (!is_dir($root)) return $out;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $abs = $f->getPathname();
        $webpRel = str_replace('\\', '/', substr($abs, strlen($root) + 1));
        $name = $f->getFilename();
        if (!ai_is_image_file($name, $cfg)) continue;
        $origRel = ai_orig_rel($webpRel);
        $out[$webpRel] = [
            'webpRel'  => $webpRel,
            'origRel'  => $origRel,
            'origPath' => $cfg['image_root'] . '/' . $origRel,
            'size'     => (int)$f->getSize(),
            'mtime'    => (int)$f->getMTime(),
            'name'     => $name,
            'ext'      => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        ];
    }
    ksort($out);
    return $out;
}

// ============================================================
// 向量服务调用
// ============================================================
function ai_http_json($url, $payload, $apiKey, $timeout = 60) {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

    // 通道 1: curl（生产云端通常有）
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['error' => '请求失败: ' . $err];
        return ai_parse_json_resp($resp, $code);
    }

    // 通道 2: file_get_contents 流（本地/受限环境）
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => (float)$timeout,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($resp === false) return ['error' => '请求失败: HTTP ' . $code];
        return ai_parse_json_resp($resp, $code);
    }

    return ['error' => '服务器缺少 curl 扩展且 allow_url_fopen 关闭'];
}

function ai_parse_json_resp($resp, $code) {
    $j = json_decode($resp, true);
    if (!is_array($j)) return ['error' => "HTTP $code 非 JSON 响应"];
    if (isset($j['code']) && $j['code'] !== 0) return ['error' => '服务错误: ' . ($j['message'] ?? json_encode($j))];
    return ['ok' => true, 'code' => $code, 'json' => $j];
}

/** 图片 → 向量（支持 path / url / base64 三种模式） */
function ai_img2vec($cfg, $item) {
    $mode = $cfg['image_mode'];
    if ($mode === 'base64') {
        $b64 = '';
        $abs = $cfg['cache_dir'] . '/' . $item['webpRel'];
        if (is_file($abs)) $b64 = base64_encode((string)file_get_contents($abs));
        if ($b64 === '') return ['error' => '读取 webp 失败'];
        $payload = ['image' => $b64];
    } elseif ($mode === 'url') {
        // 需要服务端开启 image_url 白名单；URL 由部署方决定（暂以 origPath 兜底不可用）
        return ['error' => 'url 模式需要图片公网 URL，当前未实现（建议用 path 模式）'];
    } else {
        $payload = ['image_path' => $item['origPath']];
    }
    $r = ai_http_json($cfg['base_url'] . '/api/img2vec', $payload, $cfg['api_key']);
    if (!empty($r['error'])) return $r;
    $vec = $r['json']['data']['vector'] ?? null;
    if (!is_array($vec) || count($vec) === 0) return ['error' => 'img2vec 返回空向量'];
    return ['ok' => true, 'vec' => $vec];
}

/** 文本 → 向量 */
function ai_txt2vec($cfg, $text) {
    if (trim($text) === '') return ['error' => '查询词为空'];
    $r = ai_http_json($cfg['base_url'] . '/api/txt2vec', ['text' => (string)$text, 'lang' => 'auto'], $cfg['api_key']);
    if (!empty($r['error'])) return $r;
    $vec = $r['json']['data']['vector'] ?? null;
    if (!is_array($vec) || count($vec) === 0) return ['error' => 'txt2vec 返回空向量'];
    return ['ok' => true, 'vec' => $vec];
}

// ============================================================
// 余弦相似度 + 搜索
// ============================================================
function ai_cosine($a, $b) {
    $n = min(count($a), count($b));
    if ($n === 0) return 0.0;
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float)$a[$i]; $y = (float)$b[$i];
        $dot += $x * $y;
        $na  += $x * $x;
        $nb  += $y * $y;
    }
    if ($na <= 0.0 || $nb <= 0.0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}

/**
 * AI 语义搜索：txt2vec(query) → 全库余弦 → top-k
 * 返回 get_images.php search 兼容格式：
 *   ['success'=>true, 'images'=>[['name','path','size','format'], ...], 'ai'=>true, 'scores'=>[...]]
 */
function ai_search_images($cfg, $query, $topK = 50) {
    $tv = ai_txt2vec_cached($cfg, $query);
    if (!empty($tv['error'])) return ['success' => false, 'error' => $tv['error']];
    $store = ai_store($cfg);
    $hits = $store->topByCosine($tv['vec'], max(1, (int)$topK));
    if (count($hits) === 0) return ['success' => false, 'error' => '向量库为空，请先建立向量缓存后再搜索'];

    $images = [];
    $scores = [];
    foreach ($hits as $h) {
        $images[] = [
            'name'   => basename($h['webpRel']),
            'path'   => $h['webpRel'],
            'size'   => $h['size'],
            'format' => strtoupper(pathinfo($h['webpRel'], PATHINFO_EXTENSION)),
        ];
        $scores[] = round($h['score'], 4);
    }
    return ['success' => true, 'images' => $images, 'ai' => true, 'scores' => $scores];
}

// ============================================================
// AI 分类（左侧 AI分类 虚拟文件夹）
// 三档：off=不启用 / realtime=点击时实时算 topK / cache=预生成索引(推荐)
// ============================================================
function ai_classify_index_file() { return __DIR__ . '/.ai_categories_index.json'; }

/** 实时模式：类别描述 → 文本向量 → 全库 topK（复用分批游标，秒级） */
function ai_classify_realtime($cfg, $desc, $topK = 200, $minScore = 0) {
    $tv = ai_txt2vec_cached($cfg, $desc);
    if (!empty($tv['error'])) return ['success' => false, 'error' => $tv['error']];
    $store = ai_store($cfg);
    $hits = $store->topByCosine($tv['vec'], max(1, (int)$topK));
    $images = [];
    $scores = [];
    foreach ($hits as $h) {
        if ($minScore > 0 && $h['score'] < $minScore) continue;
        $images[] = [
            'name'   => basename($h['webpRel']),
            'path'   => $h['webpRel'],
            'size'   => $h['size'],
            'format' => strtoupper(pathinfo($h['webpRel'], PATHINFO_EXTENSION)),
        ];
        $scores[] = round($h['score'], 4);
    }
    return ['success' => true, 'images' => $images, 'ai' => true, 'mode' => 'realtime', 'scores' => $scores];
}

/**
 * 预生成分类索引（cache 模式）：对每个类别算 topK，结果存 .ai_categories_index.json。
 * 增量逻辑：索引存在则只对「向量库中新增/变更」的图片重新分类（按 webpRel 集合差集），
 * 删掉的图片自动从索引移除。
 * 返回 ['ok'=>true, 'categories'=>n, 'images'=>n] 或 ['error'=>...]
 */
function ai_classify_build_index($cfg, $progress = null) {
    $cats = ai_categories();
    if (!$cats) return ['error' => 'ai_categories.json 为空或不存在'];

    $store = ai_store($cfg);
    $allVecs = [];
    try { $allVecs = $store->all(); } catch (Throwable $e) { return ['error' => '读取向量库失败: ' . $e->getMessage()]; }
    if (!$allVecs) return ['error' => '向量库为空，请先建立向量缓存'];

    $idxFile = ai_classify_index_file();
    $idx = is_file($idxFile) ? json_decode((string)@file_get_contents($idxFile), true) : [];
    if (!is_array($idx)) $idx = [];
    $old = $idx['categories'] ?? [];

    // 现有向量库的 webpRel 集合；索引里对应图片集合
    $vecKeys = array_keys($allVecs);
    $idxKeys = [];
    foreach ($old as $c => $list) { foreach ($list as $im) { $idxKeys[$im['path']] = true; } }
    $idxKeys = array_keys($idxKeys);

    // 需要处理 = 向量库新增/变更（向量库行有 size/mtime，但索引只存了 path；简化：全量差集）
    // 增量策略：向量库有而索引没有的 → 重新分类；索引有而向量库没有的 → 删除
    $need = array_diff($vecKeys, $idxKeys);
    $drop = array_diff($idxKeys, $vecKeys);

    // 类别向量（带缓存，只需算一次）
    $catVecs = [];
    foreach ($cats as $name => $desc) {
        $tv = ai_txt2vec_cached($cfg, $desc);
        if (!empty($tv['ok'])) $catVecs[$name] = $tv['vec'];
    }
    if (!$catVecs) return ['error' => '类别文本向量化全部失败（检查向量服务）'];

    $newIdx = [];
    foreach ($cats as $name => $desc) {
        if (!isset($catVecs[$name])) continue;
        // 已有索引里保留仍有效的图片（未删除、且不是待重分类的）
        $kept = [];
        if (isset($old[$name])) {
            foreach ($old[$name] as $im) {
                if (in_array($im['path'], $drop, true)) continue;      // 向量库已删
                if (in_array($im['path'], $need, true)) continue;      // 需要重新分类
                $kept[] = $im;
            }
        }
        $newIdx[$name] = $kept;
    }
    // 对新增/变更图片,每类都算一遍相似度,若进入 topK 则插入
    if ($need) {
        $hitsByCat = [];
        foreach ($cats as $name => $desc) {
            if (!isset($catVecs[$name])) continue;
            $cand = [];
            foreach ($need as $w) {
                $score = ai_cosine($catVecs[$name], $allVecs[$w]['vec']);
                $cand[] = ['path' => $w, 'origRel' => $allVecs[$w]['origRel'], 'size' => (int)($allVecs[$w]['size'] ?? 0), 'score' => round($score, 4)];
            }
            usort($cand, function ($a, $b) { return $b['score'] <=> $a['score']; });
            $topN = array_slice($cand, 0, max(1, (int)$cfg['classify_top']));
            $hitsByCat[$name] = $topN;
        }
        // 合并：保留旧的 + 按分数插入新的,再截断到 topK
        $merge = [];
        foreach ($cats as $name => $desc) {
            if (!isset($catVecs[$name])) continue;
            $merged = $newIdx[$name];
            foreach (($hitsByCat[$name] ?? []) as $h) $merged[] = $h;
            usort($merged, function ($a, $b) { return $b['score'] <=> $a['score']; });
            $newIdx[$name] = array_slice($merged, 0, max(1, (int)$cfg['classify_top']));
        }
    }

    $idx = [
        'timestamp' => time(),
        'mode' => 'cache',
        'top' => max(1, (int)$cfg['classify_top']),
        'categories' => $newIdx,
    ];
    @file_put_contents($idxFile, json_encode($idx, JSON_UNESCAPED_UNICODE));

    return ['ok' => true, 'categories' => count($cats), 'images' => count($vecKeys), 'added' => count($need), 'removed' => count($drop)];
}

/** 按当前模式查询某分类（realtime=实时 / cache=读索引） */
function ai_classify_query($cfg, $catName, $topK = 200) {
    $cats = ai_categories();
    if (!isset($cats[$catName])) return ['success' => false, 'error' => "未知分类: {$catName}"];
    $desc = $cats[$catName];
    $minScore = (float)$cfg['classify_min_score'];

    if ($cfg['classify_mode'] === 'cache') {
        $idxFile = ai_classify_index_file();
        if (!is_file($idxFile)) return ['success' => false, 'error' => '分类索引未建立，请在后台生成'];
        $idx = json_decode((string)@file_get_contents($idxFile), true);
        $list = (is_array($idx) && isset($idx['categories'][$catName])) ? $idx['categories'][$catName] : [];
        if (!$list) return ['success' => true, 'images' => [], 'ai' => true, 'mode' => 'cache'];
        $images = [];
        $scores = [];
        foreach ($list as $h) {
            if ($minScore > 0 && (float)($h['score'] ?? 0) < $minScore) continue;
            $images[] = [
                'name'   => basename($h['path']),
                'path'   => $h['path'],
                'size'   => (int)($h['size'] ?? 0),
                'format' => strtoupper(pathinfo($h['path'], PATHINFO_EXTENSION)),
            ];
            $scores[] = (float)($h['score'] ?? 0);
        }
        return ['success' => true, 'images' => $images, 'ai' => true, 'mode' => 'cache', 'scores' => $scores];
    }

    // realtime
    return ai_classify_realtime($cfg, $desc, max(1, (int)$topK), $minScore);
}

/** 分类模式是否启用（off 则不显示 AI 分类） */
function ai_classify_enabled($cfg) {
    return $cfg['classify_mode'] === 'realtime' || $cfg['classify_mode'] === 'cache';
}
