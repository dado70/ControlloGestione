<?php

declare(strict_types=1);

class FatturaImporter
{
    private P7MDecryptor $decryptor;
    private FatturaParser $parser;
    private ContoSuggestor $suggestor;

    public function __construct()
    {
        $this->decryptor = new P7MDecryptor();
        $this->parser    = new FatturaParser();
        $this->suggestor = new ContoSuggestor();
    }

    /**
     * Importa un singolo file fattura (XML o P7M) nel database.
     *
     * @return array{status: string, message: string, id_fattura: int|null, cedente: string, n_linee: int}
     */
    public function importFile(
        string $filePath,
        string $fileName,
        int $idAzienda,
        int $idUtente
    ): array {
        $tipoFile = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $xmlTmp   = null;

        try {
            // 1. Hash SHA256 per deduplicazione
            $hash = hash_file('sha256', $filePath);
            $duplicato = Database::fetchOne(
                'SELECT id FROM fatture_elettroniche WHERE hash_sha256 = ?',
                [$hash]
            );
            if ($duplicato) {
                return [
                    'status'     => 'duplicate',
                    'message'    => 'Fattura già importata (file identico).',
                    'id_fattura' => (int)$duplicato['id'],
                    'cedente'    => '',
                    'n_linee'    => 0,
                ];
            }

            // 2. Decifratura P7M se necessario
            if ($tipoFile === 'p7m') {
                $xmlTmp = $this->decryptor->decrypt($filePath);
                $xmlPath = $xmlTmp;
            } else {
                $xmlPath = $filePath;
            }

            // 3. Parsing XML
            $dati = $this->parser->parse($xmlPath);

            $header  = $dati['header'];
            $cedente = $header['cedente_prestatore'];
            $bodies  = $dati['body'];

            // 4. Upsert cedente_prestatore
            $idCedente = $this->upsertCedente($cedente, $idAzienda);

            $nomeFornitore = $cedente['denominazione']
                ?? trim(($cedente['cognome'] ?? '') . ' ' . ($cedente['nome'] ?? ''))
                ?: 'N/D';

            // 4b. Controllo duplicato per numero + data + P.IVA cedente
            foreach ($bodies as $bodyCheck) {
                $dgCheck = $bodyCheck['dati_generali'];
                $dupLogico = Database::fetchOne(
                    'SELECT fe.id FROM fatture_elettroniche fe
                     WHERE fe.id_azienda=? AND fe.id_cedente=?
                       AND fe.numero_documento=? AND fe.data_documento=?',
                    [
                        $idAzienda,
                        $idCedente,
                        $dgCheck['numero_documento'] ?? '',
                        $dgCheck['data_documento']   ?? '',
                    ]
                );
                if ($dupLogico) {
                    return [
                        'status'     => 'duplicate',
                        'message'    => 'Fattura già presente: n.' . ($dgCheck['numero_documento'] ?? '') .
                                        ' del ' . ($dgCheck['data_documento'] ?? '') .
                                        ' — ' . $nomeFornitore,
                        'id_fattura' => (int)$dupLogico['id'],
                        'cedente'    => $nomeFornitore,
                        'n_linee'    => 0,
                    ];
                }
            }

            $totalLinee = 0;
            $primoIdFattura = null;

            // 5. Per ogni body (normalmente uno solo per FPR12)
            foreach ($bodies as $body) {
                $dg  = $body['dati_generali'];
                $pag = $body['pagamento'];
                $trp = $dg['trasporto'];
                $dt  = $header['dati_trasmissione'];

                Database::beginTransaction();
                try {
                    // Archivia il file
                    $percorsoFile = $this->archiviaFile($filePath, $fileName, $tipoFile);

                    // 6. Insert fattura
                    $idFattura = (int)Database::insert(
                        'INSERT INTO fatture_elettroniche (
                            id_azienda, id_cedente, nome_file, tipo_file, percorso_file, hash_sha256,
                            id_trasmittente_paese, id_trasmittente_codice, progressivo_invio,
                            formato_trasmissione, codice_destinatario, pec_destinatario,
                            tipo_documento, divisa, data_documento, numero_documento,
                            importo_totale, causale,
                            causale_trasporto, numero_colli, descrizione_colli,
                            unita_misura_peso, peso_lordo, peso_netto, data_inizio_trasporto,
                            condizioni_pagamento, modalita_pagamento, data_scadenza_pagamento,
                            importo_pagamento, istituto_finanziario, iban, abi, cab,
                            stato, importata_da
                        ) VALUES (
                            ?,?,?,?,?,?,
                            ?,?,?,
                            ?,?,?,
                            ?,?,?,?,
                            ?,?,
                            ?,?,?,
                            ?,?,?,?,
                            ?,?,?,
                            ?,?,?,?,?,
                            ?,?
                        )',
                        [
                            $idAzienda, $idCedente, $fileName, $tipoFile, $percorsoFile, $hash,
                            $dt['id_trasmittente_paese'], $dt['id_trasmittente_codice'], $dt['progressivo_invio'],
                            $dt['formato_trasmissione'], $dt['codice_destinatario'], $dt['pec_destinatario'],
                            $dg['tipo_documento'], $dg['divisa'], $dg['data_documento'], $dg['numero_documento'],
                            $dg['importo_totale'], $dg['causale'],
                            $trp['causale']      ?? null,
                            $trp['numero_colli'] ?? null,
                            $trp['descrizione_colli'] ?? null,
                            $trp['unita_misura_peso'] ?? null,
                            $trp['peso_lordo']   ?? null,
                            $trp['peso_netto']   ?? null,
                            isset($trp['data_inizio']) ? substr($trp['data_inizio'], 0, 10) : null,
                            $pag['condizioni_pagamento'] ?? null,
                            $pag['modalita_pagamento']   ?? null,
                            $pag['data_scadenza']        ?? null,
                            $pag['importo_pagamento']    ?? null,
                            $pag['istituto_finanziario'] ?? null,
                            $pag['iban']                 ?? null,
                            $pag['abi']                  ?? null,
                            $pag['cab']                  ?? null,
                            'importata',
                            $idUtente,
                        ]
                    );

                    if ($primoIdFattura === null) {
                        $primoIdFattura = $idFattura;
                    }

                    // 7. Insert linee
                    foreach ($body['linee'] as $linea) {
                        $idConto = $this->suggestor->suggest($linea['descrizione'] ?? '', $idAzienda);

                        // Suggerisci anche centro di costo dal mapping predefinito
                        $idCentro = null;
                        if ($idConto) {
                            $mapping = Database::fetchOne(
                                'SELECT id_centro_costo FROM mappatura_conto_cc
                                 WHERE id_azienda = ? AND id_conto = ?
                                 ORDER BY percentuale DESC LIMIT 1',
                                [$idAzienda, $idConto]
                            );
                            $idCentro = $mapping ? (int)$mapping['id_centro_costo'] : null;
                        }

                        Database::query(
                            'INSERT INTO fatture_linee (
                                id_fattura, id_azienda, numero_linea, tipo_cessione_prestazione,
                                codice_articolo_tipo, codice_articolo_valore, descrizione,
                                quantita, unita_misura, data_inizio_periodo, data_fine_periodo,
                                prezzo_unitario, sconto_tipo, sconto_percentuale, sconto_importo,
                                prezzo_totale, aliquota_iva, ritenuta, natura_iva,
                                id_conto, id_centro_costo, classificazione_confermata
                            ) VALUES (
                                ?,?,?,?,
                                ?,?,?,
                                ?,?,?,?,
                                ?,?,?,?,
                                ?,?,?,?,
                                ?,?,?
                            )',
                            [
                                $idFattura, $idAzienda, $linea['numero_linea'], $linea['tipo_cessione_prestazione'],
                                $linea['codice_articolo_tipo'], $linea['codice_articolo_valore'], $linea['descrizione'],
                                $linea['quantita'], $linea['unita_misura'], $linea['data_inizio_periodo'] ?: null, $linea['data_fine_periodo'] ?: null,
                                $linea['prezzo_unitario'], $linea['sconto_tipo'], $linea['sconto_percentuale'], $linea['sconto_importo'],
                                $linea['prezzo_totale'], $linea['aliquota_iva'], $linea['ritenuta'], $linea['natura_iva'],
                                $idConto, $idCentro, 0,
                            ]
                        );
                        $totalLinee++;
                    }

                    // 8. Insert riepilogo IVA
                    foreach ($body['riepilogo_iva'] as $rip) {
                        Database::query(
                            'INSERT INTO fatture_riepilogo_iva
                             (id_fattura, aliquota_iva, natura_iva, imponibile, imposta, esigibilita_iva, riferimento_normativo)
                             VALUES (?,?,?,?,?,?,?)',
                            [
                                $idFattura,
                                $rip['aliquota_iva'],
                                $rip['natura_iva'],
                                $rip['imponibile'],
                                $rip['imposta'],
                                $rip['esigibilita_iva'],
                                $rip['riferimento_normativo'],
                            ]
                        );
                    }

                    Database::commit();
                } catch (Throwable $e) {
                    Database::rollback();
                    throw $e;
                }
            }

            return [
                'status'     => 'ok',
                'message'    => "Importata con successo ($totalLinee righe).",
                'id_fattura' => $primoIdFattura,
                'cedente'    => $nomeFornitore,
                'n_linee'    => $totalLinee,
            ];
        } catch (Throwable $e) {
            return [
                'status'     => 'error',
                'message'    => $e->getMessage(),
                'id_fattura' => null,
                'cedente'    => '',
                'n_linee'    => 0,
            ];
        } finally {
            // Rimuovi file XML temporaneo creato da P7MDecryptor
            if ($xmlTmp !== null && file_exists($xmlTmp)) {
                @unlink($xmlTmp);
            }
        }
    }

    /** Crea o aggiorna il cedente_prestatore e ritorna il suo id */
    private function upsertCedente(array $c, int $idAzienda): int
    {
        $existing = Database::fetchOne(
            'SELECT id FROM cedenti_prestatori WHERE id_azienda = ? AND id_codice = ?',
            [$idAzienda, $c['id_codice']]
        );

        if ($existing) {
            Database::query(
                'UPDATE cedenti_prestatori SET
                    id_paese=?, codice_fiscale=?, denominazione=?, nome=?, cognome=?,
                    titolo=?, cod_eori=?, regime_fiscale=?,
                    sede_indirizzo=?, sede_numerocivico=?, sede_cap=?,
                    sede_comune=?, sede_provincia=?, sede_nazione=?,
                    rea_ufficio=?, rea_numero=?, rea_capitale_sociale=?,
                    rea_socio_unico=?, rea_stato_liquidazione=?,
                    telefono=?, email=?, updated_at=NOW()
                 WHERE id=?',
                [
                    $c['id_paese'], $c['codice_fiscale'], $c['denominazione'], $c['nome'], $c['cognome'],
                    $c['titolo'], $c['cod_eori'], $c['regime_fiscale'],
                    $c['sede_indirizzo'], $c['sede_numerocivico'], $c['sede_cap'],
                    $c['sede_comune'], $c['sede_provincia'], $c['sede_nazione'],
                    $c['rea_ufficio'], $c['rea_numero'], $c['rea_capitale_sociale'],
                    $c['rea_socio_unico'], $c['rea_stato_liquidazione'],
                    $c['telefono'], $c['email'],
                    (int)$existing['id'],
                ]
            );
            return (int)$existing['id'];
        }

        return (int)Database::insert(
            'INSERT INTO cedenti_prestatori (
                id_azienda, id_paese, id_codice, codice_fiscale, denominazione, nome, cognome,
                titolo, cod_eori, regime_fiscale,
                sede_indirizzo, sede_numerocivico, sede_cap, sede_comune, sede_provincia, sede_nazione,
                rea_ufficio, rea_numero, rea_capitale_sociale, rea_socio_unico, rea_stato_liquidazione,
                telefono, email
            ) VALUES (
                ?,?,?,?,?,?,?,
                ?,?,?,
                ?,?,?,?,?,?,
                ?,?,?,?,?,
                ?,?
            )',
            [
                $idAzienda, $c['id_paese'], $c['id_codice'], $c['codice_fiscale'], $c['denominazione'], $c['nome'], $c['cognome'],
                $c['titolo'], $c['cod_eori'], $c['regime_fiscale'],
                $c['sede_indirizzo'], $c['sede_numerocivico'], $c['sede_cap'], $c['sede_comune'], $c['sede_provincia'], $c['sede_nazione'],
                $c['rea_ufficio'], $c['rea_numero'], $c['rea_capitale_sociale'], $c['rea_socio_unico'], $c['rea_stato_liquidazione'],
                $c['telefono'], $c['email'],
            ]
        );
    }

    /** Sposta il file originale in uploads/xml/ o uploads/p7m/ e ritorna il percorso relativo */
    private function archiviaFile(string $filePath, string $fileName, string $tipoFile): string
    {
        $sottodir    = ($tipoFile === 'p7m') ? 'p7m' : 'xml';
        $destDir     = UPLOAD_DIR . $sottodir . '/';
        $safeNome    = preg_replace('/[^A-Za-z0-9._\-]/', '_', $fileName);
        $destPath    = $destDir . date('Ymd_His_') . $safeNome;

        if (!is_dir($destDir)) {
            mkdir($destDir, 0750, true);
        }

        // Copia (non sposta) così il temp file rimane gestibile dal chiamante
        copy($filePath, $destPath);

        return 'uploads/' . $sottodir . '/' . basename($destPath);
    }
}
