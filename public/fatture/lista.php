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
$filtroCedente = trim($_GET['cedente']       ?? '');
$filtroDataDa  = $_GET['data_da']            ?? '';
$filtroDataA   = $_GET['data_a']             ?? '';
$filtroStato   = $_GET['stato']              ?? '';
$filtroTipoDoc = $_GET['tipo_documento']     ?? '';
$page          = max(1, (int)($_GET['page']  ?? 1));
$perPage       = 25;
$offset        = ($page - 1) * $perPage;
$export        = isset($_GET['export']) && $_GET['export'] === 'csv';

// Ordinamento
$sortCol     = $_GET['sort'] ?? 'data_documento';
$sortDir     = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$sortAllowed = ['data_documento','numero_documento','denominazione','tipo_documento',
                'tot_imponibile','tot_iva','importo_totale','stato'];
if (!in_array($sortCol, $sortAllowed, true)) { $sortCol = 'data_documento'; }

// Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idAzienda) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        die('Token CSRF non valido.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'cambia_stato') {
        $idFattura  = (int)($_POST['id_fattura'] ?? 0);
        $nuovoStato = $_POST['nuovo_stato'] ?? '';
        $statiValidi = ['importata','verificata','contabilizzata','pagata','annullata'];
        if ($idFattura && in_array($nuovoStato, $statiValidi, true)) {
            Database::query(
                'UPDATE fatture_elettroniche SET stato=? WHERE id=? AND id_azienda=?',
                [$nuovoStato, $idFattura, $idAzienda]
            );
        }
    }

    if ($action === 'elimina' && Auth::isAdmin()) {
        $idFattura = (int)($_POST['id_fattura'] ?? 0);
        if ($idFattura) {
            Database::query(
                'DELETE FROM fatture_elettroniche WHERE id=? AND id_azienda=?',
                [$idFattura, $idAzienda]
            );
        }
    }

    if ($action === 'bulk_action') {
        $ids    = array_values(array_filter(array_map('intval', $_POST['ids'] ?? []), fn($v) => $v > 0));
        $bulkOp = $_POST['bulk_op'] ?? '';
        $statiValidi = ['verificata','contabilizzata','pagata','annullata'];
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if (in_array($bulkOp, $statiValidi, true)) {
                Database::query(
                    "UPDATE fatture_elettroniche SET stato=? WHERE id IN ($ph) AND id_azienda=?",
                    array_merge([$bulkOp], $ids, [$idAzienda])
                );
            } elseif ($bulkOp === 'elimina' && Auth::isAdmin()) {
                Database::query(
                    "DELETE FROM fatture_elettroniche WHERE id IN ($ph) AND id_azienda=?",
                    array_merge($ids, [$idAzienda])
                );
            }
        }
    }

    $qs = http_build_query(array_filter([
        'cedente'        => $filtroCedente,
        'data_da'        => $filtroDataDa,
        'data_a'         => $filtroDataA,
        'stato'          => $filtroStato,
        'tipo_documento' => $filtroTipoDoc,
        'page'           => $page,
    ]));
    header('Location: lista.php' . ($qs ? "?$qs" : ''));
    exit;
}

// Costruzione WHERE
$where  = ['fe.id_azienda = ?'];
$params = [$idAzienda];

if ($filtroCedente !== '') {
    $where[]  = '(cp.denominazione LIKE ? OR cp.id_codice LIKE ?)';
    $params[] = "%$filtroCedente%";
    $params[] = "%$filtroCedente%";
}
if ($filtroDataDa !== '') { $where[] = 'fe.data_documento >= ?'; $params[] = $filtroDataDa; }
if ($filtroDataA  !== '') { $where[] = 'fe.data_documento <= ?'; $params[] = $filtroDataA; }
if ($filtroStato  !== '') { $where[] = 'fe.stato = ?';           $params[] = $filtroStato; }
if ($filtroTipoDoc !== '') { $where[] = 'fe.tipo_documento = ?'; $params[] = $filtroTipoDoc; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$baseSQL  = "FROM fatture_elettroniche fe
             JOIN cedenti_prestatori cp ON cp.id = fe.id_cedente
             $whereSQL";

// Espressione di ordinamento (alias MySQL 8 supportati in ORDER BY)
if ($sortCol === 'denominazione') {
    $orderExpr = 'cp.denominazione';
} elseif (in_array($sortCol, ['tot_imponibile','tot_iva'], true)) {
    $orderExpr = $sortCol;
} else {
    $orderExpr = "fe.$sortCol";
}
$orderSQL = "ORDER BY $orderExpr $sortDir, fe.id DESC";

// Totale righe per paginazione
$totRow  = $idAzienda ? Database::fetchOne("SELECT COUNT(*) as tot $baseSQL", $params) : ['tot' => 0];
$totale  = (int)($totRow['tot'] ?? 0);
$totPagine = (int)ceil($totale / $perPage);

// Totalizzatori (su tutta la selezione filtrata, non solo la pagina corrente)
$totals = $idAzienda ? Database::fetchOne(
    "SELECT COALESCE(SUM((SELECT SUM(ri.imponibile) FROM fatture_riepilogo_iva ri WHERE ri.id_fattura=fe.id)),0) AS sum_imponibile,
            COALESCE(SUM((SELECT SUM(ri.imposta)    FROM fatture_riepilogo_iva ri WHERE ri.id_fattura=fe.id)),0) AS sum_iva,
            COALESCE(SUM(fe.importo_totale),0) AS sum_totale
     $baseSQL",
    $params
) : null;

// Export CSV
if ($export && $idAzienda) {
    $righe = Database::fetchAll(
        "SELECT fe.data_documento, fe.numero_documento, cp.denominazione, fe.tipo_documento,
                COALESCE((SELECT SUM(ri.imponibile) FROM fatture_riepilogo_iva ri WHERE ri.id_fattura=fe.id),0) AS tot_imponibile,
                COALESCE((SELECT SUM(ri.imposta)    FROM fatture_riepilogo_iva ri WHERE ri.id_fattura=fe.id),0) AS tot_iva,
                fe.importo_totale, fe.stato
         $baseSQL ORDER BY fe.data_documento DESC, fe.id DESC",
        $params
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fatture_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Data','Numero','Fornitore','Tipo','Imponibile','IVA','Totale','Stato'], ';');
    foreach ($righe as $r) {
        fputcsv($out, [
            $r['data_documento'], $r['numero_documento'], $r['denominazione'], $r['tipo_documento'],
            number_format((float)$r['tot_imponibile'], 2, ',', '.'),
            number_format((float)$r['tot_iva'],        2, ',', '.'),
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
            cp.denominazione, cp.nome as cp_nome, cp.cognome as cp_cognome, cp.id_codice,
            COALESCE((SELECT SUM(ri.imponibile) FROM fatture_riepilogo_iva ri WHERE ri.id_fattura=fe.id),0) AS tot_imponibile,
            COALESCE((SELECT SUM(ri.imposta)    FROM fatture_riepilogo_iva ri WHERE ri.id_fattura=fe.id),0) AS tot_iva
     $baseSQL $orderSQL
     LIMIT $perPage OFFSET $offset",
    $params
) : [];

$stati = ['importata','verificata','contabilizzata','pagata','annullata'];
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

// Helper intestazione ordinabile
function thSort(string $col, string $label, string $cur, string $dir, string $fqs): string {
    $next = ($cur === $col && $dir === 'asc') ? 'desc' : 'asc';
    $ico  = $cur === $col
        ? ($dir === 'asc'
            ? '<i class="bi bi-caret-up-fill text-primary ms-1" style="font-size:.6rem"></i>'
            : '<i class="bi bi-caret-down-fill text-primary ms-1" style="font-size:.6rem"></i>')
        : '<i class="bi bi-chevron-expand text-muted ms-1" style="font-size:.6rem;opacity:.5"></i>';
    $sep = $fqs ? '&' : '';
    return '<a href="lista.php?' . htmlspecialchars($fqs . $sep . "sort=$col&dir=$next")
         . '" class="text-decoration-none text-dark d-inline-flex align-items-center">'
         . $label . $ico . '</a>';
}

// Query string filtri (senza page, con sort)
$filtriArr = array_filter([
    'cedente'        => $filtroCedente,
    'data_da'        => $filtroDataDa,
    'data_a'         => $filtroDataA,
    'stato'          => $filtroStato,
    'tipo_documento' => $filtroTipoDoc,
]);
$filtriQs = http_build_query($filtriArr);
if ($sortCol !== 'data_documento' || $sortDir !== 'desc') {
    $filtriQs .= ($filtriQs ? '&' : '') . "sort=$sortCol&dir=$sortDir";
}
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
        <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-search"></i></button>
        <a href="lista.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Totalizzatori -->
<?php if ($totals): ?>
<div class="row g-2 mb-2">
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-light text-center p-2">
      <div class="text-muted" style="font-size:.72rem">Imponibile (<?= $totale ?> fatt.)</div>
      <div class="fw-semibold"><?= number_format((float)$totals['sum_imponibile'], 2, ',', '.') ?> €</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-light text-center p-2">
      <div class="text-muted" style="font-size:.72rem">IVA</div>
      <div class="fw-semibold"><?= number_format((float)$totals['sum_iva'], 2, ',', '.') ?> €</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 bg-primary bg-opacity-10 text-center p-2">
      <div class="text-muted" style="font-size:.72rem">Totale</div>
      <div class="fw-bold text-primary"><?= number_format((float)$totals['sum_totale'], 2, ',', '.') ?> €</div>
    </div>
  </div>
  <div class="col-6 col-md-3 d-flex align-items-center gap-2 justify-content-end">
    <a href="lista.php?<?= $filtriQs ?>&export=csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download me-1"></i>CSV
    </a>
    <a href="upload.php" class="btn btn-sm btn-primary">
      <i class="bi bi-cloud-upload me-1"></i>Importa
    </a>
  </div>
</div>
<?php endif; ?>

<!-- Toolbar azioni massive -->
<form id="bulkForm" method="post" class="d-flex align-items-center gap-2 mb-2 flex-wrap">
  <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
  <input type="hidden" name="action"     value="bulk_action">
  <div id="bulkIds"></div>
  <span class="small text-muted" id="bulkCount">0 selezionate</span>
  <select name="bulk_op" id="bulkOp" class="form-select form-select-sm" style="width:auto">
    <option value="">— Azione massiva —</option>
    <option value="verificata">Segna: Verificata</option>
    <option value="contabilizzata">Segna: Contabilizzata</option>
    <option value="pagata">Segna: Pagata</option>
    <option value="annullata">Segna: Annullata</option>
    <?php if (Auth::isAdmin()): ?>
    <option value="elimina">Elimina selezionate</option>
    <?php endif; ?>
  </select>
  <button type="button" id="btnBulk" class="btn btn-sm btn-outline-primary" disabled onclick="submitBulk()">
    <i class="bi bi-check2-all me-1"></i>Applica
  </button>
</form>

<!-- Tabella -->
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:36px">
            <input type="checkbox" id="selAll" class="form-check-input" title="Seleziona/deseleziona tutti">
          </th>
          <th><?= thSort('data_documento',  'Data',           $sortCol, $sortDir, $filtriQs) ?></th>
          <th><?= thSort('numero_documento','Numero',         $sortCol, $sortDir, $filtriQs) ?></th>
          <th><?= thSort('denominazione',   'Fornitore',      $sortCol, $sortDir, $filtriQs) ?></th>
          <th><?= thSort('tipo_documento',  'Tipo',           $sortCol, $sortDir, $filtriQs) ?></th>
          <th class="text-end"><?= thSort('tot_imponibile',  'Imponibile (€)', $sortCol, $sortDir, $filtriQs) ?></th>
          <th class="text-end"><?= thSort('tot_iva',         'IVA (€)',        $sortCol, $sortDir, $filtriQs) ?></th>
          <th class="text-end"><?= thSort('importo_totale',  'Totale (€)',     $sortCol, $sortDir, $filtriQs) ?></th>
          <th><?= thSort('stato',           'Stato',          $sortCol, $sortDir, $filtriQs) ?></th>
          <th class="text-center">Azioni</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($fatture)): ?>
      <tr><td colspan="10" class="text-center text-muted py-4">Nessuna fattura trovata.</td></tr>
      <?php else: ?>
      <?php foreach ($fatture as $f): ?>
      <tr>
        <td><input type="checkbox" class="form-check-input rowCb" data-id="<?= $f['id'] ?>"></td>
        <td class="text-nowrap"><?= htmlspecialchars($f['data_documento']) ?></td>
        <td class="text-nowrap"><?= htmlspecialchars($f['numero_documento']) ?></td>
        <td class="text-truncate" style="max-width:180px">
          <?= htmlspecialchars(nomeFornitore($f)) ?>
          <div class="small text-muted"><?= htmlspecialchars($f['id_codice']) ?></div>
        </td>
        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($f['tipo_documento']) ?></span></td>
        <td class="text-end"><?= number_format((float)$f['tot_imponibile'], 2, ',', '.') ?></td>
        <td class="text-end"><?= number_format((float)$f['tot_iva'],        2, ',', '.') ?></td>
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
                onsubmit="return confirm('Eliminare definitivamente la fattura n.<?= htmlspecialchars(addslashes($f['numero_documento'])) ?>?')">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="elimina">
            <input type="hidden" name="id_fattura" value="<?= $f['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
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
      <a class="page-link" href="lista.php?<?= $filtriQs ?>&page=<?= $p ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php
$extraJs = '<script>
(function () {
    const selAll   = document.getElementById("selAll");
    const bulkIds  = document.getElementById("bulkIds");
    const bulkOp   = document.getElementById("bulkOp");
    const btnBulk  = document.getElementById("btnBulk");
    const countLbl = document.getElementById("bulkCount");

    function getChecked() { return [...document.querySelectorAll(".rowCb:checked")]; }

    function updateToolbar() {
        const n = getChecked().length;
        countLbl.textContent = n + " selezionat" + (n === 1 ? "a" : "e");
        btnBulk.disabled = n === 0;
    }

    if (selAll) {
        selAll.addEventListener("change", function () {
            document.querySelectorAll(".rowCb").forEach(cb => cb.checked = this.checked);
            updateToolbar();
        });
    }

    document.querySelectorAll(".rowCb").forEach(cb => {
        cb.addEventListener("change", () => {
            const all = [...document.querySelectorAll(".rowCb")];
            const chk = getChecked();
            if (selAll) {
                selAll.indeterminate = chk.length > 0 && chk.length < all.length;
                selAll.checked       = chk.length === all.length && all.length > 0;
            }
            updateToolbar();
        });
    });

    window.submitBulk = function () {
        const chk = getChecked();
        if (!chk.length)    { alert("Seleziona almeno una fattura."); return; }
        const op = bulkOp.value;
        if (!op)            { alert("Seleziona un\'azione."); return; }
        if (op === "elimina" && !confirm("Eliminare definitivamente le " + chk.length + " fatture selezionate?")) return;
        bulkIds.innerHTML = "";
        chk.forEach(cb => {
            const inp = document.createElement("input");
            inp.type = "hidden"; inp.name = "ids[]"; inp.value = cb.dataset.id;
            bulkIds.appendChild(inp);
        });
        document.getElementById("bulkForm").submit();
    };
})();
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
