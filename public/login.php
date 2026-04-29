<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';

Auth::init();

if (Auth::isLoggedIn()) {
    header('Location: ' . APP_URL . '/public/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Inserire username e password.';
    } elseif (!Auth::login($username, $password)) {
        $error = 'Credenziali non valide o account bloccato.';
    } else {
        header('Location: ' . APP_URL . '/public/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accesso — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="login-page">

<div class="login-card card p-4">
  <!-- Logo / Brand -->
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center bg-primary rounded-circle mb-3"
         style="width:56px;height:56px">
      <i class="bi bi-building text-white fs-4"></i>
    </div>
    <h5 class="fw-bold mb-0"><?= APP_NAME ?></h5>
    <small class="text-muted">Controllo di gestione fatture</small>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger py-2 small">
    <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">

    <div class="mb-3">
      <label class="form-label fw-semibold small">Username</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-person"></i></span>
        <input type="text" name="username" class="form-control"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               autofocus required autocomplete="username">
      </div>
    </div>

    <div class="mb-4">
      <label class="form-label fw-semibold small">Password</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-lock"></i></span>
        <input type="password" name="password" id="pwd" class="form-control"
               required autocomplete="current-password">
        <button type="button" class="btn btn-outline-secondary" id="togglePwd" tabindex="-1">
          <i class="bi bi-eye" id="eyeIcon"></i>
        </button>
      </div>
    </div>

    <div class="d-grid">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-box-arrow-in-right me-1"></i>Accedi
      </button>
    </div>
  </form>

  <div class="text-center mt-3 text-muted" style="font-size:.75rem">
    GestHotel FE v<?= APP_VERSION ?> &mdash;
    <a href="https://github.com/dado70/gesthotel-fe" target="_blank" rel="noopener" class="text-muted">GPL v3</a>
  </div>
</div>

<script>
document.getElementById('togglePwd').addEventListener('click', function() {
  const p = document.getElementById('pwd');
  const i = document.getElementById('eyeIcon');
  if (p.type === 'password') { p.type = 'text';     i.className = 'bi bi-eye-slash'; }
  else                       { p.type = 'password'; i.className = 'bi bi-eye'; }
});
</script>
</body>
</html>
