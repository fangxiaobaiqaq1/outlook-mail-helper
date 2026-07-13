@echo off
chcp 65001 >nul
setlocal EnableExtensions EnableDelayedExpansion
title Outlook 邮箱助手
cd /d "%~dp0"

set "APP_DIR=%~dp0app"
set "RUNTIME_DIR=%~dp0runtime"
set "PHP_DIR=%RUNTIME_DIR%\php"
set "PHP_EXE=%PHP_DIR%\php.exe"
set "PORT=17890"
set "HOST=127.0.0.1"

echo.
echo  ============================================
echo    Outlook 邮箱助手  -  Windows 便携版
echo  ============================================
echo.

if not exist "%APP_DIR%\index.php" (
  echo [错误] 找不到 app 目录，请勿单独移动启动脚本。
  pause
  exit /b 1
)

if not exist "%PHP_EXE%" (
  echo [信息] 首次运行，正在准备便携 PHP 运行时...
  call "%~dp0tools\ensure_php.bat"
  if errorlevel 1 (
    echo [错误] PHP 准备失败。
    pause
    exit /b 1
  )
)

if not exist "%PHP_EXE%" (
  echo [错误] 仍未找到 php.exe
  pause
  exit /b 1
)

REM 找空闲端口（简单探测）
for %%P in (17890 17891 17892 17900 18080 18888) do (
  netstat -ano | findstr /R /C:":%%P .*LISTENING" >nul 2>&1
  if errorlevel 1 (
    set "PORT=%%P"
    goto :port_ok
  )
)
:port_ok

REM 不再把中文绝对路径写入 php.ini（会变成 ??? 导致 HTTPS 失败）
REM CA 由 PHP 代码通过 CURLOPT_CAINFO 加载 runtime\php\cacert.pem

if not exist "%APP_DIR%\data" mkdir "%APP_DIR%\data"

echo [信息] PHP : "%PHP_EXE%"
echo [信息] 地址: http://%HOST%:!PORT!
echo [信息] 默认账号 admin / admin123 （首次登录强制改密）
echo [信息] 关闭本窗口即停止服务
echo.

start "" "http://%HOST%:!PORT!/"
"%PHP_EXE%" -S %HOST%:!PORT! -t "%APP_DIR%" "%APP_DIR%\router.php"
echo.
echo 服务已停止。
pause
