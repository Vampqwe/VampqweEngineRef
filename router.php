<?php
// Встроенный PHP-сервер: эмуляция .htaccess — все запросы идут в index.php,
// существующие статические файлы отдаются напрямую.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false; // serve as-is
}
require __DIR__ . '/index.php';
