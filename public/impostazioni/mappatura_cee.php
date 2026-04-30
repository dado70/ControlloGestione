<?php
declare(strict_types=1);
$pageTitle  = 'Mappatura PDC ↔ CEE';
$activePage = 'mappatura_cee';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::init();
Auth::requireRole('admin');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$idAzienda = Auth::getIdAzienda();
$msg = '';

// Salva mappatura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_mappatura'])) {
    if ($_POST['csrf'] !== $_SESSION['csrf']) die('CSRF error');
    $idConto   = (int)($_POST['id_conto'] ?? 0);
    $codiceCee = trim($_POST['codice_cee'] ?? '');
    if ($idConto && $codiceCee) {
        Database::execute(
            'INSERT INTO mappatura_pdc_cee (id_azienda, id_conto, codice_cee)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE codice_cee=VALUES(codice_cee)',
            [$idAzienda, $idConto, $codiceCee]
        );
        $msg = 'Mappatura salvata.';
    }
}

// Elimina mappatura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elimina_mappatura'])) {
    if ($_POST['csrf'] !== $_SESSION['csrf']) die('CSRF error');
    Database::execute(
        'DELETE FROM mappatura_pdc_cee WHERE id_azienda=? AND id_conto=?',
        [$idAzienda, (int)$_POST['id_conto']]
    );
    $msg = 'Mappatura rimossa.';
}

$soloNonMappati = !empty($_GET['non_mappati']);

$sql = 'SELECT pc.id, pc.codice, pc.descrizione, pc.tipo, pc.livello,
               m.codice_cee, cee.descrizione AS desc_cee
        FROM piano_conti pc
        LEFT JOIN mappatura_pdc_cee m ON m.id_conto=pc.id AND m.id_azienda=?
        LEFT JOIN piano_conti_cee cee ON cee.codice=m.codice_cee
        WHERE pc.id_azienda=? AND pc.attivo=1';
$params = [$idAzienda, $idAzienda];

if ($soloNonMappati) {
    $sql .= ' AND m.id IS NULL';
}
$sql .= ' ORDER BY pc.codice';

$conti = Database::fetchAll($sql, $params);

// Opzioni CEE per i select
$opzioniCee = Database::fetchAll(
    'SELECT codice, descrizione, sezione FROM piano_conti_cee ORDER BY codice'
);

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Mappatura Piano dei Conti ↔ CEE</h4>
  <a href="?<?= $soloNonMappati ? '' : 'non_mappati=1' ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-filter me-1"></i>
    <?= $soloNonMappati ? 'Mostra tutti' : 'Solo non mappati' ?>
  </a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
$nonMappati = count(array_filter($conti, fn($r) => !$r['codice_cee']));
if ($nonMappati > 0 && !$soloNonMappati):
?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-1"></i>
  <strong><?= $nonMappati ?></strong> conti non hanno ancora una mappatura CEE.
  <a href="?non_mappati=1" class="alert-link">Mostra solo i non mappati</a>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>Codice PDC</th>
            <th>Descrizione</th>
            <th>Tipo</th>
            <th>Lv.</th>
            <th class="text-center">→</th>
            <th>Codice CEE</th>
            <th>Descrizione CEE</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($conti as $r): ?>
          <tr class="<?= !$r['codice_cee'] ? 'table-warning' : '' ?>">
            <td><code><?= htmlspecialchars($r['codice']) ?></code></td>
            <td><?= htmlspecialchars($r['descrizione']) ?></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($r['tipo']) ?></span></td>
            <td><?= $r['livello'] ?></td>
            <td class="text-center text-muted">→</td>
            <td>
              <?php if ($r['codice_cee']): ?>
                <code><?= htmlspecialchars($r['codice_cee']) ?></code>
              <?php else: ?>
                <form method="post" class="d-flex gap-1">
                  <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                  <input type="hidden" name="id_conto" value="<?= $r['id'] ?>">
                  <select name="codice_cee" class="form-select form-select-sm" required style="min-width:180px">
                    <option value="">-- seleziona --</option>
                    <?php
                    $sezioneCorrente = '';
                    foreach ($opzioniCee as $cee):
                        if ($cee['sezione'] !== $sezioneCorrente) {
                            if ($sezioneCorrente) echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($cee['sezione']) . '">';
                            $sezioneCorrente = $cee['sezione'];
                        }
                    ?>
                    <option value="<?= htmlspecialchars($cee['codice']) ?>">
                      <?= htmlspecialchars($cee['codice']) ?> — <?= htmlspecialchars(mb_substr($cee['descrizione'], 0, 40)) ?>
                    </option>
                    <?php endforeach; if ($sezioneCorrente) echo '</optgroup>'; ?>
                  </select>
                  <button type="submit" name="salva_mappatura" class="btn btn-sm btn-success">
                    <i class="bi bi-check-lg"></i>
                  </button>
                </form>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)($r['desc_cee'] ?? '')) ?></td>
            <td>
              <?php if ($r['codice_cee']): ?>
              <form method="post" onsubmit="return confirm('Rimuovere la mappatura?')">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="id_conto" value="<?= $r['id'] ?>">
                <button type="submit" name="elimina_mappatura" class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer text-muted small">
    <?= count($conti) ?> conti — <?= count($conti) - $nonMappati ?> mappati — <?= $nonMappati ?> non mappati
  </div>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
