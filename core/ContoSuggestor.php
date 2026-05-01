<?php

declare(strict_types=1);

class ContoSuggestor
{
    /**
     * Suggerisce il conto contabile più adatto per una riga di fattura.
     *
     * Priorità:
     *   1. Storico confermato stesso fornitore + descrizione simile
     *   2. Storico confermato stesso fornitore (qualsiasi descrizione)
     *   3. Keyword matching su keyword_conto (fallback)
     *
     * @return int|null id_conto con il punteggio più alto, oppure null
     */
    public function suggest(string $descrizione, int $idAzienda, ?int $idCedente = null): ?int
    {
        $desc = mb_strtolower(trim($descrizione), 'UTF-8');

        if ($desc === '') {
            return null;
        }

        // Livello 1 e 2: storico fornitore (richiede id_cedente)
        if ($idCedente !== null) {
            // Livello 1: stesso fornitore + descrizione simile (primi 30 caratteri)
            $prefix = addcslashes(mb_substr($desc, 0, 30, 'UTF-8'), '%_\\');
            $row = Database::fetchOne(
                'SELECT fl.id_conto, COUNT(*) AS freq
                 FROM fatture_linee fl
                 JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
                 WHERE fl.id_azienda = ?
                   AND fl.classificazione_confermata = 1
                   AND fl.id_conto IS NOT NULL
                   AND fe.id_cedente = ?
                   AND LOWER(fl.descrizione) LIKE ?
                 GROUP BY fl.id_conto
                 ORDER BY freq DESC
                 LIMIT 1',
                [$idAzienda, $idCedente, $prefix . '%']
            );
            if ($row) {
                return (int)$row['id_conto'];
            }

            // Livello 2: stesso fornitore, qualsiasi descrizione
            $row = Database::fetchOne(
                'SELECT fl.id_conto, COUNT(*) AS freq
                 FROM fatture_linee fl
                 JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
                 WHERE fl.id_azienda = ?
                   AND fl.classificazione_confermata = 1
                   AND fl.id_conto IS NOT NULL
                   AND fe.id_cedente = ?
                 GROUP BY fl.id_conto
                 ORDER BY freq DESC
                 LIMIT 1',
                [$idAzienda, $idCedente]
            );
            if ($row) {
                return (int)$row['id_conto'];
            }
        }

        // Livello 3: keyword matching (fallback)
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

        arsort($punteggi);
        return (int)array_key_first($punteggi);
    }
}
