<?php

declare(strict_types=1);
$pageTitle  = 'Costi per periodo';
$activePage = 'costi_periodo';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();

// Anni disponibili
$anniDisp = $idAzienda ? Database::fetchAll(
    'SELECT DISTINCT YEAR(data_documento) as anno
     FROM fatture_elettroniche WHERE id_azienda=? ORDER BY anno DESC',
    [$idAzienda]
) : [];

$annoDefault = (int)date('Y');

// Parametri filtro
$anno      = (int)($_GET['anno']      ?? $annoDefault);
$meseDa    = (int)($_GET['mese_da']   ?? 1);
$meseA     = (int)($_GET['mese_a']    ?? 12);
$idCentro  = isset($_GET['id_centro']) && $_GET['id_centro'] !== '' ? (int)$_GET['id_centro'] : null;
$export    = isset($_GET['export']) && $_GET['export'] === 'csv';

// Vincola mesi al range valido
$meseDa = max(1, min(12, $meseDa));
$meseA  = max($meseDa, min(12, $meseA));

$mesiLabel = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

$centri = $idAzienda ? Database::fetchAll(
    'SELECT id, codice, descrizione FROM centri_costo WHERE id_azienda=? AND attivo=1 ORDER BY ordine, codice',
    [$idAzienda]
) : [];

$pivot = [];
$contiInfo = [];

if ($idAzienda) {
    $extraWhere = '';
    $params = [$idAzienda, $anno, $meseDa, $meseA];
    if ($idCentro !== null) {
        $extraWhere = ' AND fl.id_centro_costo = ?';
        $params[] = $idCentro;
    }

    $rows = Database::fetchAll(
        "SELECT pc.id, pc.codice, pc.descrizione,
                MONTH(fe.data_documento) as mese,
                SUM(fl.prezzo_totale) as importo
         FROM fatture_linee fl
         JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
         JOIN piano_conti pc ON pc.id = fl.id_conto
         WHERE fl.id_azienda = ?
           AND YEAR(fe.data_documento) = ?
           AND MONTH(fe.data_documento) BETWEEN ? AND ?
           AND fl.id_conto IS NOT NULL
           $extraWhere
         GROUP BY pc.id, pc.codice, pc.descrizione, MONTH(fe.data_documento)
         ORDER BY pc.codice, mese",
        $params
    );

    foreach ($rows as $r) {
        $key = $r['id'];
        $contiInfo[$key] = ['codice' => $r['codice'], 'descrizione' => $r['descrizione']];
        $pivot[$key][(int)$r['mese']] = (float)$r['importo'];
    }
}

// Colonne mesi selezionati
$mesiColonne = range($meseDa, $meseA);

// Totali colonna
$totaliColonna = [];
foreach ($mesiColonne as $m) {
    $totaliColonna[$m] = array_sum(array_column(array_map(fn($r) => [$m => $r[$m] ?? 0], array_values($pivot)), $m));
}

// Export CSV
if ($export && $idAzienda) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="costi_periodo_' . $anno . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $intestazione = ['Codice', 'Descrizione'];
    foreach ($mesiColonne as $m) {
        $intestazione[] = $mesiLabel[$m - 1] . ' ' . $anno;
    }
    $intestazione[] = 'Totale';
    fputcsv($out, $intestazione, ';');

    foreach ($contiInfo as $id => $info) {
        $riga = [$info['codice'], $info['descrizione']];
        $tot  = 0.0;
        foreach ($mesiColonne as $m) {
            $v = $pivot[$id][$m] ?? 0.0;
            $riga[] = number_format($v, 2, ',', '.');
            $tot   += $v;
        }
        $riga[] = number_format($tot, 2, ',', '.');
        fputcsv($out, $riga, ';');
    }

    $rigaTot = ['', 'TOTALE'];
    $grandTot = 0.0;
    foreach ($mesiColonne as $m) {
        $v = 0.0;
        foreach ($pivot as $id => $mesi) {
            $v += $mesi[$m] ?? 0.0;
        }
        $rigaTot[] = number_format($v, 2, ',', '.');
        $grandTot += $v;
    }
    $rigaTot[] = number_format($grandTot, 2, ',', '.');
    fputcsv($out, $rigaTot, ';');

    fclose($out);
    exit;
}
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Nessuna azienda selezionata.</div>
<?php else: ?>

<!-- Filtri -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-2">
        <label class="form-label small mb-1">Anno</label>
        <select name="anno" class="form-select form-select-sm">
          <?php foreach ($anniDisp as $ar): ?>
          <option value="<?= $ar['anno'] ?>" <?= (int)$ar['anno'] === $anno ? 'selected' : '' ?>>
            <?= $ar['anno'] ?>
          </option>
          <?php endforeach; ?>
          <?php if (empty($anniDisp)): ?>
          <option value="<?= $anno ?>" selected><?= $anno ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Mese da</label>
        <select name="mese_da" class="form-select form-select-sm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m === $meseDa ? 'selected' : '' ?>><?= $mesiLabel[$m-1] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Mese a</label>
        <select name="mese_a" class="form-select form-select-sm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m === $meseA ? 'selected' : '' ?>><?= $mesiLabel[$m-1] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Centro di costo</label>
        <select name="id_centro" class="form-select form-select-sm">
          <option value="">Tutti</option>
          <?php foreach ($centri as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $idCentro === (int)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['codice'] . ' ' . $c['descrizione']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-search me-1"></i>Aggiorna
        </button>
        <a href="?anno=<?= $anno ?>&mese_da=<?= $meseDa ?>&mese_a=<?= $meseA ?><?= $idCentro ? '&id_centro='.$idCentro : '' ?>&export=csv"
           class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-download me-1"></i>CSV
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Tabella pivot -->
<?php if (empty($contiInfo)): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  Nessuna riga classificata trovata per il periodo selezionato.
  Verifica che le fatture abbiano il conto contabile assegnato.
</div>
<?php else: ?>
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Codice</th>
          <th>Descrizione conto</th>
          <?php foreach ($mesiColonne as $m): ?>
          <th class="text-end"><?= $mesiLabel[$m-1] ?></th>
          <?php endforeach; ?>
          <th class="text-end fw-bold">Totale</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $grandTotale = 0.0;
      foreach ($contiInfo as $id => $info):
          $totRiga = 0.0;
          foreach ($mesiColonne as $m) {
              $totRiga += $pivot[$id][$m] ?? 0.0;
          }
          $grandTotale += $totRiga;
      ?>
      <tr>
        <td class="text-nowrap"><code><?= htmlspecialchars($info['codice']) ?></code></td>
        <td><?= htmlspecialchars($info['descrizione']) ?></td>
        <?php foreach ($mesiColonne as $m): ?>
        <td class="text-end text-nowrap">
          <?php $v = $pivot[$id][$m] ?? null; ?>
          <?= $v !== null ? number_format($v, 2, ',', '.') : '<span class="text-muted">—</span>' ?>
        </td>
        <?php endforeach; ?>
        <td class="text-end fw-semibold text-nowrap"><?= number_format($totRiga, 2, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">Totale</td>
          <?php foreach ($mesiColonne as $m): ?>
          <?php
          $vCol = 0.0;
          foreach ($pivot as $id => $mesi) {
              $vCol += $mesi[$m] ?? 0.0;
          }
          ?>
          <td class="text-end text-nowrap"><?= number_format($vCol, 2, ',', '.') ?></td>
          <?php endforeach; ?>
          <td class="text-end text-nowrap"><?= number_format($grandTotale, 2, ',', '.') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
