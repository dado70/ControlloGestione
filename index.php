<?php
// Entry point: redirect al login o alla dashboard
$configFile = __DIR__ . '/config/config.php';

// Se non ancora installato → wizard
if (!file_exists($configFile)) {
    header('Location: setup/install.php');
    exit;
}

$cfg = file_get_contents($configFile);
if (strpos($cfg, '{{') !== false) {
    // config.php è ancora il template → wizard
    header('Location: setup/install.php');
    exit;
}

require_once $configFile;
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';

Auth::init();

if (Auth::isLoggedIn()) {
    header('Location: ' . APP_URL . '/public/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/public/login.php');
}
exit;
