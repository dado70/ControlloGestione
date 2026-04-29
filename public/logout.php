<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';

Auth::init();
Auth::logout();
header('Location: ' . APP_URL . '/public/login.php');
exit;
