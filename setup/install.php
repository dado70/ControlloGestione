<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ControlloGestione — Installazione</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #f0f2f5; }
  .wizard-card { max-width: 760px; margin: 40px auto; }
  .step-nav .step { display: flex; align-items: center; gap: .5rem; padding: .5rem 1rem;
    border-radius: .5rem; color: #6c757d; font-size: .9rem; }
  .step-nav .step.active { background: #0d6efd22; color: #0d6efd; font-weight: 600; }
  .step-nav .step.done  { color: #198754; }
  .step-nav .step .badge { min-width: 1.6rem; text-align: center; }
  .check-row { display: flex; align-items: center; gap:.5rem; padding:.35rem 0;
    border-bottom: 1px solid #f0f0f0; font-size:.9rem; }
  .spinner-border-sm { width:1rem; height:1rem; }
</style>
</head>
<body>
<?php
session_start();

// Blocca l'accesso se l'app è già installata
if (file_exists(dirname(__DIR__) . '/config/config.php')) {
    $cfg = file_get_contents(dirname(__DIR__) . '/config/config.php');
    if (strpos($cfg, '{{') === false) {
        // config.php è compilato → installazione già eseguita
        // Permettiamo solo se viene passato ?force=1
        if (empty($_GET['force'])) {
            die('<div class="container mt-5 alert alert-warning">
                <strong>Installazione già completata.</strong>
                Per reinizializzare aggiungere <code>?force=1</code> all\'URL (attenzione: sovrascrive i dati).
            </div>');
        }
    }
}

$step = (int)($_GET['step'] ?? 1);
if ($step < 1 || $step > 4) { $step = 1; }

// ---- STEP 2: test connessione DB ----
$dbError = '';
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $url  = trim($_POST['app_url'] ?? '');

    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // crea DB se non esiste
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");

        $_SESSION['install'] = compact('host','name','user','pass','url');
        header('Location: install.php?step=3'); exit;
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ---- STEP 3: esegui schema.sql ----
$schemaError = '';
$schemaOk    = false;
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = $_SESSION['install'] ?? null;
    if (!$cfg) { header('Location: install.php?step=2'); exit; }

    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
            $cfg['user'], $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        // Esegui statement per statement
        $pdo->exec($sql);
        $schemaOk = true;
        header('Location: install.php?step=4'); exit;
    } catch (Throwable $e) {
        $schemaError = $e->getMessage();
    }
}

// ---- STEP 4: crea superadmin e config.php ----
$adminError = '';
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg      = $_SESSION['install'] ?? null;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email    = trim($_POST['email'] ?? '');
    $nome     = trim($_POST['nome'] ?? '');
    $cognome  = trim($_POST['cognome'] ?? '');

    if (!$cfg || $username === '' || strlen($password) < 8) {
        $adminError = 'Username obbligatorio e password minimo 8 caratteri.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
                $cfg['user'], $cfg['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO utenti (username, password_hash, nome, cognome, email, ruolo)
                 VALUES (?, ?, ?, ?, ?, "superadmin")
                 ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)'
            )->execute([$username, $hash, $nome, $cognome, $email]);

            // Scrivi config.php
            $tpl = file_get_contents(dirname(__DIR__) . '/config/config.template.php');
            $appUrl = rtrim($cfg['url'] ?: 'http://localhost/controllogestione', '/');
            $tpl = str_replace(
                ['{{DB_HOST}}','{{DB_NAME}}','{{DB_USER}}','{{DB_PASS}}','{{APP_URL}}'],
                [$cfg['host'], $cfg['name'], $cfg['user'], $cfg['pass'], $appUrl],
                $tpl
            );
            $configPath = dirname(__DIR__) . '/config/config.php';
            $written = file_put_contents($configPath, $tpl);
            if ($written === false) {
                $adminError = 'Impossibile scrivere config/config.php. '
                    . 'Verificare che la directory config/ sia scrivibile da Apache (www-data). '
                    . 'Eseguire: <code>sudo chmod o+w ' . dirname(__DIR__) . '/config/</code>';
            } else {
                unset($_SESSION['install']);
                header('Location: install.php?step=done'); exit;
            }
        } catch (Throwable $e) {
            $adminError = $e->getMessage();
        }
    }
}

if (isset($_GET['step']) && $_GET['step'] === 'done') { $step = 5; }

function stepClass(int $current, int $check): string {
    if ($check < $current)  return 'done';
    if ($check === $current) return 'active';
    return '';
}
function stepIcon(int $current, int $check): string {
    if ($check < $current) return '<i class="bi bi-check-circle-fill text-success"></i>';
    return '<span class="badge bg-' . ($check === $current ? 'primary' : 'secondary') . '">' . $check . '</span>';
}
?>

<div class="container">
  <div class="wizard-card card shadow-sm">
    <div class="card-header bg-primary text-white d-flex align-items-center gap-2 py-3">
      <i class="bi bi-building fs-4"></i>
      <div>
        <div class="fw-bold fs-5">ControlloGestione — Installazione guidata</div>
        <small class="opacity-75">Versione 0.0.1 beta</small>
      </div>
    </div>

    <div class="card-body">
      <!-- Navigazione step -->
      <div class="step-nav d-flex gap-1 mb-4 flex-wrap">
        <?php foreach ([1=>'Verifica dipendenze',2=>'Database',3=>'Schema DB',4=>'Account admin'] as $n=>$label): ?>
        <div class="step <?= stepClass($step, $n) ?>">
          <?= stepIcon($step, $n) ?> <?= $label ?>
        </div>
        <?php if ($n < 4): ?><div class="text-muted">›</div><?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($step === 1): ?>
      <!-- ===================== STEP 1: DIPENDENZE ===================== -->
      <h5 class="mb-3"><i class="bi bi-search me-2"></i>Verifica dipendenze</h5>
      <div id="checks-container">
        <div class="d-flex justify-content-center py-4">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
      <div class="d-flex justify-content-end mt-3">
        <a id="btn-next-1" href="install.php?step=2" class="btn btn-primary disabled">
          Avanti <i class="bi bi-arrow-right"></i>
        </a>
      </div>
      <script>
      fetch('check_deps.php').then(r=>r.json()).then(data=>{
        const c = document.getElementById('checks-container');
        let html = '';
        for (const [k, v] of Object.entries(data.checks)) {
          const ico = v.ok
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<i class="bi bi-x-circle-fill text-danger"></i>';
          html += `<div class="check-row">${ico} <span class="flex-grow-1">${v.label}</span>
                   <span class="text-muted small">${v.valore}</span></div>`;
        }
        if (data.all_ok) {
          html += '<div class="alert alert-success mt-3 mb-0"><i class="bi bi-check-lg me-1"></i>Tutte le dipendenze sono soddisfatte.</div>';
          document.getElementById('btn-next-1').classList.remove('disabled');
        } else {
          html += '<div class="alert alert-warning mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Risolvere le dipendenze mancanti prima di continuare.</div>';
        }
        c.innerHTML = html;
      }).catch(()=>{
        document.getElementById('checks-container').innerHTML =
          '<div class="alert alert-danger">Errore durante la verifica. Controllare la configurazione del server.</div>';
      });
      </script>

      <?php elseif ($step === 2): ?>
      <!-- ===================== STEP 2: DATABASE ===================== -->
      <h5 class="mb-3"><i class="bi bi-database me-2"></i>Configurazione database</h5>
      <?php if ($dbError): ?>
      <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($dbError) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Host MySQL</label>
            <input type="text" name="db_host" class="form-control" value="localhost" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nome database</label>
            <input type="text" name="db_name" class="form-control" placeholder="gesthotel" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Utente MySQL</label>
            <input type="text" name="db_user" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Password MySQL</label>
            <input type="password" name="db_pass" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">URL applicazione</label>
            <input type="url" name="app_url" class="form-control" placeholder="http://localhost/gesthotel" required>
            <div class="form-text">Es. http://mioserver.it/gesthotel — senza slash finale.</div>
          </div>
        </div>
        <div class="d-flex justify-content-between mt-4">
          <a href="install.php?step=1" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Indietro
          </a>
          <button type="submit" class="btn btn-primary">
            Testa connessione e continua <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </form>

      <?php elseif ($step === 3): ?>
      <!-- ===================== STEP 3: SCHEMA ===================== -->
      <h5 class="mb-3"><i class="bi bi-table me-2"></i>Creazione schema database</h5>
      <?php if ($schemaError): ?>
      <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($schemaError) ?></div>
      <?php endif; ?>
      <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Verranno create tutte le tabelle e caricati i dati iniziali:
        <ul class="mb-0 mt-1">
          <li>Piano dei conti Villa Ottone (livelli 1-3, ~400 voci)</li>
          <li>Centri di costo (CC01–CC09)</li>
          <li>Keyword per suggeritore automatico conti</li>
          <li>Azienda: Villa Ottone S.R.L.</li>
        </ul>
      </div>
      <form method="post">
        <div class="d-flex justify-content-between">
          <a href="install.php?step=2" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Indietro
          </a>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-play-circle me-1"></i>Esegui creazione schema
          </button>
        </div>
      </form>

      <?php elseif ($step === 4): ?>
      <!-- ===================== STEP 4: SUPERADMIN ===================== -->
      <h5 class="mb-3"><i class="bi bi-person-gear me-2"></i>Creazione account superadmin</h5>
      <?php if ($adminError): ?>
      <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($adminError) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nome</label>
            <input type="text" name="nome" class="form-control" placeholder="Mario">
          </div>
          <div class="col-md-6">
            <label class="form-label">Cognome</label>
            <input type="text" name="cognome" class="form-control" placeholder="Rossi">
          </div>
          <div class="col-md-6">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" required autocomplete="new-password">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Password <span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
            <div class="form-text">Minimo 8 caratteri.</div>
          </div>
        </div>
        <div class="d-flex justify-content-between mt-4">
          <a href="install.php?step=3" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Indietro
          </a>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg me-1"></i>Completa installazione
          </button>
        </div>
      </form>

      <?php else: ?>
      <!-- ===================== STEP 5: COMPLETATO ===================== -->
      <div class="text-center py-4">
        <i class="bi bi-check-circle-fill text-success" style="font-size:4rem"></i>
        <h4 class="mt-3 text-success">Installazione completata!</h4>
        <p class="text-muted">ControlloGestione è pronto all'uso.</p>
        <div class="alert alert-warning d-inline-block text-start mt-2">
          <i class="bi bi-shield-exclamation me-1"></i>
          <strong>Attenzione:</strong> per sicurezza, eliminare o proteggere la cartella
          <code>setup/</code> prima di andare in produzione.
        </div>
        <br>
        <a href="../public/login.php" class="btn btn-primary btn-lg mt-3">
          <i class="bi bi-box-arrow-in-right me-2"></i>Vai al login
        </a>
      </div>
      <?php endif; ?>

    </div><!-- /card-body -->
    <div class="card-footer text-muted text-center small">
      ControlloGestione v0.0.1 beta — Licenza GPL v3 —
      <a href="https://github.com/dado70/gesthotel-fe" target="_blank" rel="noopener">GitHub</a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
