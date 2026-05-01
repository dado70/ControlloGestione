<?php
declare(strict_types=1);

class PDCImporter
{
    private static string $templateFile = '';

    public static function importa(PDO $pdo, int $idAzienda): void
    {
        $tpl = self::templatePath();
        if (!file_exists($tpl)) {
            throw new RuntimeException('File template PDC non trovato: ' . $tpl);
        }

        $raw = file_get_contents($tpl);
        $raw = str_replace('__ID__', (string)$idAzienda, $raw);
        // Rimuove le righe di commento SQL (--) prima di dividere per ";"
        $sql = preg_replace('/^--[^\n]*$/m', '', $raw);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    private static function templatePath(): string
    {
        return dirname(__DIR__) . '/setup/pdc_template.sql';
    }
}
