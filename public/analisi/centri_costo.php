<?php

declare(strict_types=1);
$pageTitle  = 'Analisi centri di costo';
$activePage = 'centri_costo';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();

$anniDisp = $idAzienda ? Database::fetchAll(
    'SELECT DISTINCT YEAR(data_documento) as anno FROM fatture_elettroniche WHERE id_azienda=? ORDER BY anno DESC',
    [$idAzienda]
) : [];

$anno  = (int)($_GET['anno'] ?? (int)date('Y'));
$mese  = isset($_GET['mese']) && $_GET['mese'] !== '' ? (int)$_GET['mese'] : (int)date('n');

$mesiLabel = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

$datiCC  = [];
$totMese = 0.0;
$totYtd  = 0.0;

if ($idAzienda) {
    // Costi del mese per ogni centro (solo righe confermate)
    $rowsMese = Database::fetchAll(
        'SELECT cc.id, cc.codice, cc.descrizione, cc.tipo,
                SUM(fl.prezzo_totale) as costi_mese,
                COUNT(DISTINCT fe.id) as n_fatture
         FROM centri_costo cc
         LEFT JOIN fatture_linee fl ON fl.id_centro_costo = cc.id
             AND fl.classificazione_confermata = 1
             AND fl.id_azienda = cc.id_azienda
         LEFT JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
             AND YEAR(fe.data_documento) = ? AND MONTH(fe.data_documento) = ?
         WHERE cc.id_azienda = ? AND cc.attivo = 1
         GROUP BY cc.id, cc.codice, cc.descrizione, cc.tipo
         ORDER BY cc.ordine, cc.codice',
        [$anno, $mese, $idAzienda]
    );

    // YTD per ogni centro (da gennaio al mese selezionato)
    $rowsYtd = Database::fetchAll(
        'SELECT cc.id, SUM(fl.prezzo_totale) as costi_ytd
         FROM centri_costo cc
         LEFT JOIN fatture_linee fl ON fl.id_centro_costo = cc.id
             AND fl.classificazione_confermata = 1
             AND fl.id_azienda = cc.id_azienda
         LEFT JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
             AND YEAR(fe.data_documento) = ? AND MONTH(fe.data_documento) <= ?
         WHERE cc.id_azienda = ? AND cc.attivo = 1
         GROUP BY cc.id',
        [$anno, $mese, $idAzienda]
    );

    $ytdMap = [];
    foreach ($rowsYtd as $r) {
        $ytdMap[(int)$r['id']] = (float)$r['costi_ytd'];
    }

    foreach ($rowsMese as $r) {
        $id = (int)$r['id'];
        $datiCC[] = [
            'id'          => $id,
            'codice'      => $r['codice'],
            'descrizione' => $r['descrizione'],
            'tipo'        => $r['tipo'],
            'costi_mese'  => (float)$r['costi_mese'],
            'costi_ytd'   => $ytdMap[$id] ?? 0.0,
            'n_fatture'   => (int)$r['n_fatture'],
        ];
        $totMese += (float)$r['costi_mese'];
        $totYtd  += $ytdMap[$id] ?? 0.0;
    }
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
          <option value="<?= $ar['anno'] ?>" <?= (int)$ar['anno'] === $anno ? 'selected' : '' ?>><?= $ar['anno'] ?></option>
          <?php endforeach; ?>
          <?php if (empty($anniDisp)): ?>
          <option value="<?= $anno ?>" selected><?= $anno ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">Mese</label>
        <select name="mese" class="form-select form-select-sm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m === $mese ? 'selected' : '' ?>><?= $mesiLabel[$m-1] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-search me-1"></i>Aggiorna
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Info periodo -->
<div class="mb-2 small text-muted">
  Periodo: <strong><?= $mesiLabel[$mese-1] ?> <?= $anno ?></strong> — solo righe con classificazione confermata.
</div>

<!-- Tabella centri di costo -->
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-gh table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Codice</th>
          <th>Centro di costo</th>
          <th>Tipo</th>
          <th class="text-end">Costi mese (€)</th>
          <th class="text-end">Costi YTD (€)</th>
          <th class="text-center">N. fatture</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($datiCC)): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">Nessun centro di costo configurato.</td></tr>
      <?php else: ?>
      <?php foreach ($datiCC as $cc): ?>
      <tr>
        <td><code><?= htmlspecialchars($cc['codice']) ?></code></td>
        <td class="fw-semibold"><?= htmlspecialchars($cc['descrizione']) ?></td>
        <td>
          <?php
          $tipoBadge = match ($cc['tipo']) {
              'RICAVO' => 'bg-success',
              'MISTO'  => 'bg-info text-dark',
              default  => 'bg-secondary',
          };
          ?>
          <span class="badge <?= $tipoBadge ?>"><?= htmlspecialchars($cc['tipo']) ?></span>
        </td>
        <td class="text-end fw-semibold">
          <?= $cc['costi_mese'] > 0 ? number_format($cc['costi_mese'], 2, ',', '.') : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end">
          <?= $cc['costi_ytd'] > 0 ? number_format($cc['costi_ytd'], 2, ',', '.') : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-center">
          <?= $cc['n_fatture'] > 0 ? $cc['n_fatture'] : '<span class="text-muted">—</span>' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
      <?php if ($totMese > 0 || $totYtd > 0): ?>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="3">Totale</td>
          <td class="text-end"><?= number_format($totMese, 2, ',', '.') ?></td>
          <td class="text-end"><?= number_format($totYtd, 2, ',', '.') ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php if ($totMese > 0): ?>
<div class="card shadow-sm border-0 mt-3">
  <div class="card-header bg-white fw-semibold border-bottom-0">
    <i class="bi bi-pie-chart me-2 text-primary"></i>Distribuzione mese — <?= $mesiLabel[$mese-1] ?> <?= $anno ?>
  </div>
  <div class="card-body text-center">
    <canvas id="chartCC" style="max-height:280px; max-width:400px; margin:auto"></canvas>
  </div>
</div>

<?php
$chartLabelsCC = [];
$chartDataCC   = [];
foreach ($datiCC as $cc) {
    if ($cc['costi_mese'] > 0) {
        $chartLabelsCC[] = $cc['codice'] . ' ' . $cc['descrizione'];
        $chartDataCC[]   = round($cc['costi_mese'], 2);
    }
}
?>
<?php endif; ?>

<?php endif; ?>

<?php if (!empty($chartDataCC)): ?>
<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const ctx = document.getElementById("chartCC");
    if (!ctx) return;
    new Chart(ctx, {
        type: "doughnut",
        data: {
            labels: ' . json_encode($chartLabelsCC) . ',
            datasets: [{
                data: ' . json_encode($chartDataCC) . ',
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: "right", labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ctx.label + ": € " + ctx.parsed.toLocaleString("it-IT") } }
            }
        }
    });
})();
</script>';
?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
