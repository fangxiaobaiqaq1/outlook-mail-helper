<?php
/**
 * CLI 验活 worker —— 独立进程，不占用 php -S 请求线程
 * 用法: php check_worker.php <job_id>
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    fwrite(STDERR, "usage: php check_worker.php <job_id>\n");
    exit(2);
}

require dirname(__DIR__) . '/config.php';

$job = job_read($jobId);
if (!$job) {
    fwrite(STDERR, "job not found\n");
    exit(3);
}

$job['status'] = 'running';
$job['started_at'] = $job['started_at'] ?: date('c');
$job['pid'] = getmypid();
$job['message'] = '运行中';
$job['heartbeat_at'] = date('c');
job_append_log($job, 'worker pid=' . getmypid() . ' 开始');
job_write($jobId, $job);

// 忽略用户中断，尽量写完状态
if (function_exists('ignore_user_abort')) ignore_user_abort(true);
@set_time_limit(0);

$ids = $job['ids'] ?? [];
$total = count($ids);
$concurrency = max(1, min(50, (int)($job['concurrency'] ?? setting_int('check_concurrency', 20))));
$t0 = microtime(true);

$live = (int)($job['live'] ?? 0);
$dead = (int)($job['dead'] ?? 0);
$unknown = (int)($job['unknown'] ?? 0);
$refreshed = (int)($job['refreshed'] ?? 0);
$done = (int)($job['done'] ?? 0);
$cursor = (int)($job['cursor'] ?? 0);
$mode = (string)($job['mode'] ?? ($job['type'] ?? 'check'));
$forceImap = array_key_exists('imap_probe', $job) ? (bool)$job['imap_probe'] : setting_bool('check_imap_probe');
$forceUpdate = array_key_exists('update_token', $job) ? (bool)$job['update_token'] : setting_bool('check_update_token');
if ($mode === 'refresh') {
    $forceImap = false;
    $forceUpdate = true;
}
$engineOpts = [
    'force_imap' => $forceImap,
    'force_update_token' => $forceUpdate,
];
job_append_log($job, 'worker 参数 mode=' . $mode . ' imap=' . ($forceImap ? '1' : '0') . ' update_token=' . ($forceUpdate ? '1' : '0'));
job_write($jobId, $job);

try {
    while ($cursor < $total) {
        // 刷新取消状态
        $job = job_read($jobId) ?: $job;
        if (!empty($job['cancel_requested'])) {
            $job['status'] = 'cancelled';
            $job['finished_at'] = date('c');
            $job['message'] = '已取消';
            $job['done'] = $done;
            $job['live'] = $live;
            $job['dead'] = $dead;
            $job['unknown'] = $unknown;
            $job['cursor'] = $cursor;
            $job['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
            $job['speed'] = $done > 0 ? round($done / max((microtime(true) - $t0), 0.001), 2) : 0;
            job_append_log($job, "用户取消 · 已完成 {$done}/{$total}");
            job_write($jobId, $job);
            exit(0);
        }

        $chunkIds = array_slice($ids, $cursor, $concurrency);
        if (!$chunkIds) break;

        // 拉账号行，保持顺序
        $placeholders = implode(',', array_fill(0, count($chunkIds), '?'));
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id IN ($placeholders)");
        $stmt->execute(array_map('intval', $chunkIds));
        $rows = $stmt->fetchAll();
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;
        $ordered = [];
        foreach ($chunkIds as $id) {
            if (isset($byId[(int)$id])) $ordered[] = $byId[(int)$id];
        }

        job_append_log($job, '分片开始 size=' . count($ordered) . ' cursor=' . $cursor);
        $job['message'] = '验活中 ' . ($done + 1) . '/' . $total;
        $job['status'] = 'running';
        $job['heartbeat_at'] = date('c');
        $job['pid'] = getmypid();
        job_write($jobId, $job);

        $tChunk = microtime(true);
        $results = check_accounts_parallel($db, $ordered, $engineOpts);
        $chunkMs = (int)round((microtime(true) - $tChunk) * 1000);

        // 分片期间 cancel 可能已写入磁盘：先重读再合并，避免覆盖 cancel_requested
        $fresh = job_read($jobId);
        if (is_array($fresh)) $job = $fresh;

        foreach ($results as $r) {
            $st = $r['status'] ?? 'dead';
            if ($st === 'live') $live++;
            elseif ($st === 'unknown') $unknown++;
            else $dead++;
            // 粗略：有新 RT 行时 check_engine 会写库；用 stage/oauth 失败区分
            if ($st === 'live' || ($st !== 'dead' && empty($r['error']))) {
                // no-op
            }
            if (!empty($r['line']) && $forceUpdate && $st === 'live') {
                $refreshed++;
            } elseif ($mode === 'refresh' && $st === 'live') {
                $refreshed++;
            }
            $done++;
            $line = ($r['email'] ?? ('#' . ($r['id'] ?? '?'))) . ' => ' . strtoupper($st);
            if (!empty($r['error'])) $line .= ' | ' . $r['error'];
            if (!empty($r['stage'])) $line .= ' [' . $r['stage'] . ']';
            job_append_log($job, $line);
        }

        // 缺结果兜底
        if (count($results) < count($ordered)) {
            $miss = count($ordered) - count($results);
            $dead += $miss;
            $done += $miss;
            job_append_log($job, "分片缺 {$miss} 条结果");
        }

        $cursor += count($chunkIds);
        $elapsed = microtime(true) - $t0;
        $job['cursor'] = $cursor;
        $job['done'] = $done;
        $job['live'] = $live;
        $job['dead'] = $dead;
        $job['unknown'] = $unknown;
        $job['refreshed'] = $refreshed;
        $job['elapsed_ms'] = (int)round($elapsed * 1000);
        $job['speed'] = $done > 0 ? round($done / max($elapsed, 0.001), 2) : 0;
        $job['message'] = ($mode === 'refresh' ? '刷新令牌 ' : '验活 ') . "{$done}/{$total}";
        // 若已请求取消，不要把 status 写回 running
        if (!empty($job['cancel_requested'])) {
            $job['status'] = 'cancelling';
        } else {
            $job['status'] = 'running';
        }
        job_append_log($job, "— 分片 " . count($ordered) . " 完成 {$chunkMs}ms · 累计 {$done}/{$total} · {$job['speed']}/s");
        job_write($jobId, $job);

        if (!empty($job['cancel_requested'])) {
            $job['status'] = 'cancelled';
            $job['finished_at'] = date('c');
            $job['message'] = '已取消';
            job_append_log($job, "用户取消 · 已完成 {$done}/{$total}");
            job_write($jobId, $job);
            exit(0);
        }
    }

    $job = job_read($jobId) ?: $job;
    if (!empty($job['cancel_requested']) && ($job['status'] ?? '') !== 'done') {
        $job['status'] = 'cancelled';
        $job['message'] = '已取消';
    } else {
        $job['status'] = 'done';
        $job['message'] = '完成';
    }
    $job['finished_at'] = date('c');
    $job['done'] = $done;
    $job['live'] = $live;
    $job['dead'] = $dead;
    $job['unknown'] = $unknown;
    $job['refreshed'] = $refreshed;
    $job['cursor'] = $cursor;
    $job['elapsed_ms'] = (int)round((microtime(true) - $t0) * 1000);
    $job['speed'] = $done > 0 ? round($done / max((microtime(true) - $t0), 0.001), 2) : 0;
    job_append_log($job, "任务结束 status={$job['status']} mode={$mode} live={$live} dead={$dead} refreshed={$refreshed} speed={$job['speed']}/s");
    job_write($jobId, $job);
    exit(0);
} catch (Throwable $e) {
    $job = job_read($jobId) ?: $job;
    $job['status'] = 'error';
    $job['error'] = $e->getMessage();
    $job['finished_at'] = date('c');
    $job['message'] = '失败: ' . $e->getMessage();
    job_append_log($job, '异常: ' . $e->getMessage());
    job_write($jobId, $job);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(10);
}
