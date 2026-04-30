-- ControlloGestione v0.0.1 - Schema Database
-- Charset: utf8mb4_unicode_ci
-- Compatibile con MySQL 8.0+

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

-- =============================================================
-- TABELLE
-- =============================================================

CREATE TABLE IF NOT EXISTS `aziende` (
  `id`                    INT            AUTO_INCREMENT PRIMARY KEY,
  `ragione_sociale`       VARCHAR(255)   NOT NULL,
  `partita_iva`           VARCHAR(20)    NOT NULL,
  `codice_fiscale`        VARCHAR(20)    DEFAULT NULL,
  `indirizzo`             VARCHAR(255)   DEFAULT NULL,
  `cap`                   VARCHAR(10)    DEFAULT NULL,
  `comune`                VARCHAR(100)   DEFAULT NULL,
  `provincia`             CHAR(2)        DEFAULT NULL,
  `nazione`               CHAR(2)        DEFAULT 'IT',
  `codice_destinatario`   VARCHAR(10)    DEFAULT NULL,
  `pec_destinatario`      VARCHAR(255)   DEFAULT NULL,
  `attiva`                TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_piva` (`partita_iva`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utenti` (
  `id`            INT            AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50)    NOT NULL,
  `password_hash` VARCHAR(255)   NOT NULL,
  `nome`          VARCHAR(100)   DEFAULT NULL,
  `cognome`       VARCHAR(100)   DEFAULT NULL,
  `email`         VARCHAR(255)   DEFAULT NULL,
  `ruolo`         ENUM('superadmin','admin','operatore','readonly') NOT NULL DEFAULT 'operatore',
  `attivo`        TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utenti_aziende` (
  `id`         INT  AUTO_INCREMENT PRIMARY KEY,
  `id_utente`  INT  NOT NULL,
  `id_azienda` INT  NOT NULL,
  `ruolo`      ENUM('admin','operatore','readonly') NOT NULL DEFAULT 'operatore',
  UNIQUE KEY `uk_utente_azienda` (`id_utente`, `id_azienda`),
  FOREIGN KEY (`id_utente`)  REFERENCES `utenti`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `piano_conti_cee` (
  `id`                    INT            AUTO_INCREMENT PRIMARY KEY,
  `codice`                VARCHAR(20)    NOT NULL,
  `descrizione`           VARCHAR(255)   NOT NULL,
  `livello`               TINYINT        NOT NULL,
  `codice_padre`          VARCHAR(20)    DEFAULT NULL,
  `sezione`               ENUM('ATTIVO','PASSIVO','PATRIMONIO_NETTO','COSTI','RICAVI') NOT NULL,
  `formula_totalizzazione` VARCHAR(100)  DEFAULT NULL,
  `stampa_bilancio`       TINYINT(1)     NOT NULL DEFAULT 1,
  `ordine`                INT            DEFAULT 0,
  UNIQUE KEY `uk_codice_cee` (`codice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `piano_conti` (
  `id`           INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`   INT            NOT NULL,
  `codice`       VARCHAR(20)    NOT NULL,
  `descrizione`  VARCHAR(255)   NOT NULL,
  `livello`      TINYINT        NOT NULL DEFAULT 3,
  `codice_padre` VARCHAR(20)    DEFAULT NULL,
  `tipo`         ENUM('COSTO','RICAVO','ATTIVO','PASSIVO','PATRIMONIALE') NOT NULL,
  `attivo`       TINYINT(1)     NOT NULL DEFAULT 1,
  `note`         VARCHAR(255)   DEFAULT NULL,
  `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_codice_azienda` (`id_azienda`, `codice`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `centri_costo` (
  `id`          INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`  INT            NOT NULL,
  `codice`      VARCHAR(20)    NOT NULL,
  `descrizione` VARCHAR(100)   NOT NULL,
  `tipo`        ENUM('COSTO','RICAVO','MISTO') NOT NULL DEFAULT 'COSTO',
  `attivo`      TINYINT(1)     NOT NULL DEFAULT 1,
  `ordine`      INT            NOT NULL DEFAULT 0,
  `note`        VARCHAR(255)   DEFAULT NULL,
  UNIQUE KEY `uk_cc_azienda` (`id_azienda`, `codice`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mappatura_pdc_cee` (
  `id`          INT         AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`  INT         NOT NULL,
  `id_conto`    INT         NOT NULL,
  `codice_cee`  VARCHAR(20) NOT NULL,
  `note`        VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_mapping` (`id_azienda`, `id_conto`, `codice_cee`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`),
  FOREIGN KEY (`id_conto`)   REFERENCES `piano_conti`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mappatura_conto_cc` (
  `id`              INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`      INT            NOT NULL,
  `id_conto`        INT            NOT NULL,
  `id_centro_costo` INT            NOT NULL,
  `percentuale`     DECIMAL(5,2)   NOT NULL DEFAULT 100.00,
  UNIQUE KEY `uk_conto_cc` (`id_azienda`, `id_conto`, `id_centro_costo`),
  FOREIGN KEY (`id_conto`)        REFERENCES `piano_conti`(`id`),
  FOREIGN KEY (`id_centro_costo`) REFERENCES `centri_costo`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `keyword_conto` (
  `id`         INT           AUTO_INCREMENT PRIMARY KEY,
  `id_azienda` INT           NOT NULL,
  `id_conto`   INT           NOT NULL,
  `keyword`    VARCHAR(100)  NOT NULL,
  `peso`       INT           NOT NULL DEFAULT 1,
  FOREIGN KEY (`id_conto`) REFERENCES `piano_conti`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cedenti_prestatori` (
  `id`                   INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`           INT            NOT NULL,
  `id_paese`             CHAR(2)        NOT NULL DEFAULT 'IT',
  `id_codice`            VARCHAR(28)    NOT NULL,
  `codice_fiscale`       VARCHAR(16)    DEFAULT NULL,
  `denominazione`        VARCHAR(255)   DEFAULT NULL,
  `nome`                 VARCHAR(100)   DEFAULT NULL,
  `cognome`              VARCHAR(100)   DEFAULT NULL,
  `titolo`               VARCHAR(10)    DEFAULT NULL,
  `cod_eori`             VARCHAR(17)    DEFAULT NULL,
  `regime_fiscale`       VARCHAR(4)     DEFAULT NULL,
  `sede_indirizzo`       VARCHAR(255)   DEFAULT NULL,
  `sede_numerocivico`    VARCHAR(10)    DEFAULT NULL,
  `sede_cap`             VARCHAR(10)    DEFAULT NULL,
  `sede_comune`          VARCHAR(100)   DEFAULT NULL,
  `sede_provincia`       CHAR(2)        DEFAULT NULL,
  `sede_nazione`         CHAR(2)        NOT NULL DEFAULT 'IT',
  `rea_ufficio`          CHAR(2)        DEFAULT NULL,
  `rea_numero`           VARCHAR(20)    DEFAULT NULL,
  `rea_capitale_sociale` DECIMAL(15,2)  DEFAULT NULL,
  `rea_socio_unico`      ENUM('SU','SM') DEFAULT NULL,
  `rea_stato_liquidazione` ENUM('LS','LN') DEFAULT NULL,
  `telefono`             VARCHAR(30)    DEFAULT NULL,
  `email`                VARCHAR(255)   DEFAULT NULL,
  `attivo`               TINYINT(1)     NOT NULL DEFAULT 1,
  `note`                 TEXT           DEFAULT NULL,
  `created_at`           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_piva_azienda` (`id_azienda`, `id_codice`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fatture_elettroniche` (
  `id`                        INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`                INT            NOT NULL,
  `id_cedente`                INT            NOT NULL,
  `nome_file`                 VARCHAR(255)   NOT NULL,
  `tipo_file`                 ENUM('xml','p7m') NOT NULL,
  `percorso_file`             VARCHAR(500)   DEFAULT NULL,
  `hash_sha256`               VARCHAR(64)    DEFAULT NULL,
  `id_trasmittente_paese`     CHAR(2)        DEFAULT NULL,
  `id_trasmittente_codice`    VARCHAR(28)    DEFAULT NULL,
  `progressivo_invio`         VARCHAR(10)    DEFAULT NULL,
  `formato_trasmissione`      VARCHAR(10)    DEFAULT NULL,
  `codice_destinatario`       VARCHAR(10)    DEFAULT NULL,
  `pec_destinatario`          VARCHAR(255)   DEFAULT NULL,
  `tipo_documento`            VARCHAR(4)     NOT NULL,
  `divisa`                    CHAR(3)        NOT NULL DEFAULT 'EUR',
  `data_documento`            DATE           NOT NULL,
  `numero_documento`          VARCHAR(20)    NOT NULL,
  `importo_totale`            DECIMAL(15,2)  DEFAULT NULL,
  `causale`                   TEXT           DEFAULT NULL,
  `causale_trasporto`         VARCHAR(100)   DEFAULT NULL,
  `numero_colli`              INT            DEFAULT NULL,
  `descrizione_colli`         VARCHAR(100)   DEFAULT NULL,
  `unita_misura_peso`         VARCHAR(10)    DEFAULT NULL,
  `peso_lordo`                DECIMAL(10,3)  DEFAULT NULL,
  `peso_netto`                DECIMAL(10,3)  DEFAULT NULL,
  `data_inizio_trasporto`     DATE           DEFAULT NULL,
  `condizioni_pagamento`      VARCHAR(4)     DEFAULT NULL,
  `modalita_pagamento`        VARCHAR(4)     DEFAULT NULL,
  `data_scadenza_pagamento`   DATE           DEFAULT NULL,
  `importo_pagamento`         DECIMAL(15,2)  DEFAULT NULL,
  `istituto_finanziario`      VARCHAR(100)   DEFAULT NULL,
  `iban`                      VARCHAR(34)    DEFAULT NULL,
  `abi`                       VARCHAR(5)     DEFAULT NULL,
  `cab`                       VARCHAR(5)     DEFAULT NULL,
  `stato`                     ENUM('importata','verificata','contabilizzata','pagata','annullata') NOT NULL DEFAULT 'importata',
  `data_import`               TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `importata_da`              INT            DEFAULT NULL,
  `note`                      TEXT           DEFAULT NULL,
  `created_at`                TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_hash` (`hash_sha256`),
  INDEX `idx_azienda_data` (`id_azienda`, `data_documento`),
  INDEX `idx_cedente`      (`id_cedente`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`),
  FOREIGN KEY (`id_cedente`) REFERENCES `cedenti_prestatori`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fatture_linee` (
  `id`                          INT             AUTO_INCREMENT PRIMARY KEY,
  `id_fattura`                  INT             NOT NULL,
  `id_azienda`                  INT             NOT NULL,
  `numero_linea`                INT             NOT NULL,
  `tipo_cessione_prestazione`   VARCHAR(4)      DEFAULT NULL,
  `codice_articolo_tipo`        VARCHAR(35)     DEFAULT NULL,
  `codice_articolo_valore`      VARCHAR(35)     DEFAULT NULL,
  `descrizione`                 TEXT            NOT NULL,
  `quantita`                    DECIMAL(12,5)   DEFAULT NULL,
  `unita_misura`                VARCHAR(10)     DEFAULT NULL,
  `data_inizio_periodo`         DATE            DEFAULT NULL,
  `data_fine_periodo`           DATE            DEFAULT NULL,
  `prezzo_unitario`             DECIMAL(15,8)   DEFAULT NULL,
  `sconto_tipo`                 ENUM('SC','MG') DEFAULT NULL,
  `sconto_percentuale`          DECIMAL(6,2)    DEFAULT NULL,
  `sconto_importo`              DECIMAL(15,2)   DEFAULT NULL,
  `prezzo_totale`               DECIMAL(15,2)   NOT NULL,
  `aliquota_iva`                DECIMAL(6,2)    DEFAULT NULL,
  `ritenuta`                    VARCHAR(2)      DEFAULT NULL,
  `natura_iva`                  VARCHAR(4)      DEFAULT NULL,
  `id_conto`                    INT             DEFAULT NULL,
  `id_centro_costo`             INT             DEFAULT NULL,
  `classificazione_confermata`  TINYINT(1)      NOT NULL DEFAULT 0,
  `note_classificazione`        VARCHAR(255)    DEFAULT NULL,
  `created_at`                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_fattura` (`id_fattura`),
  INDEX `idx_conto`   (`id_conto`),
  INDEX `idx_centro`  (`id_centro_costo`),
  FOREIGN KEY (`id_fattura`) REFERENCES `fatture_elettroniche`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fatture_riepilogo_iva` (
  `id`                   INT            AUTO_INCREMENT PRIMARY KEY,
  `id_fattura`           INT            NOT NULL,
  `aliquota_iva`         DECIMAL(6,2)   DEFAULT NULL,
  `natura_iva`           VARCHAR(4)     DEFAULT NULL,
  `imponibile`           DECIMAL(15,2)  DEFAULT NULL,
  `imposta`              DECIMAL(15,2)  DEFAULT NULL,
  `esigibilita_iva`      CHAR(1)        DEFAULT NULL,
  `riferimento_normativo` VARCHAR(100)  DEFAULT NULL,
  FOREIGN KEY (`id_fattura`) REFERENCES `fatture_elettroniche`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `movimenti_contabili` (
  `id`              INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`      INT            NOT NULL,
  `anno`            YEAR           NOT NULL,
  `mese`            TINYINT        NOT NULL,
  `id_conto`        INT            NOT NULL,
  `id_centro_costo` INT            DEFAULT NULL,
  `dare`            DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `avere`           DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `origine`         ENUM('fattura','manuale','import_xls') NOT NULL DEFAULT 'fattura',
  `id_fattura`      INT            DEFAULT NULL,
  `note`            VARCHAR(255)   DEFAULT NULL,
  `created_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_periodo` (`id_azienda`, `anno`, `mese`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`),
  FOREIGN KEY (`id_conto`)   REFERENCES `piano_conti`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         INT          AUTO_INCREMENT PRIMARY KEY,
  `username`   VARCHAR(50)  NOT NULL,
  `ip`         VARCHAR(45)  NOT NULL,
  `creato_il`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_username_data` (`username`, `creato_il`),
  INDEX `idx_ip_data`       (`ip`, `creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         INT           AUTO_INCREMENT PRIMARY KEY,
  `id_utente`  INT           DEFAULT NULL,
  `username`   VARCHAR(50)   DEFAULT NULL,
  `azione`     VARCHAR(100)  NOT NULL,
  `dettaglio`  TEXT          DEFAULT NULL,
  `ip`         VARCHAR(45)   DEFAULT NULL,
  `creato_il`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_utente`  (`id_utente`),
  INDEX `idx_azione`  (`azione`),
  INDEX `idx_data`    (`creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
