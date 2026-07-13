<?php
/**
 * 验活任务队列（文件状态机）
 * 解耦：Web 只负责 start/status/cancel，真正干活在 CLI worker。
 */

function jobs_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}

function job_path(string $jobId): string {
    $jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId);
    return jobs_dir() . DIRECTORY_SEPARATOR . $jobId . '.json';
}

function job_new_id(): string {
    return date('Ymd_His') . '_' . bin2hex(random_bytes(4));
}

function job_read(string $jobId): ?array {
    $path = job_path($jobId);
    if (!is_file($path)) return null;
    $fp = fopen($path, 'rb');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : null;
}

function job_write(string $jobId, array $data): bool {
    $path = job_path($jobId);
    $data['updated_at'] = date('c');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $fp = fopen($path, 'c+b');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function job_append_log(array &$job, string $line, int $maxLines = 500): void {
    if (!isset($job['log']) || !is_array($job['log'])) $job['log'] = [];
    $job['log'][] = '[' . date('H:i:s') . '] ' . $line;
    if (count($job['log']) > $maxLines) {
        $job['log'] = array_slice($job['log'], -$maxLines);
    }
}

function find_php_cli_binary(): string {
    // 1) 便携包 runtime
    $portable = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
    if (is_file($portable)) return $portable;
    $portable2 = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php';
    if (is_file($portable2)) return $portable2;

    // 2) 当前 PHP_BINARY（内置服务器时通常也是 php.exe）
    if (defined('PHP_BINARY') && PHP_BINARY && is_file(PHP_BINARY)) {
        return PHP_BINARY;
    }

    // 3) PATH
    return PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
}

/**
 * Windows 下判断进程是否仍存活
 */
function pid_is_alive(?int $pid): bool {
    if (!$pid || $pid <= 0) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        $out = [];
        exec('tasklist /FI "PID eq ' . (int)$pid . '" /NH 2>NUL', $out);
        $joined = implode("\n", $out);
        return str_contains($joined, (string)$pid);
    }
    // posix
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return file_exists("/proc/{$pid}");
}

/**
 * 清理僵死任务：running/cancelling/queued 但超时无心跳或 pid 已死
 */
function job_reap_stale(int $staleSeconds = 120): int {
    $dir = jobs_dir();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $n = 0;
    $now = time();
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) continue;
        $st = $j['status'] ?? '';
        if (!in_array($st, ['queued', 'running', 'cancelling'], true)) continue;

        $updated = strtotime((string)($j['updated_at'] ?? $j['created_at'] ?? '')) ?: 0;
        $pid = isset($j['pid']) ? (int)$j['pid'] : 0;
        $age = $updated > 0 ? ($now - $updated) : 99999;
        $deadPid = $pid > 0 ? !pid_is_alive($pid) : ($age > $staleSeconds);

        // queued 超过 60s 还没 pid，也算僵死
        if ($st === 'queued' && $age > 60 && $pid <= 0) $deadPid = true;
        if ($age < $staleSeconds && !$deadPid) continue;
        if (!$deadPid && $age < $staleSeconds * 2) continue;

        $j['status'] = 'error';
        $j['error'] = 'worker 僵死或进程已退出（自动回收）';
        $j['finished_at'] = date('c');
        $j['message'] = '已回收僵死任务';
        $j['cancel_requested'] = true;
        if (!isset($j['log']) || !is_array($j['log'])) $j['log'] = [];
        $j['log'][] = '[' . date('H:i:s') . "] 自动回收 stale age={$age}s pid={$pid}";
        @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $n++;
    }
    return $n;
}

/**
 * 启动后台 worker（独立进程，不阻塞 php -S）
 */
function job_spawn_worker(string $jobId): array {
    $php = find_php_cli_binary();
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'check_worker.php';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'worker 脚本不存在: ' . $script];
    }

    $jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId);
    $outLog = jobs_dir() . DIRECTORY_SEPARATOR . $jobId . '.worker.log';

    if (PHP_OS_FAMILY === 'Windows') {
        // cmd /c start /B：真正脱离当前 php-S 请求
        $cmd = 'cmd /c start /B "" '
            . escapeshellarg($php) . ' -f '
            . escapeshellarg($script) . ' '
            . escapeshellarg($jobId)
            . ' > '
            . escapeshellarg($outLog)
            . ' 2>&1';
        pclose(popen($cmd, 'r'));
        return ['ok' => true, 'cmd' => $cmd, 'php' => $php];
    }

    $cmd = escapeshellarg($php) . ' -f ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId)
        . ' > ' . escapeshellarg($outLog) . ' 2>&1 &';
    exec($cmd);
    return ['ok' => true, 'cmd' => $cmd, 'php' => $php];
}

/**
 * 创建并启动验活任务
 * @param int[] $ids
 */
function job_start_check(PDO $db, array $ids, array $opts = []): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    if (!$ids) return ['ok' => false, 'error' => '没有可验活的账号'];

    // 先回收僵死任务，避免永远卡在 running
    job_reap_stale(120);

    // 若已有活跃任务，拒绝
    $active = job_find_active();
    if ($active && in_array($active['status'] ?? '', ['queued', 'running', 'cancelling'], true)) {
        return [
            'ok' => false,
            'error' => '已有验活任务在运行',
            'job' => job_public_view($active),
        ];
    }

    $jobId = job_new_id();
    $concurrency = (int)($opts['concurrency'] ?? setting_int('check_concurrency', 20));
    $concurrency = max(1, min(50, $concurrency));

    $job = [
        'id' => $jobId,
        'type' => 'check',
        'status' => 'queued', // queued|running|cancelling|cancelled|done|error
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'started_at' => null,
        'finished_at' => null,
        'cancel_requested' => false,
        'total' => count($ids),
        'done' => 0,
        'live' => 0,
        'dead' => 0,
        'unknown' => 0,
        'concurrency' => $concurrency,
        'imap_probe' => setting_bool('check_imap_probe'),
        'ids' => $ids,
        'cursor' => 0,
        'elapsed_ms' => 0,
        'speed' => 0,
        'message' => '排队中',
        'log' => [],
        'error' => null,
        'pid' => null,
    ];
    job_append_log($job, '任务创建 total=' . count($ids) . ' concurrency=' . $concurrency);
    job_write($jobId, $job);

    $spawn = job_spawn_worker($jobId);
    if (!$spawn['ok']) {
        $job['status'] = 'error';
        $job['error'] = $spawn['error'] ?? 'spawn failed';
        job_append_log($job, '启动 worker 失败: ' . $job['error']);
        job_write($jobId, $job);
        return ['ok' => false, 'error' => $job['error'], 'job' => job_public_view($job)];
    }
    job_append_log($job, 'worker 已启动 php=' . ($spawn['php'] ?? ''));
    $job['status'] = 'running';
    $job['message'] = 'worker 已拉起';
    job_write($jobId, $job);

    return ['ok' => true, 'job_id' => $jobId, 'job' => job_public_view($job)];
}

function job_request_cancel(string $jobId): array {
    $job = job_read($jobId);
    if (!$job) return ['ok' => false, 'error' => '任务不存在'];
    if (in_array($job['status'] ?? '', ['done', 'cancelled', 'error'], true)) {
        return ['ok' => true, 'job' => job_public_view($job), 'message' => '任务已结束'];
    }
    $job['cancel_requested'] = true;
    $job['status'] = 'cancelling';
    $job['message'] = '正在停止…';
    job_append_log($job, '收到取消请求');
    job_write($jobId, $job);
    return ['ok' => true, 'job' => job_public_view($job)];
}

function job_public_view(array $job): array {
    // 不把完整 ids 列表回前端（太大）；给摘要
    return [
        'id' => $job['id'] ?? '',
        'type' => $job['type'] ?? 'check',
        'status' => $job['status'] ?? 'unknown',
        'created_at' => $job['created_at'] ?? null,
        'updated_at' => $job['updated_at'] ?? null,
        'started_at' => $job['started_at'] ?? null,
        'finished_at' => $job['finished_at'] ?? null,
        'total' => (int)($job['total'] ?? 0),
        'done' => (int)($job['done'] ?? 0),
        'live' => (int)($job['live'] ?? 0),
        'dead' => (int)($job['dead'] ?? 0),
        'unknown' => (int)($job['unknown'] ?? 0),
        'concurrency' => (int)($job['concurrency'] ?? 0),
        'imap_probe' => !empty($job['imap_probe']),
        'cursor' => (int)($job['cursor'] ?? 0),
        'elapsed_ms' => (int)($job['elapsed_ms'] ?? 0),
        'speed' => (float)($job['speed'] ?? 0),
        'message' => $job['message'] ?? '',
        'error' => $job['error'] ?? null,
        'cancel_requested' => !empty($job['cancel_requested']),
        'log' => array_slice($job['log'] ?? [], -80),
        'percent' => ($job['total'] ?? 0) > 0
            ? round(((int)$job['done'] / (int)$job['total']) * 100, 1)
            : 0,
    ];
}

function job_find_active(): ?array {
    job_reap_stale(120);
    $dir = jobs_dir();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    // 新的在前
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) continue;
        if (in_array($j['status'] ?? '', ['queued', 'running', 'cancelling'], true)) {
            return $j;
        }
    }
    return null;
}

function job_list_recent(int $limit = 10): array {
    $dir = jobs_dir();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $out = [];
    foreach (array_slice($files, 0, $limit) as $f) {
        $raw = @file_get_contents($f);
        $j = json_decode((string)$raw, true);
        if (is_array($j)) $out[] = job_public_view($j);
    }
    return $out;
}
