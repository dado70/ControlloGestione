<?php

declare(strict_types=1);
$pageTitle  = 'Bilancio CEE semplificato';
$activePage = 'bilancio_cee';
require_once dirname(__DIR__) . '/layout/header.php';

Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();

$anniDisp = $idAzienda ? Database::fetchAll(
    'SELECT DISTINCT YEAR(data_documento) as anno FROM fatture_elettroniche WHERE id_azienda=? ORDER BY anno DESC',
    [$idAzienda]
) : [];

$annoCorr = (int)($_GET['anno'] ?? (int)date('Y'));
$annoPre  = $annoCorr - 1;

// Quanti conti non hanno mappatura CEE
$contiNonMappati = 0;
if ($idAzienda) {
    $res = Database::fetchOne(
        'SELECT COUNT(DISTINCT fl.id_conto) as tot
         FROM fatture_linee fl
         WHERE fl.id_azienda = ? AND fl.id_conto IS NOT NULL
           AND fl.id_conto NOT IN (
               SELECT id_conto FROM mappatura_pdc_cee WHERE id_azienda = ?
           )',
        [$idAzienda, $idAzienda]
    );
    $contiNonMappati = (int)($res['tot'] ?? 0);
}

/**
 * Aggrega importi per sezione CEE per un dato anno.
 * Ritorna array indicizzato per codice_cee.
 */
function aggregaCee(int $idAzienda, int $anno): array
{
    $rows = Database::fetchAll(
        'SELECT pcc.codice, pcc.descrizione, pcc.sezione, pcc.livello, pcc.codice_padre,
                SUM(fl.prezzo_totale) as importo
         FROM fatture_linee fl
         JOIN fatture_elettroniche fe ON fe.id = fl.id_fattura
         JOIN mappatura_pdc_cee mpc ON mpc.id_conto = fl.id_conto AND mpc.id_azienda = fl.id_azienda
         JOIN piano_conti_cee pcc ON pcc.codice = mpc.codice_cee
         WHERE fl.id_azienda = ? AND YEAR(fe.data_documento) = ?
         GROUP BY pcc.codice, pcc.descrizione, pcc.sezione, pcc.livello, pcc.codice_padre
         ORDER BY pcc.sezione, pcc.codice',
        [$idAzienda, $anno]
    );

    $mappa = [];
    foreach ($rows as $r) {
        $mappa[$r['codice']] = [
            'descrizione' => $r['descrizione'],
            'sezione'     => $r['sezione'],
            'livello'     => (int)$r['livello'],
            'padre'       => $r['codice_padre'],
            'importo'     => (float)$r['importo'],
        ];
    }
    return $mappa;
}

$datiCorr = $idAzienda ? aggregaCee($idAzienda, $annoCorr) : [];
$datiPre  = $idAzienda ? aggregaCee($idAzienda, $annoPre)  : [];

// Raggruppa per sezione
$sezioni = ['COSTI' => 'Costi', 'RICAVI' => 'Ricavi', 'ATTIVO' => 'Attivo', 'PASSIVO' => 'Passivo'];
$bySezione = [];
foreach (['COSTI', 'RICAVI', 'ATTIVO', 'PASSIVO'] as $s) {
    $bySezione[$s] = [];
}
foreach ($datiCorr as $codice => $info) {
    $bySezione[$info['sezione']][$codice] = $info;
}
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Nessuna azienda selezionata.</div>
<?php else: ?>

<!-- Filtro anno -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-2">
        <label class="form-label small mb-1">Anno di riferimento</label>
        <select name="anno" class="form-select form-select-sm">
          <?php foreach ($anniDisp as $ar): ?>
          <option value="<?= $ar['anno'] ?>" <?= (int)$ar['anno'] === $annoCorr ? 'selected' : '' ?>><?= $ar['anno'] ?></option>
          <?php endforeach; ?>
          <?php if (empty($anniDisp)): ?>
          <option value="<?= $annoCorr ?>" selected><?= $annoCorr ?></option>
          <?php endif; ?>
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

<?php if ($contiNonMappati > 0): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2"></i>
  <strong><?= $contiNonMappati ?></strong> cont<?= $contiNonMappati === 1 ? 'o' : 'i' ?> utilizzat<?= $contiNonMappati === 1 ? 'o' : 'i' ?>
  nelle fatture non ha<?= $contiNonMappati === 1 ? '' : 'nno' ?> ancora una mappatura CEE.
  <a href="../impostazioni/mappatura_cee.php?solo_non_mappati=1" class="alert-link">
    Vai alle impostazioni &rarr;
  </a>
</div>
<?php endif; ?>

<?php if (empty($datiCorr)): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  Nessun dato disponibile per <?= $annoCorr ?>. Verifica che le fatture siano classificate
  e che la mappatura PDC → CEE sia configurata.
</div>
<?php else: ?>

<?php foreach (['COSTI', 'RICAVI'] as $sezione): ?>
<?php $righe = $bySezione[$sezione]; ?>
<?php if (empty($righe)) continue; ?>

<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-white fw-semibold">
    <i class="bi bi-<?= $sezione === 'COSTI' ? 'arrow-down-circle text-danger' : 'arrow-up-circle text-success' ?> me-2"></i>
    <?= $sezioni[$sezione] ?>
  </div>
  <div class="table-responsive">
    <table class="table table-gh mb-0">
      <thead class="table-light">
        <tr>
          <th>Codice CEE</th>
          <th>Descrizione</th>
          <th class="text-end"><?= $annoCorr ?> (€)</th>
          <th class="text-end"><?= $annoPre ?> (€)</th>
          <th class="text-end">Var. %</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $totSezione = 0.0;
      foreach ($righe as $codice => $info):
          $valCorr = $info['importo'];
          $valPre  = $datiPre[$codice]['importo'] ?? 0.0;
          $variaz  = $valPre > 0 ? (($valCorr - $valPre) / $valPre * 100) : null;
          $totSezione += $valCorr;
          $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', max(0, $info['livello'] - 1));
      ?>
      <tr>
        <td><code class="small"><?= htmlspecialchars($codice) ?></code></td>
        <td><?= $indent ?><?= htmlspecialchars($info['descrizione']) ?></td>
        <td class="text-end fw-semibold"><?= number_format($valCorr, 2, ',', '.') ?></td>
        <td class="text-end text-muted"><?= $valPre > 0 ? number_format($valPre, 2, ',', '.') : '—' ?></td>
        <td class="text-end">
          <?php if ($variaz !== null): ?>
          <span class="badge <?= $variaz >= 0 ? ($sezione === 'RICAVI' ? 'bg-success' : 'bg-danger') : ($sezione === 'RICAVI' ? 'bg-danger' : 'bg-success') ?>">
            <?= ($variaz >= 0 ? '+' : '') . number_format($variaz, 1) ?>%
          </span>
          <?php else: ?>
          <span class="text-muted small">n/d</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">Totale <?= $sezioni[$sezione] ?></td>
          <td class="text-end"><?= number_format($totSezione, 2, ',', '.') ?></td>
          <?php
          $totPre = array_sum(array_map(
              fn($c) => $datiPre[$c]['importo'] ?? 0.0,
              array_keys($righe)
          ));
          ?>
          <td class="text-end text-muted"><?= $totPre > 0 ? number_format($totPre, 2, ',', '.') : '—' ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
