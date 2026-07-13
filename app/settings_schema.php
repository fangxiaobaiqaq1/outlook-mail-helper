<?php
/**
 * 全部可配置项定义（无黑盒默认写死在业务里时，都从这里出）
 * type: string|int|bool|select|text
 */
function settings_schema(): array {
    return [
        // —— 基础 ——
        'app_name' => [
            'group' => 'basic', 'label' => '应用名称', 'type' => 'string', 'default' => 'Outlook邮箱助手',
        ],
        'export_delimiter' => [
            'group' => 'basic', 'label' => '凭证分隔符', 'type' => 'string', 'default' => '----',
            'hint' => '导入/导出四段格式用的分隔符',
        ],

        // —— 代理 ——
        'proxy_enabled' => [
            'group' => 'proxy', 'label' => '启用代理', 'type' => 'bool', 'default' => '0',
        ],
        'proxy_type' => [
            'group' => 'proxy', 'label' => '代理类型', 'type' => 'select', 'default' => 'http',
            'options' => ['http' => 'HTTP/HTTPS', 'socks5' => 'SOCKS5'],
        ],
        'proxy_host' => [
            'group' => 'proxy', 'label' => '代理主机', 'type' => 'string', 'default' => '',
        ],
        'proxy_port' => [
            'group' => 'proxy', 'label' => '代理端口', 'type' => 'int', 'default' => '0', 'min' => 0, 'max' => 65535,
        ],
        'proxy_user' => [
            'group' => 'proxy', 'label' => '代理用户名', 'type' => 'string', 'default' => '',
        ],
        'proxy_pass' => [
            'group' => 'proxy', 'label' => '代理密码', 'type' => 'password', 'default' => '',
        ],

        // —— 验活 ——
        'check_concurrency' => [
            'group' => 'check', 'label' => '验活并发数', 'type' => 'int', 'default' => '5',
            'min' => 1, 'max' => 50, 'hint' => '前端同时请求数；建议 3~10',
        ],
        'check_imap_probe' => [
            'group' => 'check', 'label' => '验活时探测 IMAP', 'type' => 'bool', 'default' => '1',
            'hint' => '关闭则只刷新 OAuth Token（更快，但不保证能收信）',
        ],
        'check_update_token' => [
            'group' => 'check', 'label' => '验活成功写回新 refresh_token', 'type' => 'bool', 'default' => '1',
        ],
        'check_mark_dead_on_imap_fail' => [
            'group' => 'check', 'label' => 'IMAP 失败标 Dead', 'type' => 'bool', 'default' => '1',
            'hint' => '关闭后：Token 成功但 IMAP 失败标 unknown 并记错误',
        ],

        // —— 网络超时 / SSL ——
        'http_timeout' => [
            'group' => 'network', 'label' => 'HTTP 超时(秒)', 'type' => 'int', 'default' => '25', 'min' => 5, 'max' => 120,
        ],
        'imap_timeout' => [
            'group' => 'network', 'label' => 'IMAP 超时(秒)', 'type' => 'int', 'default' => '15', 'min' => 5, 'max' => 120,
        ],
        'connect_timeout' => [
            'group' => 'network', 'label' => '连接超时(秒)', 'type' => 'int', 'default' => '12', 'min' => 3, 'max' => 60,
        ],
        'ssl_verify' => [
            'group' => 'network', 'label' => '校验证书(SSL Verify)', 'type' => 'bool', 'default' => '1',
            'hint' => '仅调试坏代理时关闭；正式使用请保持开启',
        ],
        'imap_hosts' => [
            'group' => 'network', 'label' => 'IMAP 主机列表', 'type' => 'string', 'default' => 'outlook.office365.com,outlook.live.com',
            'hint' => '逗号分隔，按顺序尝试',
        ],
        'imap_port' => [
            'group' => 'network', 'label' => 'IMAP 端口', 'type' => 'int', 'default' => '993', 'min' => 1, 'max' => 65535,
        ],

        // —— OAuth ——
        'oauth_tenant' => [
            'group' => 'oauth', 'label' => 'OAuth Tenant', 'type' => 'string', 'default' => 'consumers',
            'hint' => 'consumers / common / organizations 或具体 tenant id',
        ],
        'oauth_token_url' => [
            'group' => 'oauth', 'label' => 'Token URL 模板', 'type' => 'string',
            'default' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
        ],
        'oauth_device_url' => [
            'group' => 'oauth', 'label' => 'DeviceCode URL 模板', 'type' => 'string',
            'default' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/devicecode',
        ],
        'default_client_id' => [
            'group' => 'oauth', 'label' => '默认 Client ID', 'type' => 'string',
            'default' => '9e5f94bc-e8a4-4e73-b8be-63364c29d753',
            'hint' => '拿 Token 页默认值（Thunderbird 公共 ID）',
        ],
        'oauth_scopes' => [
            'group' => 'oauth', 'label' => 'OAuth Scopes', 'type' => 'text',
            'default' => 'https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/POP.AccessAsUser.All offline_access',
        ],

        // —— 收件 ——
        'mail_default_folder' => [
            'group' => 'mail', 'label' => '默认文件夹', 'type' => 'select', 'default' => 'all',
            'options' => ['INBOX' => '收件箱', 'Junk' => '垃圾箱', 'all' => '收件箱+垃圾箱'],
        ],
        'mail_default_limit' => [
            'group' => 'mail', 'label' => '默认拉取封数', 'type' => 'int', 'default' => '20', 'min' => 1, 'max' => 200,
        ],
        'mail_max_limit' => [
            'group' => 'mail', 'label' => '最大拉取封数', 'type' => 'int', 'default' => '50', 'min' => 1, 'max' => 500,
        ],
        'mail_junk_folder_name' => [
            'group' => 'mail', 'label' => '垃圾箱文件夹名', 'type' => 'string', 'default' => 'Junk',
        ],

        // —— 验证码提取 ——
        'code_keywords' => [
            'group' => 'extract', 'label' => '验证码关键词', 'type' => 'string',
            'default' => '验证码,校验码,动态码,安全码,code,Code,CODE,otp,OTP,pin,PIN',
            'hint' => '逗号分隔',
        ],
        'code_min_len' => [
            'group' => 'extract', 'label' => '验证码最短位数', 'type' => 'int', 'default' => '4', 'min' => 3, 'max' => 12,
        ],
        'code_max_len' => [
            'group' => 'extract', 'label' => '验证码最长位数', 'type' => 'int', 'default' => '8', 'min' => 4, 'max' => 16,
        ],
        'code_loose_6digit' => [
            'group' => 'extract', 'label' => '额外匹配独立6位数字', 'type' => 'bool', 'default' => '1',
        ],
        'code_year_filter_min' => [
            'group' => 'extract', 'label' => '过滤年份下限', 'type' => 'int', 'default' => '1990',
        ],
        'code_year_filter_max' => [
            'group' => 'extract', 'label' => '过滤年份上限', 'type' => 'int', 'default' => '2035',
        ],
        'extract_links' => [
            'group' => 'extract', 'label' => '提取链接', 'type' => 'bool', 'default' => '1',
        ],

        // —— TG ——
        'tg_token' => [
            'group' => 'tg', 'label' => 'TG Bot Token', 'type' => 'string', 'default' => '',
        ],
        'tg_chatid' => [
            'group' => 'tg', 'label' => 'TG Chat ID', 'type' => 'string', 'default' => '',
        ],
    ];
}

function settings_groups(): array {
    return [
        'basic'   => '基础',
        'proxy'   => '代理',
        'check'   => '验活',
        'network' => '网络',
        'oauth'   => 'OAuth',
        'mail'    => '收件',
        'extract' => '验证码提取',
        'tg'      => 'Telegram',
    ];
}

/**
 * 确保 schema 默认值写入 DB
 */
function settings_ensure_defaults(PDO $db): void {
    $ins = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach (settings_schema() as $key => $meta) {
        $ins->execute([$key, (string)$meta['default']]);
    }
}

/**
 * 读取全部设置（带默认回退）
 */
function settings_all(PDO $db): array {
    settings_ensure_defaults($db);
    $rows = $db->query("SELECT key, value FROM settings")->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[$r['key']] = (string)$r['value'];

    $out = [];
    foreach (settings_schema() as $key => $meta) {
        $out[$key] = array_key_exists($key, $map) ? $map[$key] : (string)$meta['default'];
    }
    // 保留未知历史键（不丢）
    foreach ($map as $k => $v) {
        if (!array_key_exists($k, $out)) $out[$k] = $v;
    }
    return $out;
}

function setting_get(string $key, $default = null) {
    global $db;
    static $cache = null;
    static $cacheGen = 0;
    // 每次请求内可清
    if ($cache === null) {
        $cache = settings_all($db);
    }
    if (array_key_exists($key, $cache)) return $cache[$key];
    $schema = settings_schema();
    if (isset($schema[$key])) return $schema[$key]['default'];
    return $default;
}

function settings_clear_cache(): void {
    // setting_get 用 static；同进程多请求时需清
    // 通过重新赋值技巧：调用方在 save 后 exit 即可；内置服务器同进程要清
    $ref = null;
}

/**
 * 强制重读（save 后）
 */
function setting_get_fresh(string $key, $default = null) {
    global $db;
    $all = settings_all($db);
    if (array_key_exists($key, $all)) return $all[$key];
    return $default;
}

function setting_bool(string $key): bool {
    $v = setting_get_fresh($key, '0');
    return $v === '1' || $v === 'true' || $v === true || $v === 1 || $v === 'on';
}

function setting_int(string $key, int $fallback = 0): int {
    return (int)setting_get_fresh($key, (string)$fallback);
}

/**
 * 校验并保存
 * @return array{ok:bool, saved?:array, errors?:array}
 */
function settings_save(PDO $db, array $input): array {
    $schema = settings_schema();
    $errors = [];
    $saved = [];
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");

    foreach ($input as $key => $raw) {
        if (!isset($schema[$key])) {
            // 允许 admin_user / admin_pass 在外面处理
            continue;
        }
        $meta = $schema[$key];
        $type = $meta['type'];
        $val = is_string($raw) ? trim($raw) : $raw;

        if ($type === 'bool') {
            $val = ($val === true || $val === 1 || $val === '1' || $val === 'true' || $val === 'on') ? '1' : '0';
        } elseif ($type === 'int') {
            if ($val === '' || $val === null) $val = (string)$meta['default'];
            if (!is_numeric($val)) {
                $errors[$key] = '必须是数字';
                continue;
            }
            $n = (int)$val;
            if (isset($meta['min'])) $n = max((int)$meta['min'], $n);
            if (isset($meta['max'])) $n = min((int)$meta['max'], $n);
            $val = (string)$n;
        } elseif ($type === 'select') {
            $opts = $meta['options'] ?? [];
            if (!array_key_exists((string)$val, $opts)) {
                $val = (string)$meta['default'];
            } else {
                $val = (string)$val;
            }
        } else {
            $val = (string)$val;
        }

        $stmt->execute([$key, $val]);
        $saved[$key] = $val;
    }

    if ($errors) return ['ok' => false, 'errors' => $errors, 'saved' => $saved];
    return ['ok' => true, 'saved' => $saved];
}

function oauth_url(string $which): string {
    $tenant = trim((string)setting_get_fresh('oauth_tenant', 'consumers'));
    if ($tenant === '') $tenant = 'consumers';
    $tpl = $which === 'device'
        ? (string)setting_get_fresh('oauth_device_url')
        : (string)setting_get_fresh('oauth_token_url');
    return str_replace('{tenant}', rawurlencode($tenant) === $tenant ? $tenant : $tenant, str_replace('{tenant}', $tenant, $tpl));
}

function imap_host_list(): array {
    $raw = (string)setting_get_fresh('imap_hosts', 'outlook.office365.com,outlook.live.com');
    $parts = preg_split('/[\s,;]+/', $raw);
    $hosts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $hosts[] = $p;
    }
    return $hosts ?: ['outlook.office365.com'];
}
