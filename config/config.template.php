<?php
// ControlloGestione - Configurazione applicazione
// Copiare in config.php e compilare con i dati reali

// Database
define('DB_HOST',    '{{DB_HOST}}');
define('DB_NAME',    '{{DB_NAME}}');
define('DB_USER',    '{{DB_USER}}');
define('DB_PASS',    '{{DB_PASS}}');
define('DB_CHARSET', 'utf8mb4');

// Applicazione
define('APP_NAME',    'ControlloGestione');
define('APP_VERSION', '1.0.0');
// APP_URL auto-rilevato: funziona su qualsiasi server/IP senza modifiche
$_proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_subpath = '/gesthotel';   // modifica se la cartella sul server si chiama diversamente
define('APP_URL', $_proto . '://' . $_host . $_subpath);
unset($_proto, $_host, $_subpath);
define('APP_ENV',     'development');   // development | production

// Sessioni
define('SESSION_TIMEOUT',     28800);   // 8 ore in secondi
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOGIN_LOCKOUT_TIME',  900);     // 15 minuti in secondi

// Percorsi
define('BASE_DIR',    dirname(__DIR__));
define('UPLOAD_DIR',  BASE_DIR . '/uploads/');
define('LOG_FILE',    BASE_DIR . '/logs/app.log');

// Sicurezza
define('CSRF_TOKEN_LENGTH', 32);

// Errori PHP
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOG_FILE);
