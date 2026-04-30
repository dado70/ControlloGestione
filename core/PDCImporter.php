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

        $sql = str_replace('__ID__', (string)$idAzienda, file_get_contents($tpl));

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                $pdo->exec($stmt);
            }
        }
    }

    private static function templatePath(): string
    {
        return dirname(__DIR__) . '/setup/pdc_template.sql';
    }
}
