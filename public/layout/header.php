<?php
// Include in cima a ogni pagina autenticata
// Richiede: $pageTitle (string), eventualmente $activePage (string)
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::init();
Auth::requireLogin();

$user     = Auth::getUser();
$idAzienda = Auth::getIdAzienda();

// Cambia azienda
if (isset($_POST['switch_azienda']) && Auth::isAdmin()) {
    Auth::verifyCsrf();
    Auth::setAzienda((int)$_POST['switch_azienda']);
    $idAzienda = Auth::getIdAzienda();
}

// Carica aziende disponibili per l'utente
if ($user['ruolo'] === 'superadmin') {
    $aziende = Database::fetchAll('SELECT id, ragione_sociale FROM aziende WHERE attiva=1 ORDER BY ragione_sociale');
} else {
    $aziende = Database::fetchAll(
        'SELECT a.id, a.ragione_sociale FROM aziende a
         JOIN utenti_aziende ua ON ua.id_azienda=a.id
         WHERE ua.id_utente=? AND a.attiva=1 ORDER BY a.ragione_sociale',
        [$user['id']]
    );
}

// Auto-seleziona la prima azienda disponibile se nessuna è in sessione
if (!$idAzienda && !empty($aziende)) {
    Auth::setAzienda((int)$aziende[0]['id']);
    $idAzienda = Auth::getIdAzienda();
}

// Dati azienda corrente
$aziendaCorrente = null;
if ($idAzienda) {
    $aziendaCorrente = Database::fetchOne(
        'SELECT ragione_sociale FROM aziende WHERE id=?', [$idAzienda]
    );
}

$activePage = $activePage ?? '';
$pageTitle  = $pageTitle  ?? APP_NAME;

function navLink(string $href, string $icon, string $label, string $active, string $page): string {
    $cls = ($active === $page) ? ' active' : '';
    return "<a href=\"{$href}\" class=\"nav-link{$cls}\">
        <i class=\"bi bi-{$icon}\"></i>{$label}</a>";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

<!-- Sidebar -->
<nav id="sidebar">
  <div class="sidebar-brand">
    <i class="bi bi-building"></i>
    <div>
      <?= APP_NAME ?>
      <small>v<?= APP_VERSION ?> beta</small>
    </div>
  </div>

  <div class="nav-section">Principale</div>
  <?= navLink(APP_URL.'/public/dashboard.php',       'speedometer2',     'Dashboard',         $activePage, 'dashboard') ?>

  <div class="nav-section">Fatture</div>
  <?= navLink(APP_URL.'/public/fatture/upload.php',  'cloud-upload',     'Importa fatture',   $activePage, 'upload') ?>
  <?= navLink(APP_URL.'/public/fatture/lista.php',   'file-earmark-text','Archivio fatture',  $activePage, 'fatture') ?>

  <div class="nav-section">Analisi</div>
  <?= navLink(APP_URL.'/public/analisi/costi_periodo.php',   'calendar3',        'Costi per periodo', $activePage, 'costi_periodo') ?>
  <?= navLink(APP_URL.'/public/analisi/costi_fornitore.php', 'shop',             'Per fornitore',     $activePage, 'costi_fornitore') ?>
  <?= navLink(APP_URL.'/public/analisi/centri_costo.php',    'diagram-3',        'Centri di costo',   $activePage, 'centri_costo') ?>
  <?= navLink(APP_URL.'/public/analisi/bilancio_cee.php',    'bar-chart-line',   'Bilancio CEE',      $activePage, 'bilancio_cee') ?>

  <?php if (Auth::isAdmin()): ?>
  <div class="nav-section">Impostazioni</div>
  <?= navLink(APP_URL.'/public/impostazioni/piano_conti.php',  'list-nested',   'Piano dei conti',   $activePage, 'piano_conti') ?>
  <?= navLink(APP_URL.'/public/impostazioni/centri_costo.php', 'tags',          'Centri di costo',   $activePage, 'imp_cc') ?>
  <?= navLink(APP_URL.'/public/impostazioni/mappatura_cc.php', 'diagram-3',    'Mapp. Conti→CC',    $activePage, 'mappatura_cc') ?>
  <?= navLink(APP_URL.'/public/impostazioni/mappatura_cee.php','arrow-left-right','Mapp. CEE',       $activePage, 'mappatura_cee') ?>
  <?php if (Auth::isSuperadmin()): ?>
  <?= navLink(APP_URL.'/public/impostazioni/aziende.php',      'buildings',     'Aziende',           $activePage, 'aziende') ?>
  <?= navLink(APP_URL.'/public/impostazioni/utenti.php',       'people',        'Utenti',            $activePage, 'utenti') ?>
  <?php endif; ?>
  <?php endif; ?>

  <div class="mt-auto p-3 border-top border-secondary" style="margin-top:auto">
    <div class="text-muted small mb-1">
      <i class="bi bi-person-circle me-1"></i>
      <?= htmlspecialchars($user['username']) ?>
      <span class="badge bg-secondary ms-1"><?= htmlspecialchars($user['ruolo']) ?></span>
    </div>
    <a href="<?= APP_URL ?>/public/logout.php" class="btn btn-sm btn-outline-danger w-100">
      <i class="bi bi-box-arrow-right me-1"></i>Esci
    </a>
  </div>
</nav>

<!-- Main content -->
<div id="main">
  <!-- Topbar -->
  <div id="topbar">
    <button class="btn btn-sm btn-outline-secondary d-md-none" id="sidebarToggle">
      <i class="bi bi-list"></i>
    </button>
    <span class="topbar-title"><?= htmlspecialchars($pageTitle) ?></span>
    <div class="ms-auto d-flex align-items-center gap-2">
      <!-- Selettore azienda -->
      <?php if (count($aziende) > 1): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
        <select name="switch_azienda" class="form-select form-select-sm azienda-selector"
                onchange="this.form.submit()" title="Seleziona azienda">
          <?php foreach ($aziende as $az): ?>
          <option value="<?= $az['id'] ?>" <?= $az['id'] == $idAzienda ? 'selected' : '' ?>>
            <?= htmlspecialchars($az['ragione_sociale']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php elseif ($aziendaCorrente): ?>
      <span class="badge bg-light text-dark border">
        <i class="bi bi-building me-1"></i><?= htmlspecialchars($aziendaCorrente['ragione_sociale']) ?>
      </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Contenuto pagina -->
  <div class="page-content">
