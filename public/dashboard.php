<?php
declare(strict_types=1);
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/layout/header.php';

$idAzienda = Auth::getIdAzienda();

// KPI del mese corrente
$anno = (int)date('Y');
$mese = (int)date('n');

$kpiFatture = ['tot' => 0, 'importate' => 0, 'importo' => 0.0];
$top5       = [];
$andamento  = [];

if ($idAzienda) {
    // Totale fatture importate nel mese
    $kpiFatture = Database::fetchOne(
        'SELECT COUNT(*) as tot,
                SUM(CASE WHEN stato="importata" THEN 1 ELSE 0 END) as importate,
                SUM(importo_totale) as importo
         FROM fatture_elettroniche
         WHERE id_azienda=? AND YEAR(data_documento)=? AND MONTH(data_documento)=?',
        [$idAzienda, $anno, $mese]
    ) ?: $kpiFatture;

    // Top 5 fornitori per importo nel mese
    $top5 = Database::fetchAll(
        'SELECT cp.denominazione, SUM(fe.importo_totale) as totale
         FROM fatture_elettroniche fe
         JOIN cedenti_prestatori cp ON cp.id=fe.id_cedente
         WHERE fe.id_azienda=? AND YEAR(fe.data_documento)=? AND MONTH(fe.data_documento)=?
         GROUP BY cp.id ORDER BY totale DESC LIMIT 5',
        [$idAzienda, $anno, $mese]
    );

    // Andamento 12 mesi (per grafico)
    $andamento = Database::fetchAll(
        'SELECT YEAR(data_documento) as anno, MONTH(data_documento) as mese,
                SUM(importo_totale) as totale, COUNT(*) as n
         FROM fatture_elettroniche
         WHERE id_azienda=? AND data_documento >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY YEAR(data_documento), MONTH(data_documento)
         ORDER BY anno, mese',
        [$idAzienda]
    );
}

// Prepara dati grafico
$mesiLabel = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
$chartLabels = [];
$chartData   = [];
foreach ($andamento as $row) {
    $chartLabels[] = $mesiLabel[(int)$row['mese'] - 1] . ' ' . $row['anno'];
    $chartData[]   = round((float)$row['totale'], 2);
}
?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <!-- Fatture mese -->
  <div class="col-sm-6 col-xl-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-primary bg-opacity-10 text-primary">
          <i class="bi bi-file-earmark-text"></i>
        </div>
        <div>
          <div class="kpi-value text-primary"><?= number_format((int)$kpiFatture['tot']) ?></div>
          <div class="kpi-label">Fatture <?= $mesiLabel[$mese-1] ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Da classificare -->
  <div class="col-sm-6 col-xl-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-warning bg-opacity-10 text-warning">
          <i class="bi bi-clock-history"></i>
        </div>
        <div>
          <div class="kpi-value text-warning"><?= number_format((int)$kpiFatture['importate']) ?></div>
          <div class="kpi-label">Da classificare</div>
        </div>
      </div>
      <?php if ($kpiFatture['importate'] > 0): ?>
      <a href="fatture/lista.php?stato=importata" class="stretched-link"></a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Importo mese -->
  <div class="col-sm-6 col-xl-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-success bg-opacity-10 text-success">
          <i class="bi bi-currency-euro"></i>
        </div>
        <div>
          <div class="kpi-value text-success">
            <?= number_format((float)$kpiFatture['importo'], 0, ',', '.') ?>
          </div>
          <div class="kpi-label">Importo totale mese (€)</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Anno -->
  <div class="col-sm-6 col-xl-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-info bg-opacity-10 text-info">
          <i class="bi bi-calendar-check"></i>
        </div>
        <div>
          <div class="kpi-value text-info"><?= $anno ?></div>
          <div class="kpi-label">Anno in corso</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2"></i>
  Nessuna azienda selezionata. Seleziona un'azienda dall'header per visualizzare i dati.
</div>
<?php else: ?>

<!-- Grafici + Top 5 -->
<div class="row g-3">
  <!-- Grafico andamento mensile -->
  <div class="col-lg-8">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold border-bottom-0 pb-0">
        <i class="bi bi-graph-up me-2 text-primary"></i>Andamento fatture — ultimi 12 mesi
      </div>
      <div class="card-body">
        <canvas id="chartAndamento" height="120"></canvas>
      </div>
    </div>
  </div>

  <!-- Top 5 fornitori -->
  <div class="col-lg-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white fw-semibold border-bottom-0 pb-0">
        <i class="bi bi-award me-2 text-warning"></i>Top 5 fornitori — <?= $mesiLabel[$mese-1] ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($top5)): ?>
        <div class="p-3 text-muted small">Nessun dato per il mese corrente.</div>
        <?php else: ?>
        <table class="table table-gh mb-0">
          <tbody>
          <?php foreach ($top5 as $i => $row): ?>
          <tr>
            <td>
              <span class="badge bg-light text-dark me-1"><?= $i+1 ?></span>
              <?= htmlspecialchars($row['denominazione'] ?? '—') ?>
            </td>
            <td class="text-end fw-semibold text-nowrap">
              € <?= number_format((float)$row['totale'], 2, ',', '.') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Link rapidi -->
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-body d-flex gap-2 flex-wrap">
        <a href="fatture/upload.php" class="btn btn-primary">
          <i class="bi bi-cloud-upload me-1"></i>Importa fatture
        </a>
        <a href="fatture/lista.php" class="btn btn-outline-secondary">
          <i class="bi bi-list-ul me-1"></i>Archivio fatture
        </a>
        <a href="analisi/costi_periodo.php" class="btn btn-outline-secondary">
          <i class="bi bi-table me-1"></i>Analisi per periodo
        </a>
        <a href="analisi/bilancio_cee.php" class="btn btn-outline-secondary">
          <i class="bi bi-bar-chart-line me-1"></i>Bilancio CEE
        </a>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById("chartAndamento");
if (ctx) {
  new Chart(ctx, {
    type: "bar",
    data: {
      labels: ' . json_encode($chartLabels) . ',
      datasets: [{
        label: "Importo fatture (€)",
        data: ' . json_encode($chartData) . ',
        backgroundColor: "rgba(37,99,235,.15)",
        borderColor: "rgba(37,99,235,.8)",
        borderWidth: 2,
        borderRadius: 4,
        tension: .4,
        type: "bar"
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => "€ " + v.toLocaleString("it-IT") } }
      }
    }
  });
}
</script>';

require_once __DIR__ . '/layout/footer.php';
