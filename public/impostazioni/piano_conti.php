<?php

declare(strict_types=1);
$pageTitle  = 'Piano dei conti';
$activePage = 'piano_conti';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireRole('superadmin', 'admin');

$idAzienda = Auth::getIdAzienda();
$csrfToken = Auth::csrfToken();

if (!$idAzienda) {
    echo '<div class="alert alert-warning">Seleziona un\'azienda prima di procedere.</div>';
    require_once dirname(__DIR__) . '/layout/footer.php';
    exit;
}

$errore  = '';
$successo = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        die('Token CSRF non valido.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'salva') {
        $id          = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $codice      = trim($_POST['codice']      ?? '');
        $descrizione = trim($_POST['descrizione'] ?? '');
        $livello     = (int)($_POST['livello']     ?? 3);
        $codicePadre = trim($_POST['codice_padre'] ?? '');
        $tipo        = $_POST['tipo']              ?? '';

        $tipiValidi = ['COSTO', 'RICAVO', 'ATTIVO', 'PASSIVO', 'PATRIMONIALE'];
        if ($codice === '' || $descrizione === '' || !in_array($tipo, $tipiValidi, true)) {
            $errore = 'Compilare tutti i campi obbligatori.';
        } else {
            if ($id) {
                Database::query(
                    'UPDATE piano_conti SET codice=?, descrizione=?, livello=?, codice_padre=?, tipo=?
                     WHERE id=? AND id_azienda=?',
                    [$codice, $descrizione, $livello, $codicePadre ?: null, $tipo, $id, $idAzienda]
                );
                $successo = 'Conto aggiornato.';
            } else {
                try {
                    Database::insert(
                        'INSERT INTO piano_conti (id_azienda, codice, descrizione, livello, codice_padre, tipo)
                         VALUES (?,?,?,?,?,?)',
                        [$idAzienda, $codice, $descrizione, $livello, $codicePadre ?: null, $tipo]
                    );
                    $successo = 'Conto aggiunto.';
                } catch (PDOException $e) {
                    $errore = 'Codice conto già esistente.';
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id     = (int)($_POST['id']     ?? 0);
        $attivo = (int)($_POST['attivo'] ?? 0);
        Database::query(
            'UPDATE piano_conti SET attivo=? WHERE id=? AND id_azienda=?',
            [$attivo ? 0 : 1, $id, $idAzienda]
        );
        $successo = 'Stato aggiornato.';
    }
}

// Filtri
$filtCodice = trim($_GET['codice']       ?? '');
$filtDesc   = trim($_GET['descrizione']  ?? '');
$filtLivello = isset($_GET['livello']) && $_GET['livello'] !== '' ? (int)$_GET['livello'] : null;
$filtTipo    = $_GET['tipo'] ?? '';

$where  = ['id_azienda = ?'];
$params = [$idAzienda];
if ($filtCodice !== '') { $where[] = 'codice LIKE ?';       $params[] = "%$filtCodice%"; }
if ($filtDesc   !== '') { $where[] = 'descrizione LIKE ?';  $params[] = "%$filtDesc%"; }
if ($filtLivello !== null) { $where[] = 'livello = ?';      $params[] = $filtLivello; }
if ($filtTipo   !== '') { $where[] = 'tipo = ?';            $params[] = $filtTipo; }

$conti = Database::fetchAll(
    'SELECT * FROM piano_conti WHERE ' . implode(' AND ', $where) . ' ORDER BY codice LIMIT 500',
    $params
);

$tipi = ['COSTO', 'RICAVO', 'ATTIVO', 'PASSIVO', 'PATRIMONIALE'];
$tipiBadge = [
    'COSTO'         => 'bg-danger',
    'RICAVO'        => 'bg-success',
    'ATTIVO'        => 'bg-primary',
    'PASSIVO'       => 'bg-warning text-dark',
    'PATRIMONIALE'  => 'bg-info text-dark',
];
?>

<?php if ($errore): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($errore) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($successo): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successo) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filtri + pulsante aggiungi -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-2">
        <label class="form-label small mb-1">Codice</label>
        <input type="text" name="codice" class="form-control form-control-sm" value="<?= htmlspecialchars($filtCodice) ?>" placeholder="es. 73.01">
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Descrizione</label>
        <input type="text" name="descrizione" class="form-control form-control-sm" value="<?= htmlspecialchars($filtDesc) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Livello</label>
        <select name="livello" class="form-select form-select-sm">
          <option value="">Tutti</option>
          <option value="1" <?= $filtLivello === 1 ? 'selected' : '' ?>>1 - Mastro</option>
          <option value="2" <?= $filtLivello === 2 ? 'selected' : '' ?>>2 - Conto</option>
          <option value="3" <?= $filtLivello === 3 ? 'selected' : '' ?>>3 - Sottoconto</option>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Tipo</label>
        <select name="tipo" class="form-select form-select-sm">
          <option value="">Tutti</option>
          <?php foreach ($tipi as $t): ?>
          <option value="<?= $t ?>" <?= $filtTipo === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
        <a href="piano_conti.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
      </div>
      <div class="col-auto ms-auto">
        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalConto">
          <i class="bi bi-plus-circle me-1"></i>Aggiungi conto
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Tabella conti -->
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Codice</th>
          <th>Descrizione</th>
          <th class="text-center">Liv.</th>
          <th>Padre</th>
          <th>Tipo</th>
          <th class="text-center">Attivo</th>
          <th class="text-center">Azioni</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($conti)): ?>
      <tr><td colspan="7" class="text-center text-muted py-4">Nessun conto trovato.</td></tr>
      <?php else: ?>
      <?php foreach ($conti as $c): ?>
      <tr class="<?= !$c['attivo'] ? 'text-muted' : '' ?>">
        <td><code><?= htmlspecialchars($c['codice']) ?></code></td>
        <td><?= htmlspecialchars($c['descrizione']) ?></td>
        <td class="text-center"><?= (int)$c['livello'] ?></td>
        <td class="small text-muted"><?= htmlspecialchars($c['codice_padre'] ?? '—') ?></td>
        <td><span class="badge <?= $tipiBadge[$c['tipo']] ?? 'bg-secondary' ?>"><?= htmlspecialchars($c['tipo']) ?></span></td>
        <td class="text-center">
          <i class="bi bi-<?= $c['attivo'] ? 'check-circle-fill text-success' : 'x-circle text-muted' ?>"></i>
        </td>
        <td class="text-center text-nowrap">
          <button type="button" class="btn btn-sm btn-outline-primary me-1"
                  data-bs-toggle="modal" data-bs-target="#modalConto"
                  data-id="<?= $c['id'] ?>"
                  data-codice="<?= htmlspecialchars($c['codice']) ?>"
                  data-descrizione="<?= htmlspecialchars($c['descrizione']) ?>"
                  data-livello="<?= (int)$c['livello'] ?>"
                  data-padre="<?= htmlspecialchars($c['codice_padre'] ?? '') ?>"
                  data-tipo="<?= htmlspecialchars($c['tipo']) ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <input type="hidden" name="attivo" value="<?= (int)$c['attivo'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $c['attivo'] ? 'warning' : 'success' ?>"
                    title="<?= $c['attivo'] ? 'Disattiva' : 'Attiva' ?>">
              <i class="bi bi-<?= $c['attivo'] ? 'pause-circle' : 'play-circle' ?>"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-1 small text-muted"><?= count($conti) ?> conti visualizzati (max 500)</div>

<!-- Modal aggiungi/modifica conto -->
<div class="modal fade" id="modalConto" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="salva">
        <input type="hidden" name="id" id="modalId">
        <div class="modal-header">
          <h5 class="modal-title" id="modalContoTitle">Aggiungi conto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Codice <span class="text-danger">*</span></label>
            <input type="text" name="codice" id="modalCodice" class="form-control" required placeholder="es. 73.01.013">
          </div>
          <div class="mb-3">
            <label class="form-label">Descrizione <span class="text-danger">*</span></label>
            <input type="text" name="descrizione" id="modalDescrizione" class="form-control" required>
          </div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label">Livello</label>
              <select name="livello" id="modalLivello" class="form-select">
                <option value="1">1 — Mastro</option>
                <option value="2">2 — Conto</option>
                <option value="3" selected>3 — Sottoconto</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Tipo <span class="text-danger">*</span></label>
              <select name="tipo" id="modalTipo" class="form-select" required>
                <?php foreach ($tipi as $t): ?>
                <option value="<?= $t ?>"><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Codice padre</label>
            <input type="text" name="codice_padre" id="modalPadre" class="form-control" placeholder="es. 73.01">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script>
document.getElementById("modalConto").addEventListener("show.bs.modal", function (e) {
    const btn = e.relatedTarget;
    const id  = btn ? btn.dataset.id : "";
    document.getElementById("modalContoTitle").textContent = id ? "Modifica conto" : "Aggiungi conto";
    document.getElementById("modalId").value          = id || "";
    document.getElementById("modalCodice").value      = id ? btn.dataset.codice      : "";
    document.getElementById("modalDescrizione").value = id ? btn.dataset.descrizione : "";
    document.getElementById("modalLivello").value     = id ? btn.dataset.livello     : "3";
    document.getElementById("modalPadre").value       = id ? btn.dataset.padre       : "";
    document.getElementById("modalTipo").value        = id ? btn.dataset.tipo        : "COSTO";
});
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
