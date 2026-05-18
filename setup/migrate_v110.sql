-- Migrazione v1.1.0
-- Eseguire su installazioni esistenti (v1.0.x → v1.1.0)
-- Aggiunge: tabella corrispettivi, tabella budget

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------------
-- Corrispettivi giornalieri (ricavi da registratore/POS)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `corrispettivi` (
  `id`                         INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`                 INT            NOT NULL,
  `data_documento`             DATE           NOT NULL,
  `tipo`                       ENUM('corrispettivo','reso','annullo') NOT NULL DEFAULT 'corrispettivo',
  `descrizione`                VARCHAR(255)   DEFAULT NULL,
  `imponibile`                 DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `aliquota_iva`               DECIMAL(6,2)   DEFAULT NULL,
  `imposta`                    DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `totale`                     DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `id_conto`                   INT            DEFAULT NULL,
  `id_centro_costo`            INT            DEFAULT NULL,
  `classificazione_confermata` TINYINT(1)     NOT NULL DEFAULT 0,
  `nome_file`                  VARCHAR(255)   DEFAULT NULL,
  `importato_da`               INT            DEFAULT NULL,
  `note`                       TEXT           DEFAULT NULL,
  `created_at`                 TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_azienda_data` (`id_azienda`, `data_documento`),
  FOREIGN KEY (`id_azienda`)    REFERENCES `aziende`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_conto`)      REFERENCES `piano_conti`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Budget mensile per conto del piano dei conti
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `budget` (
  `id`              INT            AUTO_INCREMENT PRIMARY KEY,
  `id_azienda`      INT            NOT NULL,
  `anno`            YEAR           NOT NULL,
  `mese`            TINYINT        NOT NULL   COMMENT '1-12',
  `id_conto`        INT            NOT NULL,
  `id_centro_costo` INT            DEFAULT NULL,
  `importo`         DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `note`            VARCHAR(255)   DEFAULT NULL,
  `created_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_budget` (`id_azienda`, `anno`, `mese`, `id_conto`),
  INDEX `idx_periodo` (`id_azienda`, `anno`, `mese`),
  FOREIGN KEY (`id_azienda`) REFERENCES `aziende`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`id_conto`)   REFERENCES `piano_conti`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
