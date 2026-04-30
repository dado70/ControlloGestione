<?php
declare(strict_types=1);
$pageTitle  = 'Gestione Aziende';
$activePage = 'aziende';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/setup/import_pdc.php';
Auth::init();
Auth::requireRole('superadmin');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$msg      = '';
$msgType  = 'success';
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['csrf'] !== $_SESSION['csrf']) die('CSRF error');

    $action          = $_POST['action'] ?? '';
    $ragioneSociale  = trim($_POST['ragione_sociale'] ?? '');
    $partitaIva      = trim($_POST['partita_iva'] ?? '');
    $codiceFiscale   = trim($_POST['codice_fiscale'] ?? '');
    $indirizzo       = trim($_POST['indirizzo'] ?? '');
    $cap             = trim($_POST['cap'] ?? '');
    $comune          = trim($_POST['comune'] ?? '');
    $provincia       = strtoupper(trim($_POST['provincia'] ?? ''));
    $codDest         = trim($_POST['codice_destinatario'] ?? '');
    $pec             = trim($_POST['pec_destinatario'] ?? '');
    $importaPDC      = !empty($_POST['importa_pdc']);

    if ($ragioneSociale === '') $errors[] = 'Ragione sociale obbligatoria.';
    if ($partitaIva === '')     $errors[] = 'Partita IVA obbligatoria.';

    if (empty($errors)) {
        if ($action === 'add') {
            try {
                $nuovoId = (int)Database::insert(
                    'INSERT INTO aziende (ragione_sociale, partita_iva, codice_fiscale, indirizzo, cap, comune, provincia, codice_destinatario, pec_destinatario)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$ragioneSociale, $partitaIva, $codiceFiscale, $indirizzo, $cap, $comune, $provincia, $codDest, $pec]
                );
                if ($importaPDC && $nuovoId > 0) {
                    $pdo = Database::getInstance();
                    importaPDCTemplate($pdo, $nuovoId);
                    $msg = 'Azienda aggiunta con piano dei conti predefinito importato.';
                } else {
                    $msg = 'Azienda aggiunta.';
                }
            } catch (\Exception $e) {
                $msg = 'Errore: ' . $e->getMessage();
                $msgType = 'danger';
            }
        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];
            Database::execute(
                'UPDATE aziende SET ragione_sociale=?, partita_iva=?, codice_fiscale=?, indirizzo=?, cap=?, comune=?, provincia=?, codice_destinatario=?, pec_destinatario=? WHERE id=?',
                [$ragioneSociale, $partitaIva, $codiceFiscale, $indirizzo, $cap, $comune, $provincia, $codDest, $pec, $id]
            );
            $msg = 'Azienda aggiornata.';
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            Database::execute('UPDATE aziende SET attiva = NOT attiva WHERE id=?', [$id]);
            $msg = 'Stato aggiornato.';
        }
    } else {
        $msg     = implode(' ', $errors);
        $msgType = 'danger';
    }
}

$aziende = Database::fetchAll('SELECT * FROM aziende ORDER BY ragione_sociale');

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-buildings me-2"></i>Gestione Aziende</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAzienda" data-action="add">
    <i class="bi bi-plus-lg me-1"></i>Nuova azienda
  </button>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>Ragione sociale</th>
            <th>P.IVA</th>
            <th>Comune</th>
            <th>Cod. destinatario</th>
            <th>Stato</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($aziende as $az): ?>
          <tr>
            <td><?= htmlspecialchars($az['ragione_sociale']) ?></td>
            <td><code><?= htmlspecialchars($az['partita_iva'] ?? '') ?></code></td>
            <td><?= htmlspecialchars(($az['comune'] ?? '') . ($az['provincia'] ? ' (' . $az['provincia'] . ')' : '')) ?></td>
            <td><?= htmlspecialchars($az['codice_destinatario'] ?? '') ?></td>
            <td>
              <?php if ($az['attiva']): ?>
                <span class="badge bg-success">Attiva</span>
              <?php else: ?>
                <span class="badge bg-secondary">Disattiva</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary me-1"
                data-bs-toggle="modal" data-bs-target="#modalAzienda"
                data-action="edit"
                data-id="<?= $az['id'] ?>"
                data-ragione="<?= htmlspecialchars($az['ragione_sociale']) ?>"
                data-piva="<?= htmlspecialchars($az['partita_iva']) ?>"
                data-cf="<?= htmlspecialchars($az['codice_fiscale'] ?? '') ?>"
                data-indirizzo="<?= htmlspecialchars($az['indirizzo'] ?? '') ?>"
                data-cap="<?= htmlspecialchars($az['cap'] ?? '') ?>"
                data-comune="<?= htmlspecialchars($az['comune'] ?? '') ?>"
                data-provincia="<?= htmlspecialchars($az['provincia'] ?? '') ?>"
                data-coddest="<?= htmlspecialchars($az['codice_destinatario'] ?? '') ?>"
                data-pec="<?= htmlspecialchars($az['pec_destinatario'] ?? '') ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $az['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $az['attiva'] ? 'warning' : 'success' ?>">
                  <i class="bi bi-<?= $az['attiva'] ? 'pause' : 'play' ?>"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal add/edit -->
<div class="modal fade" id="modalAzienda" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="modalId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitolo">Nuova azienda</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Ragione sociale <span class="text-danger">*</span></label>
              <input type="text" name="ragione_sociale" id="fRagione" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Partita IVA <span class="text-danger">*</span></label>
              <input type="text" name="partita_iva" id="fPiva" class="form-control" required maxlength="20">
            </div>
            <div class="col-md-6">
              <label class="form-label">Codice fiscale</label>
              <input type="text" name="codice_fiscale" id="fCf" class="form-control" maxlength="20">
            </div>
            <div class="col-12">
              <label class="form-label">Indirizzo sede</label>
              <input type="text" name="indirizzo" id="fIndirizzo" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">CAP</label>
              <input type="text" name="cap" id="fCap" class="form-control" maxlength="10">
            </div>
            <div class="col-md-6">
              <label class="form-label">Comune</label>
              <input type="text" name="comune" id="fComune" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Provincia</label>
              <input type="text" name="provincia" id="fProvincia" class="form-control" maxlength="2">
            </div>
            <div class="col-md-6">
              <label class="form-label">Codice destinatario SdI</label>
              <input type="text" name="codice_destinatario" id="fCodDest" class="form-control" maxlength="10">
            </div>
            <div class="col-md-6">
              <label class="form-label">PEC destinatario</label>
              <input type="email" name="pec_destinatario" id="fPec" class="form-control">
            </div>
            <div class="col-12" id="rowImportaPDC">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="importa_pdc" id="fImportaPDC" value="1" checked>
                <label class="form-check-label" for="fImportaPDC">
                  Importa piano dei conti e centri di costo predefiniti
                  <small class="text-muted">(~400 voci + 9 centri di costo alberghieri)</small>
                </label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn btn-primary">Salva</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('modalAzienda').addEventListener('show.bs.modal', function(e) {
  const btn = e.relatedTarget;
  if (!btn) return;
  const action = btn.dataset.action;
  document.getElementById('modalAction').value = action;
  document.getElementById('modalTitolo').textContent = action === 'add' ? 'Nuova azienda' : 'Modifica azienda';
  document.getElementById('rowImportaPDC').style.display = action === 'add' ? '' : 'none';
  document.getElementById('modalId').value       = btn.dataset.id ?? '';
  document.getElementById('fRagione').value      = btn.dataset.ragione ?? '';
  document.getElementById('fPiva').value         = btn.dataset.piva ?? '';
  document.getElementById('fCf').value           = btn.dataset.cf ?? '';
  document.getElementById('fIndirizzo').value    = btn.dataset.indirizzo ?? '';
  document.getElementById('fCap').value          = btn.dataset.cap ?? '';
  document.getElementById('fComune').value       = btn.dataset.comune ?? '';
  document.getElementById('fProvincia').value    = btn.dataset.provincia ?? '';
  document.getElementById('fCodDest').value      = btn.dataset.coddest ?? '';
  document.getElementById('fPec').value          = btn.dataset.pec ?? '';
});
</script>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
