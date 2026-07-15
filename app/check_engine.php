<?php
/**
 * 真并发验活引擎（curl_multi）
 * 解决 PHP 内置服务器单线程导致前端并发被串行的问题。
 */

/**
 * 创建 OAuth refresh curl handle（未 exec）
 */
function make_token_refresh_handle(string $client_id, string $refresh_token) {
    $ch = curl_init(oauth_url('token'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $client_id,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]),
        CURLOPT_HEADER         => false,
    ]);
    curl_apply_defaults($ch);
    return $ch;
}

/**
 * 解析 token 响应
 */
function parse_token_refresh_result($ch, $body): array {
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $body === null) {
        return ['ok' => false, 'error' => '网络错误: ' . $err];
    }
    $res = json_decode((string)$body, true);
    if (!is_array($res)) {
        return ['ok' => false, 'error' => 'Token 响应无法解析 HTTP=' . $code];
    }
    if (empty($res['access_token'])) {
        $desc = $res['error_description'] ?? ($res['error'] ?? 'token 刷新失败');
        if (is_string($desc) && strlen($desc) > 300) $desc = substr($desc, 0, 300) . '...';
        return ['ok' => false, 'error' => (string)$desc];
    }
    return [
        'ok'            => true,
        'access_token'  => $res['access_token'],
        'refresh_token' => $res['refresh_token'] ?? null,
    ];
}

/**
 * IMAP 探活 curl handle（XOAUTH2，可进 multi）
 */
function make_imap_probe_handle(string $email, string $access_token, string $host, int $port = 993) {
    // imaps://user@host/ — USERNAME + XOAUTH2_BEARER
    $url = 'imaps://' . $host . ':' . $port . '/INBOX';
    $ch = curl_init($url);
    $timeout = setting_int('imap_timeout', 15);
    $ctimeout = setting_int('connect_timeout', 12);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERNAME       => $email,
        CURLOPT_XOAUTH2_BEARER => $access_token,
        CURLOPT_LOGIN_OPTIONS  => 'AUTH=XOAUTH2',
        CURLOPT_CUSTOMREQUEST  => 'EXAMINE INBOX',
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $ctimeout,
        CURLOPT_HEADER         => false,
    ]);

    // SSL
    $ca = ca_bundle_path();
    $verify = setting_bool('ssl_verify');
    if ($ca !== '' && $verify) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    curl_apply_proxy($ch);
    return $ch;
}

/**
 * 并行执行一组 curl handle
 * @param array<int|string, resource> $handles
 * @return array<int|string, array{body:string|false, err:string, code:int}>
 */
function curl_multi_run(array $handles): array {
    if (!$handles) return [];
    $mh = curl_multi_init();
    foreach ($handles as $ch) {
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $key => $ch) {
        $out[$key] = [
            'body' => curl_multi_getcontent($ch),
            'err'  => curl_error($ch),
            'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * 并行验活一批账号（OAuth multi → IMAP multi）
 * @param array $accounts DB 行
 * @return array 结果列表（与输入同序）
 */
/**
 * @param array $opts force_imap / force_update_token 可覆盖全局设置
 */
function check_accounts_parallel(PDO $db, array $accounts, array $opts = []): array {
    $now = date('Y-m-d H:i:s');
    $delim = (string)setting_get_fresh('export_delimiter', '----');
    if ($delim === '') $delim = '----';
    $doImap = array_key_exists('force_imap', $opts)
        ? (bool)$opts['force_imap']
        : setting_bool('check_imap_probe');
    $updateToken = array_key_exists('force_update_token', $opts)
        ? (bool)$opts['force_update_token']
        : setting_bool('check_update_token');
    $imapFailDead = setting_bool('check_mark_dead_on_imap_fail');
    $hosts = imap_host_list();
    $port = setting_int('imap_port', 993);
    $primaryHost = $hosts[0] ?? 'outlook.office365.com';

    $results = []; // id => result skeleton
    $tokenHandles = [];
    $accByKey = [];

    foreach ($accounts as $i => $acc) {
        $id = (int)$acc['id'];
        $email = (string)$acc['email'];
        $key = $id;

        if (empty($acc['client_id']) || empty($acc['refresh_token'])) {
            $err = '缺少 OAuth 凭证（client_id / refresh_token）';
            $db->prepare("UPDATE accounts SET status='dead', last_check_at=?, last_error=? WHERE id=?")
                ->execute([$now, $err, $id]);
            $results[$key] = ['id' => $id, 'email' => $email, 'status' => 'dead', 'error' => $err, 'stage' => 'oauth'];
            continue;
        }

        $tokenHandles[$key] = make_token_refresh_handle((string)$acc['client_id'], (string)$acc['refresh_token']);
        $accByKey[$key] = $acc;
    }

    // ---- 阶段1：并行 OAuth ----
    $tokenRaw = curl_multi_run($tokenHandles);

    $imapHandles = [];
    $tokenOk = []; // key => tok

    foreach ($accByKey as $key => $acc) {
        $id = (int)$acc['id'];
        $email = (string)$acc['email'];
        $raw = $tokenRaw[$key] ?? ['body' => false, 'err' => 'no handle', 'code' => 0];
        // re-parse using a dummy: we already closed ch; parse from body/err/code
        $tok = parse_token_body($raw['body'], $raw['err'], $raw['code']);
        if (!$tok['ok']) {
            $err = $tok['error'] ?? 'token 失败';
            $db->prepare("UPDATE accounts SET status='dead', last_check_at=?, last_error=? WHERE id=?")
                ->execute([$now, $err, $id]);
            $results[$key] = ['id' => $id, 'email' => $email, 'status' => 'dead', 'error' => $err, 'stage' => 'oauth'];
            continue;
        }

        $rt = $acc['refresh_token'];
        if ($updateToken && !empty($tok['refresh_token']) && $tok['refresh_token'] !== $acc['refresh_token']) {
            $db->prepare("UPDATE accounts SET refresh_token=? WHERE id=?")->execute([$tok['refresh_token'], $id]);
            $rt = $tok['refresh_token'];
        }
        $tokenOk[$key] = ['tok' => $tok, 'rt' => $rt, 'acc' => $acc];

        if ($doImap) {
            $imapHandles[$key] = make_imap_probe_handle($email, $tok['access_token'], $primaryHost, $port);
        }
    }

    // ---- 阶段2：并行 IMAP（可选）----
    $imapRaw = $doImap ? curl_multi_run($imapHandles) : [];

    foreach ($tokenOk as $key => $pack) {
        $acc = $pack['acc'];
        $id = (int)$acc['id'];
        $email = (string)$acc['email'];
        $rt = $pack['rt'];
        $host = '';

        if ($doImap) {
            $ir = $imapRaw[$key] ?? ['body' => false, 'err' => 'imap missing', 'code' => 0];
            $imapOk = imap_probe_result_ok($ir);
            if (!$imapOk) {
                // 主 host 失败时，串行尝试备用 host（少见，不拖垮整体）
                $okAlt = false;
                foreach (array_slice($hosts, 1) as $h) {
                    $ch = make_imap_probe_handle($email, $pack['tok']['access_token'], $h, $port);
                    $body = curl_exec($ch);
                    $one = ['body' => $body, 'err' => curl_error($ch), 'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE)];
                    curl_close($ch);
                    if (imap_probe_result_ok($one)) {
                        $okAlt = true;
                        $host = $h;
                        break;
                    }
                }
                if (!$okAlt) {
                    $err = trim(($ir['err'] ?? '') !== '' ? $ir['err'] : 'IMAP 认证失败');
                    if ($err === '') $err = 'IMAP 认证失败';
                    $status = $imapFailDead ? 'dead' : 'unknown';
                    $db->prepare("UPDATE accounts SET status=?, last_check_at=?, last_error=? WHERE id=?")
                        ->execute([$status, $now, $err, $id]);
                    $results[$key] = ['id' => $id, 'email' => $email, 'status' => $status, 'error' => $err, 'stage' => 'imap'];
                    continue;
                }
            } else {
                $host = $primaryHost;
            }
        }

        $db->prepare("UPDATE accounts SET status='live', last_check_at=?, last_error=NULL WHERE id=?")
            ->execute([$now, $id]);
        $results[$key] = [
            'id' => $id,
            'email' => $email,
            'status' => 'live',
            'host' => $host,
            'imap_probed' => $doImap,
            'line' => implode($delim, [$email, $acc['password'] ?? '', $acc['client_id'] ?? '', $rt]),
        ];
    }

    // 按输入顺序返回
    $ordered = [];
    foreach ($accounts as $acc) {
        $id = (int)$acc['id'];
        if (isset($results[$id])) $ordered[] = $results[$id];
    }
    return $ordered;
}

function parse_token_body($body, string $err, int $code): array {
    if ($body === false || $body === null) {
        return ['ok' => false, 'error' => '网络错误: ' . $err];
    }
    $res = json_decode((string)$body, true);
    if (!is_array($res)) {
        return ['ok' => false, 'error' => 'Token 响应无法解析 HTTP=' . $code];
    }
    if (empty($res['access_token'])) {
        $desc = $res['error_description'] ?? ($res['error'] ?? 'token 刷新失败');
        if (is_string($desc) && strlen($desc) > 300) $desc = substr($desc, 0, 300) . '...';
        return ['ok' => false, 'error' => (string)$desc];
    }
    return [
        'ok' => true,
        'access_token' => $res['access_token'],
        'refresh_token' => $res['refresh_token'] ?? null,
    ];
}

function imap_probe_result_ok(array $ir): bool {
    // curl IMAP 成功时通常 http_code=0 但无 error；失败有 curl error
    if (!empty($ir['err'])) {
        // 部分环境 EXAMINE 会返回奇怪错误但仍登录成功；看关键词
        $e = strtolower($ir['err']);
        if (str_contains($e, 'login denied') || str_contains($e, 'auth') || str_contains($e, 'authentication') || str_contains($e, 'could not connect') || str_contains($e, 'timeout') || str_contains($e, 'proxy')) {
            return false;
        }
        // 其它错误偏失败
        return false;
    }
    return true;
}
