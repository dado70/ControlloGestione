<?php

declare(strict_types=1);
$pageTitle  = 'Importa fatture';
$activePage = 'upload';
require_once dirname(__DIR__) . '/layout/header.php';
require_once dirname(__DIR__, 2) . '/core/P7MDecryptor.php';
require_once dirname(__DIR__, 2) . '/core/FatturaParser.php';
require_once dirname(__DIR__, 2) . '/core/ContoSuggestor.php';
require_once dirname(__DIR__, 2) . '/core/FatturaImporter.php';

Auth::requireRole('superadmin', 'admin', 'operatore');

$idAzienda = Auth::getIdAzienda();
$user      = Auth::getUser();

// Gestione upload AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    // Verifica CSRF
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
        $errMsg = match ($_FILES['file']['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File troppo grande.',
            UPLOAD_ERR_NO_FILE => 'Nessun file ricevuto.',
            default => 'Errore upload (codice ' . ($_FILES['file']['error'] ?? '?') . ').',
        };
        echo json_encode(['status' => 'error', 'message' => $errMsg]);
        exit;
    }

    $tmpPath  = $_FILES['file']['tmp_name'];
    $fileName = basename($_FILES['file']['name']);
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $risultati = [];

    if ($ext === 'zip') {
        // Estrai i file dall'archivio ZIP
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $tmpDir = sys_get_temp_dir() . '/fe_zip_' . uniqid('', true) . '/';
            mkdir($tmpDir, 0700, true);
            $zip->extractTo($tmpDir);
            $zip->close();

            $importer = new FatturaImporter();
            $files    = glob($tmpDir . '*');
            foreach ($files as $f) {
                $fName = basename($f);
                $fExt  = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                if (in_array($fExt, ['xml', 'p7m'], true)) {
                    $risultati[] = $importer->importFile($f, $fName, $idAzienda, $user['id']);
                }
            }
            // Pulizia
            array_map('unlink', glob($tmpDir . '*'));
            rmdir($tmpDir);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Impossibile aprire il file ZIP.']);
            exit;
        }
    } elseif (in_array($ext, ['xml', 'p7m'], true)) {
        $importer    = new FatturaImporter();
        $risultati[] = $importer->importFile($tmpPath, $fileName, $idAzienda, $user['id']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Formato non supportato. Usare .xml, .p7m o .zip.']);
        exit;
    }

    // Se un solo file, ritorna direttamente il risultato; altrimenti ritorna array
    if (count($risultati) === 1) {
        echo json_encode($risultati[0]);
    } else {
        echo json_encode(['status' => 'multi', 'risultati' => $risultati]);
    }
    exit;
}

$csrfToken = Auth::csrfToken();
?>

<?php if (!$idAzienda): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle me-2"></i>
  Seleziona un'azienda prima di importare le fatture.
</div>
<?php else: ?>

<div class="row g-3">
  <!-- Area upload -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header fw-semibold bg-white border-bottom-0">
        <i class="bi bi-cloud-upload me-2 text-primary"></i>Carica file fatture
      </div>
      <div class="card-body">
        <div id="dropzone" class="border border-2 border-dashed rounded-3 p-5 text-center text-muted"
             style="cursor:pointer; transition: background .2s;">
          <i class="bi bi-file-earmark-arrow-up fs-1 mb-2 d-block text-primary opacity-50"></i>
          <div class="fw-semibold mb-1">Trascina qui i file oppure clicca per selezionarli</div>
          <div class="small">Formati accettati: <code>.xml</code>, <code>.p7m</code>, <code>.zip</code></div>
          <input type="file" id="fileInput" multiple accept=".xml,.p7m,.zip" class="d-none">
        </div>

        <div class="mt-3 d-flex gap-2">
          <button id="btnSeleziona" class="btn btn-primary">
            <i class="bi bi-folder2-open me-1"></i>Seleziona file
          </button>
          <a href="lista.php?stato=importata" class="btn btn-outline-secondary">
            <i class="bi bi-list-task me-1"></i>Da classificare
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Istruzioni -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header fw-semibold bg-white border-bottom-0">
        <i class="bi bi-info-circle me-2 text-info"></i>Come funziona
      </div>
      <div class="card-body small text-muted">
        <ol class="mb-0 ps-3">
          <li>Trascina o seleziona i file <code>.xml</code> o <code>.p7m</code> delle fatture passive scaricate da SDI o dal cassetto fiscale.</li>
          <li>Puoi anche caricare un archivio <code>.zip</code> contenente più file.</li>
          <li>Il sistema verifica i duplicati automaticamente (hash SHA-256).</li>
          <li>Per ogni riga viene suggerito automaticamente il conto contabile.</li>
          <li>Vai in <strong>Archivio fatture</strong> per classificare e confermare.</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<!-- Tabella risultati -->
<div class="mt-4" id="risultatiWrap" style="display:none">
  <div class="card shadow-sm border-0">
    <div class="card-header fw-semibold bg-white border-bottom-0">
      <i class="bi bi-table me-2"></i>Risultati importazione
    </div>
    <div class="table-responsive">
      <table class="table table-gh mb-0" id="tblRisultati">
        <thead class="table-light">
          <tr>
            <th>File</th>
            <th>Esito</th>
            <th>Fornitore</th>
            <th class="text-center">Righe</th>
            <th>Messaggio</th>
            <th>Azione</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

<?php
$extraJs = '
<script>
(function () {
    const dropzone   = document.getElementById("dropzone");
    const fileInput  = document.getElementById("fileInput");
    const btnSel     = document.getElementById("btnSeleziona");
    const risultatiW = document.getElementById("risultatiWrap");
    const tbody      = document.querySelector("#tblRisultati tbody");
    const csrf       = ' . json_encode($csrfToken) . ';

    if (!dropzone) return;

    btnSel.addEventListener("click", () => fileInput.click());
    dropzone.addEventListener("click", () => fileInput.click());

    dropzone.addEventListener("dragover", e => {
        e.preventDefault();
        dropzone.classList.add("bg-primary", "bg-opacity-10");
    });
    dropzone.addEventListener("dragleave", () => {
        dropzone.classList.remove("bg-primary", "bg-opacity-10");
    });
    dropzone.addEventListener("drop", e => {
        e.preventDefault();
        dropzone.classList.remove("bg-primary", "bg-opacity-10");
        if (e.dataTransfer.files.length) processFiles(e.dataTransfer.files);
    });

    fileInput.addEventListener("change", () => {
        if (fileInput.files.length) processFiles(fileInput.files);
        fileInput.value = "";
    });

    function processFiles(files) {
        risultatiW.style.display = "";
        Array.from(files).forEach(uploadFile);
    }

    function uploadFile(file) {
        // Aggiungi riga con progress
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td class="text-truncate" style="max-width:180px" title="${escHtml(file.name)}">${escHtml(file.name)}</td>
            <td><span class="badge bg-secondary">In corso…</span></td>
            <td>—</td>
            <td class="text-center">—</td>
            <td><div class="progress" style="height:6px"><div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div></div></td>
            <td>—</td>
        `;
        tbody.appendChild(tr);

        const fd = new FormData();
        fd.append("action", "upload");
        fd.append("csrf_token", csrf);
        fd.append("file", file);

        fetch(window.location.pathname, { method: "POST", body: fd })
            .then(r => r.json())
            .then(data => renderRow(tr, file.name, data))
            .catch(err => renderRow(tr, file.name, { status: "error", message: err.message, cedente: "", n_linee: 0 }));
    }

    function renderRow(tr, fileName, data) {
        // Gestione risultato multi-file (ZIP)
        if (data.status === "multi") {
            tr.remove();
            data.risultati.forEach(r => {
                const rtr = document.createElement("tr");
                tbody.appendChild(rtr);
                renderRow(rtr, fileName + " (zip)", r);
            });
            return;
        }

        const badgeClass = { ok: "bg-success", duplicate: "bg-warning text-dark", error: "bg-danger" }[data.status] || "bg-secondary";
        const badgeLbl   = { ok: "Importata", duplicate: "Duplicato", error: "Errore" }[data.status] || data.status;
        const azione = data.id_fattura
            ? `<a href="dettaglio.php?id=${data.id_fattura}" class="btn btn-sm btn-outline-primary">Dettaglio</a>`
            : "—";

        tr.innerHTML = `
            <td class="text-truncate" style="max-width:180px" title="${escHtml(fileName)}">${escHtml(fileName)}</td>
            <td><span class="badge ${badgeClass}">${badgeLbl}</span></td>
            <td>${escHtml(data.cedente || "—")}</td>
            <td class="text-center">${data.n_linee ?? "—"}</td>
            <td class="small text-muted">${escHtml(data.message || "")}</td>
            <td>${azione}</td>
        `;
    }

    function escHtml(s) {
        return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
    }
})();
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
