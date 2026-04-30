<?php

declare(strict_types=1);

class ContoSuggestor
{
    /**
     * Suggerisce il conto contabile più adatto per una riga di fattura,
     * cercando per corrispondenza keyword in keyword_conto.
     *
     * @return int|null id_conto con il peso cumulativo più alto, oppure null
     */
    public function suggest(string $descrizione, int $idAzienda): ?int
    {
        if (trim($descrizione) === '') {
            return null;
        }

        $desc = mb_strtolower(trim($descrizione), 'UTF-8');

        // Recupera tutte le keyword per questa azienda
        $keywords = Database::fetchAll(
            'SELECT kc.id_conto, kc.keyword, kc.peso
             FROM keyword_conto kc
             JOIN piano_conti pc ON pc.id = kc.id_conto
             WHERE kc.id_azienda = ? AND pc.attivo = 1
             ORDER BY kc.peso DESC',
            [$idAzienda]
        );

        if (empty($keywords)) {
            return null;
        }

        // Accumula pesi per conto
        $punteggi = [];
        foreach ($keywords as $kw) {
            $keyword = mb_strtolower(trim($kw['keyword']), 'UTF-8');
            if ($keyword !== '' && str_contains($desc, $keyword)) {
                $idConto = (int)$kw['id_conto'];
                $punteggi[$idConto] = ($punteggi[$idConto] ?? 0) + (int)$kw['peso'];
            }
        }

        if (empty($punteggi)) {
            return null;
        }

        // Ritorna l'id_conto con punteggio più alto
        arsort($punteggi);
        return (int)array_key_first($punteggi);
    }
}
