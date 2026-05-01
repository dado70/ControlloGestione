<?php
declare(strict_types=1);
$pageTitle  = 'Gestione Keyword';
$activePage = 'keywords';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::init();
Auth::requireRole('admin');

$idAzienda = Auth::getIdAzienda();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(Auth::csrfToken(), $_POST['csrf'] ?? '')) { http_response_code(419); die('CSRF non valido.'); }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $keyword  = mb_strtolower(trim($_POST['keyword'] ?? ''), 'UTF-8');
        $idConto  = (int)($_POST['id_conto'] ?? 0);
        $peso     = max(1, min(10, (int)($_POST['peso'] ?? 1)));
        if ($keyword !== '' && $idConto) {
            try {
                Database::execute(
                    'INSERT INTO keyword_conto (id_azienda, id_conto, keyword, peso) VALUES (?,?,?,?)',
                    [$idAzienda, $idConto, $keyword, $peso]
                );
                $msg = 'Keyword aggiunta.';
            } catch (\Exception $e) {
                $msg = 'Keyword già esistente per questo conto.';
                $msgType = 'warning';
            }
        } else {
            $msg = 'Compilare keyword e conto.';
            $msgType = 'danger';
        }
    }

    if ($action === 'edit_peso') {
        $id   = (int)$_POST['id'];
        $peso = max(1, min(10, (int)($_POST['peso'] ?? 1)));
        Database::execute(
            'UPDATE keyword_conto SET peso=? WHERE id=? AND id_azienda=?',
            [$peso, $id, $idAzienda]
        );
        $msg = 'Peso aggiornato.';
    }

    if ($action === 'delete') {
        Database::execute(
            'DELETE FROM keyword_conto WHERE id=? AND id_azienda=?',
            [(int)$_POST['id'], $idAzienda]
        );
        $msg = 'Keyword eliminata.';
    }
}

// Filtri
$filtKw    = trim($_GET['kw']    ?? '');
$filtConto = trim($_GET['conto'] ?? '');

$where  = ['kc.id_azienda = ?'];
$params = [$idAzienda];
if ($filtKw    !== '') { $where[] = 'kc.keyword LIKE ?';      $params[] = "%$filtKw%"; }
if ($filtConto !== '') { $where[] = 'pc.codice LIKE ?';        $params[] = "%$filtConto%"; }

$keywords = Database::fetchAll(
    'SELECT kc.id, kc.keyword, kc.peso,
            pc.id AS id_conto, pc.codice, pc.descrizione
     FROM keyword_conto kc
     JOIN piano_conti pc ON pc.id = kc.id_conto
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY pc.codice, kc.keyword
     LIMIT 500',
    $params
);

$contiDisp = Database::fetchAll(
    'SELECT id, codice, descrizione FROM piano_conti
     WHERE id_azienda=? AND livello=3 AND attivo=1 ORDER BY codice',
    [$idAzienda]
);

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-key me-2"></i>Gestione Keyword suggeritore conti</h4>
  <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalAdd">
    <i class="bi bi-plus-lg me-1"></i>Aggiungi keyword
  </button>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filtri -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small mb-1">Keyword</label>
        <input type="text" name="kw" class="form-control form-control-sm"
               value="<?= htmlspecialchars($filtKw) ?>" placeholder="es. energia">
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Codice conto</label>
        <input type="text" name="conto" class="form-control form-control-sm"
               value="<?= htmlspecialchars($filtConto) ?>" placeholder="es. 75.01">
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
        <a href="keywords.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Tabella keyword -->
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-dark">
        <tr>
          <th>Keyword</th>
          <th>Conto PDC</th>
          <th>Descrizione conto</th>
          <th class="text-center">Peso</th>
          <th class="text-center">Origine</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($keywords)): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">Nessuna keyword trovata.</td></tr>
      <?php else: ?>
      <?php foreach ($keywords as $kw): ?>
      <tr>
        <td><code><?= htmlspecialchars($kw['keyword']) ?></code></td>
        <td><code><?= htmlspecialchars($kw['codice']) ?></code></td>
        <td class="small"><?= htmlspecialchars(mb_substr($kw['descrizione'], 0, 50)) ?></td>
        <td class="text-center">
          <form method="post" class="d-flex justify-content-center gap-1 align-items-center">
            <input type="hidden" name="csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="edit_peso">
            <input type="hidden" name="id"     value="<?= $kw['id'] ?>">
            <select name="peso" class="form-select form-select-sm" style="width:60px"
                    onchange="this.form.submit()">
              <?php for ($p = 1; $p <= 10; $p++): ?>
              <option value="<?= $p ?>" <?= (int)$kw['peso'] === $p ? 'selected' : '' ?>><?= $p ?></option>
              <?php endfor; ?>
            </select>
          </form>
        </td>
        <td class="text-center">
          <?php if ((int)$kw['peso'] === 1): ?>
          <span class="badge bg-secondary" title="Appresa automaticamente">auto</span>
          <?php else: ?>
          <span class="badge bg-primary" title="Definita manualmente">manuale</span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <form method="post" class="d-inline"
                onsubmit="return confirm('Eliminare la keyword \'<?= htmlspecialchars($kw['keyword']) ?>\'?')">
            <input type="hidden" name="csrf"   value="<?= Auth::csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id"     value="<?= $kw['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-trash"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer text-muted small"><?= count($keywords) ?> keyword (max 500)</div>
</div>

<!-- Modal aggiungi -->
<div class="modal fade" id="modalAdd" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf"   value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title">Aggiungi keyword</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Keyword <span class="text-danger">*</span></label>
            <input type="text" name="keyword" class="form-control" required
                   placeholder="es. gasolio (minuscolo, senza accenti)">
            <div class="form-text">Parola o frase che identifica il tipo di costo nella descrizione della riga fattura.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Conto PDC <span class="text-danger">*</span></label>
            <select name="id_conto" class="form-select" required>
              <option value="">-- seleziona --</option>
              <?php foreach ($contiDisp as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['codice'] . ' — ' . mb_substr($c['descrizione'], 0, 50)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Peso (1=basso, 10=alto)</label>
            <input type="number" name="peso" class="form-control" value="3" min="1" max="10">
            <div class="form-text">Peso più alto = priorità maggiore quando più keyword corrispondono.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn btn-success">Aggiungi</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
