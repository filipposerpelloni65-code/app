<?php
define('APP_ROOT', dirname(__DIR__));
$_appDocRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$_appRootPath = str_replace('\\', '/', dirname(__DIR__));
$_appUrlPath = $_appDocRoot !== '' ? str_replace($_appDocRoot, '', $_appRootPath) : '';
define('APP_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_appUrlPath);
unset($_appDocRoot, $_appRootPath, $_appUrlPath);

$configFile = APP_ROOT . '/config.ini';
if (!file_exists($configFile)) {
    $currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($currentPath, '/install/') === false) {
        header('Location: ' . APP_URL . '/install/index.php');
        exit;
    }
    return;
}

$config = parse_ini_file($configFile, true);
define('DB_HOST', $config['database']['host'] ?? 'localhost');
define('DB_PORT', $config['database']['port'] ?? '3306');
define('DB_NAME', $config['database']['name'] ?? '');
define('DB_USER', $config['database']['user'] ?? '');
define('DB_PASS', $config['database']['pass'] ?? '');
define('APP_NAME', $config['app']['name'] ?? 'HelpDesk');
define('APP_VERSION', '1.0.0');
