<?php

// Router for "php artisan serve" (development only; Apache/nginx do not use this file).
// Same as Laravel's own, plus one fix: PHP's built-in server finds public/app/index.html when a path
// like /app/login does not exist and reports /app as the script's folder, which makes Laravel see the
// wrong path (404). Every non-file request is pointed back at public/index.php here.

$publicPath = __DIR__.'/public';

$uri = urldecode(parse_url('http://x'.($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
unset($_SERVER['PATH_INFO']);

require_once $publicPath.'/index.php';
