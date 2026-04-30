<?php
declare(strict_types=1);

/**
 * Importa piano dei conti, centri di costo e keyword predefiniti
 * (template Villa Ottone) per una determinata azienda.
 *
 * @param PDO $pdo        Connessione PDO già attiva
 * @param int $idAzienda  ID dell'azienda destinazione
 * @throws RuntimeException se il file template non esiste o l'esecuzione fallisce
 */
function importaPDCTemplate(PDO $pdo, int $idAzienda): void
{
    $templateFile = __DIR__ . '/pdc_template.sql';
    if (!file_exists($templateFile)) {
        throw new RuntimeException('File template non trovato: pdc_template.sql');
    }

    $sql = file_get_contents($templateFile);
    // Sostituisce il segnaposto con l'ID reale
    $sql = str_replace('__ID__', (string)$idAzienda, $sql);

    // Esegue statement per statement (il file contiene più INSERT separati da ;)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn(string $s): bool => $s !== '' && !str_starts_with($s, '--')
    );

    foreach ($statements as $stmt) {
        $pdo->exec($stmt);
    }
}
