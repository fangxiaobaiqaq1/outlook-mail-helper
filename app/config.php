<?php
/**
 * Outlook 邮箱助手 - 配置与数据库
 * 基于 CN-Root/OutlookPanel (MIT) 二改
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
date_default_timezone_set('Asia/Shanghai');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/settings_schema.php';
require_once __DIR__ . '/check_engine.php';
require_once __DIR__ . '/job_store.php';

$db_dir  = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$db_file = $db_dir . DIRECTORY_SEPARATOR . 'mail.db';

if (!is_dir($db_dir)) {
    @mkdir($db_dir, 0777, true);
}

// 禁止通过 HTTP 直接读 data 目录（内置服务器靠 router 拦）
try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');

    $db->exec("CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        password TEXT,
        client_id TEXT,
        refresh_token TEXT,
        client_secret TEXT,
        remark TEXT,
        status TEXT DEFAULT 'unknown',
        last_check_at TEXT,
        last_error TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 兼容旧库：补列
    $cols = $db->query("PRAGMA table_info(accounts)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('status', $colNames, true)) {
        $db->exec("ALTER TABLE accounts ADD COLUMN status TEXT DEFAULT 'unknown'");
    }
    if (!in_array('last_check_at', $colNames, true)) {
        $db->exec("ALTER TABLE accounts ADD COLUMN last_check_at TEXT");
    }
    if (!in_array('last_error', $colNames, true)) {
        $db->exec("ALTER TABLE accounts ADD COLUMN last_error TEXT");
    }

    $adminCols = $db->query("PRAGMA table_info(admin)")->fetchAll();
    $adminColNames = array_column($adminCols, 'name');
    if (!in_array('must_change_password', $adminColNames, true)) {
        $db->exec("ALTER TABLE admin ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0");
    }

    $admin_count = (int)$db->query("SELECT COUNT(*) FROM admin")->fetchColumn();
    if ($admin_count === 0) {
        $pwd = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admin (username, password, must_change_password) VALUES (?, ?, 1)");
        $stmt->execute(['admin', $pwd]);
    }

    settings_ensure_defaults($db);

} catch (PDOException $e) {
    http_response_code(500);
    die('数据库连接失败: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_ajax(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'application/json');
}

function check_auth(bool $allow_must_change = false): void {
    if (empty($_SESSION['logged_in'])) {
        if (is_ajax()) {
            json_out(['error' => '未登录或登录已过期'], 403);
        }
        header('Location: index.php');
        exit;
    }
    if (!$allow_must_change && !empty($_SESSION['must_change_password'])) {
        if (is_ajax()) {
            json_out(['error' => '请先修改默认密码', 'need_change_password' => true], 403);
        }
        header('Location: change_password.php');
        exit;
    }
}

/**
 * CA 证书路径（相对运行时目录，避免中文路径写入 php.ini 被编码搞坏）
 */
function ca_bundle_path(): string {
    static $path = null;
    if ($path !== null) return $path;
    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'cacert.pem',
        __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cacert.pem',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $path = $c;
            return $path;
        }
    }
    $path = '';
    return $path;
}

/**
 * 读取代理配置（不缓存：PHP 内置服务器同进程多请求，缓存会读到旧设置）
 * @return array{enabled:bool,type:string,host:string,port:int,user:string,pass:string}
 */
function get_proxy_config(): array {
    global $db;

    $rows = $db->query("SELECT key, value FROM settings WHERE key LIKE 'proxy_%'")->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[$r['key']] = (string)$r['value'];

    $type = strtolower(trim($map['proxy_type'] ?? 'http'));
    if (!in_array($type, ['http', 'socks5', 'socks5h'], true)) $type = 'http';

    return [
        'enabled' => (($map['proxy_enabled'] ?? '0') === '1' || ($map['proxy_enabled'] ?? '') === 'true'),
        'type'    => $type,
        'host'    => trim($map['proxy_host'] ?? ''),
        'port'    => (int)($map['proxy_port'] ?? 0),
        'user'    => (string)($map['proxy_user'] ?? ''),
        'pass'    => (string)($map['proxy_pass'] ?? ''),
    ];
}

/**
 * curl 统一：CA + 代理
 */
function curl_apply_defaults($ch): void {
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
    $timeout = setting_int('http_timeout', 25);
    $ctimeout = setting_int('connect_timeout', 12);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $ctimeout);
    curl_apply_proxy($ch);
}

/**
 * 给 curl handle 套代理
 */
function curl_apply_proxy($ch): void {
    $p = get_proxy_config();
    if (!$p['enabled'] || $p['host'] === '' || $p['port'] <= 0) return;

    $proxy = $p['host'] . ':' . $p['port'];
    curl_setopt($ch, CURLOPT_PROXY, $proxy);

    if ($p['type'] === 'socks5' || $p['type'] === 'socks5h') {
        // 优先 hostname 解析在远端（防 DNS 泄露）
        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
    } else {
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
    }

    if ($p['user'] !== '') {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['user'] . ':' . $p['pass']);
        if (defined('CURLAUTH_ANY')) {
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY);
        }
    }
}

/**
 * 经代理建立到 host:port 的 TCP 流，再包 SSL（用于 IMAP 993）
 * @return resource|false
 */
function proxy_ssl_connect(string $host, int $port = 993, int $timeout = 20) {
    $p = get_proxy_config();
    $ca = ca_bundle_path();
    $verify = setting_bool('ssl_verify');
    $sslOpts = [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'peer_name' => $host,
        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
    ];
    if ($ca !== '' && $verify) {
        $sslOpts['cafile'] = $ca;
    }

    if (!$p['enabled'] || $p['host'] === '' || $p['port'] <= 0) {
        $ctx = stream_context_create(['ssl' => $sslOpts]);
        $sock = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        return $sock ?: false;
    }

    $tcp = false;
    if ($p['type'] === 'socks5' || $p['type'] === 'socks5h') {
        $tcp = socks5_tcp_connect($p['host'], $p['port'], $host, $port, $p['user'], $p['pass'], $timeout);
    } else {
        $tcp = http_connect_tunnel($p['host'], $p['port'], $host, $port, $p['user'], $p['pass'], $timeout);
    }
    if (!$tcp) return false;

    stream_context_set_option($tcp, ['ssl' => $sslOpts]);
    $crypto = @stream_socket_enable_crypto($tcp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($crypto !== true) {
        fclose($tcp);
        return false;
    }
    stream_set_timeout($tcp, $timeout);
    return $tcp;
}

/**
 * HTTP CONNECT 隧道
 * @return resource|false
 */
function http_connect_tunnel(string $phost, int $pport, string $host, int $port, string $user, string $pass, int $timeout) {
    $fp = @fsockopen($phost, $pport, $errno, $errstr, $timeout);
    if (!$fp) return false;
    stream_set_timeout($fp, $timeout);

    $req = "CONNECT {$host}:{$port} HTTP/1.1\r\nHost: {$host}:{$port}\r\n";
    if ($user !== '') {
        $req .= 'Proxy-Authorization: Basic ' . base64_encode($user . ':' . $pass) . "\r\n";
    }
    $req .= "Proxy-Connection: Keep-Alive\r\n\r\n";
    fwrite($fp, $req);

    $headers = '';
    while (!feof($fp)) {
        $line = fgets($fp, 2048);
        if ($line === false) break;
        $headers .= $line;
        if ($line === "\r\n" || $line === "\n") break;
    }
    if (!preg_match('#^HTTP/\d\.\d\s+200#i', $headers)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

/**
 * SOCKS5 TCP 连接（支持用户名密码认证）
 * @return resource|false
 */
function socks5_tcp_connect(string $phost, int $pport, string $host, int $port, string $user, string $pass, int $timeout) {
    $fp = @fsockopen($phost, $pport, $errno, $errstr, $timeout);
    if (!$fp) return false;
    stream_set_timeout($fp, $timeout);

    $methods = ($user !== '') ? "\x05\x02\x00\x02" : "\x05\x01\x00"; // no-auth / userpass
    fwrite($fp, $methods);
    $resp = fread($fp, 2);
    if ($resp === false || strlen($resp) < 2 || $resp[0] !== "\x05") {
        fclose($fp);
        return false;
    }
    $method = ord($resp[1]);
    if ($method === 0x02) {
        // username/password auth (RFC 1929)
        $u = substr($user, 0, 255);
        $pw = substr($pass, 0, 255);
        $auth = "\x01" . chr(strlen($u)) . $u . chr(strlen($pw)) . $pw;
        fwrite($fp, $auth);
        $aresp = fread($fp, 2);
        if ($aresp === false || strlen($aresp) < 2 || ord($aresp[1]) !== 0x00) {
            fclose($fp);
            return false;
        }
    } elseif ($method !== 0x00) {
        fclose($fp);
        return false;
    }

    // CONNECT request — ATYP domain
    $hostBytes = $host;
    $req = "\x05\x01\x00\x03" . chr(strlen($hostBytes)) . $hostBytes . pack('n', $port);
    fwrite($fp, $req);
    $r = fread($fp, 4);
    if ($r === false || strlen($r) < 4 || ord($r[1]) !== 0x00) {
        fclose($fp);
        return false;
    }
    $atyp = ord($r[3]);
    if ($atyp === 0x01) {
        fread($fp, 4 + 2);
    } elseif ($atyp === 0x03) {
        $len = ord(fread($fp, 1));
        fread($fp, $len + 2);
    } elseif ($atyp === 0x04) {
        fread($fp, 16 + 2);
    }
    return $fp;
}

/**
 * 用 refresh_token 换 access_token
 * @return array{ok:bool, access_token?:string, refresh_token?:string, error?:string, raw?:array}
 */
function refresh_access_token(string $client_id, string $refresh_token): array {
    $client_id = trim($client_id);
    $refresh_token = trim($refresh_token);
    if ($client_id === '' || $refresh_token === '') {
        return ['ok' => false, 'error' => '缺少 client_id 或 refresh_token'];
    }

    $ch = curl_init(oauth_url('token'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $client_id,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]),
    ]);
    curl_apply_defaults($ch);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => '网络错误: ' . $err];
    }
    $res = json_decode($body, true);
    if (!is_array($res)) {
        return ['ok' => false, 'error' => 'Token 响应无法解析 HTTP=' . $code];
    }
    if (empty($res['access_token'])) {
        $desc = $res['error_description'] ?? ($res['error'] ?? 'token 刷新失败');
        // 截断超长错误
        if (is_string($desc) && strlen($desc) > 300) {
            $desc = substr($desc, 0, 300) . '...';
        }
        return ['ok' => false, 'error' => (string)$desc, 'raw' => $res];
    }
    return [
        'ok'            => true,
        'access_token'  => $res['access_token'],
        'refresh_token' => $res['refresh_token'] ?? null,
        'raw'           => $res,
    ];
}

/**
 * 测试代理：访问公网拿出口 IP + 探测微软连通
 * @return array{ok:bool,ip?:string,ms_ok?:bool,error?:string,proxy?:array}
 */
function test_proxy_connection(): array {
    $p = get_proxy_config();
    if (!$p['enabled']) {
        return ['ok' => false, 'error' => '代理未启用', 'proxy' => $p];
    }
    if ($p['host'] === '' || $p['port'] <= 0) {
        return ['ok' => false, 'error' => '请填写代理主机和端口', 'proxy' => $p];
    }

    $ch = curl_init('https://api.ipify.org?format=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_apply_defaults($ch);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'error' => '代理连接失败: ' . $err, 'proxy' => [
            'type' => $p['type'], 'host' => $p['host'], 'port' => $p['port'],
        ]];
    }
    $j = json_decode($body, true);
    $ip = is_array($j) ? ($j['ip'] ?? '') : trim($body);

    // 再探微软
    $ch2 = curl_init('https://login.microsoftonline.com/consumers/v2.0/.well-known/openid-configuration');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY => false,
    ]);
    curl_apply_defaults($ch2);
    $b2 = curl_exec($ch2);
    $err2 = curl_error($ch2);
    $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    $ms_ok = ($b2 !== false && $code2 >= 200 && $code2 < 500);

    return [
        'ok' => true,
        'ip' => $ip,
        'ms_ok' => $ms_ok,
        'ms_error' => $ms_ok ? null : ($err2 ?: ('HTTP ' . $code2)),
        'proxy' => [
            'type' => $p['type'],
            'host' => $p['host'],
            'port' => $p['port'],
            'auth' => $p['user'] !== '',
        ],
    ];
}

/**
 * IMAP XOAUTH2 探活（可选进一步拉信）
 * @return array{ok:bool, host?:string, error?:string}
 */
function imap_probe(string $email, string $access_token): array {
    $hosts = imap_host_list();
    $port = setting_int('imap_port', 993);
    $timeout = setting_int('imap_timeout', 15);
    foreach ($hosts as $host) {
        $sock = proxy_ssl_connect($host, $port, $timeout);
        if (!$sock) {
            continue;
        }
        stream_set_timeout($sock, $timeout);
        fgets($sock, 1024);
        $auth = base64_encode("user={$email}\x01auth=Bearer {$access_token}\x01\x01");
        fputs($sock, "A01 AUTHENTICATE XOAUTH2 {$auth}\r\n");
        $login = fgets($sock, 2048);
        if ($login !== false && str_contains($login, 'A01 OK')) {
            fputs($sock, "A99 LOGOUT\r\n");
            fclose($sock);
            return ['ok' => true, 'host' => $host];
        }
        fclose($sock);
    }
    $p = get_proxy_config();
    $hint = $p['enabled'] ? '（已走代理 ' . $p['type'] . '://' . $p['host'] . ':' . $p['port'] . '）' : '';
    return ['ok' => false, 'error' => 'IMAP 认证失败' . $hint . '（token 权限不足 / 代理不通 / 账号异常）'];
}

/**
 * 从正文提取验证码 / 链接
 */
function extract_codes(string $text): array {
    $codes = [];
    $links = [];

    $min = max(3, setting_int('code_min_len', 4));
    $max = max($min, setting_int('code_max_len', 8));
    $kwRaw = (string)setting_get_fresh('code_keywords', '验证码,code');
    $kws = array_values(array_filter(array_map('trim', preg_split('/[,，;|]+/u', $kwRaw))));
    if (!$kws) $kws = ['验证码', 'code'];
    $kwAlt = implode('|', array_map(function ($k) {
        return preg_quote($k, '/');
    }, $kws));

    if (preg_match_all('/(?:' . $kwAlt . ')[^\d]{0,24}(\d{' . $min . ',' . $max . '})/u', $text, $m)) {
        foreach ($m[1] as $c) $codes[] = $c;
    }

    if (setting_bool('code_loose_6digit')) {
        if (preg_match_all('/(?<!\d)(\d{6})(?!\d)/', $text, $m2)) {
            $ymin = setting_int('code_year_filter_min', 1990);
            $ymax = setting_int('code_year_filter_max', 2035);
            foreach ($m2[1] as $c) {
                if ((int)$c >= $ymin && (int)$c <= $ymax) continue;
                $codes[] = $c;
            }
        }
    }

    if (setting_bool('extract_links')) {
        if (preg_match_all('#https?://[^\s"\'<>]+#i', $text, $lm)) {
            foreach ($lm[0] as $u) {
                if (preg_match('/\.(png|jpg|jpeg|gif|css|js|svg|woff2?)($|\?)/i', $u)) continue;
                $links[] = rtrim($u, '.,);]');
            }
        }
    }

    $codes = array_values(array_unique($codes));
    $links = array_values(array_unique($links));
    return ['codes' => array_slice($codes, 0, 10), 'links' => array_slice($links, 0, 10)];
}

function decode_mime_header_value(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($value, 0, 'UTF-8');
        if ($d !== false) return $d;
    }
    return $value;
}

function extract_message_body(string $part): string {
    $parts = preg_split('/\r?\n\r?\n/', $part, 2);
    if (count($parts) < 2) return '';

    $header  = $parts[0];
    $content = trim($parts[1]);

    if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $header)) {
        $content = base64_decode(str_replace(["\r", "\n"], '', $content));
    } elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $header)) {
        $content = quoted_printable_decode($content);
    }

    if (preg_match('/charset="?([^"\r\n;]+)"?/i', $header, $charMat)) {
        $charset = strtoupper(trim($charMat[1]));
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII' && $charset !== '') {
            $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
            if ($converted !== false) $content = $converted;
        }
    }
    return is_string($content) ? $content : '';
}

/**
 * 拉最近邮件
 * @return array{ok:bool, messages?:array, error?:string}
 */
function fetch_recent_mails(string $email, string $access_token, int $limit = 15, string $folder = 'INBOX'): array {
    $hosts = imap_host_list();
    $maxLimit = max(1, setting_int('mail_max_limit', 50));
    $limit = max(1, min($maxLimit, $limit));
    $junkName = (string)setting_get_fresh('mail_junk_folder_name', 'Junk');
    if ($junkName === '') $junkName = 'Junk';
    $folder = ($folder === 'Junk' || $folder === $junkName) ? $junkName : 'INBOX';
    $port = setting_int('imap_port', 993);
    $timeout = setting_int('imap_timeout', 15);

    foreach ($hosts as $host) {
        $sock = proxy_ssl_connect($host, $port, $timeout);
        if (!$sock) continue;
        stream_set_timeout($sock, $timeout);
        fgets($sock, 1024);

        $auth = base64_encode("user={$email}\x01auth=Bearer {$access_token}\x01\x01");
        fputs($sock, "A01 AUTHENTICATE XOAUTH2 {$auth}\r\n");
        $login = fgets($sock, 2048);
        if ($login === false || !str_contains($login, 'A01 OK')) {
            fclose($sock);
            continue;
        }

        fputs($sock, "A02 SELECT {$folder}\r\n");
        while ($line = fgets($sock, 2048)) {
            if (str_contains($line, 'A02 OK') || str_contains($line, 'A02 NO') || str_contains($line, 'A02 BAD')) break;
        }

        fputs($sock, "A03 SEARCH ALL\r\n");
        $ids = [];
        while ($line = fgets($sock, 8192)) {
            $line = trim($line);
            if (preg_match('/^\* SEARCH(.*)$/i', $line, $match)) {
                $ids = array_values(array_filter(preg_split('/\s+/', trim($match[1]))));
            }
            if (str_contains($line, 'A03 OK') || str_contains($line, 'A03 NO')) break;
        }

        $messages = [];
        if (!empty($ids)) {
            rsort($ids, SORT_NUMERIC);
            $ids = array_slice($ids, 0, $limit);
            foreach ($ids as $mid) {
                $mid = (int)$mid;
                if ($mid <= 0) continue;
                fputs($sock, "A04 FETCH {$mid} (RFC822)\r\n");
                $rawMail = '';
                while ($line = fgets($sock, 16384)) {
                    $rawMail .= $line;
                    if (preg_match('/^A04 OK/m', $line) || preg_match('/^A04 NO/m', $line)) break;
                }

                preg_match('/^Subject:\s*(.*?)$/im', $rawMail, $subjMat);
                $subject = isset($subjMat[1]) ? decode_mime_header_value(trim($subjMat[1])) : '无主题';

                preg_match('/^From:\s*(.*?)$/im', $rawMail, $fromMat);
                $from = isset($fromMat[1]) ? decode_mime_header_value(trim($fromMat[1])) : '未知发件人';

                preg_match('/^Date:\s*(.*?)$/im', $rawMail, $dateMat);
                $date = isset($dateMat[1]) ? trim($dateMat[1]) : '';

                preg_match('/boundary="?([^"\r\n;]+)"?/is', $rawMail, $bndMat);
                $boundary = $bndMat[1] ?? '';

                $body = '';
                if ($boundary) {
                    $parts = explode('--' . $boundary, $rawMail);
                    foreach ($parts as $part) {
                        if (stripos($part, 'Content-Type: text/html') !== false) {
                            $body = extract_message_body($part);
                            break;
                        }
                    }
                    if ($body === '') {
                        foreach ($parts as $part) {
                            if (stripos($part, 'Content-Type: text/plain') !== false) {
                                $body = extract_message_body($part);
                                break;
                            }
                        }
                    }
                } else {
                    $body = extract_message_body($rawMail);
                }

                $plain = $body;
                if (str_contains($plain, '<')) {
                    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $extracted = extract_codes($subject . "\n" . $plain . "\n" . $body);

                $messages[] = [
                    'subject' => $subject,
                    'from'    => $from,
                    'date'    => $date,
                    'folder'  => $folder,
                    'body'    => $body,
                    'codes'   => $extracted['codes'],
                    'links'   => $extracted['links'],
                ];
            }
        }

        fputs($sock, "A99 LOGOUT\r\n");
        fclose($sock);
        return ['ok' => true, 'messages' => $messages, 'host' => $host];
    }

    return ['ok' => false, 'error' => '无法连接 IMAP 或认证失败'];
}

/**
 * 解析一行凭证
 * 支持:
 *   email----pass----client_id----refresh_token
 *   email:pass
 *   email|pass|client_id|refresh_token
 */
function parse_account_line(string $line): ?array {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) return null;
    $delim = (string)setting_get_fresh('export_delimiter', '----');
    if ($delim === '') $delim = '----';

    if (str_contains($line, $delim)) {
        $p = array_map('trim', explode($delim, $line));
    } elseif (substr_count($line, '|') >= 3) {
        $p = array_map('trim', explode('|', $line));
    } elseif (str_contains($line, ':') && !str_contains($line, $delim)) {
        // mail:pass 简单格式（无 oauth 则无法收件，仍可入库）
        $p = array_map('trim', explode(':', $line, 2));
        if (count($p) === 2) {
            return [
                'email' => $p[0],
                'password' => $p[1],
                'client_id' => '',
                'refresh_token' => '',
                'remark' => '',
            ];
        }
        return null;
    } else {
        return null;
    }

    if (count($p) < 4) return null;
    if (!filter_var($p[0], FILTER_VALIDATE_EMAIL) && !str_contains($p[0], '@')) return null;

    return [
        'email'         => $p[0],
        'password'      => $p[1] ?? '',
        'client_id'     => $p[2] ?? '',
        'refresh_token' => $p[3] ?? '',
        'remark'        => $p[4] ?? '',
    ];
}
