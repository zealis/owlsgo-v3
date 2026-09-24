<?php
// PHP 内置服务器开发路由：php -d opcache.enable=0 -S 127.0.0.1:8099 router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    // 保护运行时目录
    if (preg_match('#^/app/(data|plugins|optional|core)/#', $path)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PATH_INFO'] = $path;
require __DIR__ . '/index.php';
