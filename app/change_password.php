<?php
require __DIR__ . '/config.php';
check_auth(true);

if (empty($_SESSION['must_change_password'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = (string)($_POST['old_pass'] ?? '');
    $new = (string)($_POST['new_pass'] ?? '');
    $new2 = (string)($_POST['new_pass2'] ?? '');

    if (strlen($new) < 6) {
        $error = '新密码至少 6 位';
    } elseif ($new !== $new2) {
        $error = '两次新密码不一致';
    } elseif ($new === 'admin123') {
        $error = '不能继续使用默认密码';
    } else {
        $admin = $db->query("SELECT * FROM admin WHERE id=1")->fetch();
        if (!$admin || !password_verify($old, $admin['password'])) {
            $error = '原密码不正确';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare("UPDATE admin SET password=?, must_change_password=0 WHERE id=1")->execute([$hash]);
            $_SESSION['must_change_password'] = false;
            $ok = true;
            header('Refresh: 1; url=dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改默认密码</title>
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;
            background:#0f172a; color:#e2e8f0; }
        .card { width:100%; max-width:420px; background:#111827; border:1px solid #1f2937;
            border-radius:18px; padding:32px; box-shadow:0 20px 60px rgba(0,0,0,.35); }
        h1 { margin:0 0 8px; font-size:20px; }
        p { margin:0 0 20px; color:#94a3b8; font-size:13px; line-height:1.6; }
        label { display:block; font-size:12px; color:#94a3b8; margin:0 0 6px; }
        input { width:100%; box-sizing:border-box; padding:12px; border-radius:10px; border:1px solid #334155;
            background:#0b1220; color:#fff; margin-bottom:12px; }
        button { width:100%; padding:12px; border:0; border-radius:10px; background:#22c55e; color:#052e16;
            font-weight:700; cursor:pointer; }
        .error { background:#450a0a; color:#fecaca; padding:10px; border-radius:8px; margin-bottom:12px; font-size:13px; }
        .ok { background:#052e16; color:#bbf7d0; padding:10px; border-radius:8px; margin-bottom:12px; font-size:13px; }
    </style>
</head>
<body>
<div class="card">
    <h1>首次登录：修改密码</h1>
    <p>检测到你仍在使用默认密码。为安全起见，必须先改密后才能进入控制台。</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok">修改成功，正在进入控制台…</div><?php endif; ?>
    <?php if (!$ok): ?>
    <form method="POST">
        <label>原密码（默认 admin123）</label>
        <input type="password" name="old_pass" required>
        <label>新密码（至少 6 位）</label>
        <input type="password" name="new_pass" required minlength="6">
        <label>确认新密码</label>
        <input type="password" name="new_pass2" required minlength="6">
        <button type="submit">确认修改并进入</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
