<?php

declare(strict_types=1);
$pageTitle  = 'Budget';
$activePage = 'budget';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireRole('superadmin', 'admin');

$idAzienda = Auth::getIdAzienda();

$mesiLabel = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

// ── Salvataggio budget (AJAX POST) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salva') {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        echo json_encode(['ok' => false, 'msg' => 'CSRF non valido.']);
        exit;
    }
    if (!$idAzienda) {
        echo json_encode(['ok' => false, 'msg' => 'Nessuna azienda.']);
        exit;
    }

    $anno    = (int)($_POST['anno'] ?? 0);
    $idConto = (int)($_POST['id_conto'] ?? 0);
    $mese    = (int)($_POST['mese'] ?? 0);
    $importo = (float)str_replace(',', '.', $_POST['importo'] ?? '0');

    if ($anno < 2000 || $anno > 2100 || $idConto <= 0 || $mese < 1 || $mese > 12) {
        echo json_encode(['ok' => false, 'msg' => 'Parametri non validi.']);
        exit;
    }

    // Verifica conto appartiene all'azienda
    $conto = Database::fetchOne('SELECT id FROM piano_conti WHERE id=? AND id_azienda=?', [$idConto, $idAzienda]);
    if (!$conto) {
        echo json_encode(['ok' => false, 'msg' => 'Conto non trovato.']);
        exit;
    }

    if ($importo == 0) {
        Database::query(
            'DELETE FROM budget WHERE id_azienda=? AND anno=? AND mese=? AND id_conto=?',
            [$idAzienda, $anno, $mese, $idConto]
        );
    } else {
        Database::query(
            'INSERT INTO budget (id_azienda, anno, mese, id_conto, importo)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE importo=VALUES(importo), updated_at=NOW()',
            [$idAzienda, $anno, $mese, $idConto, $importo]
        );
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Parametri pagina ─────────────────────────────────────────────────────────
$anno = (int)($_GET['anno'] ?? date('Y'));

// Conti COSTO/RICAVO livello 3 (sottoconti) usati recentemente o con budget
$conti = $idAzienda ? Database::fetchAll(
    'SELECT pc.id, pc.codice, pc.descrizione, pc.tipo
     FROM piano_conti pc
     WHERE pc.id_azienda = ? AND pc.attivo = 1 AND pc.livello = 3
       AND pc.tipo IN (\'COSTO\',\'RICAVO\')
       AND (
         EXISTS (SELECT 1 FROM fatture_linee fl
                 JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
                 WHERE fl.id_conto = pc.id AND fe.id_azienda = ? AND YEAR(fe.data_documento) = ?)
         OR
         EXISTS (SELECT 1 FROM budget b WHERE b.id_conto = pc.id AND b.id_azienda = ? AND b.anno = ?)
       )
     ORDER BY pc.codice',
    [$idAzienda, $idAzienda, $anno, $idAzienda, $anno]
) : [];

// Budget esistente per l'anno
$budgetEsistente = [];
if ($idAzienda) {
    $rows = Database::fetchAll(
        'SELECT id_conto, mese, importo FROM budget WHERE id_azienda=? AND anno=?',
        [$idAzienda, $anno]
    );
    foreach ($rows as $r) {
        $budgetEsistente[(int)$r['id_conto']][(int)$r['mese']] = (float)$r['importo'];
    }
}

// Consuntivo per confronto (da fatture_linee)
$consuntivo = [];
if ($idAzienda) {
    $rows = Database::fetchAll(
        'SELECT fl.id_conto, MONTH(fe.data_documento) as mese, SUM(fl.prezzo_totale) as totale
         FROM fatture_linee fl
         JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
         WHERE fe.id_azienda = ? AND YEAR(fe.data_documento) = ? AND fl.id_conto IS NOT NULL
         GROUP BY fl.id_conto, MONTH(fe.data_documento)',
        [$idAzienda, $anno]
    );
    foreach ($rows as $r) {
        $consuntivo[(int)$r['id_conto']][(int)$r['mese']] = (float)$r['totale'];
    }
}

function fmtNum(float $v): string {
    return $v != 0 ? number_format($v, 0, ',', '.') : '';
}
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Seleziona un'azienda.</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <p class="text-muted mb-0 small">Inserisci gli importi mensili di budget per ciascun conto. Clicca una cella per modificarla.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <!-- Selettore anno -->
    <form method="get" class="d-inline">
      <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
        <?php for ($y = (int)date('Y') + 1; $y >= 2022; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $anno ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <a href="confronto.php?anno=<?= $anno ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-bar-chart me-1"></i>Budget vs Consuntivo
    </a>
  </div>
</div>

<?php if (empty($conti)): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  Nessun conto con movimenti nel <?= $anno ?>. Importa prima delle fatture o seleziona un anno con dati.
</div>
<?php else: ?>

<div class="card shadow-sm border-0">
  <div class="card-header fw-semibold bg-white border-bottom-0">
    <i class="bi bi-calculator me-2 text-primary"></i>Budget <?= $anno ?>
    <small class="text-muted ms-2">— valori in €, IVA esclusa</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" id="tblBudget" style="font-size:.85rem">
      <thead class="table-light sticky-top">
        <tr>
          <th style="min-width:200px">Conto</th>
          <?php foreach ($mesiLabel as $ml): ?>
          <th class="text-end" style="min-width:85px"><?= $ml ?></th>
          <?php endforeach; ?>
          <th class="text-end fw-bold" style="min-width:100px">Totale</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $tipoCorrente = '';
        foreach ($conti as $c):
            // Intestazione per tipo
            if ($c['tipo'] !== $tipoCorrente):
                $tipoCorrente = $c['tipo'];
                $tipoLabel    = $c['tipo'] === 'COSTO' ? 'Costi' : 'Ricavi';
                $tipoCls      = $c['tipo'] === 'COSTO' ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10';
        ?>
        <tr class="<?= $tipoCls ?>">
          <td colspan="14" class="fw-semibold py-1 small text-uppercase text-muted"><?= $tipoLabel ?></td>
        </tr>
        <?php endif; ?>
        <?php
            $totaleBudget = 0;
            $totaleCons   = 0;
        ?>
        <tr data-id-conto="<?= $c['id'] ?>">
          <td class="text-nowrap">
            <span class="text-muted small me-1"><?= htmlspecialchars($c['codice']) ?></span>
            <?= htmlspecialchars($c['descrizione']) ?>
          </td>
          <?php for ($m = 1; $m <= 12; $m++):
            $bVal = $budgetEsistente[$c['id']][$m] ?? 0;
            $cVal = $consuntivo[$c['id']][$m] ?? 0;
            $totaleBudget += $bVal;
            $totaleCons   += $cVal;
            $diffCls = '';
            if ($bVal > 0 && $cVal > 0) {
                $diffCls = ($c['tipo'] === 'COSTO')
                    ? ($cVal > $bVal ? 'text-danger' : 'text-success')
                    : ($cVal < $bVal ? 'text-danger' : 'text-success');
            }
          ?>
          <td class="text-end p-0">
            <div class="cell-budget" data-mese="<?= $m ?>" data-valore="<?= $bVal ?>">
              <input type="number" step="0.01" min="-999999" max="9999999"
                     class="form-control form-control-sm text-end budget-input border-0 bg-transparent"
                     style="width:85px;font-size:.82rem"
                     value="<?= $bVal != 0 ? number_format($bVal, 2, '.', '') : '' ?>"
                     placeholder="—"
                     data-id-conto="<?= $c['id'] ?>" data-mese="<?= $m ?>">
              <?php if ($cVal != 0): ?>
              <div class="small <?= $diffCls ?>" style="font-size:.7rem;line-height:1;padding:0 4px 2px">
                cons. <?= fmtNum($cVal) ?>
              </div>
              <?php endif; ?>
            </div>
          </td>
          <?php endfor; ?>
          <td class="text-end fw-semibold pe-2">
            <?= $totaleBudget != 0 ? '€ ' . number_format($totaleBudget, 0, ',', '.') : '—' ?>
            <?php if ($totaleCons != 0): ?>
            <div class="small text-muted" style="font-size:.7rem">
              cons. <?= fmtNum($totaleCons) ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="mt-2 small text-muted">
  <i class="bi bi-info-circle me-1"></i>
  Le righe "cons." mostrano il consuntivo da fatture passive classificate. Verde = sotto budget (costi) / sopra budget (ricavi). Rosso = sopra budget (costi) / sotto budget (ricavi).
</div>

<?php endif; ?>
<?php endif; ?>

<?php
$csrfToken = Auth::csrfToken();
$extraJs = '
<script>
(function () {
    const csrf = ' . json_encode($csrfToken) . ';
    const anno = ' . json_encode($anno) . ';

    let saveTimer = null;

    document.querySelectorAll(".budget-input").forEach(inp => {
        inp.addEventListener("change", function () {
            const idConto = this.dataset.idConto;
            const mese    = this.dataset.mese;
            const importo = this.value || "0";

            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => salvaBudget(idConto, mese, importo, this), 400);
        });
    });

    function salvaBudget(idConto, mese, importo, el) {
        const fd = new FormData();
        fd.append("action", "salva");
        fd.append("csrf_token", csrf);
        fd.append("anno", anno);
        fd.append("id_conto", idConto);
        fd.append("mese", mese);
        fd.append("importo", importo);

        el.classList.add("saving");
        fetch(window.location.pathname, { method: "POST", body: fd })
            .then(r => r.json())
            .then(data => {
                el.classList.remove("saving");
                if (data.ok) {
                    el.classList.add("saved");
                    setTimeout(() => el.classList.remove("saved"), 1500);
                    ricalcolaTotaleRiga(el);
                } else {
                    el.classList.add("error");
                    alert("Errore salvataggio: " + data.msg);
                }
            })
            .catch(() => el.classList.remove("saving"));
    }

    function ricalcolaTotaleRiga(el) {
        const tr   = el.closest("tr");
        if (!tr) return;
        const inputs = tr.querySelectorAll(".budget-input");
        let tot = 0;
        inputs.forEach(i => { tot += parseFloat(i.value || 0); });
        const tdTot = tr.querySelector("td:last-child");
        if (tdTot) {
            const fmt = tot != 0 ? "€ " + Math.round(tot).toLocaleString("it-IT") : "—";
            tdTot.childNodes[0].textContent = fmt;
        }
    }
})();
</script>
<style>
.budget-input:focus { background: #fff !important; border-color: #86b7fe !important; z-index:2; }
.budget-input.saving { background: #fff9c4 !important; }
.budget-input.saved  { background: #d4edda !important; transition: background .5s; }
.budget-input.error  { background: #f8d7da !important; }
</style>';

require_once dirname(__DIR__) . '/layout/footer.php';
