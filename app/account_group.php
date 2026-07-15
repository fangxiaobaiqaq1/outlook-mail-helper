<?php
/**
 * 账号邮件家族分组（纯函数 + SQL filter builder）
 *
 * 协议层（OAuth/IMAP）不按域名分支；本模块只做组织/筛选。
 * 分组值落库 accounts.mail_group，查询走等值 + 索引。
 */

/** @return list<string> */
function account_group_values(): array {
    return ['hotmail', 'outlook', 'other'];
}

/**
 * 域名主标签 → 分组。可扩展，改这里即可。
 * 匹配规则：取 email @ 后域名，用第一个标签（hotmail.com / hotmail.co.uk → hotmail）
 *
 * @return array<string, list<string>>
 */
function account_group_domain_rules(): array {
    return [
        'hotmail' => ['hotmail', 'live', 'msn', 'passport'],
        'outlook' => ['outlook'],
    ];
}

/** @return array<string, string> */
function account_group_labels(): array {
    return [
        'all'     => '全部域名',
        'hotmail' => 'Hotmail',
        'outlook' => 'Outlook',
        'other'   => '其他',
    ];
}

/**
 * 过滤器入参规范化。非法/空 → all（兼容旧前端不传 group）
 */
function account_group_normalize(?string $g): string {
    $g = strtolower(trim((string)$g));
    if ($g === '' || $g === 'all') return 'all';
    return in_array($g, account_group_values(), true) ? $g : 'all';
}

/**
 * 从邮箱推导分组。永远返回 hotmail|outlook|other（从不返回 all）
 */
function account_group_from_email(string $email): string {
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) return 'other';
    $domain = substr($email, $at + 1);
    if ($domain === '' || !str_contains($domain, '.')) return 'other';

    // 主标签：hotmail.com → hotmail；live.cn → live；outlook.co.uk → outlook
    $label = explode('.', $domain, 2)[0];
    if ($label === '') return 'other';

    foreach (account_group_domain_rules() as $group => $labels) {
        if (in_array($label, $labels, true)) {
            return $group;
        }
    }
    return 'other';
}

/**
 * 统一 WHERE 构建。唯一筛选来源，list/list_ids/export/delete 共用。
 *
 * @param array{status?:string,group?:string,q?:string} $opts
 * @return array{where:list<string>,params:list<mixed>,sql:string}
 */
function accounts_filter_clause(array $opts = []): array {
    $status = strtolower(trim((string)($opts['status'] ?? 'all')));
    $group  = account_group_normalize($opts['group'] ?? 'all');
    $q      = trim((string)($opts['q'] ?? ''));

    $where = [];
    $params = [];

    if ($status === 'live' || $status === 'dead') {
        $where[] = 'status = ?';
        $params[] = $status;
    } elseif ($status === 'unknown') {
        $where[] = "(status IS NULL OR status = '' OR status = 'unknown')";
    }

    if ($group !== 'all') {
        $where[] = 'mail_group = ?';
        $params[] = $group;
    }

    if ($q !== '') {
        $where[] = '(email LIKE ? OR IFNULL(remark,\'\') LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    return ['where' => $where, 'params' => $params, 'sql' => $sql];
}

/**
 * 分批按 email 重算 mail_group（迁移 / reclassify）
 *
 * @return array{updated:int,total:int}
 */
function accounts_reclassify_mail_groups(PDO $db, int $chunk = 1000): array {
    $chunk = max(100, min(5000, $chunk));
    $total = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    $updated = 0;
    $lastId = 0;
    $sel = $db->prepare('SELECT id, email FROM accounts WHERE id > ? ORDER BY id ASC LIMIT ' . (int)$chunk);
    $upd = $db->prepare('UPDATE accounts SET mail_group = ? WHERE id = ?');

    while (true) {
        $sel->execute([$lastId]);
        $rows = $sel->fetchAll();
        if (!$rows) break;
        $db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $g = account_group_from_email((string)$r['email']);
                $upd->execute([$g, (int)$r['id']]);
                $updated += $upd->rowCount() ? 1 : 0;
                $lastId = (int)$r['id'];
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if (count($rows) < $chunk) break;
    }
    return ['updated' => $updated, 'total' => $total];
}
