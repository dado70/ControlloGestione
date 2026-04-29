# ControlloGestione

**Sistema web di Controllo di Gestione per Fatture Elettroniche**

[![Versione](https://img.shields.io/badge/versione-0.0.1--beta-orange)](https://github.com/dado70/controllogestione/releases)
[![Licenza](https://img.shields.io/badge/licenza-GPL%20v3-blue)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-blue)](https://www.mysql.com/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5-blueviolet)](https://getbootstrap.com/)

---

## Descrizione

**ControlloGestione** è un sistema web open source di controllo di gestione progettato per hotel e strutture ricettive. Permette di:

- 📥 **Importare fatture elettroniche passive** in formato `.xml` e `.p7m` (FatturaPA FPR12 v1.2)
- 🏷️ **Classificare i costi** su piano dei conti aziendale a 3 livelli
- 📊 **Analizzare i costi** per centro di costo, fornitore, periodo
- 📋 **Riclassificare** secondo lo schema CEE (art. 2424-2425 c.c.)
- 🏢 **Gestione multi-azienda / multi-utente** con ruoli profilati
- 📈 **Dashboard** con KPI mensili e grafici (Chart.js)

---

## Stack tecnologico

| Componente | Tecnologia |
|---|---|
| Backend | PHP 8.1+ (PSR-12, PHP puro senza framework) |
| Database | MySQL 8.0+ |
| Frontend | Bootstrap 5 + vanilla JS + Chart.js |
| Server | Apache 2.4 su Debian/Ubuntu/Linux Mint |
| XML Parser | PHP SimpleXML / DOMDocument (nativo) |
| P7M | OpenSSL CLI + php-openssl |

---

## Requisiti di sistema

### Software obbligatorio

| Software | Versione minima | Installazione |
|---|---|---|
| PHP | >= 8.1 | `apt install php` |
| MySQL / MariaDB | >= 8.0 / 10.5 | `apt install mysql-server` |
| Apache | 2.4 | `apt install apache2 libapache2-mod-php` |
| OpenSSL (CLI) | qualsiasi | `apt install openssl` |

### Estensioni PHP richieste

```bash
apt install php-xml php-mbstring php-mysql php-openssl php-zip php-curl php-intl
```

Verifica che siano attive:

```bash
php -m | grep -E "xml|simplexml|pdo_mysql|openssl|mbstring|zip|curl|intl"
```

---

## Installazione

### 1. Clona il repository

```bash
git clone https://github.com/dado70/controllogestione.git /var/www/html/controllogestione
```

### 2. Imposta i permessi

```bash
cd /var/www/html/controllogestione
sudo chown -R www-data:www-data uploads/ logs/
sudo chmod 750 uploads/ logs/
```

### 3. Configura Apache

Crea un VirtualHost o usa la sottocartella. Esempio con sottocartella `/controllogestione`:

```apache
# In /etc/apache2/sites-available/000-default.conf oppure in un file dedicato
Alias /controllogestione /var/www/html/controllogestione

<Directory /var/www/html/controllogestione>
    AllowOverride All
    Require all granted
</Directory>
```

Abilita `mod_rewrite` se necessario:

```bash
sudo a2enmod rewrite
sudo systemctl reload apache2
```

### 4. Esegui il wizard di installazione web

Apri il browser e naviga su:

```
http://localhost/controllogestione/setup/install.php
```

Il wizard si compone di **4 step**:

| Step | Descrizione |
|---|---|
| 1️⃣ Verifica dipendenze | Controlla automaticamente PHP, estensioni, OpenSSL e permessi directory |
| 2️⃣ Configurazione database | Inserisci host, nome DB, utente e password MySQL — testa la connessione in tempo reale |
| 3️⃣ Creazione schema | Crea tutte le tabelle e carica i dati iniziali (piano dei conti, centri di costo, keyword) |
| 4️⃣ Account superadmin | Crea l'utente amministratore principale e genera `config/config.php` |

### 5. Accedi all'applicazione

Al termine del wizard, vai su:

```
http://localhost/controllogestione/
```

Accedi con le credenziali superadmin create al punto 4.

### ⚠️ Sicurezza post-installazione

> **Importante:** dopo l'installazione, proteggere o rimuovere la cartella `setup/` in produzione:

```bash
# Opzione A — rimuovi la cartella
sudo rm -rf /var/www/html/controllogestione/setup/

# Opzione B — blocca l'accesso via Apache (se vuoi poterla riusare)
# Aggiungi al VirtualHost:
# <Directory /var/www/html/controllogestione/setup>
#     Require all denied
# </Directory>
```

---

## Struttura del progetto

```
controllogestione/
├── index.php                  # Entry point → redirect a login o setup
├── config/
│   ├── config.template.php    # Template configurazione (committato)
│   └── config.php             # Configurazione reale (NON committata, generata dal wizard)
├── setup/
│   ├── install.php            # Wizard di installazione web (4 step)
│   ├── check_deps.php         # Verifica dipendenze via JSON (usato dal wizard)
│   └── schema.sql             # DDL completo + dati iniziali Villa Ottone
├── core/
│   ├── Database.php           # PDO singleton wrapper
│   ├── Auth.php               # Autenticazione, sessioni, CSRF, ruoli, bruteforce
│   ├── Router.php             # Router minimale
│   ├── FatturaParser.php      # Parser XML FPR12 (Fase 2)
│   ├── P7MDecryptor.php       # Decriptazione file .p7m (Fase 2)
│   ├── FatturaImporter.php    # Orchestratore import (Fase 2)
│   └── ContoSuggestor.php     # Suggerimento conto da keyword (Fase 3)
├── public/
│   ├── login.php / logout.php
│   ├── dashboard.php          # KPI + grafici Chart.js
│   ├── layout/
│   │   ├── header.php         # Sidebar + topbar Bootstrap 5
│   │   └── footer.php
│   ├── fatture/
│   │   ├── upload.php         # Drag&drop multi-file XML/P7M
│   │   ├── lista.php          # Archivio con filtri
│   │   └── dettaglio.php      # Dettaglio + classificazione righe
│   ├── analisi/
│   │   ├── costi_periodo.php  # Pivot mensile per conto
│   │   ├── costi_fornitore.php
│   │   ├── centri_costo.php
│   │   ├── bilancio_cee.php
│   │   └── export.php         # CSV/Excel/PDF
│   └── impostazioni/
│       ├── piano_conti.php    # CRUD piano dei conti
│       ├── mappatura_cee.php  # Raccordo PDC ↔ CEE
│       ├── centri_costo.php
│       ├── aziende.php
│       └── utenti.php
├── assets/
│   ├── css/style.css          # Stili personalizzati Bootstrap 5
│   └── js/main.js
├── uploads/
│   ├── xml/                   # File XML originali (esclusi da git)
│   └── p7m/                   # File P7M originali (esclusi da git)
├── logs/                      # Log applicazione (esclusi da git)
├── LICENSE                    # GNU GPL v3
└── README.md
```

---

## Ruoli utente

| Funzione | superadmin | admin | operatore | readonly |
|---|:---:|:---:|:---:|:---:|
| Importa fatture | ✅ | ✅ | ✅ | ❌ |
| Classifica righe | ✅ | ✅ | ✅ | ❌ |
| Elimina fatture | ✅ | ✅ | ❌ | ❌ |
| Gestisce PDC / mappature | ✅ | ✅ | ❌ | ❌ |
| Gestisce utenti | ✅ | (propria az.) | ❌ | ❌ |
| Gestisce aziende | ✅ | ❌ | ❌ | ❌ |
| Analisi e report | ✅ | ✅ | ✅ | ✅ |

---

## Fasi di sviluppo

| Fase | Descrizione | Stato |
|---|---|:---:|
| **1** | Foundation: installer web, auth, layout Bootstrap 5, schema DB | ✅ Completata |
| **2** | Import fatture XML/P7M (parser FPR12, drag&drop, deduplicazione) | ⏳ Pianificata |
| **3** | Classificazione righe, piano dei conti, suggeritore automatico | ⏳ Pianificata |
| **4** | Analisi costi, dashboard KPI, export CSV/Excel/PDF | ⏳ Pianificata |
| **5** | Multi-ditta completo, gestione utenti avanzata | ⏳ Pianificata |

---

## Sicurezza

- Autenticazione con sessioni PHP + timeout configurabile (default 8h)
- Password con bcrypt (`PASSWORD_BCRYPT`)
- Protezione bruteforce: blocco dopo 5 tentativi falliti per 15 minuti
- Token CSRF su tutti i form POST
- Prepared statements PDO su **tutte** le query (zero SQL injection)
- Output sempre con `htmlspecialchars()`
- Log audit di tutti gli accessi e le azioni

---

## Riferimenti normativi

- [Specifiche FatturaPA FPR12 v1.2](https://www.fatturapa.gov.it/it/norme-e-regole/documentazione-fattura-pa/)
- Schema CEE bilancio: art. 2424 e 2425 c.c. + OIC 12
- Codici natura IVA (N1–N7), tipi documento (TD01–TD28), regimi fiscali (RF01–RF19)

---

## Licenza

Distribuito sotto licenza **GNU General Public License v3.0**.  
Vedere [LICENSE](LICENSE) per i dettagli.

---

## Contributing

Le issue e le pull request sono benvenute.  
Per segnalare un bug o proporre una funzionalità: [apri una issue](https://github.com/dado70/controllogestione/issues).

---

*ControlloGestione è un progetto open source indipendente.*
