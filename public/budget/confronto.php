<?php

declare(strict_types=1);
$pageTitle  = 'Budget vs Consuntivo';
$activePage = 'budget_confronto';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();

$mesiLabel = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
$anno = (int)($_GET['anno'] ?? date('Y'));
$idCentro = isset($_GET['id_centro']) && $_GET['id_centro'] !== '' ? (int)$_GET['id_centro'] : null;

// Centri di costo per filtro
$centri = $idAzienda ? Database::fetchAll(
    'SELECT id, codice, descrizione FROM centri_costo WHERE id_azienda=? AND attivo=1 ORDER BY ordine, codice',
    [$idAzienda]
) : [];

// Budget per l'anno (aggregato per conto e mese)
$budgetMap = [];
if ($idAzienda) {
    $rows = Database::fetchAll(
        'SELECT b.id_conto, b.mese, b.importo, pc.codice, pc.descrizione, pc.tipo
         FROM budget b
         JOIN piano_conti pc ON pc.id = b.id_conto
         WHERE b.id_azienda = ? AND b.anno = ? AND b.importo != 0
         ORDER BY pc.codice, b.mese',
        [$idAzienda, $anno]
    );
    foreach ($rows as $r) {
        $budgetMap[$r['id_conto']]['info'] = ['codice' => $r['codice'], 'descrizione' => $r['descrizione'], 'tipo' => $r['tipo']];
        $budgetMap[$r['id_conto']]['budget'][$r['mese']] = (float)$r['importo'];
    }
}

// Consuntivo (da fatture_linee) per l'anno
$consuntivoMap = [];
if ($idAzienda) {
    $centroWhere = $idCentro ? 'AND fl.id_centro_costo = ?' : '';
    $params = [$idAzienda, $anno];
    if ($idCentro) $params[] = $idCentro;

    $rows = Database::fetchAll(
        "SELECT fl.id_conto, MONTH(fe.data_documento) as mese, SUM(fl.prezzo_totale) as totale,
                pc.codice, pc.descrizione, pc.tipo
         FROM fatture_linee fl
         JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
         JOIN piano_conti pc ON pc.id = fl.id_conto
         WHERE fe.id_azienda = ? AND YEAR(fe.data_documento) = ?
           AND fl.id_conto IS NOT NULL {$centroWhere}
         GROUP BY fl.id_conto, MONTH(fe.data_documento), pc.codice, pc.descrizione, pc.tipo
         ORDER BY pc.codice, mese",
        $params
    );
    foreach ($rows as $r) {
        $consuntivoMap[$r['id_conto']]['info'] = ['codice' => $r['codice'], 'descrizione' => $r['descrizione'], 'tipo' => $r['tipo']];
        $consuntivoMap[$r['id_conto']]['cons'][$r['mese']] = (float)$r['totale'];
    }
}

// Unione conti con budget o consuntivo
$tuttiConti = [];
foreach (array_unique(array_merge(array_keys($budgetMap), array_keys($consuntivoMap))) as $idConto) {
    $info = $budgetMap[$idConto]['info'] ?? $consuntivoMap[$idConto]['info'] ?? [];
    $tuttiConti[$idConto] = [
        'info'   => $info,
        'budget' => $budgetMap[$idConto]['budget'] ?? [],
        'cons'   => $consuntivoMap[$idConto]['cons'] ?? [],
    ];
}

// Ordina per codice
uasort($tuttiConti, fn($a, $b) => strcmp($a['info']['codice'] ?? '', $b['info']['codice'] ?? ''));

// Totali mensili globali
$totBudgetMese = array_fill(1, 12, 0.0);
$totConsMese   = array_fill(1, 12, 0.0);
foreach ($tuttiConti as $conto) {
    for ($m = 1; $m <= 12; $m++) {
        $totBudgetMese[$m] += $conto['budget'][$m] ?? 0;
        $totConsMese[$m]   += $conto['cons'][$m]   ?? 0;
    }
}

function fmtEur(float $v, bool $sign = false): string {
    if ($v == 0) return '—';
    $s = ($sign && $v > 0) ? '+' : '';
    return $s . '€ ' . number_format(abs($v), 0, ',', '.') . ($v < 0 ? '' : '');
}
function scostamento(float $budget, float $cons, string $tipo): string {
    if ($budget == 0 && $cons == 0) return '—';
    $diff = $cons - $budget;
    if ($diff == 0) return '<span class="text-muted">0</span>';
    // Per i costi: positivo = sforamento (rosso), negativo = risparmio (verde)
    // Per i ricavi: positivo = superamento (verde), negativo = mancato (rosso)
    $cls = $tipo === 'COSTO'
        ? ($diff > 0 ? 'text-danger' : 'text-success')
        : ($diff > 0 ? 'text-success' : 'text-danger');
    $sign = $diff > 0 ? '+' : '';
    return "<span class=\"{$cls} fw-semibold\">{$sign}€ " . number_format(abs($diff), 0, ',', '.') . "</span>";
}
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Seleziona un'azienda.</div>
<?php else: ?>

<!-- Toolbar -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <p class="text-muted mb-0 small">Confronto tra budget inserito e consuntivo da fatture classificate.</p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <form method="get" class="d-inline">
      <div class="d-flex gap-2">
        <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
          <?php for ($y = (int)date('Y') + 1; $y >= 2022; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $anno ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <select name="id_centro" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
          <option value="">Tutti i centri</option>
          <?php foreach ($centri as $cc): ?>
          <option value="<?= $cc['id'] ?>" <?= $cc['id'] == $idCentro ? 'selected' : '' ?>>
            <?= htmlspecialchars($cc['codice'] . ' ' . $cc['descrizione']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <a href="index.php?anno=<?= $anno ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil me-1"></i>Modifica budget
    </a>
  </div>
</div>

<?php if (empty($tuttiConti)): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  Nessun dato trovato per il <?= $anno ?>. Inserisci un budget o importa fatture.
</div>
<?php else: ?>

<!-- Vista mensile: selezione mese via tab -->
<ul class="nav nav-tabs mb-3" id="viewTabs">
  <li class="nav-item">
    <a class="nav-link <?= !isset($_GET['vista']) || $_GET['vista'] === 'annuale' ? 'active' : '' ?>"
       href="?anno=<?= $anno ?>&vista=annuale<?= $idCentro ? '&id_centro='.$idCentro : '' ?>">
       Riepilogo annuale
    </a>
  </li>
  <?php foreach ($mesiLabel as $i => $ml): ?>
  <li class="nav-item">
    <a class="nav-link <?= ($_GET['vista'] ?? '') === (string)($i+1) ? 'active' : '' ?>"
       href="?anno=<?= $anno ?>&vista=<?= $i+1 ?><?= $idCentro ? '&id_centro='.$idCentro : '' ?>">
       <?= $ml ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php
$vista = $_GET['vista'] ?? 'annuale';
$meseVista = ($vista !== 'annuale' && is_numeric($vista)) ? (int)$vista : null;
?>

<div class="card shadow-sm border-0">
  <div class="card-header fw-semibold bg-white border-bottom-0">
    <i class="bi bi-bar-chart me-2 text-primary"></i>
    <?= $meseVista ? $mesiLabel[$meseVista - 1] . ' ' . $anno : 'Anno ' . $anno ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr>
          <th style="min-width:220px">Conto</th>
          <?php if ($meseVista): ?>
          <th class="text-end">Budget</th>
          <th class="text-end">Consuntivo</th>
          <th class="text-end">Scostamento</th>
          <th class="text-end">% rapp.</th>
          <?php else: ?>
          <?php foreach ($mesiLabel as $ml): ?>
          <th class="text-end" style="min-width:70px; font-size:.78rem"><?= $ml ?></th>
          <?php endforeach; ?>
          <th class="text-end fw-bold">Tot. Budget</th>
          <th class="text-end fw-bold">Tot. Cons.</th>
          <th class="text-end">Scost.</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $tipoCorr = '';
        foreach ($tuttiConti as $idConto => $conto):
            $info = $conto['info'];
            // Intestazione tipo
            if ($info['tipo'] !== $tipoCorr):
                $tipoCorr = $info['tipo'] ?? '';
                $tipoLabel = $tipoCorr === 'COSTO' ? 'Costi' : 'Ricavi';
                $tipoCls   = $tipoCorr === 'COSTO' ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10';
        ?>
        <tr class="<?= $tipoCls ?>">
          <td colspan="20" class="fw-semibold py-1 small text-uppercase text-muted"><?= $tipoLabel ?></td>
        </tr>
        <?php endif; ?>
        <?php
            if ($meseVista !== null):
                $bVal = $conto['budget'][$meseVista] ?? 0;
                $cVal = $conto['cons'][$meseVista]   ?? 0;
                if ($bVal == 0 && $cVal == 0) continue;
                $perc = $bVal != 0 ? number_format($cVal / $bVal * 100, 0) . '%' : '—';
        ?>
        <tr>
          <td><span class="text-muted small me-1"><?= htmlspecialchars($info['codice']) ?></span><?= htmlspecialchars($info['descrizione']) ?></td>
          <td class="text-end"><?= fmtEur($bVal) ?></td>
          <td class="text-end"><?= fmtEur($cVal) ?></td>
          <td class="text-end"><?= scostamento($bVal, $cVal, $info['tipo']) ?></td>
          <td class="text-end"><?= $perc ?></td>
        </tr>
        <?php
            else: // annuale
                $totB = array_sum($conto['budget']);
                $totC = array_sum($conto['cons']);
                if ($totB == 0 && $totC == 0) continue;
        ?>
        <tr>
          <td class="text-nowrap">
            <span class="text-muted small me-1"><?= htmlspecialchars($info['codice']) ?></span>
            <?= htmlspecialchars($info['descrizione']) ?>
          </td>
          <?php for ($m = 1; $m <= 12; $m++):
            $bm = $conto['budget'][$m] ?? 0;
            $cm = $conto['cons'][$m]   ?? 0;
            if ($bm == 0 && $cm == 0): ?>
            <td class="text-end text-muted">—</td>
            <?php else:
                $cls = '';
                if ($bm > 0) {
                    $over = $info['tipo'] === 'COSTO' ? $cm > $bm : $cm < $bm;
                    $cls  = $over ? 'text-danger' : 'text-success';
                }
            ?>
            <td class="text-end <?= $cls ?>" title="Budget: <?= fmtEur($bm) ?> | Cons: <?= fmtEur($cm) ?>">
              <?= $cm != 0 ? '€ ' . number_format($cm, 0, ',', '.') : '' ?>
              <?php if ($bm != 0): ?><span class="d-block text-muted" style="font-size:.7rem"><?= number_format($bm, 0, ',', '.') ?></span><?php endif; ?>
            </td>
            <?php endif; ?>
          <?php endfor; ?>
          <td class="text-end fw-semibold"><?= $totB != 0 ? '€ '.number_format($totB, 0, ',', '.') : '—' ?></td>
          <td class="text-end fw-semibold"><?= $totC != 0 ? '€ '.number_format($totC, 0, ',', '.') : '—' ?></td>
          <td class="text-end"><?= scostamento($totB, $totC, $info['tipo']) ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
      <?php if ($meseVista === null): ?>
      <tfoot class="table-dark">
        <tr>
          <td class="fw-bold">Totale</td>
          <?php
          $grandTotB = 0; $grandTotC = 0;
          for ($m = 1; $m <= 12; $m++):
              $grandTotB += $totBudgetMese[$m];
              $grandTotC += $totConsMese[$m];
              $diff = $totConsMese[$m] - $totBudgetMese[$m];
              $cls  = $diff > 0 ? 'text-warning' : 'text-success';
          ?>
          <td class="text-end">
            <?= $totConsMese[$m] != 0 ? '€ ' . number_format($totConsMese[$m], 0, ',', '.') : '' ?>
            <?php if ($totBudgetMese[$m] != 0): ?>
            <span class="d-block" style="font-size:.7rem;opacity:.7"><?= number_format($totBudgetMese[$m], 0, ',', '.') ?></span>
            <?php endif; ?>
          </td>
          <?php endfor; ?>
          <td class="text-end fw-bold">€ <?= number_format($grandTotB, 0, ',', '.') ?></td>
          <td class="text-end fw-bold">€ <?= number_format($grandTotC, 0, ',', '.') ?></td>
          <td class="text-end fw-bold">
            <?php
            $d = $grandTotC - $grandTotB;
            $s = $d >= 0 ? '+' : '';
            echo "<span class=\"" . ($d > 0 ? 'text-warning' : 'text-success') . "\">$s€ " . number_format(abs($d), 0, ',', '.') . "</span>";
            ?>
          </td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="mt-2 small text-muted">
  <i class="bi bi-info-circle me-1"></i>
  Vista annuale: cella = consuntivo, grigio sotto = budget. Rosso = sforamento (costi) / mancato (ricavi). Verde = risparmio / superamento.
</div>

<?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
