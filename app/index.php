<?php
require __DIR__ . '/config.php';

if (!empty($_SESSION['logged_in'])) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: change_password.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');

    $stmt = $db->prepare("SELECT * FROM admin WHERE username = ?");
    $stmt->execute([$user]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($pass, $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        $_SESSION['must_change_password'] = !empty($admin['must_change_password']);
        if ($_SESSION['must_change_password']) {
            header('Location: change_password.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
    }
    $error = '账号或密码不正确';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - Outlook 邮箱助手</title>
    <link rel="icon" href="static/image/favicon/favicon.svg">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(145deg, #eef2ff 0%, #f8fafc 40%, #ecfeff 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            color: #0f172a;
        }
        .card {
            width: 100%; max-width: 380px; background: rgba(255,255,255,.9);
            border: 1px solid rgba(15,23,42,.06); border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15,23,42,.08); padding: 36px 32px;
        }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo h1 { margin: 0; font-size: 22px; letter-spacing: -.3px; }
        .logo p { margin: 8px 0 0; color: #64748b; font-size: 13px; }
        label { display:block; font-size: 12px; color: #64748b; margin-bottom: 6px; font-weight: 600; }
        input {
            width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 12px;
            margin-bottom: 14px; font-size: 14px; outline: none; background: #fff;
        }
        input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.12); }
        button {
            width: 100%; padding: 12px; border: 0; border-radius: 12px; background: #2563eb;
            color: #fff; font-weight: 600; font-size: 14px; cursor: pointer;
        }
        button:hover { background: #1d4ed8; }
        .error {
            background: #fef2f2; color: #dc2626; border-radius: 10px; padding: 10px 12px;
            font-size: 13px; margin-bottom: 14px; text-align: center;
        }
        .hint {
            margin-top: 16px; font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.6;
        }
        code { background: #f1f5f9; padding: 1px 6px; border-radius: 6px; color: #334155; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>Outlook 邮箱助手</h1>
        <p>验活 · 批量收件 · 验证码提取</p>
    </div>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="POST">
        <label>用户名</label>
        <input type="text" name="user" placeholder="admin" required autocomplete="username">
        <label>密码</label>
        <input type="password" name="pass" placeholder="密码" required autocomplete="current-password">
        <button type="submit">登录</button>
    </form>
    <div class="hint">首次登录默认账号 <code>admin</code> / <code>admin123</code><br>登录后会强制修改密码</div>
</div>
</body>
</html>
