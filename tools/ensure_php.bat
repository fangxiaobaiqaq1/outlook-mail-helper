@echo off
chcp 65001 >nul
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0.."

set "RUNTIME_DIR=%cd%\runtime"
set "PHP_DIR=%RUNTIME_DIR%\php"
set "ZIP=%RUNTIME_DIR%\php.zip"
REM 多镜像回退：releases 当前版 → archives 稳定版
set "URL1=https://windows.php.net/downloads/releases/php-8.3.32-Win32-vs16-x64.zip"
set "URL2=https://windows.php.net/downloads/releases/archives/php-8.3.30-Win32-vs16-x64.zip"
set "URL3=https://windows.php.net/downloads/releases/archives/php-8.2.28-Win32-vs16-x64.zip"

if not exist "%RUNTIME_DIR%" mkdir "%RUNTIME_DIR%"

where curl >nul 2>&1
if errorlevel 1 (
  echo [错误] 系统缺少 curl，请安装 Windows 10 1803+ 自带 curl，或手动下载 PHP 到 runtime\php\
  echo 下载地址: https://windows.php.net/downloads/releases/
  echo 需要 x64 Thread Safe zip，解压到 runtime\php\ 使 php.exe 位于该目录。
  exit /b 1
)

echo [下载] 正在获取 PHP 便携包...
set "OK=0"
for %%U in ("%URL1%" "%URL2%" "%URL3%") do (
  if "!OK!"=="0" (
    echo URL: %%~U
    curl -L --retry 2 --connect-timeout 20 -o "%ZIP%" "%%~U"
    if not errorlevel 1 (
      for %%S in ("%ZIP%") do if %%~zS GTR 1000000 (
        set "OK=1"
      )
    )
  )
)
if "%OK%"=="0" (
  echo [错误] 自动下载失败。请手动把 PHP zip 放到:
  echo   %ZIP%
  echo 下载: https://windows.php.net/downloads/releases/
  echo 然后重新运行 启动.bat
  exit /b 1
)

echo [解压] ...
if exist "%PHP_DIR%" rmdir /s /q "%PHP_DIR%"
mkdir "%PHP_DIR%"

REM 优先 tar（Win10+），失败用 PowerShell
tar -xf "%ZIP%" -C "%PHP_DIR%" 2>nul
if errorlevel 1 (
  powershell -NoProfile -Command "Expand-Archive -LiteralPath '%ZIP%' -DestinationPath '%PHP_DIR%' -Force"
  if errorlevel 1 (
    echo [错误] 解压失败
    exit /b 1
  )
)

REM 有的 zip 自带一层目录
if not exist "%PHP_DIR%\php.exe" (
  for /d %%D in ("%PHP_DIR%\*") do (
    if exist "%%D\php.exe" (
      xcopy "%%D\*" "%PHP_DIR%\" /E /Y /Q >nul
    )
  )
)

if not exist "%PHP_DIR%\php.exe" (
  echo [错误] 解压后找不到 php.exe
  exit /b 1
)

REM 启用扩展
if exist "%PHP_DIR%\php.ini-production" (
  copy /Y "%PHP_DIR%\php.ini-production" "%PHP_DIR%\php.ini" >nul
) else if exist "%PHP_DIR%\php.ini-development" (
  copy /Y "%PHP_DIR%\php.ini-development" "%PHP_DIR%\php.ini" >nul
)

if exist "%PHP_DIR%\php.ini" (
  powershell -NoProfile -Command ^
    "$p='%PHP_DIR%\php.ini'; $c=Get-Content -Raw $p; ^
     $c=$c -replace ';extension_dir = \"ext\"','extension_dir = \"ext\"'; ^
     $c=$c -replace ';extension=curl','extension=curl'; ^
     $c=$c -replace ';extension=openssl','extension=openssl'; ^
     $c=$c -replace ';extension=mbstring','extension=mbstring'; ^
     $c=$c -replace ';extension=pdo_sqlite','extension=pdo_sqlite'; ^
     $c=$c -replace ';extension=sqlite3','extension=sqlite3'; ^
     $c=$c -replace ';extension=fileinfo','extension=fileinfo'; ^
     Set-Content -NoNewline -Path $p -Value $c -Encoding ASCII"
)

REM 下载 CA 证书（微软 OAuth HTTPS 需要）
if not exist "%PHP_DIR%\cacert.pem" (
  echo [下载] CA 证书 cacert.pem ...
  curl -L --retry 2 --connect-timeout 20 -o "%PHP_DIR%\cacert.pem" "https://curl.se/ca/cacert.pem" >nul 2>&1
)

REM CA loaded by PHP code, not written to php.ini

echo [完成] PHP 已就绪: %PHP_DIR%\php.exe
del /f /q "%ZIP%" >nul 2>&1
exit /b 0
