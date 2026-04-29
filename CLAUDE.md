# CLAUDE.md — GestHotel FE: Sistema di Controllo di Gestione per Fatture Elettroniche
## Progetto: Villa Ottone SRL — Hotel Management ERP (Fatture Passive + Controllo di Gestione)

---

## 1. PANORAMICA DEL PROGETTO

Sistema web di **controllo di gestione** per hotel basato su PHP 8.x + MySQL 8.x (stack LAMP), installabile in sottocartella di Apache (es. `/gesthotel`). Il sistema:

1. **Importa fatture elettroniche passive** in formato `.xml` e `.p7m` (FatturaPA FPR12 v1.2)
2. **Struttura i dati** secondo le tabelle ministeriali (CedentiPrestatori, DatiGeneraliFE, DatiBeniServizi/DettaglioLinee)
3. **Classifica i costi** tramite piano dei conti aziendale e sua mappatura al bilancio CEE semplificato
4. **Analisi di controllo di gestione** per centri di costo, fornitore, periodo, tipologia
5. **Multi-azienda / multi-utente** con ruoli profilati per azienda
6. **Portale cliente** con accesso read-only a report e analisi tramite dashboard Metabase embedded

---

## 2. STACK TECNOLOGICO

```
Backend:   PHP 8.1+
Database:  MySQL 8.0+
Frontend:  Bootstrap 5 + vanilla JS + Chart.js
Server:    Apache 2.4 su Debian/Ubuntu/Mint (LAMP)
Subfolder: /var/www/html/gesthotel  (configurabile via config.php)
XML:       PHP SimpleXML / DOMDocument (nativo PHP)
P7M:       OpenSSL (CLI: openssl smime) + php-openssl extension
Installer: Script bash install.sh + setup wizard PHP
```

**Dipendenze PHP extensions richieste** (verificate dall'installer):
- `php-xml`, `php-mbstring`, `php-mysql` (o `php-mysqli`), `php-openssl`, `php-zip`, `php-curl`, `php-intl`

**Dipendenze sistema** (verificate dall'installer):
- `openssl` (CLI), `mysql-client`, `apache2`, `libapache2-mod-php`

---

## 3. STRUTTURA DIRECTORY

```
gesthotel/
├── install.sh                  # Installer bash: verifica deps, crea DB, permessi
├── index.php                   # Router principale / redirect al login
├── config/
│   └── config.php              # DB credentials, app settings, percorso uploads
├── setup/
│   ├── install.php             # Wizard web post-install.sh
│   ├── check_deps.php          # Verifica estensioni PHP e tools CLI
│   └── schema.sql              # DDL completo del database
├── public/
│   ├── login.php
│   ├── dashboard.php
│   ├── fatture/
│   │   ├── upload.php          # Upload XML/P7M (drag&drop, multi-file)
│   │   ├── lista.php           # Archivio fatture con filtri
│   │   └── dettaglio.php       # Dettaglio fattura + righe
│   ├── fornitori/
│   │   ├── lista.php
│   │   └── dettaglio.php
│   ├── analisi/
│   │   ├── costi_periodo.php
│   │   ├── costi_fornitore.php
│   │   ├── centri_costo.php
│   │   ├── bilancio_cee.php
│   │   └── export.php          # Export CSV/Excel/PDF
│   ├── impostazioni/
│   │   ├── piano_conti.php     # CRUD piano dei conti
│   │   ├── mappatura_cee.php   # Mappatura PDC → CEE
│   │   ├── centri_costo.php    # CRUD centri di costo/ricavo
│   │   ├── mappatura_cc.php    # Mappatura conto → centro di costo
│   │   ├── aziende.php         # CRUD aziende (multi-ditta)
│   │   └── utenti.php          # CRUD utenti e ruoli
│   └── api/
│       ├── suggest_conto.php   # AI-assist: suggerisce conto da descrizione linea
│       └── stats.php           # JSON per Chart.js dashboard
├── core/
│   ├── Auth.php
│   ├── Database.php            # PDO wrapper
│   ├── FatturaParser.php       # Parser XML FPR12 → array strutturato
│   ├── P7MDecryptor.php        # Decripta .p7m con openssl smime
│   ├── FatturaImporter.php     # Orchestratore: parse → validate → insert DB
│   ├── ContoSuggestor.php      # Keyword matching PDC da descrizione linea
│   └── Router.php
├── uploads/
│   ├── xml/                    # File XML originali archiviati
│   └── p7m/                    # File P7M originali archiviati
└── assets/
    ├── css/
    ├── js/
    └── img/
```

---

## 4. SCHEMA DATABASE

### Convenzioni
- Tutte le tabelle hanno `id_azienda INT NOT NULL` per multi-tenancy
- Charset: `utf8mb4_unicode_ci`
- Timestamp: `created_at`, `updated_at` su tutte le tabelle principali

---

### 4.1 Tabella `aziende`
```sql
CREATE TABLE aziende (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ragione_sociale VARCHAR(255) NOT NULL,
  partita_iva VARCHAR(20) NOT NULL UNIQUE,
  codice_fiscale VARCHAR(20),
  indirizzo VARCHAR(255),
  cap VARCHAR(10),
  comune VARCHAR(100),
  provincia CHAR(2),
  nazione CHAR(2) DEFAULT 'IT',
  codice_destinatario VARCHAR(10),       -- SdI codice destinatario
  pec_destinatario VARCHAR(255),
  attiva TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

### 4.2 Tabella `utenti`
```sql
CREATE TABLE utenti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,   -- bcrypt
  nome VARCHAR(100),
  cognome VARCHAR(100),
  email VARCHAR(255),
  ruolo ENUM('superadmin','admin','operatore','readonly') DEFAULT 'operatore',
  attivo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

### 4.3 Tabella `utenti_aziende` (relazione N:N con ruolo per azienda)
```sql
CREATE TABLE utenti_aziende (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_utente INT NOT NULL,
  id_azienda INT NOT NULL,
  ruolo ENUM('admin','operatore','readonly') DEFAULT 'operatore',
  UNIQUE KEY uk_utente_azienda (id_utente, id_azienda),
  FOREIGN KEY (id_utente) REFERENCES utenti(id),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id)
);
```

---

### 4.4 Tabella `cedenti_prestatori`
Contiene tutti i dati del tag `<CedentePrestatore>` del tracciato FPR12.

```sql
CREATE TABLE cedenti_prestatori (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  -- IdFiscaleIVA
  id_paese CHAR(2) DEFAULT 'IT',
  id_codice VARCHAR(28) NOT NULL,        -- Partita IVA o identificativo estero
  -- DatiAnagrafici
  codice_fiscale VARCHAR(16),
  denominazione VARCHAR(255),            -- Anagrafica/Denominazione
  nome VARCHAR(100),                     -- Anagrafica/Nome (persona fisica)
  cognome VARCHAR(100),                  -- Anagrafica/Cognome (persona fisica)
  titolo VARCHAR(10),
  cod_eori VARCHAR(17),
  regime_fiscale VARCHAR(4),             -- RF01, RF02... tabella ministeriale
  -- Sede
  sede_indirizzo VARCHAR(255),
  sede_numerocivico VARCHAR(10),
  sede_cap VARCHAR(10),
  sede_comune VARCHAR(100),
  sede_provincia CHAR(2),
  sede_nazione CHAR(2) DEFAULT 'IT',
  -- IscrizioneREA (opzionale)
  rea_ufficio CHAR(2),
  rea_numero VARCHAR(20),
  rea_capitale_sociale DECIMAL(15,2),
  rea_socio_unico ENUM('SU','SM'),
  rea_stato_liquidazione ENUM('LS','LN'),
  -- Contatti (opzionale)
  telefono VARCHAR(30),
  email VARCHAR(255),
  -- Metadati
  attivo TINYINT(1) DEFAULT 1,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_piva_azienda (id_azienda, id_codice),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id)
);
```

---

### 4.5 Tabella `fatture_elettroniche` (DatiGeneraliFE)
Contiene header fattura + dati generali documento + dati trasmissione.

```sql
CREATE TABLE fatture_elettroniche (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  id_cedente INT NOT NULL,               -- FK cedenti_prestatori
  -- File originale
  nome_file VARCHAR(255) NOT NULL,       -- nome file originale (.xml o .p7m)
  tipo_file ENUM('xml','p7m') NOT NULL,
  percorso_file VARCHAR(500),            -- path relativo in uploads/
  hash_sha256 VARCHAR(64),               -- impronta file per deduplicazione
  -- DatiTrasmissione
  id_trasmittente_paese CHAR(2),
  id_trasmittente_codice VARCHAR(28),
  progressivo_invio VARCHAR(10),
  formato_trasmissione VARCHAR(10),      -- FPR12, FPA12
  codice_destinatario VARCHAR(10),
  pec_destinatario VARCHAR(255),
  -- DatiGeneraliDocumento
  tipo_documento VARCHAR(4) NOT NULL,    -- TD01, TD04, TD07...
  divisa CHAR(3) DEFAULT 'EUR',
  data_documento DATE NOT NULL,
  numero_documento VARCHAR(20) NOT NULL,
  importo_totale DECIMAL(15,2),
  causale TEXT,
  -- DatiTrasporto (opzionale)
  causale_trasporto VARCHAR(100),
  numero_colli INT,
  descrizione_colli VARCHAR(100),
  unita_misura_peso VARCHAR(10),
  peso_lordo DECIMAL(10,3),
  peso_netto DECIMAL(10,3),
  data_inizio_trasporto DATE,
  -- DatiPagamento (primo record)
  condizioni_pagamento VARCHAR(4),       -- TP01, TP02, TP03
  modalita_pagamento VARCHAR(4),         -- MP01..MP23
  data_scadenza_pagamento DATE,
  importo_pagamento DECIMAL(15,2),
  istituto_finanziario VARCHAR(100),
  iban VARCHAR(34),
  abi VARCHAR(5),
  cab VARCHAR(5),
  -- Stato gestionale
  stato ENUM('importata','verificata','contabilizzata','pagata','annullata') DEFAULT 'importata',
  data_import TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  importata_da INT,                      -- FK utenti
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_hash (hash_sha256),      -- evita doppi import
  INDEX idx_azienda_data (id_azienda, data_documento),
  INDEX idx_cedente (id_cedente),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id),
  FOREIGN KEY (id_cedente) REFERENCES cedenti_prestatori(id)
);
```

---

### 4.6 Tabella `fatture_linee` (DettaglioLinee)
Una riga per ogni `<DettaglioLinee>` della fattura.

```sql
CREATE TABLE fatture_linee (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fattura INT NOT NULL,
  id_azienda INT NOT NULL,               -- denormalizzato per query veloci
  -- Dati ministeriali
  numero_linea INT NOT NULL,
  tipo_cessione_prestazione VARCHAR(4),  -- AC, SC, AB, PB
  codice_articolo_tipo VARCHAR(35),      -- CodiceArticolo/CodiceTipo
  codice_articolo_valore VARCHAR(35),    -- CodiceArticolo/CodiceValore
  descrizione TEXT NOT NULL,
  quantita DECIMAL(12,5),
  unita_misura VARCHAR(10),
  data_inizio_periodo DATE,
  data_fine_periodo DATE,
  prezzo_unitario DECIMAL(15,8),
  sconto_tipo ENUM('SC','MG'),           -- SC=Sconto, MG=Maggiorazione
  sconto_percentuale DECIMAL(6,2),
  sconto_importo DECIMAL(15,2),
  prezzo_totale DECIMAL(15,2) NOT NULL,
  aliquota_iva DECIMAL(6,2),
  ritenuta VARCHAR(2),
  natura_iva VARCHAR(4),                 -- N1..N7 per operazioni esenti/escluse
  -- Classificazione gestionale (assegnata da operatore / suggerita)
  id_conto INT,                          -- FK piano_conti
  id_centro_costo INT,                   -- FK centri_costo
  classificazione_confermata TINYINT(1) DEFAULT 0,  -- 0=suggerita, 1=confermata
  note_classificazione VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (id_fattura) REFERENCES fatture_elettroniche(id) ON DELETE CASCADE,
  FOREIGN KEY (id_azienda) REFERENCES aziende(id),
  INDEX idx_fattura (id_fattura),
  INDEX idx_conto (id_conto),
  INDEX idx_centro (id_centro_costo)
);
```

---

### 4.7 Tabella `fatture_riepilogo_iva` (DatiRiepilogo)
```sql
CREATE TABLE fatture_riepilogo_iva (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fattura INT NOT NULL,
  aliquota_iva DECIMAL(6,2),
  natura_iva VARCHAR(4),
  imponibile DECIMAL(15,2),
  imposta DECIMAL(15,2),
  esigibilita_iva CHAR(1),               -- I=immediata, D=differita, S=split payment
  riferimento_normativo VARCHAR(100),
  FOREIGN KEY (id_fattura) REFERENCES fatture_elettroniche(id) ON DELETE CASCADE
);
```

---

### 4.8 Tabella `piano_conti`
Piano dei conti aziendale (Villa Ottone usa codici tipo `73.01.013`, 3 livelli).

```sql
CREATE TABLE piano_conti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  codice VARCHAR(20) NOT NULL,           -- es. "73.01.013"
  descrizione VARCHAR(255) NOT NULL,
  livello TINYINT NOT NULL DEFAULT 3,    -- 1=mastro, 2=conto, 3=sottoconto
  codice_padre VARCHAR(20),              -- codice del livello superiore
  tipo ENUM('COSTO','RICAVO','ATTIVO','PASSIVO','PATRIMONIALE') NOT NULL,
  attivo TINYINT(1) DEFAULT 1,
  note VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_codice_azienda (id_azienda, codice),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id)
);
```

---

### 4.9 Tabella `piano_conti_cee`
Piano dei conti CEE semplificato (struttura OIC / Schema CEE art. 2424 c.c.).

```sql
CREATE TABLE piano_conti_cee (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codice VARCHAR(20) NOT NULL UNIQUE,    -- es. "1B0201", "2A0300"
  descrizione VARCHAR(255) NOT NULL,
  livello TINYINT NOT NULL,
  codice_padre VARCHAR(20),
  sezione ENUM('ATTIVO','PASSIVO','PATRIMONIO_NETTO','COSTI','RICAVI') NOT NULL,
  formula_totalizzazione VARCHAR(100),   -- formula per aggregazioni
  stampa_bilancio TINYINT(1) DEFAULT 1,
  ordine INT
);
```

---

### 4.10 Tabella `mappatura_pdc_cee`
Tabella di raccordo tra piano dei conti aziendale e schema CEE.

```sql
CREATE TABLE mappatura_pdc_cee (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  id_conto INT NOT NULL,                 -- FK piano_conti
  codice_cee VARCHAR(20) NOT NULL,       -- FK piano_conti_cee
  note VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_mapping (id_azienda, id_conto, codice_cee),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id),
  FOREIGN KEY (id_conto) REFERENCES piano_conti(id)
);
```

---

### 4.11 Tabella `centri_costo`
Centri di costo e ricavo, scalabili.

```sql
CREATE TABLE centri_costo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  codice VARCHAR(20) NOT NULL,
  descrizione VARCHAR(100) NOT NULL,
  tipo ENUM('COSTO','RICAVO','MISTO') DEFAULT 'COSTO',
  attivo TINYINT(1) DEFAULT 1,
  ordine INT DEFAULT 0,
  note VARCHAR(255),
  UNIQUE KEY uk_cc_azienda (id_azienda, codice),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id)
);
```

Dati iniziali Villa Ottone:
```sql
INSERT INTO centri_costo (id_azienda, codice, descrizione, tipo) VALUES
(1, 'CC01', 'Cucina / Ristorante', 'COSTO'),
(1, 'CC02', 'Bar', 'COSTO'),
(1, 'CC03', 'Housekeeping / Lavanderia', 'COSTO'),
(1, 'CC04', 'Manutenzione & Giardino', 'COSTO'),
(1, 'CC05', 'Reception / Front Office', 'COSTO'),
(1, 'CC06', 'Amministrazione', 'COSTO'),
(1, 'CC07', 'Energie & Utilities', 'COSTO'),
(1, 'CC08', 'Marketing', 'COSTO'),
(1, 'CC09', 'Balneare', 'COSTO');
```

---

### 4.12 Tabella `mappatura_conto_cc`
Suggerisce il centro di costo default per un conto del PDC.

```sql
CREATE TABLE mappatura_conto_cc (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  id_conto INT NOT NULL,
  id_centro_costo INT NOT NULL,
  percentuale DECIMAL(5,2) DEFAULT 100.00,  -- per ripartizioni future
  UNIQUE KEY uk_conto_cc (id_azienda, id_conto, id_centro_costo),
  FOREIGN KEY (id_conto) REFERENCES piano_conti(id),
  FOREIGN KEY (id_centro_costo) REFERENCES centri_costo(id)
);
```

---

### 4.13 Tabella `keyword_conto`
Per il motore di suggerimento conto da descrizione riga fattura.

```sql
CREATE TABLE keyword_conto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  id_conto INT NOT NULL,
  keyword VARCHAR(100) NOT NULL,         -- parola chiave lowercase
  peso INT DEFAULT 1,                    -- più alto = più rilevante
  FOREIGN KEY (id_conto) REFERENCES piano_conti(id)
);
```

---

### 4.14 Tabella `movimenti_contabili` (per analisi mensile)
Consuntivo mensile importato manualmente (per ora) o da fatture.

```sql
CREATE TABLE movimenti_contabili (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_azienda INT NOT NULL,
  anno YEAR NOT NULL,
  mese TINYINT NOT NULL,                 -- 1-12
  id_conto INT NOT NULL,
  id_centro_costo INT,
  dare DECIMAL(15,2) DEFAULT 0,
  avere DECIMAL(15,2) DEFAULT 0,
  origine ENUM('fattura','manuale','import_xls') DEFAULT 'fattura',
  id_fattura INT,                        -- se origine=fattura
  note VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_periodo (id_azienda, anno, mese),
  FOREIGN KEY (id_azienda) REFERENCES aziende(id),
  FOREIGN KEY (id_conto) REFERENCES piano_conti(id)
);
```

---

## 5. MODULO IMPORT FATTURE (Core Logic)

### 5.1 Flusso di importazione

```
[Upload file(s)] → [Detect tipo: xml/p7m]
                        ↓
              [P7M? → P7MDecryptor → xml]
                        ↓
              [FatturaParser → array dati]
                        ↓
              [Validate: P.IVA, date, importi]
                        ↓
              [Hash SHA256 → check duplicati]
                        ↓
              [Upsert CedentePrestatore]
                        ↓
              [Insert fatture_elettroniche]
                        ↓
              [Insert fatture_linee (loop DettaglioLinee)]
                        ↓
              [Insert fatture_riepilogo_iva]
                        ↓
              [ContoSuggestor → suggerisce id_conto per ogni linea]
                        ↓
              [Archivia file in uploads/xml/ o uploads/p7m/]
                        ↓
              [Report esito: N importate, M errori, K duplicate]
```

### 5.2 Decriptazione P7M

```php
// P7MDecryptor.php
class P7MDecryptor {
    public function decrypt(string $p7mPath): string {
        // Usa openssl smime -verify o openssl cms -verify
        // I file .p7m dallo SDI sono buste CMS/PKCS#7 signed (non encrypted)
        // Non richiedono certificato privato: è solo una firma, non cifratura
        $xmlPath = sys_get_temp_dir() . '/' . uniqid('fe_') . '.xml';
        $cmd = sprintf(
            'openssl smime -verify -noverify -in %s -inform DER -out %s 2>/dev/null',
            escapeshellarg($p7mPath),
            escapeshellarg($xmlPath)
        );
        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            // Fallback: try PEM format
            $cmd2 = sprintf('openssl cms -verify -noverify -in %s -inform PEM -out %s 2>/dev/null',
                escapeshellarg($p7mPath), escapeshellarg($xmlPath));
            exec($cmd2, $output, $returnCode);
        }
        if ($returnCode !== 0 || !file_exists($xmlPath)) {
            throw new Exception("Impossibile decriptare il file P7M: $p7mPath");
        }
        return $xmlPath; // ritorna path del file XML estratto
    }
}
```

**NOTA IMPORTANTE**: I file .p7m provenienti dallo SDI (Sistema di Interscambio) sono
buste **CMS SignedData** (firma digitale), NON cifrate. Non è necessario alcun certificato
privato per la lettura. `openssl smime -verify -noverify` estrae il payload XML.

### 5.3 FatturaParser — Tag supportati (FPR12 v1.2)

Il parser deve gestire TUTTI i tag del tracciato ministeriale:

```
FatturaElettronicaHeader:
  DatiTrasmissione → IdTrasmittente, ProgressivoInvio, FormatoTrasmissione,
                     CodiceDestinatario, PECDestinatario
  CedentePrestatore → DatiAnagrafici (IdFiscaleIVA, CodiceFiscale, Anagrafica,
                      RegimeFiscale), Sede, StabileOrganizzazione, IscrizioneREA,
                      Contatti
  CessionarioCommittente → DatiAnagrafici, Sede (usato per matching azienda)

FatturaElettronicaBody (può essere array per fatture multi-body):
  DatiGenerali:
    DatiGeneraliDocumento → TipoDocumento, Divisa, Data, Numero,
                            ImportoTotaleDocumento, Causale, Art73,
                            DatiRitenuta, DatiBollo, DatiCassaPrevidenziale,
                            ScontoMaggiorazione, DatiOrdineAcquisto,
                            DatiContratto, DatiConvenzione, DatiRicezione,
                            DatiFattureCollegate, DatiSAL, DatiDDT,
                            DatiTrasporto
  DatiBeniServizi:
    DettaglioLinee[] → NumeroLinea, TipoCessionePrestazione, CodiceArticolo,
                       Descrizione, Quantita, UnitaMisura, DataInizioPeriodo,
                       DataFinePeriodo, PrezzoUnitario, ScontoMaggiorazione,
                       PrezzoTotale, AliquotaIVA, Ritenuta, AltriDatiGestionali
    DatiRiepilogo[] → AliquotaIVA, Natura, SpeseAccessorie, Arrotondamento,
                      ImponibileImporto, Imposta, EsigibilitaIVA,
                      RiferimentoNormativo
  DatiPagamento[] → CondizioniPagamento, DettaglioPagamento[]
```

---

## 6. INTERFACCIA WEB — SPECIFICHE UI

### 6.1 Layout generale
- Bootstrap 5 + sidebar navigazione fissa a sinistra
- Header con: nome azienda selezionata, utente loggato, logout
- **Selettore azienda** in header (dropdown) — cambia contesto dati
- Lingua: **italiano** in tutto il sistema
- Responsive (mobile-friendly per consultazione)

### 6.2 Dashboard (dashboard.php)
Widget KPI mensili (Chart.js):
- Totale fatture importate nel mese
- Totale costi del mese (imponibile) vs mese precedente (variazione %)
- Top 5 fornitori per importo mese corrente
- Grafici: andamento costi mensile (line chart 12 mesi), ripartizione per centro di costo (pie/donut)
- Fatture in stato "importata" (da classificare) — alert se > 0

### 6.3 Upload Fatture (fatture/upload.php)
- Drag & drop multi-file (JS)
- Accetta: `.xml`, `.p7m`, `.zip` (con dentro xml/p7m)
- Progress bar per ogni file
- Tabella risultati import: file | stato | cedente rilevato | n.righe | errore
- Pulsante "Classifica ora" → va alla lista fatture filtrata per "da classificare"

### 6.4 Lista Fatture (fatture/lista.php)
Filtri: azienda, cedente, periodo (da/a), tipo documento, stato, importo min/max
Colonne: data, numero, cedente, tipo, imponibile, IVA, totale, stato, azioni
Azioni riga: Dettaglio, Classifica, Cambia stato, Elimina (solo admin)
Export: CSV, Excel

### 6.5 Dettaglio Fattura + Classificazione (fatture/dettaglio.php)
- Header fattura (tutti i dati)
- Tabella righe con colonne: n.linea | descrizione | qta | u.m. | prezzo unit. | sconto | prezzo totale | IVA | **Conto** (dropdown) | **Centro di Costo** (dropdown) | Confermata (checkbox)
- Il conto è **pre-popolato dal suggeritore** (evidenziato in giallo se non confermato)
- Pulsante "Salva classificazione" — AJAX
- Pulsante "Conferma tutto" — marca tutte le righe come classificate
- Riepilogo IVA in fondo

### 6.6 Analisi Costi per Periodo (analisi/costi_periodo.php)
Filtri: azienda, anno, mese/trimestre, centro di costo, tipo documento
Output:
- Tabella pivot: conti in riga × mesi in colonna (importi imponibili)
- Grafico bar confronto mensile
- Totali per macro-categoria PDC (73=acquisti, 75=servizi, 79=personale...)

### 6.7 Analisi per Fornitore (analisi/costi_fornitore.php)
- Ranking fornitori per importo anno/periodo
- Dettaglio per fornitore: fatture, importi, conto associato, centri di costo
- Grafici trend fornitore su 12 mesi

### 6.8 Analisi Centri di Costo (analisi/centri_costo.php)
- Tabella: centro di costo | totale costi mese | totale costi ytd | budget (futuro)
- Drill-down: click su centro → dettaglio conti e fatture del centro
- Export PDF report centro di costo

### 6.9 Bilancio CEE Semplificato (analisi/bilancio_cee.php)
- Riclassificazione automatica tramite `mappatura_pdc_cee`
- Visualizza schema CEE: Attivo/Passivo SP, Costi/Ricavi CE
- Confronto anno corrente vs anno precedente (colonne)
- Fonte dati: tabella `movimenti_contabili` + dati da `fatture_linee`

### 6.10 Impostazioni — Piano dei Conti (impostazioni/piano_conti.php)
- Albero a 3 livelli (mastro → conto → sottoconto)
- CRUD: aggiungi, modifica, disattiva conto
- Import da CSV/Excel
- Colonna "Tipo" (Costo/Ricavo/Attivo/Passivo)
- Pulsante: "Aggiorna mappatura CEE" per i nuovi conti

### 6.11 Impostazioni — Mappatura PDC↔CEE (impostazioni/mappatura_cee.php)
- Tabella: conto aziendale | descrizione | → | conto CEE | descrizione CEE
- Filtro: "Non mappati" per trovare conti senza abbinamento
- Suggerimento automatico per keyword matching
- Import/Export mappatura via CSV

### 6.12 Impostazioni — Centri di Costo (impostazioni/centri_costo.php)
- CRUD centri di costo (scalabile: aggiungi/modifica/disattiva)
- Tabella mappatura conto → centro di costo (default)

### 6.13 Gestione Utenti e Aziende (impostazioni/utenti.php)
- Superadmin: gestisce tutte le aziende e tutti gli utenti
- Admin azienda: gestisce utenti della propria azienda
- Tabella utenti con ruolo per azienda
- Reset password

---

## 7. SISTEMA DI AUTENTICAZIONE E SICUREZZA

```php
// Auth.php
- Session-based authentication (php session)
- Password hashing: password_hash() con PASSWORD_BCRYPT
- CSRF token su tutti i form POST
- Sanitizzazione input: htmlspecialchars(), PDO prepared statements SEMPRE
- Rate limiting login: max 5 tentativi in 15 minuti (tabella login_attempts)
- Timeout sessione: 8 ore (configurabile)
- Controllo ruolo per ogni pagina (middleware Auth::requireRole())
- Log accessi: tabella audit_log (utente, azione, ip, timestamp)
```

**Matrice ruoli**:
| Funzione | superadmin | admin | operatore | readonly |
|---|---|---|---|---|
| Upload fatture | ✓ | ✓ | ✓ | ✗ |
| Classificare righe | ✓ | ✓ | ✓ | ✗ |
| Eliminare fatture | ✓ | ✓ | ✗ | ✗ |
| Gestire PDC/mappature | ✓ | ✓ | ✗ | ✗ |
| Gestire utenti | ✓ | (propria az.) | ✗ | ✗ |
| Gestire aziende | ✓ | ✗ | ✗ | ✗ |
| Analisi/Report | ✓ | ✓ | ✓ | ✓ |

---

## 8. INSTALLER (install.sh + setup/install.php)

### install.sh
```bash
#!/bin/bash
# 1. Verifica prerequisiti sistema
check_command() { command -v "$1" >/dev/null 2>&1 || { echo "ERRORE: $1 non trovato. Installare con: apt install $1"; exit 1; }; }
check_command php
check_command mysql
check_command openssl
check_command apache2

# 2. Verifica versione PHP (>= 8.1)
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
# Confronta versione...

# 3. Verifica estensioni PHP richieste
REQUIRED_EXTENSIONS=(xml mbstring pdo_mysql openssl zip curl intl)
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
  php -m | grep -q "^$ext$" || echo "ATTENZIONE: Estensione PHP $ext non trovata. Installare: apt install php-$ext"
done

# 4. Crea directory uploads con permessi corretti
mkdir -p gesthotel/uploads/{xml,p7m}
chown www-data:www-data gesthotel/uploads -R
chmod 750 gesthotel/uploads -R

# 5. Crea config.php da template
cp gesthotel/config/config.template.php gesthotel/config/config.php

# 6. Apri wizard web
echo "Installazione base completata. Aprire http://localhost/gesthotel/setup/ nel browser per completare la configurazione."
```

### setup/install.php (Wizard web 4 step)
- **Step 1**: Verifica dipendenze (mostra tabella verde/rosso per ogni dep)
- **Step 2**: Configurazione DB (host, user, password, nome DB) → test connessione
- **Step 3**: Creazione schema DB (esegue schema.sql) → import dati iniziali PDC + CEE
- **Step 4**: Creazione account superadmin → completamento

### setup/check_deps.php
```php
// Ritorna JSON con stato di tutte le dipendenze
$checks = [
  'php_version'    => version_compare(PHP_VERSION, '8.1', '>='),
  'ext_xml'        => extension_loaded('xml'),
  'ext_simplexml'  => extension_loaded('simplexml'),
  'ext_pdo_mysql'  => extension_loaded('pdo_mysql'),
  'ext_openssl'    => extension_loaded('openssl'),
  'ext_mbstring'   => extension_loaded('mbstring'),
  'ext_zip'        => extension_loaded('zip'),
  'openssl_cli'    => (shell_exec('which openssl') !== null),
  'upload_dir_writable' => is_writable(__DIR__ . '/../uploads/'),
  'mysql_available'=> ... // test connessione
];
```

---

## 9. DATI INIZIALI DA CARICARE

### 9.1 Piano dei conti Villa Ottone (da pdc1.pdf — già estratto)
Il piano dei conti Villa Ottone usa codici a 3 livelli separati da punto (es. `73.01.013`).
I conti movimentati nel bilancio 2025 (290 voci) devono essere pre-caricati via `schema.sql`.

Macro-categorie identificate dal bilancio 2025:
- **04.xx.xxx**: Immobilizzazioni immateriali
- **13.xx.xxx**: Immobilizzazioni materiali
- **16.xx.xxx**: Fondi ammortamento
- **22.xx.xxx / 25.xx.xxx**: Attivo circolante
- **28.xx.xxx**: Crediti e disponibilità
- **34.xx.xxx**: Banche e cassa
- **40.xx.xxx / 46.xx.xxx**: Patrimonio netto e TFR
- **49.xx.xxx**: Debiti
- **52.xx.xxx**: Ratei e risconti
- **60.xx.xxx**: Ricavi da servizi alberghieri
- **71.xx.xxx**: Altri ricavi
- **73.xx.xxx**: Acquisti merci e materiali
- **75.xx.xxx**: Servizi (energia, manutenzioni, consulenze, commerciali)
- **77.xx.xxx**: Godimento beni di terzi (noleggi, affitti)
- **79.xx.xxx**: Costo del personale
- **81.xx.xxx / 83.xx.xxx**: Ammortamenti
- **89.xx.xxx**: Variazioni rimanenze
- **92.xx.xxx**: Oneri diversi di gestione
- **93.xx.xxx**: Proventi e oneri finanziari
- **95.xx.xxx**: Poste straordinarie

### 9.2 Piano dei conti CEE (da agganciocee.pdf)
Schema CEE con codici tipo `1B0201`, struttura gerarchica con formule.
Caricare tutti i nodi (sia di totalizzazione che di dettaglio).

### 9.3 Keyword iniziali per suggeritore conti
Pre-popolare `keyword_conto` con associazioni logiche:
```
"energia elettrica" → 75.01.025
"gas" / "gpl" / "metano" → 75.01.029
"gasolio" → 75.01.033 / 73.09.006
"acqua" → 75.01.037
"manutenzione" / "riparazione" → 75.05.181
"noleggio biancheria" → 77.05.157
"commissioni" / "agenzia" → 75.13.501
"assicurazione" → 75.15.001
"telefon" → 75.11.113
"internet" → 75.17.500
"pubblicità" / "marketing" → 75.13.037
"cancelleria" → 73.09.045
"trasporto" → 75.01.005
"vino" / "bevande" / "alimenti" / "merci" → 73.01.013
"software" / "licenza" → 77.07.013
"siae" → 75.17.137
"sky" / "rai" → 75.17.503
"musicist" → 75.17.501
```

---

## 10. CONVENZIONI DI CODICE

- **PSR-12** coding standard per tutto il PHP
- **PDO prepared statements** per TUTTE le query (zero SQL injection)
- Nessun framework MVC pesante: PHP puro con autoloading semplice
- Ogni classe in `core/` ha un solo file, nome CamelCase
- Le view in `public/` usano `require_once '../core/Auth.php'` all'inizio
- Output HTML sempre con `htmlspecialchars()` per variabili dinamiche
- Errori PHP: `error_reporting(E_ALL)` in development, `0` in production (config)
- Log errori su file (`logs/app.log`), mai a schermo in production
- Commenti in **italiano** (progetto italiano)

---

## 11. FASI DI SVILUPPO CONSIGLIATE

### Fase 1 — Foundation (priorità massima)
1. `install.sh` + `setup/` wizard
2. Schema SQL completo
3. `core/Database.php`, `core/Auth.php`, `core/Router.php`
4. Login/Logout, gestione sessione
5. Scaffolding layout Bootstrap 5

### Fase 2 — Import Fatture
6. `core/P7MDecryptor.php`
7. `core/FatturaParser.php` (parser XML FPR12 completo)
8. `core/FatturaImporter.php`
9. `public/fatture/upload.php` (drag&drop)
10. `public/fatture/lista.php` e `dettaglio.php`

### Fase 3 — Classificazione e PDC
11. `public/impostazioni/piano_conti.php` (CRUD + import CSV)
12. `core/ContoSuggestor.php`
13. Classificazione righe in dettaglio fattura (AJAX)
14. `public/impostazioni/centri_costo.php`
15. `public/impostazioni/mappatura_cee.php`

### Fase 4 — Analisi e Dashboard
16. `public/dashboard.php` con KPI e Chart.js
17. `public/analisi/costi_periodo.php`
18. `public/analisi/costi_fornitore.php`
19. `public/analisi/centri_costo.php`
20. `public/analisi/bilancio_cee.php`
21. Export CSV/Excel/PDF

### Fase 5 — Multi-ditta e Utenti
22. `public/impostazioni/aziende.php`
23. `public/impostazioni/utenti.php`
24. Profiling ruoli completo

### Fase 6 — Futura (non implementare ora)
- Fatture attive
- Budget
- Notifiche scadenze email
- Import bilancio da file contabile

---

## 12. NOTE SPECIFICHE VILLA OTTONE

- **P.IVA Cessionario**: `00074440496` — VILLA OTTONE S.R.L.
- **Indirizzo**: LOC. OTTONE, 57037 PORTOFERRAIO (LI)
- Il bilancio 2025 mostra **perdita di €141.398,54** su ~€4,1M ricavi
- Voci di costo principali: Personale (€1,75M), Acquisti merci (€0,73M), Servizi (€0,58M), Ammortamenti (€0,44M), Finanziari (€0,28M), IMU (€43k)
- Ricavi principali: Corrispettivi prestazioni (€3,72M), Penali clienti (€72k), Ricavi servizi (€195k)
- Il software sarà usato per monitorare l'andamento mensile rispetto a questi consuntivi

---

## 13. RIFERIMENTI NORMATIVI

- Tracciato FatturaPA: https://www.fatturapa.gov.it/it/norme-e-regole/documentazione-fattura-pa/
- Specifiche tecniche FPR12 v1.2.1: Allegato A del DM 55/2013 e successive
- Schema XSD: http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2
- Codici natura IVA (N1-N7), tipi documento (TD01-TD28), regimi fiscali (RF01-RF19)
- Schema CEE bilancio: art. 2424 e 2425 c.c. + OIC 12

---

*Documento generato il 29/04/2026 — GestHotel FE v1.0*
*Azienda: Villa Ottone SRL — P.IVA 00074440496*
