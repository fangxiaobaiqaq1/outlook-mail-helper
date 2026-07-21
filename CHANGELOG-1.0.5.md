# v1.0.5 (2026-07-21)

## 新功能
- **邮箱家族筛选** `mail_group`：Hotmail / Outlook / 其他 正交过滤与统计
- **分块导入**：浏览器本地读文件后按约 4000 行/块提交，去掉 80MB 总包硬限制（10 万+ 号池可直接导）
- 导入完成后重置筛选并保留结果 toast

## 修复 / 内部
- `import_account_batch` 每 500 行 commit，`memory_limit` 提升
- `accounts.email` 索引加速大号池查重
- 共享 `accounts_filter_clause` / 重算 mail_group 运维接口

## 下载
- Windows 绿色版：`OutlookMailHelper-Windows.zip`
- Linux：`outlook-mail-helper-linux-amd64.tar.gz`
- 本次未重编 Inno Setup 安装包（无 ISCC 环境）；Windows 请用绿色 zip

## 升级注意
关掉旧进程后覆盖；浏览器 **Ctrl+F5**。数据库自动加 `mail_group` 列。
