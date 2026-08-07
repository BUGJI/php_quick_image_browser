<?php
/**
 * env.php - 轻量 .env 加载器
 * 兼容密码中含特殊字符（如分号 ; 引号 "）的情况
 * 用法：$env = loadEnv();  // 默认读 __DIR__/.env
 */
function loadEnv($file = null) {
    if ($file === null) $file = __DIR__ . '/.env';
    $env = [];
    if (!is_file($file)) return $env;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // 去掉包裹的引号
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $env[$key] = $value;
    }
    return $env;
}
