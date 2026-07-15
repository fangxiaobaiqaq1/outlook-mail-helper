<?php
/**
 * API 接口
 */
require __DIR__ . '/config.php';
check_auth();
global $db;

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ---------- 列表（轻量，不含 token/密码，1 万账号也不炸前端） ----------
if ($action === 'list') {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? 'all')); // all|live|dead|unknown
    $group = account_group_normalize($_GET['group'] ?? 'all'); // all|hotmail|outlook|other
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = (int)($_GET['page_size'] ?? 100);
    if ($pageSize < 20) $pageSize = 20;
    if ($pageSize > 300) $pageSize = 300;

    $clause = accounts_filter_clause(['status' => $status, 'group' => $group, 'q' => $q]);
    $sqlWhere = $clause['sql'];
    $params = $clause['params'];

    $stCount = $db->prepare("SELECT COUNT(*) FROM accounts $sqlWhere");
    $stCount->execute($params);
    $filteredTotal = (int)$stCount->fetchColumn();

    // 全局统计（轻量聚合，不拖全表到 PHP）
    $statsRow = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='live' THEN 1 ELSE 0 END) AS live,
        SUM(CASE WHEN status='dead' THEN 1 ELSE 0 END) AS dead,
        SUM(CASE WHEN mail_group='hotmail' THEN 1 ELSE 0 END) AS g_hotmail,
        SUM(CASE WHEN mail_group='outlook' THEN 1 ELSE 0 END) AS g_outlook,
        SUM(CASE WHEN mail_group='other' THEN 1 ELSE 0 END) AS g_other
      FROM accounts")->fetch();
    $totalAll = (int)($statsRow['total'] ?? 0);
    $liveAll = (int)($statsRow['live'] ?? 0);
    $deadAll = (int)($statsRow['dead'] ?? 0);
    $unkAll = max(0, $totalAll - $liveAll - $deadAll);

    $offset = ($page - 1) * $pageSize;
    $st = $db->prepare("SELECT id, email, remark, status, mail_group, last_check_at, last_error, created_at,
        CASE WHEN IFNULL(password,'') != '' THEN 1 ELSE 0 END AS has_password,
        CASE WHEN IFNULL(refresh_token,'') != '' THEN 1 ELSE 0 END AS has_token
      FROM accounts $sqlWhere
      ORDER BY id DESC
      LIMIT $pageSize OFFSET $offset");
    $st->execute($params);
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $mg = (string)($r['mail_group'] ?? '');
        if (!in_array($mg, account_group_values(), true)) {
            $mg = account_group_from_email((string)$r['email']);
        }
        $out[] = [
            'id' => (int)$r['id'],
            'email' => $r['email'],
            'remark' => $r['remark'],
            'status' => $r['status'] ?: 'unknown',
            'mail_group' => $mg,
            'last_check_at' => $r['last_check_at'],
            'last_error' => $r['last_error'] ? mb_substr((string)$r['last_error'], 0, 80) : null,
            'created_at' => $r['created_at'],
            'has_password' => !empty($r['has_password']),
            'has_token' => !empty($r['has_token']),
        ];
    }
    json_out([
        'ok' => true,
        'data' => $out,
        'page' => $page,
        'page_size' => $pageSize,
        'filtered_total' => $filteredTotal,
        'total_pages' => max(1, (int)ceil($filteredTotal / $pageSize)),
        'group' => $group,
        'stats' => [
            'total' => $totalAll,
            'live' => $liveAll,
            'dead' => $deadAll,
            'unknown' => $unkAll,
            'groups' => [
                'hotmail' => (int)($statsRow['g_hotmail'] ?? 0),
                'outlook' => (int)($statsRow['g_outlook'] ?? 0),
                'other' => (int)($statsRow['g_other'] ?? 0),
            ],
        ],
        'csrf' => ($_SESSION['csrf'] ?? ''),
    ]);
}

// ---------- 单账号完整信息（编辑用） ----------
if ($action === 'get_account') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id=?");
    $stmt->execute([$id]);
    $acc = $stmt->fetch();
    if (!$acc) json_out(['ok' => false, 'error' => '账号不存在'], 404);
    json_out(['ok' => true, 'data' => $acc]);
}

// ---------- 仅 ID 列表（全选当前筛选用，轻量） ----------
if ($action === 'list_ids') {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? 'all'));
    $group = account_group_normalize($_GET['group'] ?? 'all');
    $clause = accounts_filter_clause(['status' => $status, 'group' => $group, 'q' => $q]);
    $st = $db->prepare("SELECT id FROM accounts {$clause['sql']} ORDER BY id DESC");
    $st->execute($clause['params']);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    json_out(['ok' => true, 'ids' => $ids, 'count' => count($ids), 'group' => $group]);
}

// ---------- 添加（单条） ----------
if ($action === 'add') {
    $raw = trim($_POST['raw_data'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $parsed = parse_account_line($raw);
    if (!$parsed) {
        json_out(['ok' => false, 'error' => '格式错误，需要: 邮箱----密码----ClientID----RefreshToken'], 400);
    }
    if ($remark !== '') $parsed['remark'] = $remark;

    // 同邮箱更新
    $exists = $db->prepare("SELECT id FROM accounts WHERE email = ?");
    $exists->execute([$parsed['email']]);
    $old = $exists->fetch();
    $mailGroup = account_group_from_email((string)$parsed['email']);
    if ($old) {
        $stmt = $db->prepare("UPDATE accounts SET password=?, client_id=?, refresh_token=?, remark=?, status='unknown', last_error=NULL, mail_group=? WHERE id=?");
        $stmt->execute([$parsed['password'], $parsed['client_id'], $parsed['refresh_token'], $parsed['remark'], $mailGroup, $old['id']]);
        json_out(['ok' => true, 'updated' => true, 'id' => (int)$old['id'], 'mail_group' => $mailGroup]);
    }

    $stmt = $db->prepare("INSERT INTO accounts (email, password, client_id, refresh_token, remark, status, mail_group) VALUES (?,?,?,?,?, 'unknown', ?)");
    $stmt->execute([$parsed['email'], $parsed['password'], $parsed['client_id'], $parsed['refresh_token'], $parsed['remark'], $mailGroup]);
    json_out(['ok' => true, 'id' => (int)$db->lastInsertId(), 'mail_group' => $mailGroup]);
}

// ---------- 批量文本导入（支持前端分块；单请求上限 32MB 文本，总包无上限） ----------
if ($action === 'import_text') {
    $text = (string)($_POST['text'] ?? '');
    $lineOffset = max(0, (int)($_POST['line_offset'] ?? 0));
    $chunkIndex = max(0, (int)($_POST['chunk_index'] ?? 0));
    $chunkTotal = max(0, (int)($_POST['chunk_total'] ?? 0));
    // 也允许 JSON body
    if ($text === '') {
        $rawBody = file_get_contents('php://input');
        if ($rawBody) {
            $j = json_decode($rawBody, true);
            if (is_array($j)) {
                if (isset($j['text'])) $text = (string)$j['text'];
                if (isset($j['line_offset'])) $lineOffset = max(0, (int)$j['line_offset']);
                if (isset($j['chunk_index'])) $chunkIndex = max(0, (int)$j['chunk_index']);
                if (isset($j['chunk_total'])) $chunkTotal = max(0, (int)$j['chunk_total']);
            } elseif ($rawBody !== '' && $rawBody[0] !== '{') {
                $text = $rawBody;
            }
        }
    }
    if ($text === '') json_out(['ok' => false, 'error' => '文本为空'], 400);
    // 单请求保护：前端应分块；32MB ≈ 6 万行左右
    $maxChunk = 32 * 1024 * 1024;
    if (strlen($text) > $maxChunk) {
        json_out([
            'ok' => false,
            'error' => '单次请求过大（>' . (int)($maxChunk / 1024 / 1024) . 'MB）。请用最新前端分块导入，或把文件拆小。',
            'hint' => 'chunked',
        ], 400);
    }

    $lines = preg_split('/\r\n|\r|\n/', $text);
    // 丢掉末尾空行，避免分块拼接产生伪失败
    while ($lines && trim((string)end($lines)) === '') array_pop($lines);

    try {
        $res = import_account_batch($db, $lines, $lineOffset);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => '导入异常: ' . $e->getMessage()], 500);
    }
    $res['ok'] = true;
    $res['chunk_index'] = $chunkIndex;
    $res['chunk_total'] = $chunkTotal;
    $res['line_offset'] = $lineOffset;
    json_out($res);
}

// ---------- 更新 ----------
if ($action === 'update_account') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_out(['ok' => false, 'error' => 'ID无效'], 400);

    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    $cid   = trim($_POST['client_id'] ?? '');
    $token = trim($_POST['refresh_token'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $mailGroup = account_group_from_email($email);

    $stmt = $db->prepare("UPDATE accounts SET email=?, password=?, client_id=?, refresh_token=?, remark=?, mail_group=? WHERE id=?");
    $stmt->execute([$email, $pass, $cid, $token, $remark, $mailGroup, $id]);
    json_out(['ok' => true, 'mail_group' => $mailGroup]);
}

// ---------- 删除 ----------
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM accounts WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
}

// ---------- 批量删除 ----------
// 支持: ids[] / scope=selected|all|live|dead|unknown，可选 group=all|hotmail|outlook|other
if ($action === 'delete_batch') {
    $input = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) $input = array_merge($input, $j);
    }
    $scope = (string)($input['scope'] ?? 'selected');
    $group = account_group_normalize($input['group'] ?? 'all');
    $ids = $input['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];

    if (in_array($scope, ['all', 'live', 'dead', 'unknown'], true)) {
        $status = $scope === 'all' ? 'all' : $scope;
        $clause = accounts_filter_clause(['status' => $status, 'group' => $group, 'q' => '']);
        $n = $db->prepare("DELETE FROM accounts {$clause['sql']}");
        $n->execute($clause['params']);
        json_out(['ok' => true, 'deleted' => $n->rowCount(), 'scope' => $scope, 'group' => $group]);
    }

    if (!is_array($ids) || !$ids) json_out(['ok' => false, 'error' => '未选择账号'], 400);
    $stmt = $db->prepare("DELETE FROM accounts WHERE id=?");
    $n = 0;
    foreach ($ids as $id) {
        $stmt->execute([(int)$id]);
        $n += $stmt->rowCount();
    }
    json_out(['ok' => true, 'deleted' => $n, 'scope' => 'selected']);
}

// ---------- 批量备注 ----------
// mode: set | append | clear
if ($action === 'remark_batch') {
    $input = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) $input = array_merge($input, $j);
    }
    $ids = $input['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    $scope = (string)($input['scope'] ?? 'selected');
    $mode = (string)($input['mode'] ?? 'set'); // set|append|clear
    $remark = (string)($input['remark'] ?? '');

    if (!in_array($mode, ['set', 'append', 'clear'], true)) {
        json_out(['ok' => false, 'error' => 'mode 无效'], 400);
    }

    // resolve ids by scope（可选 group 维）
    if (in_array($scope, ['all', 'live', 'dead', 'unknown'], true)) {
        $group = account_group_normalize($input['group'] ?? 'all');
        $status = $scope === 'all' ? 'all' : $scope;
        $clause = accounts_filter_clause(['status' => $status, 'group' => $group, 'q' => '']);
        $st = $db->prepare("SELECT id FROM accounts {$clause['sql']}");
        $st->execute($clause['params']);
        $ids = array_column($st->fetchAll(), 'id');
    }
    if (!is_array($ids) || !$ids) json_out(['ok' => false, 'error' => '未选择账号'], 400);
    $ids = array_values(array_unique(array_map('intval', $ids)));

    $n = 0;
    if ($mode === 'clear') {
        $stmt = $db->prepare("UPDATE accounts SET remark='' WHERE id=?");
        foreach ($ids as $id) { $stmt->execute([$id]); $n += $stmt->rowCount(); }
    } elseif ($mode === 'append') {
        $sel = $db->prepare("SELECT remark FROM accounts WHERE id=?");
        $upd = $db->prepare("UPDATE accounts SET remark=? WHERE id=?");
        foreach ($ids as $id) {
            $sel->execute([$id]);
            $old = (string)($sel->fetchColumn() ?: '');
            $next = trim($old === '' ? $remark : ($old . ' | ' . $remark));
            $upd->execute([$next, $id]);
            $n += $upd->rowCount();
        }
    } else { // set
        $stmt = $db->prepare("UPDATE accounts SET remark=? WHERE id=?");
        foreach ($ids as $id) { $stmt->execute([$remark, $id]); $n += $stmt->rowCount(); }
    }
    json_out(['ok' => true, 'updated' => $n, 'mode' => $mode, 'scope' => $scope, 'count' => count($ids)]);
}

// ---------- 单号验活 ----------
if ($action === 'check_one') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id=?");
    $stmt->execute([$id]);
    $acc = $stmt->fetch();
    if (!$acc) json_out(['ok' => false, 'error' => '账号不存在'], 404);

    $result = do_check_account($db, $acc);
    json_out(['ok' => true, 'result' => $result]);
}

// ---------- 后台任务：启动验活（立即返回，不阻塞） ----------
if ($action === 'job_start_check') {
    $input = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) $input = array_merge($input, $j);
    }

    $scope = $input['scope'] ?? 'filtered'; // filtered|all|ids|live|dead|unknown
    $group = account_group_normalize($input['group'] ?? 'all');
    $ids = $input['ids'] ?? null;
    if (is_string($ids)) $ids = json_decode($ids, true);

    if (is_array($ids) && $ids) {
        $idList = array_map('intval', $ids);
    } else {
        // 防御性：无 ids 时按 scope×group 过滤（前端正常路径总是传 ids）
        $status = in_array($scope, ['live', 'dead', 'unknown'], true) ? $scope : 'all';
        $clause = accounts_filter_clause(['status' => $status, 'group' => $group, 'q' => '']);
        $st = $db->prepare("SELECT id FROM accounts {$clause['sql']} ORDER BY id ASC");
        $st->execute($clause['params']);
        $idList = array_map('intval', array_column($st->fetchAll(), 'id'));
    }

    $concurrency = isset($input['concurrency']) ? (int)$input['concurrency'] : setting_int('check_concurrency', 20);
    $mode = (string)($input['mode'] ?? 'check'); // check | refresh
    $opts = ['concurrency' => $concurrency, 'mode' => $mode];
    if (array_key_exists('imap_probe', $input)) {
        $opts['imap_probe'] = !empty($input['imap_probe']) && $input['imap_probe'] !== '0' && $input['imap_probe'] !== false;
    }
    if (array_key_exists('update_token', $input)) {
        $opts['update_token'] = !empty($input['update_token']) && $input['update_token'] !== '0' && $input['update_token'] !== false;
    }
    if ($mode === 'refresh') {
        $opts['imap_probe'] = false;
        $opts['update_token'] = true;
    }
    $res = job_start_check($db, $idList, $opts);
    json_out($res, !empty($res['ok']) ? 200 : 409);
}

if ($action === 'job_status') {
    $jobId = (string)($_GET['job_id'] ?? $_POST['job_id'] ?? '');
    if ($jobId === '') {
        $active = job_find_active();
        if ($active) {
            json_out(['ok' => true, 'job' => job_public_view($active), 'active' => true]);
        }
        json_out(['ok' => true, 'job' => null, 'active' => false]);
    }
    $job = job_read($jobId);
    if (!$job) json_out(['ok' => false, 'error' => '任务不存在'], 404);
    json_out(['ok' => true, 'job' => job_public_view($job), 'active' => in_array($job['status'] ?? '', ['queued','running','cancelling'], true)]);
}

if ($action === 'job_cancel') {
    $jobId = (string)($_POST['job_id'] ?? $_GET['job_id'] ?? '');
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j) && !empty($j['job_id'])) $jobId = (string)$j['job_id'];
    }
    if ($jobId === '') {
        $active = job_find_active();
        if ($active) $jobId = (string)$active['id'];
    }
    if ($jobId === '') json_out(['ok' => false, 'error' => '没有运行中的任务'], 404);
    json_out(job_request_cancel($jobId));
}

if ($action === 'job_list') {
    json_out(['ok' => true, 'jobs' => job_list_recent(15)]);
}

// ---------- 批量验活（兼容：全量串行旧接口，不推荐） ----------
if ($action === 'check_batch') {
    $active = job_find_active();
    if ($active) {
        json_out(['ok'=>false,'error'=>'后台验活任务运行中','job'=>job_public_view($active)], 409);
    }
    $ids = $_POST['ids'] ?? null;
    if (is_string($ids)) $ids = json_decode($ids, true);

    if (is_array($ids) && count($ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id IN ($placeholders)");
        $stmt->execute(array_map('intval', $ids));
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query("SELECT * FROM accounts ORDER BY id ASC")->fetchAll();
    }

    // 走真并行引擎
    $all = check_accounts_parallel($db, $rows);
    $live = array_values(array_filter($all, fn($r) => ($r['status'] ?? '') === 'live'));
    $dead = array_values(array_filter($all, fn($r) => ($r['status'] ?? '') !== 'live'));
    json_out([
        'ok' => true,
        'total' => count($rows),
        'live_count' => count($live),
        'dead_count' => count($dead),
        'live' => $live,
        'dead' => $dead,
        'results' => $all,
        'engine' => 'curl_multi',
    ]);
}

// ---------- 分片真并发验活（推荐：前端按并发切片调用） ----------
if ($action === 'check_chunk') {
    $active = job_find_active();
    if ($active) {
        json_out(['ok'=>false,'error'=>'后台验活任务运行中，请用任务面板','job'=>job_public_view($active)], 409);
    }
    $ids = $_POST['ids'] ?? null;
    if (is_string($ids)) $ids = json_decode($ids, true);
    if (!is_array($ids) || !$ids) {
        // 也支持 JSON body
        $raw = file_get_contents('php://input');
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j) && !empty($j['ids'])) $ids = $j['ids'];
    }
    if (!is_array($ids) || !$ids) {
        json_out(['ok' => false, 'error' => 'ids 必填'], 400);
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    // 单次分片上限，防请求过大
    $maxChunk = max(1, min(100, setting_int('check_concurrency', 20) * 2));
    if (count($ids) > $maxChunk) {
        $ids = array_slice($ids, 0, $maxChunk);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    // 保持传入顺序
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) $ordered[] = $byId[$id];
    }

    $t0 = microtime(true);
    $results = check_accounts_parallel($db, $ordered);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    $live = 0; $dead = 0;
    foreach ($results as $r) {
        if (($r['status'] ?? '') === 'live') $live++; else $dead++;
    }

    json_out([
        'ok' => true,
        'engine' => 'curl_multi',
        'chunk_size' => count($ordered),
        'elapsed_ms' => $ms,
        'live_count' => $live,
        'dead_count' => $dead,
        'results' => $results,
    ]);
}

// ---------- 导出 live / dead / all（可选 group） ----------
if ($action === 'export_txt') {
    $type = $_GET['type'] ?? 'all'; // all | live | dead
    $group = account_group_normalize($_GET['group'] ?? 'all');
    $status = in_array($type, ['live', 'dead'], true) ? $type : 'all';
    $clause = accounts_filter_clause(['status' => $status, 'group' => $group, 'q' => '']);
    $st = $db->prepare("SELECT * FROM accounts {$clause['sql']} ORDER BY id");
    $st->execute($clause['params']);
    $rows = $st->fetchAll();

    $delim = (string)setting_get_fresh('export_delimiter', '----');
    if ($delim === '') $delim = '----';
    $lines = [];
    foreach ($rows as $r) {
        $lines[] = implode($delim, [
            $r['email'],
            $r['password'] ?? '',
            $r['client_id'] ?? '',
            $r['refresh_token'] ?? '',
        ]);
    }
    $gTag = $group === 'all' ? $type : "{$group}_{$type}";
    $filename = "outlook_{$gTag}_" . date('Ymd_His') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo implode("\r\n", $lines);
    exit;
}

if ($action === 'export') {
    $group = account_group_normalize($_GET['group'] ?? 'all');
    $clause = accounts_filter_clause(['status' => 'all', 'group' => $group, 'q' => '']);
    $st = $db->prepare("SELECT * FROM accounts {$clause['sql']} ORDER BY id");
    $st->execute($clause['params']);
    $data = $st->fetchAll();
    $gTag = $group === 'all' ? 'all' : $group;
    $filename = 'outlook_accounts_' . $gTag . '_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'import') {
    // 兼容旧上传入口；大文件请走前端分块 + import_text
    $raw = '';
    if (!empty($_POST['text'])) {
        $raw = (string)$_POST['text'];
    } elseif (isset($_FILES['file'])) {
        $f = $_FILES['file'];
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE   => '文件超过 php.ini upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE  => '文件超过表单 MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
                UPLOAD_ERR_NO_FILE    => '未选择文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不存在',
                UPLOAD_ERR_CANT_WRITE => '无法写入临时文件',
                UPLOAD_ERR_EXTENSION  => '扩展阻止了上传',
            ];
            $msg = $map[$err] ?? ('上传失败 error=' . $err);
            $msg .= '。建议：用「批量导入」选文件（自动分块），当前 post_max=' . ini_get('post_max_size') . ' upload_max=' . ini_get('upload_max_filesize');
            json_out(['ok' => false, 'error' => $msg], 400);
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            json_out([
                'ok' => false,
                'error' => '上传临时文件无效（可能超过 post_max_size=' . ini_get('post_max_size') . '）。请改用「批量导入」选文件。',
            ], 400);
        }
        $size = (int)@filesize($tmp);
        // 单次 multipart 仍受 php.ini 限制；超过 32MB 直接拒，避免 OOM
        if ($size > 32 * 1024 * 1024) {
            json_out([
                'ok' => false,
                'error' => '单文件上传超过 32MB。请用界面「批量导入」选文件（浏览器本地读 + 自动分块，无总大小上限）。',
                'hint' => 'chunked',
            ], 400);
        }
        $raw = (string)@file_get_contents($tmp);
        if ($raw === '' && $size > 0) {
            json_out(['ok' => false, 'error' => '无法读取上传文件'], 400);
        }
    } else {
        json_out(['ok' => false, 'error' => '未上传文件且未提供 text 字段'], 400);
    }

    $data = json_decode($raw, true);
    try {
        if (is_array($data)) {
            // JSON 数组：整包入批（前端大 JSON 不推荐，但仍兼容）
            if (count($data) > 50000) {
                json_out([
                    'ok' => false,
                    'error' => 'JSON 数组超过 5 万条。请改用 txt 一行一条，界面会自动分块导入。',
                    'hint' => 'use_txt_chunked',
                ], 400);
            }
            $res = import_account_batch($db, $data, 0);
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
            while ($lines && trim((string)end($lines)) === '') array_pop($lines);
            $res = import_account_batch($db, $lines, 0);
        }
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => '导入异常: ' . $e->getMessage()], 500);
    }
    $res['ok'] = true;
    json_out($res);
}

// ---------- 收件 ----------
if ($action === 'get_mails') {
    $id = (int)($_GET['id'] ?? 0);
    $folder = $_GET['folder'] ?? setting_get_fresh('mail_default_folder', 'all');
    $limit = (int)($_GET['limit'] ?? setting_int('mail_default_limit', 20));

    $stmt = $db->prepare("SELECT * FROM accounts WHERE id=?");
    $stmt->execute([$id]);
    $acc = $stmt->fetch();
    if (!$acc) json_out(['ok' => false, 'error' => '账号不存在'], 404);

    $tok = refresh_access_token((string)$acc['client_id'], (string)$acc['refresh_token']);
    if (!$tok['ok']) {
        $db->prepare("UPDATE accounts SET status='dead', last_check_at=?, last_error=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $tok['error'] ?? 'token失效', $id]);
        json_out(['ok' => false, 'error' => 'Token失效: ' . ($tok['error'] ?? '')]);
    }
    if (!empty($tok['refresh_token']) && $tok['refresh_token'] !== $acc['refresh_token']) {
        $db->prepare("UPDATE accounts SET refresh_token=? WHERE id=?")->execute([$tok['refresh_token'], $id]);
    }
    $db->prepare("UPDATE accounts SET status='live', last_check_at=?, last_error=NULL WHERE id=?")
        ->execute([date('Y-m-d H:i:s'), $id]);

    $all = [];
    $folders = $folder === 'all' ? ['INBOX', 'Junk'] : [$folder === 'Junk' ? 'Junk' : 'INBOX'];
    foreach ($folders as $f) {
        $r = fetch_recent_mails((string)$acc['email'], $tok['access_token'], $limit, $f);
        if ($r['ok'] && !empty($r['messages'])) {
            foreach ($r['messages'] as $m) $all[] = $m;
        } elseif (!$r['ok'] && $folder !== 'all') {
            json_out(['ok' => false, 'error' => $r['error'] ?? '收件失败']);
        }
    }

    // 按日期粗排（字符串）
    usort($all, function ($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });

    json_out(['ok' => true, 'value' => $all, 'email' => $acc['email']]);
}

// ---------- 设置 ----------
if ($action === 'get_settings') {
    $data = settings_all($db);
    $admin = $db->query("SELECT username FROM admin WHERE id=1")->fetch();
    $data['admin_user'] = $admin['username'] ?? 'admin';
    // 前端需要的运行时提示
    $data['_meta'] = [
        'groups' => settings_groups(),
        'schema' => settings_schema(),
        'ca_bundle' => ca_bundle_path(),
        'proxy' => get_proxy_config(),
    ];
    json_out(['ok' => true, 'data' => $data]);
}

if ($action === 'get_settings_schema') {
    json_out([
        'ok' => true,
        'groups' => settings_groups(),
        'schema' => settings_schema(),
        'values' => settings_all($db),
    ]);
}

if ($action === 'test_proxy') {
    $r = test_proxy_connection();
    json_out($r);
}

if ($action === 'save_settings') {
    // 支持 form 字段 或 JSON body
    $input = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) $input = array_merge($input, $j);
    }

    $result = settings_save($db, $input);
    if (!$result['ok']) {
        json_out(['ok' => false, 'error' => '设置校验失败', 'errors' => $result['errors'] ?? []], 400);
    }

    if (!empty($input['admin_user'])) {
        $db->prepare("UPDATE admin SET username=? WHERE id=1")->execute([trim((string)$input['admin_user'])]);
    }
    if (!empty($input['admin_pass'])) {
        if (strlen((string)$input['admin_pass']) < 6) {
            json_out(['ok' => false, 'error' => '密码至少 6 位'], 400);
        }
        $new_pass = password_hash((string)$input['admin_pass'], PASSWORD_DEFAULT);
        $db->prepare("UPDATE admin SET password=?, must_change_password=0 WHERE id=1")->execute([$new_pass]);
        $_SESSION['must_change_password'] = false;
    }
    json_out(['ok' => true, 'saved' => $result['saved'] ?? [], 'values' => settings_all($db)]);
}

if ($action === 'change_password') {
    $old = (string)($_POST['old_pass'] ?? '');
    $new = (string)($_POST['new_pass'] ?? '');
    $new2 = (string)($_POST['new_pass2'] ?? '');
    if (strlen($new) < 6) json_out(['ok' => false, 'error' => '新密码至少 6 位'], 400);
    if ($new !== $new2) json_out(['ok' => false, 'error' => '两次密码不一致'], 400);

    $admin = $db->query("SELECT * FROM admin WHERE id=1")->fetch();
    if (!$admin || !password_verify($old, $admin['password'])) {
        json_out(['ok' => false, 'error' => '原密码不正确'], 400);
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare("UPDATE admin SET password=?, must_change_password=0 WHERE id=1")->execute([$hash]);
    $_SESSION['must_change_password'] = false;
    json_out(['ok' => true]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($action === 'tg_backup') {
    $accounts = $db->query("SELECT * FROM accounts ORDER BY id DESC")->fetchAll();
    $json_data = json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $q = $db->query("SELECT * FROM settings");
    $settings = [];
    while ($r = $q->fetch()) $settings[$r['key']] = $r['value'];
    $token = trim($settings['tg_token'] ?? '');
    $chat_id = trim($settings['tg_chatid'] ?? '');
    if ($token === '' || $chat_id === '') {
        json_out(['ok' => false, 'error' => '请先在设置中配置 TG Token 和 ChatID']);
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'backup_');
    file_put_contents($tmpFile, $json_data);
    $url = "https://api.telegram.org/bot{$token}/sendDocument";
    $post_data = [
        'chat_id'  => $chat_id,
        'document' => new CURLFile($tmpFile, 'application/json', 'outlook_backup_' . date('Ymd_His') . '.json'),
        'caption'  => "Outlook 邮箱助手备份\n时间: " . date('Y-m-d H:i:s') . "\n账号: " . count($accounts),
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_apply_defaults($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmpFile);
    if ($err) json_out(['ok' => false, 'error' => 'CURL: ' . $err]);
    $res_arr = json_decode($res, true);
    if ($res_arr && !empty($res_arr['ok'])) json_out(['ok' => true, 'message' => '备份已发送']);
    json_out(['ok' => false, 'error' => 'TG错误: ' . ($res_arr['description'] ?? '未知')]);
}

if ($action === 'stats') {
    $row = $db->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='live' THEN 1 ELSE 0 END) AS live,
        SUM(CASE WHEN status='dead' THEN 1 ELSE 0 END) AS dead,
        SUM(CASE WHEN mail_group='hotmail' THEN 1 ELSE 0 END) AS g_hotmail,
        SUM(CASE WHEN mail_group='outlook' THEN 1 ELSE 0 END) AS g_outlook,
        SUM(CASE WHEN mail_group='other' THEN 1 ELSE 0 END) AS g_other
      FROM accounts")->fetch();
    $total = (int)($row['total'] ?? 0);
    $live  = (int)($row['live'] ?? 0);
    $dead  = (int)($row['dead'] ?? 0);
    $unk   = max(0, $total - $live - $dead);
    json_out([
        'ok' => true,
        'total' => $total,
        'live' => $live,
        'dead' => $dead,
        'unknown' => $unk,
        'groups' => [
            'hotmail' => (int)($row['g_hotmail'] ?? 0),
            'outlook' => (int)($row['g_outlook'] ?? 0),
            'other' => (int)($row['g_other'] ?? 0),
        ],
    ]);
}

// ---------- 运维：按当前规则重算 mail_group ----------
if ($action === 'reclassify_groups') {
    try {
        $res = accounts_reclassify_mail_groups($db, 1000);
        json_out(['ok' => true, 'updated' => $res['updated'], 'total' => $res['total']]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

json_out(['ok' => false, 'error' => 'unknown action: ' . $action], 400);

// ========== helpers ==========
function do_check_account(PDO $db, array $acc): array {
    $id = (int)$acc['id'];
    $email = (string)$acc['email'];
    $now = date('Y-m-d H:i:s');
    $delim = (string)setting_get_fresh('export_delimiter', '----');
    if ($delim === '') $delim = '----';
    $doImap = setting_bool('check_imap_probe');
    $updateToken = setting_bool('check_update_token');
    $imapFailDead = setting_bool('check_mark_dead_on_imap_fail');

    if (empty($acc['client_id']) || empty($acc['refresh_token'])) {
        $err = '缺少 OAuth 凭证（client_id / refresh_token）';
        $db->prepare("UPDATE accounts SET status='dead', last_check_at=?, last_error=? WHERE id=?")
            ->execute([$now, $err, $id]);
        return ['id' => $id, 'email' => $email, 'status' => 'dead', 'error' => $err];
    }

    $tok = refresh_access_token((string)$acc['client_id'], (string)$acc['refresh_token']);
    if (!$tok['ok']) {
        $err = $tok['error'] ?? 'token 失败';
        $db->prepare("UPDATE accounts SET status='dead', last_check_at=?, last_error=? WHERE id=?")
            ->execute([$now, $err, $id]);
        return ['id' => $id, 'email' => $email, 'status' => 'dead', 'error' => $err, 'stage' => 'oauth'];
    }

    $rt = $acc['refresh_token'];
    if ($updateToken && !empty($tok['refresh_token']) && $tok['refresh_token'] !== $acc['refresh_token']) {
        $db->prepare("UPDATE accounts SET refresh_token=? WHERE id=?")->execute([$tok['refresh_token'], $id]);
        $rt = $tok['refresh_token'];
    }

    $host = '';
    if ($doImap) {
        $probe = imap_probe($email, $tok['access_token']);
        if (!$probe['ok']) {
            $err = $probe['error'] ?? 'IMAP失败';
            $status = $imapFailDead ? 'dead' : 'unknown';
            $db->prepare("UPDATE accounts SET status=?, last_check_at=?, last_error=? WHERE id=?")
                ->execute([$status, $now, $err, $id]);
            return ['id' => $id, 'email' => $email, 'status' => $status, 'error' => $err, 'stage' => 'imap'];
        }
        $host = $probe['host'] ?? '';
    }

    $db->prepare("UPDATE accounts SET status='live', last_check_at=?, last_error=NULL WHERE id=?")
        ->execute([$now, $id]);
    return [
        'id' => $id,
        'email' => $email,
        'status' => 'live',
        'host' => $host,
        'imap_probed' => $doImap,
        'line' => implode($delim, [$email, $acc['password'] ?? '', $acc['client_id'] ?? '', $rt]),
    ];
}
