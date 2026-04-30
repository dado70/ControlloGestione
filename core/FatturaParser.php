<?php

declare(strict_types=1);

class FatturaParser
{
    /**
     * Analizza un file XML FatturaPA FPR12 v1.2.
     * Ritorna un array strutturato con header e body[].
     *
     * @throws RuntimeException se il file XML non è valido
     */
    public function parse(string $xmlPath): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $errori = array_map(fn($e) => $e->message, libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException('XML non valido: ' . implode('; ', $errori));
        }

        libxml_clear_errors();

        $header = $this->parseHeader($xml->FatturaElettronicaHeader);

        $body = [];
        // Il body può essere un singolo elemento o una lista (multi-body)
        $bodies = $xml->FatturaElettronicaBody;
        if ($bodies === null) {
            throw new RuntimeException('Struttura FatturaElettronicaBody mancante.');
        }

        foreach ($bodies as $b) {
            $body[] = $this->parseBody($b);
        }

        if (empty($body)) {
            throw new RuntimeException('Nessun FatturaElettronicaBody trovato.');
        }

        return ['header' => $header, 'body' => $body];
    }

    private function parseHeader(\SimpleXMLElement $h): array
    {
        $dt = $h->DatiTrasmissione;
        $cp = $h->CedentePrestatore;
        $cc = $h->CessionarioCommittente;

        return [
            'dati_trasmissione' => [
                'id_trasmittente_paese'  => $this->str($dt->IdTrasmittente->IdPaese),
                'id_trasmittente_codice' => $this->str($dt->IdTrasmittente->IdCodice),
                'progressivo_invio'      => $this->str($dt->ProgressivoInvio),
                'formato_trasmissione'   => $this->str($dt->FormatoTrasmissione),
                'codice_destinatario'    => $this->str($dt->CodiceDestinatario),
                'pec_destinatario'       => $this->str($dt->PECDestinatario),
            ],
            'cedente_prestatore' => $this->parseCedente($cp),
            'cessionario_committente' => [
                'id_paese'       => $this->str($cc->DatiAnagrafici->IdFiscaleIVA->IdPaese),
                'id_codice'      => $this->str($cc->DatiAnagrafici->IdFiscaleIVA->IdCodice),
                'codice_fiscale' => $this->str($cc->DatiAnagrafici->CodiceFiscale),
                'denominazione'  => $this->str($cc->DatiAnagrafici->Anagrafica->Denominazione),
                'nome'           => $this->str($cc->DatiAnagrafici->Anagrafica->Nome),
                'cognome'        => $this->str($cc->DatiAnagrafici->Anagrafica->Cognome),
            ],
        ];
    }

    private function parseCedente(\SimpleXMLElement $cp): array
    {
        $da  = $cp->DatiAnagrafici;
        $sed = $cp->Sede;
        $rea = $cp->IscrizioneREA;
        $con = $cp->Contatti;

        return [
            'id_paese'       => $this->str($da->IdFiscaleIVA->IdPaese) ?: 'IT',
            'id_codice'      => $this->str($da->IdFiscaleIVA->IdCodice),
            'codice_fiscale' => $this->str($da->CodiceFiscale),
            'denominazione'  => $this->str($da->Anagrafica->Denominazione),
            'nome'           => $this->str($da->Anagrafica->Nome),
            'cognome'        => $this->str($da->Anagrafica->Cognome),
            'titolo'         => $this->str($da->Anagrafica->Titolo),
            'cod_eori'       => $this->str($da->Anagrafica->CodEORI),
            'regime_fiscale' => $this->str($da->RegimeFiscale),
            // Sede
            'sede_indirizzo'    => $this->str($sed->Indirizzo),
            'sede_numerocivico' => $this->str($sed->NumeroCivico),
            'sede_cap'          => $this->str($sed->CAP),
            'sede_comune'       => $this->str($sed->Comune),
            'sede_provincia'    => $this->str($sed->Provincia),
            'sede_nazione'      => $this->str($sed->Nazione) ?: 'IT',
            // REA
            'rea_ufficio'            => $rea ? $this->str($rea->Ufficio) : null,
            'rea_numero'             => $rea ? $this->str($rea->NumeroREA) : null,
            'rea_capitale_sociale'   => $rea ? $this->decimal($rea->CapitaleSociale) : null,
            'rea_socio_unico'        => $rea ? $this->str($rea->SocioUnico) : null,
            'rea_stato_liquidazione' => $rea ? $this->str($rea->StatoLiquidazione) : null,
            // Contatti
            'telefono' => $con ? $this->str($con->Telefono) : null,
            'email'    => $con ? $this->str($con->Email) : null,
        ];
    }

    private function parseBody(\SimpleXMLElement $b): array
    {
        $dg  = $b->DatiGenerali;
        $dbs = $b->DatiBeniServizi;
        $dp  = $b->DatiPagamento;

        $dgd = $dg->DatiGeneraliDocumento;

        // ScontoMaggiorazione globale (opzionale)
        $scontoGlobale = null;
        if (isset($dgd->ScontoMaggiorazione)) {
            $scontoGlobale = [
                'tipo'        => $this->str($dgd->ScontoMaggiorazione->Tipo),
                'percentuale' => $this->decimal($dgd->ScontoMaggiorazione->Percentuale),
                'importo'     => $this->decimal($dgd->ScontoMaggiorazione->Importo),
            ];
        }

        // Causale può essere multi-valore
        $causali = [];
        if (isset($dgd->Causale)) {
            foreach ($dgd->Causale as $caus) {
                $causali[] = $this->str($caus);
            }
        }

        $datiGenerali = [
            'tipo_documento'      => $this->str($dgd->TipoDocumento),
            'divisa'              => $this->str($dgd->Divisa) ?: 'EUR',
            'data_documento'      => $this->str($dgd->Data),
            'numero_documento'    => $this->str($dgd->Numero),
            'importo_totale'      => $this->decimal($dgd->ImportoTotaleDocumento),
            'causale'             => implode(' ', $causali),
            'sconto_maggiorazione' => $scontoGlobale,
            // DatiTrasporto (opzionale)
            'trasporto'           => $this->parseTrasporto($dg->DatiTrasporto ?? null),
        ];

        // DettaglioLinee
        $linee = [];
        if ($dbs) {
            foreach ($dbs->DettaglioLinee ?? [] as $dl) {
                $linee[] = $this->parseLinea($dl);
            }
        }

        // DatiRiepilogo
        $riepilogo = [];
        if ($dbs) {
            foreach ($dbs->DatiRiepilogo ?? [] as $dr) {
                $riepilogo[] = [
                    'aliquota_iva'           => $this->decimal($dr->AliquotaIVA),
                    'natura_iva'             => $this->str($dr->Natura),
                    'imponibile'             => $this->decimal($dr->ImponibileImporto),
                    'imposta'                => $this->decimal($dr->Imposta),
                    'esigibilita_iva'        => $this->str($dr->EsigibilitaIVA),
                    'riferimento_normativo'  => $this->str($dr->RiferimentoNormativo),
                ];
            }
        }

        // DatiPagamento (primo blocco)
        $pagamento = null;
        if ($dp) {
            $firstDp = $dp[0] ?? $dp;
            $firstDet = $firstDp->DettaglioPagamento[0] ?? $firstDp->DettaglioPagamento ?? null;
            $pagamento = [
                'condizioni_pagamento' => $this->str($firstDp->CondizioniPagamento),
                'modalita_pagamento'   => $firstDet ? $this->str($firstDet->ModalitaPagamento) : null,
                'data_scadenza'        => $firstDet ? $this->str($firstDet->DataScadenzaPagamento) : null,
                'importo_pagamento'    => $firstDet ? $this->decimal($firstDet->ImportoPagamento) : null,
                'istituto_finanziario' => $firstDet ? $this->str($firstDet->IstitutoFinanziario) : null,
                'iban'                 => $firstDet ? $this->str($firstDet->IBAN) : null,
                'abi'                  => $firstDet ? $this->str($firstDet->ABI) : null,
                'cab'                  => $firstDet ? $this->str($firstDet->CAB) : null,
            ];
        }

        return [
            'dati_generali' => $datiGenerali,
            'linee'         => $linee,
            'riepilogo_iva' => $riepilogo,
            'pagamento'     => $pagamento,
        ];
    }

    private function parseLinea(\SimpleXMLElement $dl): array
    {
        // CodiceArticolo (opzionale)
        $codTipo   = null;
        $codValore = null;
        if (isset($dl->CodiceArticolo)) {
            $codTipo   = $this->str($dl->CodiceArticolo->CodiceTipo);
            $codValore = $this->str($dl->CodiceArticolo->CodiceValore);
        }

        // ScontoMaggiorazione (opzionale)
        $scontoTipo = null;
        $scontoPct  = null;
        $scontoImp  = null;
        if (isset($dl->ScontoMaggiorazione)) {
            $scontoTipo = $this->str($dl->ScontoMaggiorazione->Tipo);
            $scontoPct  = $this->decimal($dl->ScontoMaggiorazione->Percentuale);
            $scontoImp  = $this->decimal($dl->ScontoMaggiorazione->Importo);
        }

        return [
            'numero_linea'              => (int)$this->str($dl->NumeroLinea),
            'tipo_cessione_prestazione' => $this->str($dl->TipoCessionePrestazione),
            'codice_articolo_tipo'      => $codTipo,
            'codice_articolo_valore'    => $codValore,
            'descrizione'               => $this->str($dl->Descrizione),
            'quantita'                  => $this->decimal($dl->Quantita),
            'unita_misura'              => $this->str($dl->UnitaMisura),
            'data_inizio_periodo'       => $this->str($dl->DataInizioPeriodo),
            'data_fine_periodo'         => $this->str($dl->DataFinePeriodo),
            'prezzo_unitario'           => $this->decimal($dl->PrezzoUnitario),
            'sconto_tipo'               => $scontoTipo,
            'sconto_percentuale'        => $scontoPct,
            'sconto_importo'            => $scontoImp,
            'prezzo_totale'             => $this->decimal($dl->PrezzoTotale),
            'aliquota_iva'              => $this->decimal($dl->AliquotaIVA),
            'ritenuta'                  => $this->str($dl->Ritenuta),
            'natura_iva'                => $this->str($dl->Natura),
        ];
    }

    private function parseTrasporto(?\SimpleXMLElement $dt): ?array
    {
        if ($dt === null) {
            return null;
        }
        return [
            'causale'              => $this->str($dt->CausaleTrasporto),
            'numero_colli'         => (int)$this->str($dt->NumeroColli),
            'descrizione_colli'    => $this->str($dt->Descrizione),
            'unita_misura_peso'    => $this->str($dt->UnitaMisuraPeso),
            'peso_lordo'           => $this->decimal($dt->PesoLordo),
            'peso_netto'           => $this->decimal($dt->PesoNetto),
            'data_inizio'          => $this->str($dt->DataOraInizioTrasporto),
        ];
    }

    /** Converte un nodo SimpleXML in stringa, null se vuoto */
    private function str(mixed $node): ?string
    {
        if ($node === null) {
            return null;
        }
        $val = trim((string)$node);
        return $val !== '' ? $val : null;
    }

    /** Converte un nodo SimpleXML in float, null se vuoto */
    private function decimal(mixed $node): ?float
    {
        $s = $this->str($node);
        return $s !== null ? (float)str_replace(',', '.', $s) : null;
    }
}
