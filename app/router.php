<?php
/**
 * PHP 内置服务器路由
 * - 禁止直接访问 data/
 * - 静态文件正常返回
 * - 其余交给对应脚本
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__;

// 拦截数据目录
if (preg_match('#^/data(/|$)#i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden";
    return true;
}

$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    // 让内置服务器直接吐静态文件
    return false;
}

if ($uri === '/' || $uri === '') {
    require $root . '/index.php';
    return true;
}

// 未知路径
http_response_code(404);
echo "404 Not Found";
return true;
