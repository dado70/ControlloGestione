# ControlloGestione — Sistema di Controllo di Gestione per Fatture Elettroniche

**Versione:** 0.0.1 (beta)  
**Licenza:** GNU General Public License v3.0  
**Autore:** dado70  

---

## Descrizione

ControlloGestione è un sistema web open source di **controllo di gestione** per hotel, progettato per:

- Importare **fatture elettroniche passive** in formato `.xml` e `.p7m` (FatturaPA FPR12 v1.2)
- **Classificare i costi** su piano dei conti aziendale e bilancio CEE semplificato
- Analizzare costi per **centri di costo**, fornitore, periodo
- Gestione **multi-azienda / multi-utente** con ruoli profilati
- Dashboard con KPI e grafici (Chart.js)

Stack tecnologico: **PHP 8.1+ / MySQL 8.0+ / Bootstrap 5 / Apache**

---

## Requisiti

- PHP >= 8.1 con estensioni: `xml`, `simplexml`, `pdo_mysql`, `openssl`, `mbstring`, `zip`, `curl`, `intl`
- MySQL >= 8.0
- Apache 2.4 con `mod_php` o `php-fpm`
- OpenSSL (CLI) per decriptazione file `.p7m`

---

## Installazione

### 1. Clona il repository

```bash
git clone https://github.com/dado70/gesthotel-fe.git /var/www/html/gesthotel
cd /var/www/html/gesthotel
```

### 2. Esegui l'installer web

Apri il browser e naviga su:

```
http://localhost/gesthotel/setup/
```

Il wizard di installazione guiderà attraverso:

1. **Verifica dipendenze** — controlla PHP, estensioni e OpenSSL
2. **Configurazione database** — inserisci credenziali MySQL e testa la connessione
3. **Creazione schema** — crea le tabelle e carica i dati iniziali (piano dei conti CEE)
4. **Account superadmin** — crea l'utente amministratore principale

### 3. Accedi

Naviga su `http://localhost/gesthotel/` e accedi con le credenziali create.

> ⚠️ **Dopo l'installazione:** rimuovere o proteggere la cartella `setup/` in produzione.

---

## Struttura del progetto

```
gesthotel/
├── setup/          # Wizard di installazione (rimuovere dopo setup)
├── config/         # Configurazione DB e app (config.php non committato)
├── core/           # Classi PHP: Database, Auth, Router, Parser, Importer...
├── public/         # Pagine web dell'applicazione
│   ├── fatture/    # Upload, lista, dettaglio fatture
│   ├── analisi/    # Report e analisi costi
│   └── impostazioni/  # Piano dei conti, centri di costo, utenti
├── assets/         # CSS, JS, immagini
├── uploads/        # File XML/P7M caricati (esclusi da git)
└── logs/           # Log applicazione (esclusi da git)
```

---

## Fasi di sviluppo

| Fase | Descrizione | Stato |
|------|-------------|-------|
| 1 | Foundation: installer, auth, layout | 🔄 In sviluppo |
| 2 | Import fatture XML/P7M | ⏳ Pianificata |
| 3 | Classificazione e piano dei conti | ⏳ Pianificata |
| 4 | Analisi, dashboard, export | ⏳ Pianificata |
| 5 | Multi-ditta e gestione utenti | ⏳ Pianificata |

---

## Licenza

Questo progetto è distribuito sotto licenza **GNU General Public License v3.0**.  
Vedere il file [LICENSE](LICENSE) per i dettagli completi.

---

## Contributing

Le issue e le pull request sono benvenute su GitHub.  
Per segnalare un bug o proporre una funzionalità, aprire una issue nel repository.

---

*ControlloGestione è un progetto open source indipendente, non affiliato ad alcun software gestionale commerciale.*
