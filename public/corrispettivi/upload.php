<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::init();
Auth::requireRole('superadmin', 'admin', 'operatore');

$idAzienda = Auth::getIdAzienda();
$user      = Auth::getUser();

// ── Gestione upload AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token CSRF non valido.']);
        exit;
    }

    if (!$idAzienda) {
        echo json_encode(['status' => 'error', 'message' => 'Nessuna azienda selezionata.']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Errore upload file.']);
        exit;
    }

    $fileName = basename($_FILES['file']['name']);
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($ext, ['csv', 'txt'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Formato non supportato. Usare .csv o .txt']);
        exit;
    }

    $contenuto = file_get_contents($_FILES['file']['tmp_name']);
    if ($contenuto === false) {
        echo json_encode(['status' => 'error', 'message' => 'Impossibile leggere il file.']);
        exit;
    }

    // Detecta separatore (punto e virgola o virgola)
    $primRiga = strtok($contenuto, "\n");
    $sep = (substr_count($primRiga, ';') >= substr_count($primRiga, ',')) ? ';' : ',';

    $linee   = explode("\n", str_replace("\r", '', trim($contenuto)));
    $righe   = array_map(fn($l) => str_getcsv($l, $sep), $linee);

    if (empty($righe)) {
        echo json_encode(['status' => 'error', 'message' => 'File vuoto.']);
        exit;
    }

    // Salta header se la prima riga non contiene una data valida
    $startIdx = 0;
    $primaCol = strtolower(trim($righe[0][0] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $primaCol) && !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $primaCol)) {
        $startIdx = 1;
    }

    $importate = 0;
    $errori    = 0;
    $msgs      = [];

    Database::beginTransaction();
    try {
        for ($i = $startIdx; $i < count($righe); $i++) {
            $r = $righe[$i];
            // Salta righe vuote
            if (empty(array_filter($r, fn($v) => trim($v) !== ''))) {
                continue;
            }

            // Formato atteso (6 colonne): data;tipo;descrizione;imponibile;aliquota_iva;imposta
            // Formato corto (5 colonne):  data;tipo;descrizione;imponibile;aliquota_iva
            // Formato minimo (4 colonne): data;descrizione;imponibile;aliquota_iva
            $nCol = count($r);

            if ($nCol < 3) {
                $errori++;
                $msgs[] = "Riga " . ($i + 1) . ": colonne insufficienti.";
                continue;
            }

            // Normalizza data
            $dataRaw = trim($r[0] ?? '');
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dataRaw, $m)) {
                $dataRaw = "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRaw)) {
                $errori++;
                $msgs[] = "Riga " . ($i + 1) . ": data non valida «{$dataRaw}».";
                continue;
            }

            // Distingui formato 4 vs 5+ colonne
            if ($nCol >= 5 && in_array(strtolower(trim($r[1])), ['corrispettivo','reso','annullo'], true)) {
                // Formato 5-6 col: data;tipo;descrizione;imponibile;aliquota_iva[;imposta]
                $tipo        = strtolower(trim($r[1]));
                $descrizione = trim($r[2]);
                $imponibile  = (float)str_replace(',', '.', trim($r[3]));
                $aliquota    = (float)str_replace(',', '.', trim($r[4]));
                $imposta     = isset($r[5]) && trim($r[5]) !== ''
                    ? (float)str_replace(',', '.', trim($r[5]))
                    : round($imponibile * $aliquota / 100, 2);
            } else {
                // Formato 4 col: data;descrizione;imponibile;aliquota_iva
                $tipo        = 'corrispettivo';
                $descrizione = trim($r[1] ?? '');
                $imponibile  = (float)str_replace(',', '.', trim($r[2] ?? '0'));
                $aliquota    = (float)str_replace(',', '.', trim($r[3] ?? '0'));
                $imposta     = round($imponibile * $aliquota / 100, 2);
            }

            $totale = round($imponibile + $imposta, 2);

            Database::query(
                'INSERT INTO corrispettivi
                 (id_azienda, data_documento, tipo, descrizione, imponibile, aliquota_iva, imposta, totale, nome_file, importato_da)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$idAzienda, $dataRaw, $tipo, $descrizione ?: null, $imponibile, $aliquota ?: null, $imposta, $totale, $fileName, $user['id']]
            );
            $importate++;
        }

        Database::commit();
    } catch (Throwable $e) {
        Database::rollback();
        echo json_encode(['status' => 'error', 'message' => 'Errore DB: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'status'    => 'ok',
        'importate' => $importate,
        'errori'    => $errori,
        'message'   => "Importate {$importate} righe" . ($errori ? ", {$errori} errori" : '') . '.',
        'dettagli'  => $msgs,
    ]);
    exit;
}
// ── Fine POST AJAX ────────────────────────────────────────────────────────────

$pageTitle  = 'Importa corrispettivi';
$activePage = 'corr_upload';
require_once dirname(__DIR__) . '/layout/header.php';

$csrfToken = Auth::csrfToken();
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2"></i>
  Seleziona un'azienda prima di importare.
</div>
<?php else: ?>

<div class="row g-3">
  <!-- Area upload -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header fw-semibold bg-white border-bottom-0">
        <i class="bi bi-cloud-upload me-2 text-primary"></i>Carica file corrispettivi (CSV)
      </div>
      <div class="card-body">
        <div id="dropzone" class="border border-2 border-dashed rounded-3 p-5 text-center text-muted"
             style="cursor:pointer; transition: background .2s;">
          <i class="bi bi-file-earmark-spreadsheet fs-1 mb-2 d-block text-primary opacity-50"></i>
          <div class="fw-semibold mb-1">Trascina il file CSV oppure clicca per selezionarlo</div>
          <div class="small">Formato: <code>.csv</code> o <code>.txt</code> — separatore <code>;</code> o <code>,</code></div>
          <input type="file" id="fileInput" accept=".csv,.txt" class="d-none">
        </div>

        <div class="mt-3 d-flex gap-2">
          <button id="btnSeleziona" class="btn btn-primary">
            <i class="bi bi-folder2-open me-1"></i>Seleziona file
          </button>
          <a href="lista.php" class="btn btn-outline-secondary">
            <i class="bi bi-list-task me-1"></i>Archivio corrispettivi
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Formato atteso -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header fw-semibold bg-white border-bottom-0">
        <i class="bi bi-info-circle me-2 text-info"></i>Formato CSV
      </div>
      <div class="card-body small">
        <p class="text-muted mb-2">Il file deve contenere una riga per ogni corrispettivo. Separatore: <code>;</code> o <code>,</code>.</p>
        <p class="fw-semibold mb-1">Formato esteso (6 colonne):</p>
        <code class="d-block bg-light rounded p-2 mb-2 small">
          data;tipo;descrizione;imponibile;aliquota_iva;imposta<br>
          2025-06-01;corrispettivo;Ristorante;1122.33;10;112.23<br>
          2025-06-01;corrispettivo;Hotel;5671.00;10;567.10<br>
          2025-06-01;reso;Reso camera;-200.00;10;-20.00
        </code>
        <p class="fw-semibold mb-1">Formato breve (4 colonne):</p>
        <code class="d-block bg-light rounded p-2 small">
          data;descrizione;imponibile;aliquota_iva<br>
          2025-06-01;Ristorante;1122.33;10
        </code>
        <p class="text-muted mt-2 mb-0">Tipi validi: <code>corrispettivo</code>, <code>reso</code>, <code>annullo</code>.<br>
        Data: <code>YYYY-MM-DD</code> o <code>DD/MM/YYYY</code>.<br>
        Decimali: punto <code>.</code> o virgola <code>,</code>.</p>
      </div>
    </div>
  </div>
</div>

<!-- Risultati -->
<div class="mt-4" id="risultatoWrap" style="display:none">
  <div class="alert" id="risultatoAlert"></div>
</div>

<?php endif; ?>

<?php
$extraJs = '
<script>
(function () {
    const dropzone  = document.getElementById("dropzone");
    const fileInput = document.getElementById("fileInput");
    const btnSel    = document.getElementById("btnSeleziona");
    const risWrap   = document.getElementById("risultatoWrap");
    const risAlert  = document.getElementById("risultatoAlert");
    const csrf      = ' . json_encode($csrfToken) . ';

    if (!dropzone) return;

    btnSel.addEventListener("click", () => fileInput.click());
    dropzone.addEventListener("click", () => fileInput.click());

    dropzone.addEventListener("dragover", e => { e.preventDefault(); dropzone.classList.add("bg-primary","bg-opacity-10"); });
    dropzone.addEventListener("dragleave", () => dropzone.classList.remove("bg-primary","bg-opacity-10"));
    dropzone.addEventListener("drop", e => {
        e.preventDefault();
        dropzone.classList.remove("bg-primary","bg-opacity-10");
        if (e.dataTransfer.files.length) doUpload(e.dataTransfer.files[0]);
    });
    fileInput.addEventListener("change", () => { if (fileInput.files.length) doUpload(fileInput.files[0]); fileInput.value=""; });

    function doUpload(file) {
        risWrap.style.display = "";
        risAlert.className = "alert alert-secondary";
        risAlert.innerHTML = "<div class=\"spinner-border spinner-border-sm me-2\"></div>Importazione in corso…";

        const fd = new FormData();
        fd.append("action", "upload");
        fd.append("csrf_token", csrf);
        fd.append("file", file);

        fetch(window.location.pathname, { method: "POST", body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === "ok") {
                    let html = `<i class="bi bi-check-circle me-2"></i><strong>${escHtml(data.message)}</strong>`;
                    if (data.dettagli && data.dettagli.length) {
                        html += "<ul class=\"mb-0 mt-2\">" + data.dettagli.map(m => `<li>${escHtml(m)}</li>`).join("") + "</ul>";
                    }
                    risAlert.className = data.errori > 0 ? "alert alert-warning" : "alert alert-success";
                    risAlert.innerHTML = html;
                } else {
                    risAlert.className = "alert alert-danger";
                    risAlert.innerHTML = `<i class="bi bi-x-circle me-2"></i>${escHtml(data.message)}`;
                }
            })
            .catch(err => {
                risAlert.className = "alert alert-danger";
                risAlert.innerHTML = `<i class="bi bi-x-circle me-2"></i>Errore: ${escHtml(err.message)}`;
            });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
    }
})();
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
