<?php
declare(strict_types=1);
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/layout/header.php';

$idAzienda = Auth::getIdAzienda();

// Periodo selezionato
$anno     = max(2000, min(2100, (int)($_GET['anno'] ?? date('Y'))));
$mese     = max(1,    min(12,   (int)($_GET['mese'] ?? date('n'))));
$annoCorr = (int)date('Y');

$kpiFatture = ['tot' => 0, 'importate' => 0, 'importo' => 0.0];
$top5       = [];
$andamento  = [];

if ($idAzienda) {
    $kpiFatture = Database::fetchOne(
        'SELECT COUNT(*) as tot,
                SUM(CASE WHEN stato="importata" THEN 1 ELSE 0 END) as importate,
                SUM(importo_totale) as importo
         FROM fatture_elettroniche
         WHERE id_azienda=? AND YEAR(data_documento)=? AND MONTH(data_documento)=?',
        [$idAzienda, $anno, $mese]
    ) ?: $kpiFatture;

    $top5 = Database::fetchAll(
        'SELECT cp.id, cp.denominazione, cp.nome, cp.cognome, SUM(fe.importo_totale) as totale
         FROM fatture_elettroniche fe
         JOIN cedenti_prestatori cp ON cp.id=fe.id_cedente
         WHERE fe.id_azienda=? AND YEAR(fe.data_documento)=? AND MONTH(fe.data_documento)=?
         GROUP BY cp.id, cp.denominazione, cp.nome, cp.cognome
         ORDER BY totale DESC LIMIT 5',
        [$idAzienda, $anno, $mese]
    );

    // Andamento: 12 mesi fino al mese selezionato
    $startDate = date('Y-m-01', mktime(0, 0, 0, $mese - 11, 1, $anno));
    $endDate   = date('Y-m-t',  mktime(0, 0, 0, $mese,      1, $anno));
    $andamento = Database::fetchAll(
        'SELECT YEAR(data_documento) as anno, MONTH(data_documento) as mese,
                SUM(importo_totale) as totale, COUNT(*) as n
         FROM fatture_elettroniche
         WHERE id_azienda=? AND data_documento BETWEEN ? AND ?
         GROUP BY YEAR(data_documento), MONTH(data_documento)
         ORDER BY anno, mese',
        [$idAzienda, $startDate, $endDate]
    );
}

// Anni per i picker (con anno corrente sempre presente)
$anniDisp = $idAzienda ? array_column(Database::fetchAll(
    'SELECT DISTINCT YEAR(data_documento) as anno FROM fatture_elettroniche WHERE id_azienda=? ORDER BY anno DESC',
    [$idAzienda]
), 'anno') : [];
if (!in_array($annoCorr, $anniDisp))     array_unshift($anniDisp, $annoCorr);
if (!in_array($annoCorr + 1, $anniDisp)) array_unshift($anniDisp, $annoCorr + 1);
rsort($anniDisp);

$mesiLabel   = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
$chartLabels = [];
$chartData   = [];
foreach ($andamento as $row) {
    $chartLabels[] = $mesiLabel[(int)$row['mese'] - 1] . ' ' . $row['anno'];
    $chartData[]   = round((float)$row['totale'], 2);
}

function nomeFornTop(array $r): string {
    if (!empty($r['denominazione'])) return $r['denominazione'];
    return trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')) ?: '—';
}

$pickerAperto = isset($_GET['anno']) || isset($_GET['mese']);
?>

<!-- KPI Cards -->
<div class="row g-3 mb-2">

  <!-- Fatture mese -->
  <div class="col-sm-6 col-xl-3">
    <div class="card kpi-card p-3">
      <div class="d-flex align-items-start gap-3">
        <div class="kpi-icon bg-primary bg-opacity-10 text-primary">
          <i class="bi bi-file-earmark-text"></i>
        </div>
        <div class="flex-grow-1">
          <div class="kpi-value text-primary"><?= number_format((int)$kpiFatture['tot']) ?></div>
          <div class="kpi-label d-flex align-items-center gap-1">
            Fatture <?= $mesiLabel[$mese-1] ?> <?= $anno ?>
            <button type="button" id="btnPicker"
                    class="btn btn-link p-0 ms-1 border-0 text-primary" style="line-height:1;font-size:.9rem"
                    title="Cambia periodo">
              <i class="bi bi-calendar3-week"></i>
            </button>
          </div>
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

  <!-- Importo totale mese (dinamico con il periodo scelto) -->
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
          <div class="kpi-label">Importo <?= $mesiLabel[$mese-1] ?> <?= $anno ?> (€)</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Anno (cliccabile per cambio anno) -->
  <div class="col-sm-6 col-xl-3 position-relative">
    <div class="card kpi-card p-3" id="cardAnno" style="cursor:pointer" title="Clicca per cambiare anno">
      <div class="d-flex align-items-center gap-3">
        <div class="kpi-icon bg-info bg-opacity-10 text-info">
          <i class="bi bi-calendar-check"></i>
        </div>
        <div>
          <div class="kpi-value text-info"><?= $anno ?></div>
          <div class="kpi-label d-flex align-items-center gap-1">
            Anno <i class="bi bi-chevron-down small text-muted ms-1"></i>
          </div>
        </div>
      </div>
    </div>
    <div id="annoPicker" class="d-none position-absolute end-0 z-3 shadow rounded bg-white p-2"
         style="top:calc(100% + 4px); min-width:110px; max-height:220px; overflow-y:auto">
      <?php foreach ($anniDisp as $y): ?>
      <a href="?anno=<?= $y ?>&mese=<?= $mese ?>"
         class="d-block text-center btn btn-sm w-100 mb-1 <?= $y === $anno ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $y ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Pannello selezione mese/anno -->
<div id="datePicker" class="mb-3 <?= $pickerAperto ? '' : 'd-none' ?>">
  <div class="card shadow-sm border-0 p-2" style="border-top:3px solid var(--bs-primary) !important">
    <form method="get" class="d-flex align-items-center gap-2 flex-wrap">
      <label class="small fw-semibold mb-0"><i class="bi bi-calendar3 me-1"></i>Periodo:</label>
      <select name="mese" class="form-select form-select-sm" style="width:100px" onchange="this.form.submit()">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $mese ? 'selected' : '' ?>><?= $mesiLabel[$m-1] ?></option>
        <?php endfor; ?>
      </select>
      <select name="anno" class="form-select form-select-sm" style="width:88px" onchange="this.form.submit()">
        <?php foreach ($anniDisp as $y): ?>
        <option value="<?= $y ?>" <?= $y === $anno ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-clockwise"></i></button>
      <a href="dashboard.php" class="btn btn-sm btn-outline-secondary" title="Mese corrente">
        <i class="bi bi-house"></i>
      </a>
    </form>
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
  <div class="col-lg-8">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold border-bottom-0 pb-0">
        <i class="bi bi-graph-up me-2 text-primary"></i>
        Andamento fatture — 12 mesi fino a <?= $mesiLabel[$mese-1] ?> <?= $anno ?>
      </div>
      <div class="card-body">
        <canvas id="chartAndamento" height="120"></canvas>
      </div>
    </div>
  </div>

  <!-- Top 5 fornitori (cliccabili) -->
  <div class="col-lg-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white fw-semibold border-bottom-0 pb-0">
        <i class="bi bi-award me-2 text-warning"></i>
        Top 5 fornitori — <?= $mesiLabel[$mese-1] ?> <?= $anno ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($top5)): ?>
        <div class="p-3 text-muted small">Nessun dato per il periodo selezionato.</div>
        <?php else: ?>
        <table class="table table-gh mb-0">
          <tbody>
          <?php foreach ($top5 as $i => $row): ?>
          <tr>
            <td>
              <span class="badge bg-light text-dark me-1"><?= $i+1 ?></span>
              <a href="analisi/costi_fornitore.php?anno=<?= $anno ?>&cedente=<?= $row['id'] ?>"
                 class="text-decoration-none text-dark" title="Analisi fornitore">
                <?= htmlspecialchars(nomeFornTop($row)) ?>
              </a>
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
(function () {
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
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => "€ " + v.toLocaleString("it-IT") } } }
            }
        });
    }

    // Toggle pannello data picker
    const btn = document.getElementById("btnPicker");
    const dp  = document.getElementById("datePicker");
    if (btn && dp) btn.addEventListener("click", () => dp.classList.toggle("d-none"));

    // Dropdown anno
    const cardAnno  = document.getElementById("cardAnno");
    const annoPicker = document.getElementById("annoPicker");
    if (cardAnno && annoPicker) {
        cardAnno.addEventListener("click", e => { e.stopPropagation(); annoPicker.classList.toggle("d-none"); });
        document.addEventListener("click", () => annoPicker.classList.add("d-none"));
    }
})();
</script>';

require_once __DIR__ . '/layout/footer.php';
