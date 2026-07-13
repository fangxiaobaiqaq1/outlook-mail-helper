# Outlook Mail Helper / Outlook 邮箱助手

本地运行的 **Outlook / Hotmail 多账号验活 + 批量收件 + 验证码提取** 工具。

> 基于 [CN-Root/OutlookPanel](https://github.com/CN-Root/OutlookPanel)（MIT）二改，协议 **MIT**。  
> 面向「自己管理自己的邮箱号池」场景：导入 OAuth 凭证、后台并发验活、读信提码。

---

## 功能

- 批量导入 `email----password----client_id----refresh_token`
- **后台真并发验活**（独立 CLI worker + `curl_multi`，关面板不中断）
- 多选 / 全选 / 反选，**批量删除**（选中 / Live / Dead / 全部）
- **批量备注**（设置 / 追加 / 清空）
- HTTP / SOCKS5 代理（OAuth + IMAP 全链路）
- 收件箱 / 垃圾箱 / 合并视图，验证码与链接提取
- 设置页全量可配：并发、超时、IMAP 主机、OAuth scope、验证码规则等
- Windows 双击 exe / Linux 命令行启动

---

## 快速开始

### Windows

1. 下载 Release 中的 `Outlook邮箱助手-Windows版.zip`
2. 解压后双击 **`Outlook邮箱助手.exe`**
3. 浏览器打开 `http://127.0.0.1:17890`
4. 默认账号：`admin` / `admin123`（**首次登录强制改密**）

### Linux

```bash
tar -xzf outlook-mail-helper-linux-amd64.tar.gz
cd outlook-mail-helper
chmod +x outlook-mail-helper
./outlook-mail-helper
```

需要系统已安装 PHP 8.1+（cli + curl + openssl + mbstring + pdo_sqlite），或自行放置 `runtime/php/`。

### 从源码运行

```bash
cd app
php -S 127.0.0.1:17890 -t . router.php
```

---

## 导入格式

```text
user@outlook.com----密码----client_id----refresh_token
user2@hotmail.com----密码----client_id----refresh_token
```

分隔符可在设置中修改。

---

## 截图说明（界面）

- 顶部：导入 / 批量验活 / 导出 Live·Dead / 设置
- 卡片：勾选多选、备注、状态 LIVE/DEAD
- 底部黑条：已选数量 → 删除 / 备注 / 验活选中
- 验活：后端任务，顶部进度条可「后台运行」

---

## 安全说明（请读）

- 默认**只监听 127.0.0.1**，不要改成 `0.0.0.0` 裸奔公网。
- 数据库 `app/data/mail.db` 含 refresh_token，**等同邮箱钥匙**，勿明文上传网盘。
- 邮件预览使用 sandbox iframe；仍建议只导入你有权使用的账号。
- 本工具**不提供**批量注册、撞库、未授权访问他人邮箱的能力或教程。

详见 [USER_GUIDE.md](./USER_GUIDE.md) 与 [DISCLAIMER.md](./DISCLAIMER.md)。

---

## 开发

```text
app/                 PHP 应用
  api.php            HTTP API
  check_engine.php   curl_multi 验活引擎
  job_store.php      后台任务状态
  cli/check_worker.php
  dashboard.php      前端
launcher/            Go 启动器源码（编译 exe / linux 二进制）
```

编译启动器：

```bash
cd launcher
go build -ldflags="-s -w" -o Outlook邮箱助手.exe .
# linux:
GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -o outlook-mail-helper .
```

---

## License

[MIT](./LICENSE) — 见 [NOTICE](./NOTICE) 第三方归属。

## Disclaimer

**软件按现状提供，作者不对滥用、封号、数据丢失、法律风险负责。**  
完整声明：[DISCLAIMER.md](./DISCLAIMER.md)
