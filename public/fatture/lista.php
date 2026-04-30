<?php

declare(strict_types=1);
$pageTitle  = 'Archivio fatture';
$activePage = 'fatture';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();
$user      = Auth::getUser();
$csrfToken = Auth::csrfToken();

// Parametri filtro
$filtroCedente  = trim($_GET['cedente']  ?? '');
$filtroDataDa   = $_GET['data_da']       ?? '';
$filtroDataA    = $_GET['data_a']        ?? '';
$filtroStato    = $_GET['stato']         ?? '';
$filtroTipoDoc  = $_GET['tipo_documento'] ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 25;
$offset         = ($page - 1) * $perPage;
$export         = isset($_GET['export']) && $_GET['export'] === 'csv';

// Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idAzienda) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        die('Token CSRF non valido.');
    }

    // Cambio stato
    if (isset($_POST['action']) && $_POST['action'] === 'cambia_stato') {
        $idFattura  = (int)($_POST['id_fattura'] ?? 0);
        $nuovoStato = $_POST['nuovo_stato'] ?? '';
        $statiValidi = ['importata', 'verificata', 'contabilizzata', 'pagata', 'annullata'];
        if ($idFattura && in_array($nuovoStato, $statiValidi, true)) {
            Database::query(
                'UPDATE fatture_elettroniche SET stato=? WHERE id=? AND id_azienda=?',
                [$nuovoStato, $idFattura, $idAzienda]
            );
        }
    }

    // Eliminazione (solo admin)
    if (isset($_POST['action']) && $_POST['action'] === 'elimina' && Auth::isAdmin()) {
        $idFattura = (int)($_POST['id_fattura'] ?? 0);
        if ($idFattura) {
            Database::query(
                'DELETE FROM fatture_elettroniche WHERE id=? AND id_azienda=?',
                [$idFattura, $idAzienda]
            );
        }
    }

    // Redirect per evitare risubmit
    $qs = http_build_query(array_filter([
        'cedente'         => $filtroCedente,
        'data_da'         => $filtroDataDa,
        'data_a'          => $filtroDataA,
        'stato'           => $filtroStato,
        'tipo_documento'  => $filtroTipoDoc,
        'page'            => $page,
    ]));
    header('Location: lista.php' . ($qs ? "?$qs" : ''));
    exit;
}

// Costruzione query con filtri
$where  = ['fe.id_azienda = ?'];
$params = [$idAzienda];

if ($filtroCedente !== '') {
    $where[]  = '(cp.denominazione LIKE ? OR cp.id_codice LIKE ?)';
    $params[] = "%$filtroCedente%";
    $params[] = "%$filtroCedente%";
}
if ($filtroDataDa !== '') {
    $where[]  = 'fe.data_documento >= ?';
    $params[] = $filtroDataDa;
}
if ($filtroDataA !== '') {
    $where[]  = 'fe.data_documento <= ?';
    $params[] = $filtroDataA;
}
if ($filtroStato !== '') {
    $where[]  = 'fe.stato = ?';
    $params[] = $filtroStato;
}
if ($filtroTipoDoc !== '') {
    $where[]  = 'fe.tipo_documento = ?';
    $params[] = $filtroTipoDoc;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$baseSQL = "FROM fatture_elettroniche fe
            JOIN cedenti_prestatori cp ON cp.id = fe.id_cedente
            $whereSQL";

// Totale righe per paginazione
$totRow    = $idAzienda
    ? Database::fetchOne("SELECT COUNT(*) as tot $baseSQL", $params)
    : ['tot' => 0];
$totale    = (int)($totRow['tot'] ?? 0);
$totPagine = (int)ceil($totale / $perPage);

// Export CSV
if ($export && $idAzienda) {
    $righe = Database::fetchAll(
        "SELECT fe.data_documento, fe.numero_documento, cp.denominazione, fe.tipo_documento,
                fe.importo_totale, fe.stato
         $baseSQL
         ORDER BY fe.data_documento DESC, fe.id DESC",
        $params
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fatture_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 per Excel
    fputcsv($out, ['Data', 'Numero', 'Fornitore', 'Tipo', 'Totale', 'Stato'], ';');
    foreach ($righe as $r) {
        fputcsv($out, [
            $r['data_documento'],
            $r['numero_documento'],
            $r['denominazione'],
            $r['tipo_documento'],
            number_format((float)$r['importo_totale'], 2, ',', '.'),
            $r['stato'],
        ], ';');
    }
    fclose($out);
    exit;
}

// Recupera fatture paginate
$fatture = $idAzienda ? Database::fetchAll(
    "SELECT fe.id, fe.data_documento, fe.numero_documento, fe.tipo_documento,
            fe.importo_totale, fe.stato, fe.nome_file,
            cp.denominazione, cp.nome as cp_nome, cp.cognome as cp_cognome, cp.id_codice
     $baseSQL
     ORDER BY fe.data_documento DESC, fe.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
) : [];

$stati = ['importata', 'verificata', 'contabilizzata', 'pagata', 'annullata'];
$statiBadge = [
    'importata'      => 'bg-warning text-dark',
    'verificata'     => 'bg-info text-dark',
    'contabilizzata' => 'bg-primary',
    'pagata'         => 'bg-success',
    'annullata'      => 'bg-danger',
];

function nomeFornitore(array $r): string {
    if (!empty($r['denominazione'])) return $r['denominazione'];
    return trim(($r['cp_cognome'] ?? '') . ' ' . ($r['cp_nome'] ?? '')) ?: $r['id_codice'];
}

// Costruisce query string per i link conservando i filtri
$filtriQs = http_build_query(array_filter([
    'cedente'         => $filtroCedente,
    'data_da'         => $filtroDataDa,
    'data_a'          => $filtroDataA,
    'stato'           => $filtroStato,
    'tipo_documento'  => $filtroTipoDoc,
]));
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Nessuna azienda selezionata.</div>
<?php else: ?>

<!-- Filtri -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small mb-1">Fornitore</label>
        <input type="text" name="cedente" class="form-control form-control-sm"
               value="<?= htmlspecialchars($filtroCedente) ?>" placeholder="Nome o P.IVA">
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Data da</label>
        <input type="date" name="data_da" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroDataDa) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Data a</label>
        <input type="date" name="data_a" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroDataA) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Stato</label>
        <select name="stato" class="form-select form-select-sm">
          <option value="">Tutti</option>
          <?php foreach ($stati as $s): ?>
          <option value="<?= $s ?>" <?= $filtroStato === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Tipo doc.</label>
        <input type="text" name="tipo_documento" class="form-control form-control-sm"
               value="<?= htmlspecialchars($filtroTipoDoc) ?>" placeholder="es. TD01">
      </div>
      <div class="col-sm-1 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary flex-fill">
          <i class="bi bi-search"></i>
        </button>
        <a href="lista.php" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-x"></i>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Header azioni -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="small text-muted">
    <?= $totale ?> fattur<?= $totale === 1 ? 'a' : 'e' ?> trovat<?= $totale === 1 ? 'a' : 'e' ?>
  </div>
  <div class="d-flex gap-2">
    <a href="lista.php?<?= $filtriQs ?>&export=csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download me-1"></i>CSV
    </a>
    <a href="upload.php" class="btn btn-sm btn-primary">
      <i class="bi bi-cloud-upload me-1"></i>Importa
    </a>
  </div>
</div>

<!-- Tabella -->
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Data</th>
          <th>Numero</th>
          <th>Fornitore</th>
          <th>Tipo</th>
          <th class="text-end">Totale (€)</th>
          <th>Stato</th>
          <th class="text-center">Azioni</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($fatture)): ?>
      <tr><td colspan="7" class="text-center text-muted py-4">Nessuna fattura trovata.</td></tr>
      <?php else: ?>
      <?php foreach ($fatture as $f): ?>
      <tr>
        <td class="text-nowrap"><?= htmlspecialchars($f['data_documento']) ?></td>
        <td class="text-nowrap"><?= htmlspecialchars($f['numero_documento']) ?></td>
        <td class="text-truncate" style="max-width:200px">
          <?= htmlspecialchars(nomeFornitore($f)) ?>
          <div class="small text-muted"><?= htmlspecialchars($f['id_codice']) ?></div>
        </td>
        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($f['tipo_documento']) ?></span></td>
        <td class="text-end fw-semibold"><?= number_format((float)$f['importo_totale'], 2, ',', '.') ?></td>
        <td>
          <span class="badge <?= $statiBadge[$f['stato']] ?? 'bg-secondary' ?>">
            <?= htmlspecialchars(ucfirst($f['stato'])) ?>
          </span>
        </td>
        <td class="text-center text-nowrap">
          <a href="dettaglio.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Dettaglio">
            <i class="bi bi-eye"></i>
          </a>
          <!-- Cambio stato inline -->
          <form method="post" class="d-inline" id="form-stato-<?= $f['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="cambia_stato">
            <input type="hidden" name="id_fattura" value="<?= $f['id'] ?>">
            <select name="nuovo_stato" class="form-select form-select-sm d-inline-block w-auto"
                    onchange="document.getElementById('form-stato-<?= $f['id'] ?>').submit()"
                    title="Cambia stato">
              <?php foreach ($stati as $s): ?>
              <option value="<?= $s ?>" <?= $f['stato'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <?php if (Auth::isAdmin()): ?>
          <form method="post" class="d-inline ms-1"
                onsubmit="return confirm('Eliminare definitivamente la fattura <?= htmlspecialchars(addslashes($f['numero_documento'])) ?>?')">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="elimina">
            <input type="hidden" name="id_fattura" value="<?= $f['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
              <i class="bi bi-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Paginazione -->
<?php if ($totPagine > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center mb-0">
    <?php for ($p = 1; $p <= $totPagine; $p++): ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
      <a class="page-link" href="lista.php?<?= $filtriQs ?>&page=<?= $p ?>">
        <?= $p ?>
      </a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
