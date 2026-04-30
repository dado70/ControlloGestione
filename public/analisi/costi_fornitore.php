<?php

declare(strict_types=1);
$pageTitle  = 'Costi per fornitore';
$activePage = 'costi_fornitore';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();

$anniDisp = $idAzienda ? Database::fetchAll(
    'SELECT DISTINCT YEAR(data_documento) as anno FROM fatture_elettroniche WHERE id_azienda=? ORDER BY anno DESC',
    [$idAzienda]
) : [];

$anno      = (int)($_GET['anno']    ?? (int)date('Y'));
$meseDa    = (int)($_GET['mese_da'] ?? 1);
$meseA     = (int)($_GET['mese_a']  ?? 12);
$detCedente = isset($_GET['cedente']) && $_GET['cedente'] !== '' ? (int)$_GET['cedente'] : null;

$meseDa = max(1, min(12, $meseDa));
$meseA  = max($meseDa, min(12, $meseA));

$mesiLabel = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

$ranking = [];
$totaleGenerale = 0.0;
$chartLabels = [];
$chartData   = [];

if ($idAzienda) {
    $ranking = Database::fetchAll(
        'SELECT cp.id, cp.denominazione, cp.nome, cp.cognome, cp.id_codice,
                COUNT(fe.id) as n_fatture,
                SUM(fe.importo_totale) as totale
         FROM fatture_elettroniche fe
         JOIN cedenti_prestatori cp ON cp.id = fe.id_cedente
         WHERE fe.id_azienda = ? AND YEAR(fe.data_documento) = ?
           AND MONTH(fe.data_documento) BETWEEN ? AND ?
         GROUP BY cp.id, cp.denominazione, cp.nome, cp.cognome, cp.id_codice
         ORDER BY totale DESC',
        [$idAzienda, $anno, $meseDa, $meseA]
    );

    foreach ($ranking as $r) {
        $totaleGenerale += (float)$r['totale'];
    }

    // Top 10 per grafico
    $top10 = array_slice($ranking, 0, 10);
    foreach ($top10 as $r) {
        $nome = !empty($r['denominazione']) ? $r['denominazione']
            : trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''));
        $chartLabels[] = mb_strimwidth($nome, 0, 25, '…');
        $chartData[]   = round((float)$r['totale'], 2);
    }
}

// Dettaglio fatture per cedente selezionato
$dettaglioFatture = [];
$nomeCedenteDettaglio = '';
if ($detCedente && $idAzienda) {
    $cedInfo = Database::fetchOne(
        'SELECT denominazione, nome, cognome, id_codice FROM cedenti_prestatori WHERE id=? AND id_azienda=?',
        [$detCedente, $idAzienda]
    );
    if ($cedInfo) {
        $nomeCedenteDettaglio = !empty($cedInfo['denominazione']) ? $cedInfo['denominazione']
            : trim(($cedInfo['cognome'] ?? '') . ' ' . ($cedInfo['nome'] ?? ''));

        $dettaglioFatture = Database::fetchAll(
            'SELECT fe.data_documento, fe.numero_documento, fe.tipo_documento,
                    fe.importo_totale, fe.stato
             FROM fatture_elettroniche fe
             WHERE fe.id_azienda=? AND fe.id_cedente=?
               AND YEAR(fe.data_documento)=? AND MONTH(fe.data_documento) BETWEEN ? AND ?
             ORDER BY fe.data_documento DESC',
            [$idAzienda, $detCedente, $anno, $meseDa, $meseA]
        );
    }
}

function nomeForn(array $r): string {
    if (!empty($r['denominazione'])) return $r['denominazione'];
    return trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')) ?: $r['id_codice'];
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
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-search me-1"></i>Aggiorna
        </button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3">
  <!-- Ranking fornitori -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold border-bottom-0">
        <i class="bi bi-trophy me-2 text-warning"></i>Ranking fornitori — <?= $anno ?>
        (<?= $mesiLabel[$meseDa-1] ?>–<?= $mesiLabel[$meseA-1] ?>)
      </div>
      <div class="table-responsive">
        <table class="table table-gh table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Fornitore</th>
              <th class="text-center">Fatture</th>
              <th class="text-end">Importo (€)</th>
              <th class="text-end">% sul tot.</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($ranking)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Nessun dato per il periodo selezionato.</td></tr>
          <?php else: ?>
          <?php foreach ($ranking as $i => $r): ?>
          <tr class="<?= $detCedente === (int)$r['id'] ? 'table-primary' : '' ?>">
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars(nomeForn($r)) ?></div>
              <div class="small text-muted"><?= htmlspecialchars($r['id_codice']) ?></div>
            </td>
            <td class="text-center"><?= (int)$r['n_fatture'] ?></td>
            <td class="text-end fw-semibold"><?= number_format((float)$r['totale'], 2, ',', '.') ?></td>
            <td class="text-end small">
              <?php $pct = $totaleGenerale > 0 ? ((float)$r['totale'] / $totaleGenerale * 100) : 0; ?>
              <?= number_format($pct, 1) ?>%
              <div class="progress mt-1" style="height:4px">
                <div class="progress-bar" style="width:<?= min(100, $pct) ?>%"></div>
              </div>
            </td>
            <td>
              <a href="?anno=<?= $anno ?>&mese_da=<?= $meseDa ?>&mese_a=<?= $meseA ?>&cedente=<?= $r['id'] ?>"
                 class="btn btn-sm btn-outline-secondary" title="Dettaglio">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
          <?php if ($totaleGenerale > 0): ?>
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="3">Totale</td>
              <td class="text-end"><?= number_format($totaleGenerale, 2, ',', '.') ?></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <!-- Grafico top 10 -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold border-bottom-0">
        <i class="bi bi-bar-chart me-2 text-primary"></i>Top 10 fornitori
      </div>
      <div class="card-body">
        <canvas id="chartFornitori" height="280"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Dettaglio cedente -->
<?php if ($detCedente && !empty($dettaglioFatture)): ?>
<div class="card shadow-sm border-0 mt-3">
  <div class="card-header bg-white fw-semibold">
    <i class="bi bi-file-earmark-text me-2"></i>
    Fatture di <?= htmlspecialchars($nomeCedenteDettaglio) ?>
    — <?= $anno ?> (<?= $mesiLabel[$meseDa-1] ?>–<?= $mesiLabel[$meseA-1] ?>)
  </div>
  <div class="table-responsive">
    <table class="table table-gh mb-0">
      <thead class="table-light">
        <tr>
          <th>Data</th>
          <th>Numero</th>
          <th>Tipo</th>
          <th class="text-end">Totale (€)</th>
          <th>Stato</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php
      $statiBadge = [
          'importata'=>'bg-warning text-dark','verificata'=>'bg-info text-dark',
          'contabilizzata'=>'bg-primary','pagata'=>'bg-success','annullata'=>'bg-danger',
      ];
      foreach ($dettaglioFatture as $df):
      ?>
      <tr>
        <td><?= htmlspecialchars($df['data_documento']) ?></td>
        <td><?= htmlspecialchars($df['numero_documento']) ?></td>
        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($df['tipo_documento']) ?></span></td>
        <td class="text-end fw-semibold"><?= number_format((float)$df['importo_totale'], 2, ',', '.') ?></td>
        <td><span class="badge <?= $statiBadge[$df['stato']] ?? 'bg-secondary' ?>"><?= ucfirst($df['stato']) ?></span></td>
        <td>
          <a href="../fatture/lista.php?cedente=<?= $detCedente ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const ctx = document.getElementById("chartFornitori");
    if (!ctx) return;
    new Chart(ctx, {
        type: "bar",
        data: {
            labels: ' . json_encode($chartLabels) . ',
            datasets: [{
                label: "Importo (€)",
                data: ' . json_encode($chartData) . ',
                backgroundColor: "rgba(37,99,235,.7)",
                borderRadius: 4,
            }]
        },
        options: {
            indexAxis: "y",
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { callback: v => "€ " + v.toLocaleString("it-IT") } }
            }
        }
    });
})();
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
