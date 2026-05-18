<?php

declare(strict_types=1);
$pageTitle  = 'Archivio corrispettivi';
$activePage = 'corr_lista';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();

$mesiLabel = ['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

// Gestione eliminazione (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina' && Auth::isAdmin()) {
    Auth::verifyCsrf();
    $idDel = (int)($_POST['id'] ?? 0);
    if ($idDel > 0 && $idAzienda) {
        Database::query('DELETE FROM corrispettivi WHERE id=? AND id_azienda=?', [$idDel, $idAzienda]);
    }
    header('Location: lista.php');
    exit;
}

// Anni disponibili
$anniDisp = $idAzienda ? Database::fetchAll(
    'SELECT DISTINCT YEAR(data_documento) as anno FROM corrispettivi WHERE id_azienda=? ORDER BY anno DESC',
    [$idAzienda]
) : [];

$annoDefault = (int)date('Y');
$anno  = isset($_GET['anno'])  && $_GET['anno']  !== '' ? (int)$_GET['anno']  : $annoDefault;
$mese  = isset($_GET['mese'])  && $_GET['mese']  !== '' ? (int)$_GET['mese']  : 0;
$tipo  = $_GET['tipo'] ?? '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$params = [$idAzienda];
$where  = ' AND YEAR(data_documento) = ?';
$params[] = $anno;

if ($mese > 0) {
    $where .= ' AND MONTH(data_documento) = ?';
    $params[] = $mese;
}
if ($tipo !== '') {
    $where .= ' AND tipo = ?';
    $params[] = $tipo;
}

$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Conteggio totale
$totRighe = $idAzienda ? (int)(Database::fetchOne(
    "SELECT COUNT(*) as n FROM corrispettivi WHERE id_azienda=? {$where}",
    $params
)['n'] ?? 0) : 0;

// Aggregati per periodo visibile
$aggregati = $idAzienda ? Database::fetchOne(
    "SELECT SUM(imponibile) as tot_imponibile, SUM(imposta) as tot_imposta, SUM(totale) as tot_totale
     FROM corrispettivi WHERE id_azienda=? {$where}",
    $params
) : null;

// Righe paginate
$righe = [];
if ($idAzienda && !$export) {
    $params2   = $params;
    $params2[] = $limit;
    $params2[] = $offset;
    $righe = Database::fetchAll(
        "SELECT * FROM corrispettivi WHERE id_azienda=? {$where} ORDER BY data_documento DESC, id DESC LIMIT ? OFFSET ?",
        $params2
    );
}

// Export CSV
if ($export && $idAzienda) {
    $tutte = Database::fetchAll(
        "SELECT * FROM corrispettivi WHERE id_azienda=? {$where} ORDER BY data_documento, id",
        $params
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="corrispettivi_' . $anno . ($mese ? "_{$mese}" : '') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo "data;tipo;descrizione;imponibile;aliquota_iva;imposta;totale\n";
    foreach ($tutte as $r) {
        echo implode(';', [
            $r['data_documento'], $r['tipo'], $r['descrizione'] ?? '',
            number_format((float)$r['imponibile'], 2, '.', ''),
            $r['aliquota_iva'] ?? '',
            number_format((float)$r['imposta'], 2, '.', ''),
            number_format((float)$r['totale'], 2, '.', ''),
        ]) . "\n";
    }
    exit;
}

$totalPagine = $totRighe > 0 ? (int)ceil($totRighe / $limit) : 1;

function fmtEur(mixed $v): string {
    return '€ ' . number_format((float)($v ?? 0), 2, ',', '.');
}

function buildUrl(array $extra): string {
    $params = array_merge($_GET, $extra);
    unset($params['action']);
    return '?' . http_build_query($params);
}
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Seleziona un'azienda.</div>
<?php else: ?>

<!-- Filtri -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-center">
      <div class="col-auto">
        <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($anniDisp as $ad): ?>
          <option value="<?= $ad['anno'] ?>" <?= $ad['anno'] == $anno ? 'selected' : '' ?>><?= $ad['anno'] ?></option>
          <?php endforeach; ?>
          <?php if (!$anniDisp): ?>
          <option value="<?= $anno ?>" selected><?= $anno ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="mese" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Tutti i mesi</option>
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m === $mese ? 'selected' : '' ?>><?= $mesiLabel[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="tipo" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Tutti i tipi</option>
          <option value="corrispettivo" <?= $tipo === 'corrispettivo' ? 'selected' : '' ?>>Corrispettivo</option>
          <option value="reso"          <?= $tipo === 'reso'          ? 'selected' : '' ?>>Reso</option>
          <option value="annullo"       <?= $tipo === 'annullo'       ? 'selected' : '' ?>>Annullo</option>
        </select>
      </div>
      <div class="col-auto ms-auto">
        <a href="<?= buildUrl(['export' => 'csv']) ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-download me-1"></i>CSV
        </a>
        <a href="upload.php" class="btn btn-sm btn-primary ms-1">
          <i class="bi bi-cloud-upload me-1"></i>Importa
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Totali aggregati -->
<?php if ($aggregati && $totRighe > 0): ?>
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card border-0 shadow-sm text-center py-2">
      <div class="card-body py-1">
        <div class="small text-muted">Imponibile</div>
        <div class="fs-5 fw-bold"><?= fmtEur($aggregati['tot_imponibile']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm text-center py-2">
      <div class="card-body py-1">
        <div class="small text-muted">IVA</div>
        <div class="fs-5 fw-bold"><?= fmtEur($aggregati['tot_imposta']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm text-center py-2">
      <div class="card-body py-1">
        <div class="small text-muted">Totale lordo</div>
        <div class="fs-5 fw-bold text-success"><?= fmtEur($aggregati['tot_totale']) ?></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Tabella -->
<div class="card shadow-sm border-0">
  <div class="card-header fw-semibold bg-white border-bottom-0 d-flex justify-content-between align-items-center">
    <span><i class="bi bi-receipt me-2 text-success"></i>Corrispettivi (<?= number_format($totRighe, 0, ',', '.') ?> righe)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Data</th>
          <th>Tipo</th>
          <th>Descrizione</th>
          <th class="text-end">Imponibile</th>
          <th class="text-center">Aliq. %</th>
          <th class="text-end">IVA</th>
          <th class="text-end">Totale</th>
          <?php if (Auth::isAdmin()): ?><th class="text-center">Azioni</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($righe)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Nessun corrispettivo trovato.</td></tr>
        <?php else: ?>
        <?php foreach ($righe as $r): ?>
        <tr>
          <td class="text-nowrap"><?= htmlspecialchars($r['data_documento']) ?></td>
          <td>
            <?php
            $tipoBadge = match($r['tipo']) {
                'reso'    => '<span class="badge bg-warning text-dark">Reso</span>',
                'annullo' => '<span class="badge bg-secondary">Annullo</span>',
                default   => '<span class="badge bg-success">Corrispettivo</span>',
            };
            echo $tipoBadge;
            ?>
          </td>
          <td class="text-truncate" style="max-width:220px"><?= htmlspecialchars($r['descrizione'] ?? '—') ?></td>
          <td class="text-end"><?= fmtEur($r['imponibile']) ?></td>
          <td class="text-center"><?= $r['aliquota_iva'] !== null ? number_format((float)$r['aliquota_iva'], 0) . '%' : '—' ?></td>
          <td class="text-end"><?= fmtEur($r['imposta']) ?></td>
          <td class="text-end fw-semibold"><?= fmtEur($r['totale']) ?></td>
          <?php if (Auth::isAdmin()): ?>
          <td class="text-center">
            <form method="post" onsubmit="return confirm('Eliminare questo corrispettivo?');" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
              <input type="hidden" name="action" value="elimina">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Paginazione -->
<?php if ($totalPagine > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center mb-0">
    <?php if ($page > 1): ?>
    <li class="page-item"><a class="page-link" href="<?= buildUrl(['page' => $page - 1]) ?>">‹</a></li>
    <?php endif; ?>
    <?php
    $pStart = max(1, $page - 2);
    $pEnd   = min($totalPagine, $page + 2);
    for ($p = $pStart; $p <= $pEnd; $p++):
    ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
      <a class="page-link" href="<?= buildUrl(['page' => $p]) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
    <?php if ($page < $totalPagine): ?>
    <li class="page-item"><a class="page-link" href="<?= buildUrl(['page' => $page + 1]) ?>">›</a></li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
