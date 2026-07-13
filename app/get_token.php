<?php
/**
 * 设备码授权拿 Refresh Token
 * 默认使用 Thunderbird 公开 Client ID（体验用）
 */
require __DIR__ . '/config.php';
check_auth();

// Thunderbird public client id（社区常用）
$default_client_id = (string)setting_get_fresh('default_client_id', '9e5f94bc-e8a4-4e73-b8be-63364c29d753');
$client_id = trim($_POST['client_id'] ?? $_GET['client_id'] ?? $default_client_id);
if ($client_id === '') $client_id = $default_client_id;

$scopes = (string)setting_get_fresh('oauth_scopes', 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access');

if (isset($_GET['reset'])) {
    unset($_SESSION['device_code'], $_SESSION['device_client_id']);
    header('Location: get_token.php');
    exit;
}

$step = $_POST['step'] ?? 'start';
$error = '';
$result_line = '';

if ($step === 'start' || !isset($_SESSION['device_code'])) {
    $ch = curl_init(oauth_url('device'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $client_id,
            'scope' => $scopes,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_apply_defaults($ch);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    if (empty($res['device_code'])) {
        $error = '获取设备码失败: ' . htmlspecialchars(json_encode($res, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    } else {
        $_SESSION['device_code'] = $res['device_code'];
        $_SESSION['device_client_id'] = $client_id;
        $user_code = $res['user_code'];
        $verify_uri = $res['verification_uri'] ?? 'https://microsoft.com/devicelogin';
        $step = 'wait';
    }
}

if ($step === 'finish' && isset($_SESSION['device_code'])) {
    $client_id = $_SESSION['device_client_id'] ?? $client_id;
    $ch = curl_init(oauth_url('token'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $client_id,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $_SESSION['device_code'],
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_apply_defaults($ch);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['refresh_token'])) {
        $email = trim($_POST['email'] ?? '');
        $pass  = trim($_POST['pass'] ?? '');
        $result_line = "{$email}----{$pass}----{$client_id}----{$res['refresh_token']}";
        unset($_SESSION['device_code'], $_SESSION['device_client_id']);
        $step = 'done';
    } else {
        $err = $res['error'] ?? 'unknown';
        if ($err === 'authorization_pending') {
            $error = '尚未完成微软页面授权，请先在浏览器完成登录与允许，再点生成。';
            $step = 'wait';
            $user_code = '（请使用刚才显示的验证码）';
            $verify_uri = 'https://microsoft.com/devicelogin';
        } else {
            $error = $res['error_description'] ?? $err;
            $step = 'wait';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>获取 Outlook Token</title>
<style>
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;background:#f8fafc;color:#0f172a}
.wrap{max-width:720px;margin:40px auto;padding:0 16px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(15,23,42,.05)}
h1{margin:0 0 8px;font-size:22px}
.sub{color:#64748b;font-size:13px;margin-bottom:18px}
.code{font-size:34px;font-weight:800;letter-spacing:4px;color:#dc2626;text-align:center;padding:16px;background:#fff7ed;border-radius:12px;margin:12px 0}
a.btn,button{display:inline-block;background:#2563eb;color:#fff;border:0;border-radius:10px;padding:12px 16px;text-decoration:none;font-weight:700;cursor:pointer}
input,textarea{width:100%;box-sizing:border-box;padding:12px;border:1px solid #cbd5e1;border-radius:10px;margin:8px 0 12px}
.err{background:#fef2f2;color:#b91c1c;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:13px}
.ok{background:#ecfdf5;color:#166534;padding:10px 12px;border-radius:10px;margin-bottom:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
label{font-size:12px;color:#64748b;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>获取 Outlook Token</h1>
      <div class="sub">设备码模式 · 无需 Azure 回调 · 生成可直接导入的四段凭证</div>
    </div>
    <a class="btn" href="dashboard.php" style="background:#0f172a">返回控制台</a>
  </div>

  <div class="card">
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($step === 'done'): ?>
      <div class="ok">✅ 获取成功，复制下面整行，到控制台「添加 / 批量导入」</div>
      <textarea rows="5" onclick="this.select()"><?= htmlspecialchars($result_line, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p><a class="btn" href="get_token.php?reset=1">继续下一个</a></p>
    <?php elseif ($step === 'wait'): ?>
      <p><b>第一步</b>：记住验证码</p>
      <div class="code"><?= htmlspecialchars($user_code ?? '', ENT_QUOTES, 'UTF-8') ?></div>
      <p><b>第二步</b>：打开微软页面，登录目标邮箱并输入验证码</p>
      <p><a class="btn" href="<?= htmlspecialchars($verify_uri ?? 'https://microsoft.com/devicelogin', ENT_QUOTES, 'UTF-8') ?>" target="_blank">打开微软授权页</a></p>
      <form method="POST" style="margin-top:20px;border-top:1px dashed #e2e8f0;padding-top:16px">
        <input type="hidden" name="step" value="finish">
        <label>邮箱（仅用于拼装导出格式）</label>
        <input name="email" placeholder="xxx@outlook.com" required>
        <label>密码（可填真实密码，仅本地保存）</label>
        <input name="pass" placeholder="密码可留空占位" value="">
        <button type="submit">我已完成授权，生成凭证</button>
      </form>
      <p style="margin-top:14px"><a href="get_token.php?reset=1" style="color:#94a3b8;font-size:12px">重置重来</a></p>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="step" value="start">
        <label>Client ID（默认 Thunderbird 公共 ID，可换成你自己的）</label>
        <input name="client_id" value="<?= htmlspecialchars($client_id, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">开始获取设备码</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
