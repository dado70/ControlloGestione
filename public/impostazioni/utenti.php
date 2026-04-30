<?php
declare(strict_types=1);
$pageTitle  = 'Gestione Utenti';
$activePage = 'utenti';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::init();
Auth::requireRole('admin');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$me      = Auth::getUser();
$isSuper = Auth::isSuperadmin();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['csrf'] !== $_SESSION['csrf']) die('CSRF error');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $nome     = trim($_POST['nome'] ?? '');
        $cognome  = trim($_POST['cognome'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $ruolo    = $_POST['ruolo'] ?? 'operatore';
        $idAz     = (int)($_POST['id_azienda'] ?? Auth::getIdAzienda());

        if ($username === '' || strlen($password) < 8) {
            $msg = 'Username obbligatorio e password minimo 8 caratteri.';
            $msgType = 'danger';
        } else {
            try {
                Database::execute(
                    'INSERT INTO utenti (username, password_hash, nome, cognome, email, ruolo)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$username, password_hash($password, PASSWORD_BCRYPT), $nome, $cognome, $email, $ruolo]
                );
                $idUtente = Database::lastInsertId();
                Database::execute(
                    'INSERT IGNORE INTO utenti_aziende (id_utente, id_azienda, ruolo) VALUES (?,?,?)',
                    [$idUtente, $idAz, $ruolo === 'superadmin' ? 'admin' : $ruolo]
                );
                $msg = 'Utente creato.';
            } catch (\Exception $e) {
                $msg = 'Errore: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$me['id']) {
            Database::execute('UPDATE utenti SET attivo = NOT attivo WHERE id=?', [$id]);
            $msg = 'Stato aggiornato.';
        }
    } elseif ($action === 'reset_pwd') {
        $id      = (int)$_POST['id'];
        $newPwd  = bin2hex(random_bytes(6)); // 12 char hex
        Database::execute(
            'UPDATE utenti SET password_hash=? WHERE id=?',
            [password_hash($newPwd, PASSWORD_BCRYPT), $id]
        );
        $msg = 'Password reimpostata: <strong>' . htmlspecialchars($newPwd) . '</strong> — comunicarla all\'utente e chiedere di cambiarla.';
    } elseif ($action === 'edit_ruolo') {
        $id    = (int)$_POST['id'];
        $ruolo = $_POST['ruolo'] ?? 'operatore';
        $idAz  = (int)($_POST['id_azienda'] ?? Auth::getIdAzienda());
        Database::execute('UPDATE utenti SET ruolo=? WHERE id=?', [$ruolo, $id]);
        Database::execute(
            'INSERT INTO utenti_aziende (id_utente, id_azienda, ruolo) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE ruolo=VALUES(ruolo)',
            [$id, $idAz, $ruolo === 'superadmin' ? 'admin' : $ruolo]
        );
        $msg = 'Ruolo aggiornato.';
    }
}

// Carica utenti (superadmin vede tutti, admin vede solo quelli della propria azienda)
if ($isSuper) {
    $utenti = Database::fetchAll(
        'SELECT u.*, GROUP_CONCAT(a.ragione_sociale ORDER BY a.ragione_sociale SEPARATOR ", ") AS aziende_str
         FROM utenti u
         LEFT JOIN utenti_aziende ua ON ua.id_utente=u.id
         LEFT JOIN aziende a ON a.id=ua.id_azienda
         GROUP BY u.id ORDER BY u.username'
    );
    $aziende = Database::fetchAll('SELECT id, ragione_sociale FROM aziende WHERE attiva=1 ORDER BY ragione_sociale');
} else {
    $idAzienda = Auth::getIdAzienda();
    $utenti = Database::fetchAll(
        'SELECT u.*, ? AS aziende_str
         FROM utenti u
         JOIN utenti_aziende ua ON ua.id_utente=u.id
         WHERE ua.id_azienda=?
         ORDER BY u.username',
        [$idAzienda, $idAzienda]
    );
    $aziende = Database::fetchAll('SELECT id, ragione_sociale FROM aziende WHERE id=?', [$idAzienda]);
}

$ruoliDisponibili = $isSuper
    ? ['superadmin', 'admin', 'operatore', 'readonly']
    : ['admin', 'operatore', 'readonly'];

require_once dirname(__DIR__) . '/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-people me-2"></i>Gestione Utenti</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUtente">
    <i class="bi bi-plus-lg me-1"></i>Nuovo utente
  </button>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= $msg ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>Username</th>
            <th>Nome</th>
            <th>Email</th>
            <th>Ruolo</th>
            <?php if ($isSuper): ?><th>Aziende</th><?php endif; ?>
            <th>Stato</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($utenti as $u): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($u['username']) ?></strong>
              <?php if ((int)$u['id'] === (int)$me['id']): ?>
                <span class="badge bg-info ms-1">tu</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(trim($u['nome'] . ' ' . $u['cognome'])) ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
            <td>
              <form method="post" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="action" value="edit_ruolo">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <?php if (!empty($aziende)): ?>
                <input type="hidden" name="id_azienda" value="<?= $aziende[0]['id'] ?>">
                <?php endif; ?>
                <select name="ruolo" class="form-select form-select-sm" style="width:auto"
                  onchange="this.form.submit()" <?= (int)$u['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
                  <?php foreach ($ruoliDisponibili as $r): ?>
                  <option value="<?= $r ?>" <?= $u['ruolo'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <?php if ($isSuper): ?>
            <td><small class="text-muted"><?= htmlspecialchars($u['aziende_str'] ?? '—') ?></small></td>
            <?php endif; ?>
            <td>
              <span class="badge bg-<?= $u['attivo'] ? 'success' : 'secondary' ?>">
                <?= $u['attivo'] ? 'Attivo' : 'Disattivo' ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ((int)$u['id'] !== (int)$me['id']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $u['attivo'] ? 'warning' : 'success' ?>"
                  title="<?= $u['attivo'] ? 'Disattiva' : 'Attiva' ?>">
                  <i class="bi bi-<?= $u['attivo'] ? 'pause' : 'play' ?>"></i>
                </button>
              </form>
              <form method="post" class="d-inline"
                onsubmit="return confirm('Reimpostare la password di <?= htmlspecialchars($u['username']) ?>?')">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                <input type="hidden" name="action" value="reset_pwd">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Reset password">
                  <i class="bi bi-key"></i>
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
</div>

<!-- Modal nuovo utente -->
<div class="modal fade" id="modalUtente" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title">Nuovo utente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome</label>
              <input type="text" name="nome" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Cognome</label>
              <input type="text" name="cognome" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" required autocomplete="new-password">
            </div>
            <div class="col-12">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
              <div class="form-text">Minimo 8 caratteri.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Ruolo</label>
              <select name="ruolo" class="form-select">
                <?php foreach ($ruoliDisponibili as $r): ?>
                <option value="<?= $r ?>"><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if (count($aziende) > 1): ?>
            <div class="col-md-6">
              <label class="form-label">Azienda</label>
              <select name="id_azienda" class="form-select">
                <?php foreach ($aziende as $az): ?>
                <option value="<?= $az['id'] ?>"><?= htmlspecialchars($az['ragione_sociale']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php elseif (!empty($aziende)): ?>
            <input type="hidden" name="id_azienda" value="<?= $aziende[0]['id'] ?>">
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn btn-primary">Crea utente</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/layout/footer.php'; ?>
