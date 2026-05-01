<?php
declare(strict_types=1);

// Carica dipendenze senza output (le POST devono rispondere prima dell'HTML)
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::init();
Auth::requireLogin();

$idAzienda = Auth::getIdAzienda();
$idFattura = (int)($_GET['id'] ?? 0);

// ── Gestione POST (prima di qualsiasi output HTML) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = hash_equals(Auth::csrfToken(), $_POST['csrf_token'] ?? '');

    if (!$csrfOk) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'CSRF non valido.']);
            exit;
        }
        http_response_code(419);
        die('CSRF token non valido.');
    }

    $action = $_POST['action'] ?? '';

    // Cambio stato (POST normale, redirect)
    if ($action === 'cambia_stato') {
        $statiValidi = ['importata','verificata','contabilizzata','pagata','annullata'];
        $s = $_POST['stato'] ?? '';
        if (in_array($s, $statiValidi, true) && $idAzienda && $idFattura) {
            Database::execute(
                'UPDATE fatture_elettroniche SET stato=? WHERE id=? AND id_azienda=?',
                [$s, $idFattura, $idAzienda]
            );
        }
        header('Location: dettaglio.php?id=' . $idFattura);
        exit;
    }

    // Conferma tutto (POST normale, redirect)
    if ($action === 'conferma_tutto' && Auth::canWrite() && $idAzienda && $idFattura) {
        Database::execute(
            'UPDATE fatture_linee SET classificazione_confermata=1
             WHERE id_fattura=? AND id_azienda=?',
            [$idFattura, $idAzienda]
        );
        header('Location: dettaglio.php?id=' . $idFattura . '&saved=1');
        exit;
    }

    // Salva classificazione (AJAX)
    if ($action === 'salva_classificazione' && Auth::canWrite() && $idAzienda && $idFattura) {
        $stopWords = ['della','dello','delle','degli','del','dei','una','uno','per','con','che',
                      'dal','dai','nel','nei','gli','alle','alla','sul','sui','tra','fra','anche',
                      'come','dopo','prima','ogni','questo','questa','questi','queste','anno',
                      'mese','data','numero','codice','fatt','doc'];
        $lineePost = $_POST['linee'] ?? [];
        foreach ($lineePost as $idLinea => $dati) {
            $idLinea  = (int)$idLinea;
            $idConto  = isset($dati['id_conto'])  && $dati['id_conto']  !== '' ? (int)$dati['id_conto']  : null;
            $idCentro = isset($dati['id_centro']) && $dati['id_centro'] !== '' ? (int)$dati['id_centro'] : null;
            $conferm  = isset($dati['confermata']) ? 1 : 0;

            Database::execute(
                'UPDATE fatture_linee
                 SET id_conto=?, id_centro_costo=?, classificazione_confermata=?
                 WHERE id=? AND id_fattura=? AND id_azienda=?',
                [$idConto, $idCentro, $conferm, $idLinea, $idFattura, $idAzienda]
            );

            // Apprendimento keyword automatico quando confermato con un conto
            if ($conferm && $idConto) {
                $riga = Database::fetchOne(
                    'SELECT descrizione FROM fatture_linee WHERE id=? AND id_azienda=?',
                    [$idLinea, $idAzienda]
                );
                if ($riga) {
                    $parole = preg_split('/[\s\-\/\(\)\.,;:]+/', mb_strtolower($riga['descrizione'], 'UTF-8'));
                    foreach ($parole as $p) {
                        $p = trim($p);
                        if (mb_strlen($p) >= 4 && !in_array($p, $stopWords, true) && !is_numeric($p)) {
                            Database::execute(
                                'INSERT IGNORE INTO keyword_conto (id_azienda, id_conto, keyword, peso)
                                 VALUES (?,?,?,1)',
                                [$idAzienda, $idConto, $p]
                            );
                        }
                    }
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}
// ── Fine POST ────────────────────────────────────────────────────────────────

$pageTitle  = 'Dettaglio fattura';
$activePage = 'fatture';
require_once dirname(__DIR__) . '/layout/header.php';

if (!$idAzienda || !$idFattura) {
    echo '<div class="alert alert-danger">Parametri non validi.</div>';
    require_once dirname(__DIR__) . '/layout/footer.php';
    exit;
}

$fattura = Database::fetchOne(
    'SELECT fe.*, cp.denominazione, cp.nome as cp_nome, cp.cognome as cp_cognome,
            cp.id_codice, cp.sede_comune, cp.sede_provincia, cp.regime_fiscale
     FROM fatture_elettroniche fe
     JOIN cedenti_prestatori cp ON cp.id = fe.id_cedente
     WHERE fe.id = ? AND fe.id_azienda = ?',
    [$idFattura, $idAzienda]
);

if (!$fattura) {
    echo '<div class="alert alert-danger">Fattura non trovata.</div>';
    require_once dirname(__DIR__) . '/layout/footer.php';
    exit;
}

$csrfToken = Auth::csrfToken();

$linee = Database::fetchAll(
    'SELECT fl.*,
            pc.codice AS conto_codice, pc.descrizione AS conto_desc,
            cc.codice AS cc_codice,   cc.descrizione  AS cc_desc
     FROM fatture_linee fl
     LEFT JOIN piano_conti pc ON pc.id = fl.id_conto
     LEFT JOIN centri_costo cc ON cc.id = fl.id_centro_costo
     WHERE fl.id_fattura=? AND fl.id_azienda=? ORDER BY fl.numero_linea',
    [$idFattura, $idAzienda]
);

$riepilogoIva = Database::fetchAll(
    'SELECT * FROM fatture_riepilogo_iva WHERE id_fattura=? ORDER BY aliquota_iva',
    [$idFattura]
);

$opzioniConti = Database::fetchAll(
    'SELECT id, codice, descrizione FROM piano_conti
     WHERE id_azienda=? AND livello=3 AND attivo=1 ORDER BY codice',
    [$idAzienda]
);

$opzioniCentri = Database::fetchAll(
    'SELECT id, codice, descrizione FROM centri_costo
     WHERE id_azienda=? AND attivo=1 ORDER BY ordine, codice',
    [$idAzienda]
);

$stati = ['importata','verificata','contabilizzata','pagata','annullata'];
$statiBadge = [
    'importata'      => 'bg-warning text-dark',
    'verificata'     => 'bg-info text-dark',
    'contabilizzata' => 'bg-primary',
    'pagata'         => 'bg-success',
    'annullata'      => 'bg-danger',
];

$nomeFornitore = !empty($fattura['denominazione'])
    ? $fattura['denominazione']
    : trim(($fattura['cp_cognome'] ?? '') . ' ' . ($fattura['cp_nome'] ?? ''));
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="bi bi-check-circle me-2"></i>Classificazione salvata con successo.
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Header fattura -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
          <div>
            <h5 class="mb-0 fw-bold">Fattura n. <?= htmlspecialchars($fattura['numero_documento']) ?></h5>
            <div class="text-muted small">
              del <?= htmlspecialchars($fattura['data_documento']) ?> &mdash;
              <span class="badge bg-light text-dark border"><?= htmlspecialchars($fattura['tipo_documento']) ?></span>
            </div>
          </div>
          <span class="badge fs-6 <?= $statiBadge[$fattura['stato']] ?? 'bg-secondary' ?>">
            <?= ucfirst($fattura['stato']) ?>
          </span>
        </div>
        <div class="row g-2 small">
          <div class="col-sm-6">
            <div class="fw-semibold text-muted text-uppercase small mb-1">Fornitore</div>
            <div class="fw-semibold"><?= htmlspecialchars($nomeFornitore) ?></div>
            <div class="text-muted">P.IVA: <?= htmlspecialchars($fattura['id_codice']) ?></div>
            <div class="text-muted">
              <?= htmlspecialchars($fattura['sede_comune'] ?? '') ?>
              <?= $fattura['sede_provincia'] ? '(' . htmlspecialchars($fattura['sede_provincia']) . ')' : '' ?>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="fw-semibold text-muted text-uppercase small mb-1">Importo</div>
            <div class="fw-bold fs-5 text-primary">
              € <?= number_format((float)$fattura['importo_totale'], 2, ',', '.') ?>
            </div>
            <div class="text-muted">Divisa: <?= htmlspecialchars($fattura['divisa']) ?></div>
          </div>
          <?php if ($fattura['causale']): ?>
          <div class="col-12">
            <div class="fw-semibold text-muted text-uppercase small mb-1">Causale</div>
            <div><?= htmlspecialchars($fattura['causale']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($fattura['data_scadenza_pagamento']): ?>
          <div class="col-sm-6">
            <div class="fw-semibold text-muted text-uppercase small mb-1">Pagamento</div>
            <div>
              <?= htmlspecialchars($fattura['modalita_pagamento'] ?? '') ?>
              — scadenza <?= htmlspecialchars($fattura['data_scadenza_pagamento']) ?>
            </div>
            <?php if ($fattura['iban']): ?>
            <div class="text-muted">IBAN: <?= htmlspecialchars($fattura['iban']) ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Cambio stato -->
  <div class="col-lg-4">
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <div class="fw-semibold mb-2 small text-muted text-uppercase">Cambia stato</div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="action" value="cambia_stato">
          <select name="stato" class="form-select form-select-sm mb-2">
            <?php foreach ($stati as $s): ?>
            <option value="<?= $s ?>" <?= $fattura['stato'] === $s ? 'selected' : '' ?>>
              <?= ucfirst($s) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
            <i class="bi bi-check2 me-1"></i>Applica
          </button>
        </form>
        <hr class="my-2">
        <a href="lista.php" class="btn btn-sm btn-outline-secondary w-100">
          <i class="bi bi-arrow-left me-1"></i>Torna all'elenco
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Righe fattura -->
<div class="card shadow-sm border-0 mb-3">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Righe fattura</span>
    <?php if (Auth::canWrite()): ?>
    <div class="d-flex gap-2">
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="conferma_tutto">
        <button type="submit" class="btn btn-sm btn-success">
          <i class="bi bi-check-all me-1"></i>Conferma tutto
        </button>
      </form>
      <button id="btnSalva" type="button" class="btn btn-sm btn-primary">
        <i class="bi bi-save me-1"></i>Salva classificazione
      </button>
    </div>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <form id="formClassifica" method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="salva_classificazione">
      <table class="table table-gh table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th class="text-center" style="width:40px">#</th>
            <th>Descrizione</th>
            <th class="text-end">Totale €</th>
            <th class="text-center">IVA%</th>
            <th style="min-width:220px">Conto</th>
            <th style="min-width:160px">Centro costo</th>
            <th class="text-center">OK</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($linee as $linea): ?>
        <tr class="<?= !$linea['classificazione_confermata'] ? 'table-warning' : '' ?>">
          <td class="text-center"><?= (int)$linea['numero_linea'] ?></td>
          <td>
            <div><?= htmlspecialchars($linea['descrizione']) ?></div>
            <?php if ($linea['quantita'] !== null): ?>
            <div class="small text-muted">
              Qtà: <?= number_format((float)$linea['quantita'], 2, ',', '') ?>
              <?= htmlspecialchars($linea['unita_misura'] ?? '') ?>
              <?= $linea['prezzo_unitario'] !== null ? '× ' . number_format((float)$linea['prezzo_unitario'], 4, ',', '.') : '' ?>
            </div>
            <?php endif; ?>
            <?php if ($linea['data_inizio_periodo']): ?>
            <div class="small text-muted">
              <?= htmlspecialchars($linea['data_inizio_periodo']) ?> — <?= htmlspecialchars($linea['data_fine_periodo'] ?? '') ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="text-end fw-semibold text-nowrap">
            <?= number_format((float)$linea['prezzo_totale'], 2, ',', '.') ?>
          </td>
          <td class="text-center">
            <?= $linea['aliquota_iva'] !== null ? (float)$linea['aliquota_iva'] . '%' : htmlspecialchars($linea['natura_iva'] ?? '—') ?>
          </td>
          <td>
            <?php if (Auth::canWrite()): ?>
            <select name="linee[<?= $linea['id'] ?>][id_conto]" class="form-select form-select-sm">
              <option value="">— nessuno —</option>
              <?php foreach ($opzioniConti as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)$linea['id_conto'] === (int)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['codice'] . ' ' . mb_substr($c['descrizione'], 0, 35)) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <span class="small"><?= htmlspecialchars(($linea['conto_codice'] ?? '') . ' ' . ($linea['conto_desc'] ?? '—')) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (Auth::canWrite()): ?>
            <select name="linee[<?= $linea['id'] ?>][id_centro]" class="form-select form-select-sm">
              <option value="">— nessuno —</option>
              <?php foreach ($opzioniCentri as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)$linea['id_centro_costo'] === (int)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['codice'] . ' ' . $c['descrizione']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <span class="small"><?= htmlspecialchars(($linea['cc_codice'] ?? '') . ' ' . ($linea['cc_desc'] ?? '—')) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if (Auth::canWrite()): ?>
            <input type="checkbox" name="linee[<?= $linea['id'] ?>][confermata]"
                   class="form-check-input"
                   <?= $linea['classificazione_confermata'] ? 'checked' : '' ?>>
            <?php else: ?>
            <i class="bi bi-<?= $linea['classificazione_confermata'] ? 'check-circle-fill text-success' : 'circle text-muted' ?>"></i>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </form>
  </div>
</div>

<!-- Riepilogo IVA -->
<?php if (!empty($riepilogoIva)): ?>
<div class="card shadow-sm border-0">
  <div class="card-header bg-white fw-semibold">
    <i class="bi bi-receipt me-2"></i>Riepilogo IVA
  </div>
  <div class="table-responsive">
    <table class="table table-gh mb-0">
      <thead class="table-light">
        <tr>
          <th>Aliquota IVA</th>
          <th>Natura</th>
          <th class="text-end">Imponibile (€)</th>
          <th class="text-end">Imposta (€)</th>
          <th>Esigibilità</th>
          <th>Rif. normativo</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $totImponibile = 0.0;
      $totImposta    = 0.0;
      foreach ($riepilogoIva as $rip):
          $totImponibile += (float)$rip['imponibile'];
          $totImposta    += (float)$rip['imposta'];
      ?>
      <tr>
        <td><?= $rip['aliquota_iva'] !== null ? (float)$rip['aliquota_iva'] . '%' : '—' ?></td>
        <td><?= htmlspecialchars($rip['natura_iva'] ?? '—') ?></td>
        <td class="text-end"><?= number_format((float)$rip['imponibile'], 2, ',', '.') ?></td>
        <td class="text-end"><?= number_format((float)$rip['imposta'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars($rip['esigibilita_iva'] ?? '—') ?></td>
        <td class="small"><?= htmlspecialchars($rip['riferimento_normativo'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">Totale</td>
          <td class="text-end"><?= number_format($totImponibile, 2, ',', '.') ?></td>
          <td class="text-end"><?= number_format($totImposta, 2, ',', '.') ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = '<script>
(function () {
    const btn  = document.getElementById("btnSalva");
    const form = document.getElementById("formClassifica");
    if (!btn || !form) return;

    btn.addEventListener("click", function () {
        btn.disabled = true;
        btn.innerHTML = \'<span class="spinner-border spinner-border-sm me-1"></span>Salvataggio…\';
        const fd = new FormData(form);
        fetch("dettaglio.php?id=' . $idFattura . '", { method: "POST", body: fd })
        .then(r => {
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
        })
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-save me-1"></i>Salva classificazione\';
            if (data.ok) {
                form.querySelectorAll("input[type=checkbox]:checked").forEach(cb => {
                    cb.closest("tr")?.classList.remove("table-warning");
                });
                const t = document.createElement("div");
                t.className = "alert alert-success alert-dismissible fade show position-fixed top-0 end-0 m-3";
                t.style.zIndex = 9999;
                t.innerHTML = \'<i class="bi bi-check-circle me-2"></i>Salvato.<button type="button" class="btn-close" data-bs-dismiss="alert"></button>\';
                document.body.appendChild(t);
                setTimeout(() => t.remove(), 3000);
            } else {
                alert("Errore: " + (data.error || "sconosciuto"));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-save me-1"></i>Salva classificazione\';
            alert("Errore salvataggio: " + err.message);
        });
    });
})();
</script>';

require_once dirname(__DIR__) . '/layout/footer.php';
