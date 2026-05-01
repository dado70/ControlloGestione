<?php
declare(strict_types=1);
$pageTitle  = 'Mappatura Conti → Centri di Costo';
$activePage = 'mappatura_cc';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::init();
Auth::requireRole('admin');

$idAzienda = Auth::getIdAzienda();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(Auth::csrfToken(), $_POST['csrf'] ?? '')) { http_response_code(419); die('CSRF token non valido.'); }

    if (isset($_POST['salva'])) {
        $idConto  = (int)($_POST['id_conto']       ?? 0);
        $idCentro = (int)($_POST['id_centro_costo'] ?? 0);
        if ($idConto && $idCentro) {
            Database::execute(
                'INSERT INTO mappatura_conto_cc (id_azienda, id_conto, id_centro_costo, percentuale)
                 VALUES (?,?,?,100)
                 ON DUPLICATE KEY UPDATE id_centro_costo=VALUES(id_centro_costo)',
                [$idAzienda, $idConto, $idCentro]
            );
            $msg = 'Mappatura salvata.';
        }
    }

    if (isset($_POST['elimina'])) {
        Database::execute(
            'DELETE FROM mappatura_conto_cc WHERE id_azienda=? AND id_conto=?',
            [$idAzienda, (int)$_POST['id_conto']]
        );
        $msg = 'Mappatura rimossa.';
    }
}

$soloNonMappati = !empty($_GET['non_mappati']);

$sql = 'SELECT pc.id, pc.codice, pc.descrizione, pc.tipo,
               cc.id AS id_centro, cc.codice AS cc_codice, cc.descrizione AS cc_desc
        FROM piano_conti pc
        LEFT JOIN mappatura_conto_cc mcc ON mcc.id_conto=pc.id AND mcc.id_azienda=?
        LEFT JOIN centri_costo cc ON cc.id=mcc.id_centro_costo
        WHERE pc.id_azienda=? AND pc.attivo=1 AND pc.livello=3
          AND pc.tipo IN (\'COSTO\',\'RICAVO\',\'PATRIMONIALE\')';
$params = [$idAzienda, $idAzienda];

if ($soloNonMappati) {
    $sql .= ' AND mcc.id IS NULL';
}
$sql .= ' ORDER BY pc.codice';

$conti   = Database::fetchAll($sql, $params);
$centri  = Database::fetchAll(
    'SELECT id, codice, descrizione FROM centri_costo WHERE id_azienda=? AND attivo=1 ORDER BY ordine, codice',
    [$idAzienda]
);

$nonMappati = count(array_filter($conti, fn($r) => !$r['id_centro']));

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Mappatura Conti → Centri di Costo</h4>
  <a href="?<?= $soloNonMappati ? '' : 'non_mappati=1' ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-filter me-1"></i>
    <?= $soloNonMappati ? 'Mostra tutti' : 'Solo non mappati' ?>
  </a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($nonMappati > 0 && !$soloNonMappati): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-1"></i>
  <strong><?= $nonMappati ?></strong> conti non hanno ancora un centro di costo predefinito.
  <a href="?non_mappati=1" class="alert-link">Mostra solo i non mappati</a>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>Codice PDC</th>
            <th>Descrizione conto</th>
            <th>Tipo</th>
            <th class="text-center">→</th>
            <th>Centro di costo</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($conti as $r): ?>
          <tr class="<?= !$r['id_centro'] ? 'table-warning' : '' ?>">
            <td><code><?= htmlspecialchars($r['codice']) ?></code></td>
            <td><?= htmlspecialchars($r['descrizione']) ?></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($r['tipo']) ?></span></td>
            <td class="text-center text-muted">→</td>
            <td>
              <?php if ($r['id_centro']): ?>
                <span class="badge bg-primary">
                  <?= htmlspecialchars($r['cc_codice']) ?> — <?= htmlspecialchars($r['cc_desc']) ?>
                </span>
              <?php else: ?>
                <form method="post" class="d-flex gap-1">
                  <input type="hidden" name="csrf" value="<?= Auth::csrfToken() ?>">
                  <input type="hidden" name="id_conto" value="<?= $r['id'] ?>">
                  <select name="id_centro_costo" class="form-select form-select-sm" required style="min-width:200px">
                    <option value="">-- seleziona --</option>
                    <?php foreach ($centri as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['codice'] . ' — ' . $c['descrizione']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="salva" class="btn btn-sm btn-success">
                    <i class="bi bi-check-lg"></i>
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['id_centro']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Rimuovere la mappatura?')">
                <input type="hidden" name="csrf" value="<?= Auth::csrfToken() ?>">
                <input type="hidden" name="id_conto" value="<?= $r['id'] ?>">
                <button type="submit" name="elimina" class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer text-muted small">
    <?= count($conti) ?> conti — <?= count($conti) - $nonMappati ?> mappati — <?= $nonMappati ?> non mappati
  </div>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
