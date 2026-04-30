<?php
// Endpoint JSON: verifica tutte le dipendenze per il wizard di installazione
header('Content-Type: application/json; charset=utf-8');

$checks = [];

// PHP version
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$checks['php_version'] = [
    'label'  => 'PHP >= 8.1',
    'ok'     => $phpOk,
    'valore' => PHP_VERSION,
];

// Estensioni PHP
$extensions = [
    'xml'       => 'php-xml',
    'simplexml' => 'php-xml',
    'pdo_mysql' => 'php-mysql',
    'openssl'   => 'php-openssl',
    'mbstring'  => 'php-mbstring',
    'zip'       => 'php-zip',
    'curl'      => 'php-curl',
    'intl'      => 'php-intl',
];
foreach ($extensions as $ext => $pkg) {
    $checks['ext_' . $ext] = [
        'label'  => "Estensione PHP: $ext",
        'ok'     => extension_loaded($ext),
        'valore' => extension_loaded($ext) ? 'caricata' : "mancante (apt install $pkg)",
    ];
}

// OpenSSL CLI
$opensslCli = !empty(shell_exec('which openssl 2>/dev/null'));
$checks['openssl_cli'] = [
    'label'  => 'OpenSSL CLI',
    'ok'     => $opensslCli,
    'valore' => $opensslCli ? trim(shell_exec('openssl version 2>/dev/null')) : 'non trovato (apt install openssl)',
];

// Directory uploads scrivibile
$uploadDir = dirname(__DIR__) . '/uploads/';
$uploadOk  = is_dir($uploadDir) && is_writable($uploadDir);
$checks['upload_dir'] = [
    'label'  => 'Directory uploads/ scrivibile',
    'ok'     => $uploadOk,
    'valore' => $uploadOk ? 'OK' : 'Non scrivibile (chmod 750 uploads/)',
];

// Directory logs scrivibile
$logDir  = dirname(__DIR__) . '/logs/';
$logOk   = is_dir($logDir) && is_writable($logDir);
$checks['log_dir'] = [
    'label'  => 'Directory logs/ scrivibile',
    'ok'     => $logOk,
    'valore' => $logOk ? 'OK' : 'Non scrivibile (chmod 750 logs/)',
];

// config.php template presente
$tplOk = file_exists(dirname(__DIR__) . '/config/config.template.php');
$checks['config_template'] = [
    'label'  => 'config/config.template.php presente',
    'ok'     => $tplOk,
    'valore' => $tplOk ? 'OK' : 'File mancante',
];

// Directory config/ scrivibile (necessaria per generare config.php)
$configDir    = dirname(__DIR__) . '/config/';
$configDirOk  = is_dir($configDir) && is_writable($configDir);
$checks['config_dir'] = [
    'label'  => 'Directory config/ scrivibile',
    'ok'     => $configDirOk,
    'valore' => $configDirOk ? 'OK' : 'Non scrivibile — eseguire: chmod o+w ' . $configDir,
];

// setup/schema.sql presente
$sqlOk = file_exists(__DIR__ . '/schema.sql');
$checks['schema_sql'] = [
    'label'  => 'setup/schema.sql presente',
    'ok'     => $sqlOk,
    'valore' => $sqlOk ? 'OK' : 'File mancante',
];

$allOk = array_reduce($checks, fn($carry, $c) => $carry && $c['ok'], true);

echo json_encode([
    'all_ok' => $allOk,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
