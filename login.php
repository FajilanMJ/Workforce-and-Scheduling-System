<?php
declare(strict_types=1);

$bootstrapFiles = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/includes/session.php',
    __DIR__ . '/includes/functions.php',
    __DIR__ . '/includes/helpers.php',
    __DIR__ . '/includes/auth.php',
    __DIR__ . '/includes/init.php',
    __DIR__ . '/includes/bootstrap.php',
];

foreach ($bootstrapFiles as $bootstrapFile) {
    if (is_file($bootstrapFile)) {
        require_once $bootstrapFile;
    }
}

require_once __DIR__ . '/controllers/AuthController.php';

if ((!isset($conn) || !($conn instanceof mysqli)) && function_exists('get_db_connection')) {
    $conn = get_db_connection();
}

$resolvedBaseUrl = isset($baseUrl) && is_string($baseUrl)
    ? $baseUrl
    : (defined('BASE_URL') ? (string) BASE_URL : '/Scheduling_System');

handle_login_page($conn ?? null, $resolvedBaseUrl);