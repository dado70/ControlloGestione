<?php

declare(strict_types=1);
$pageTitle  = 'Centri di costo';
$activePage = 'imp_cc';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireRole('superadmin', 'admin');

$idAzienda = Auth::getIdAzienda();
$csrfToken = Auth::csrfToken();

if (!$idAzienda) {
    echo '<div class="alert alert-warning">Seleziona un\'azienda prima di procedere.</div>';
    require_once dirname(__DIR__) . '/layout/footer.php';
    exit;
}

$errore   = '';
$successo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        die('Token CSRF non valido.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'salva') {
        $id          = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $codice      = trim($_POST['codice']      ?? '');
        $descrizione = trim($_POST['descrizione'] ?? '');
        $tipo        = $_POST['tipo']              ?? 'COSTO';
        $ordine      = (int)($_POST['ordine']      ?? 0);

        $tipiValidi = ['COSTO', 'RICAVO', 'MISTO'];
        if ($codice === '' || $descrizione === '' || !in_array($tipo, $tipiValidi, true)) {
            $errore = 'Compilare tutti i campi obbligatori.';
        } else {
            if ($id) {
                Database::query(
                    'UPDATE centri_costo SET codice=?, descrizione=?, tipo=?, ordine=?
                     WHERE id=? AND id_azienda=?',
                    [$codice, $descrizione, $tipo, $ordine, $id, $idAzienda]
                );
                $successo = 'Centro di costo aggiornato.';
            } else {
                try {
                    Database::insert(
                        'INSERT INTO centri_costo (id_azienda, codice, descrizione, tipo, ordine)
                         VALUES (?,?,?,?,?)',
                        [$idAzienda, $codice, $descrizione, $tipo, $ordine]
                    );
                    $successo = 'Centro di costo aggiunto.';
                } catch (PDOException) {
                    $errore = 'Codice già esistente.';
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id     = (int)($_POST['id']     ?? 0);
        $attivo = (int)($_POST['attivo'] ?? 0);
        Database::query(
            'UPDATE centri_costo SET attivo=? WHERE id=? AND id_azienda=?',
            [$attivo ? 0 : 1, $id, $idAzienda]
        );
        $successo = 'Stato aggiornato.';
    }
}

$centri = Database::fetchAll(
    'SELECT * FROM centri_costo WHERE id_azienda=? ORDER BY ordine, codice',
    [$idAzienda]
);

$tipiBadge = ['COSTO' => 'bg-secondary', 'RICAVO' => 'bg-success', 'MISTO' => 'bg-info text-dark'];
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

<div class="d-flex justify-content-end mb-3">
  <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCC">
    <i class="bi bi-plus-circle me-1"></i>Aggiungi centro
  </button>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Codice</th>
          <th>Descrizione</th>
          <th>Tipo</th>
          <th class="text-center">Ordine</th>
          <th class="text-center">Attivo</th>
          <th class="text-center">Azioni</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($centri)): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">Nessun centro di costo configurato.</td></tr>
      <?php else: ?>
      <?php foreach ($centri as $cc): ?>
      <tr class="<?= !$cc['attivo'] ? 'text-muted' : '' ?>">
        <td><code><?= htmlspecialchars($cc['codice']) ?></code></td>
        <td class="fw-semibold"><?= htmlspecialchars($cc['descrizione']) ?></td>
        <td><span class="badge <?= $tipiBadge[$cc['tipo']] ?? 'bg-secondary' ?>"><?= htmlspecialchars($cc['tipo']) ?></span></td>
        <td class="text-center"><?= (int)$cc['ordine'] ?></td>
        <td class="text-center">
          <i class="bi bi-<?= $cc['attivo'] ? 'check-circle-fill text-success' : 'x-circle text-muted' ?>"></i>
        </td>
        <td class="text-center text-nowrap">
          <button type="button" class="btn btn-sm btn-outline-primary me-1"
                  data-bs-toggle="modal" data-bs-target="#modalCC"
                  data-id="<?= $cc['id'] ?>"
                  data-codice="<?= htmlspecialchars($cc['codice']) ?>"
                  data-descrizione="<?= htmlspecialchars($cc['descrizione']) ?>"
                  data-tipo="<?= htmlspecialchars($cc['tipo']) ?>"
                  data-ordine="<?= (int)$cc['ordine'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $cc['id'] ?>">
            <input type="hidden" name="attivo" value="<?= (int)$cc['attivo'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $cc['attivo'] ? 'warning' : 'success' ?>"
                    title="<?= $cc['attivo'] ? 'Disattiva' : 'Attiva' ?>">
              <i class="bi bi-<?= $cc['attivo'] ? 'pause-circle' : 'play-circle' ?>"></i>
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

<!-- Modal aggiungi/modifica -->
<div class="modal fade" id="modalCC" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="salva">
        <input type="hidden" name="id" id="ccId">
        <div class="modal-header">
          <h5 class="modal-title" id="ccTitle">Aggiungi centro di costo</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Codice <span class="text-danger">*</span></label>
            <input type="text" name="codice" id="ccCodice" class="form-control" required placeholder="es. CC10">
          </div>
          <div class="mb-3">
            <label class="form-label">Descrizione <span class="text-danger">*</span></label>
            <input type="text" name="descrizione" id="ccDescrizione" class="form-control" required>
          </div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label">Tipo</label>
              <select name="tipo" id="ccTipo" class="form-select">
                <option value="COSTO">COSTO</option>
                <option value="RICAVO">RICAVO</option>
                <option value="MISTO">MISTO</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Ordine</label>
              <input type="number" name="ordine" id="ccOrdine" class="form-control" value="0" min="0">
            </div>
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
document.getElementById("modalCC").addEventListener("show.bs.modal", function (e) {
    const btn = e.relatedTarget;
    const id  = btn ? btn.dataset.id : "";
    document.getElementById("ccTitle").textContent       = id ? "Modifica centro" : "Aggiungi centro di costo";
    document.getElementById("ccId").value                = id || "";
    document.getElementById("ccCodice").value            = id ? btn.dataset.codice      : "";
    document.getElementById("ccDescrizione").value       = id ? btn.dataset.descrizione : "";
    document.getElementById("ccTipo").value              = id ? btn.dataset.tipo        : "COSTO";
    document.getElementById("ccOrdine").value            = id ? btn.dataset.ordine      : "0";
});
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
