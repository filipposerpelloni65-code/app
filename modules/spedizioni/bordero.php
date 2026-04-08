<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';
require_once __DIR__ . '/../../includes/brt_api.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Gestione Bordero');
define('BREADCRUMB', [
    'Dashboard'  => APP_URL . '/dashboard.php',
    'Spedizioni' => APP_URL . '/modules/spedizioni/index.php',
    'Bordero'    => '',
]);

$db         = getDB();
$user       = currentUser();
$brtReady   = (getBrtApi() !== null);
$csrfToken  = generateCsrfToken();

// ── Tab: bozza list (default) ─────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'da_trasmettere';

// Load bozza / da_spedire shipments for transmission
$bozzaStmt = $db->query("
    SELECT s.*,
        t.title AS ticket_title,
        d.name  AS dealer_name,
        dl.name AS location_name,
        sp.name AS part_name
    FROM spedizioni s
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
    LEFT JOIN spare_parts sp ON spr.part_id = sp.id
    WHERE s.status IN ('bozza','da_spedire')
    ORDER BY s.created_at DESC
");
$bozzaList = $bozzaStmt->fetchAll();

// Count bozza-only for badge (from loaded list, which is already filtered)
$bozzaCount = 0;
foreach ($bozzaList as $_bs) { if ($_bs['status'] === 'bozza') { $bozzaCount++; } }

// ── Tab: archivio borderi ─────────────────────────────────────────────────
$borderiStmt = $db->query("
    SELECT b.*, u.full_name AS creator_name
    FROM borderi b
    LEFT JOIN users u ON b.created_by = u.id
    ORDER BY b.created_at DESC
    LIMIT 100
");
$borderi = $borderiStmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-warning"></i>Gestione Bordero BRT</h4>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/spedizioni/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuova Spedizione</a>
        <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista Spedizioni</a>
    </div>
</div>

<?php if (!$brtReady): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Le credenziali BRT non sono configurate. Le spedizioni possono essere marcate come "Da Spedire" ma non sarà possibile ottenere tracking e etichette. Configura BRT in <a href="<?= APP_URL ?>/modules/settings/index.php">Impostazioni</a>.</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="borderoTabs">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'da_trasmettere' ? 'active' : '' ?>" href="?tab=da_trasmettere">
            <i class="bi bi-send me-1"></i>Da Trasmettere
            <?php if ($bozzaCount): ?><span class="badge bg-warning text-dark ms-1"><?= $bozzaCount ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'archivio' ? 'active' : '' ?>" href="?tab=archivio">
            <i class="bi bi-archive me-1"></i>Archivio Borderi
            <span class="badge bg-secondary ms-1"><?= count($borderi) ?></span>
        </a>
    </li>
</ul>

<!-- ═══════════════════════════ TAB: DA TRASMETTERE ═══════════════════════════ -->
<?php if ($activeTab === 'da_trasmettere'): ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Spedizioni in Bozza / Da Spedire</h6>
        <div class="d-flex gap-2">
            <button id="selectAll" class="btn btn-outline-secondary btn-sm">Seleziona Tutto</button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!$bozzaList): ?>
        <p class="text-center text-muted py-5"><i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>Nessuna spedizione in attesa di trasmissione.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px"><input type="checkbox" id="checkAll" class="form-check-input"></th>
                        <th>#</th>
                        <th>Stato</th>
                        <th>Destinatario</th>
                        <th>Corriere</th>
                        <th>Colli</th>
                        <th>Peso</th>
                        <th>Ticket</th>
                        <th>Ricambio</th>
                        <th>Note</th>
                        <th>Data Creazione</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bozzaList as $s): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input sped-check" value="<?= $s['id'] ?>"></td>
                    <td class="text-muted small"><a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $s['id'] ?>" class="text-decoration-none">#<?= $s['id'] ?></a></td>
                    <td><?= getSpedizioneStatusBadge($s['status']) ?></td>
                    <td class="small">
                        <?php if ($s['dealer_name']): ?>
                        <div class="fw-semibold"><?= h($s['dealer_name']) ?></div>
                        <?php if ($s['location_name']): ?><div class="text-muted"><?= h($s['location_name']) ?></div><?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                        <?php if (!empty($s['brt_consignee_json'])): ?>
                        <?php $bcd = json_decode($s['brt_consignee_json'], true) ?? []; ?>
                        <div class="text-muted" style="font-size:.75rem"><?= h($bcd['consigneeZIPCode'] ?? '') ?> <?= h($bcd['consigneeCity'] ?? '') ?> <?= h($bcd['consigneeProvinceAbbreviation'] ?? '') ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($s['corriere'] ?: '—') ?></td>
                    <td class="small"><?= (int)($s['num_colli'] ?? 1) ?></td>
                    <td class="small"><?= number_format((float)($s['peso_kg'] ?? 1), 2) ?> kg</td>
                    <td class="small">
                        <?php if ($s['ticket_id']): ?>
                        <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $s['ticket_id'] ?>" class="text-decoration-none"><?= h(getTicketPrefix().'-'.str_pad($s['ticket_id'],4,'0',STR_PAD_LEFT)) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="small"><?= $s['part_name'] ? h($s['part_name']) : '—' ?></td>
                    <td class="small text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($s['note'] ?? '') ?></td>
                    <td class="small text-muted"><?= formatDate($s['created_at'], 'd/m/Y') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($bozzaList): ?>
<!-- Action panel -->
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label fw-semibold">Note Bordero (opzionale)</label>
                <input type="text" id="borderoNote" class="form-control" placeholder="Es. Bordero del giorno — pomeriggio">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="salvaBordero" checked>
                    <label class="form-check-label" for="salvaBordero">Salva bordero in archivio</label>
                </div>
            </div>
            <div class="col-md-4 d-flex gap-2 justify-content-end">
                <button id="btnTrasmetti" class="btn btn-warning fw-semibold" disabled>
                    <i class="bi bi-send me-1"></i>Trasmetti a BRT
                </button>
                <button id="btnBordero" class="btn btn-outline-primary" disabled>
                    <i class="bi bi-file-earmark-text me-1"></i>Solo Bordero
                </button>
            </div>
        </div>
        <div id="transmitResult" class="mt-3"></div>
    </div>
</div>
<?php endif; ?>

<?php endif; /* tab: da_trasmettere */ ?>

<!-- ═══════════════════════════ TAB: ARCHIVIO BORDERI ═══════════════════════════ -->
<?php if ($activeTab === 'archivio'): ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$borderi): ?>
        <p class="text-center text-muted py-5"><i class="bi bi-archive fs-1 d-block mb-2"></i>Nessun bordero archiviato.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Creato da</th>
                        <th>Spedizioni</th>
                        <th>Note</th>
                        <th>Creato il</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($borderi as $b): ?>
                <tr>
                    <td class="text-muted small"><?= (int)$b['id'] ?></td>
                    <td class="fw-semibold"><?= formatDate($b['data_bordero'], 'd/m/Y') ?></td>
                    <td class="small"><?= h($b['creator_name'] ?? '') ?></td>
                    <td><span class="badge bg-secondary"><?= (int)$b['shipped_count'] ?> sped.</span></td>
                    <td class="small text-muted"><?= h($b['note'] ?? '') ?></td>
                    <td class="small text-muted"><?= formatDate($b['created_at'], 'd/m/Y H:i') ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?= APP_URL ?>/modules/spedizioni/bordero_print.php?id=<?= $b['id'] ?>" class="btn btn-outline-primary" target="_blank" title="Visualizza/Stampa Bordero"><i class="bi bi-printer"></i></a>
                            <?php
                            $bIds = json_decode($b['spedizioni_ids'], true) ?? [];
                            if ($bIds):
                            $idsParam = implode(',', array_map('intval', $bIds));
                            ?>
                            <a href="<?= APP_URL ?>/api/brt_labels_a4.php?ids=<?= $idsParam ?>" class="btn btn-outline-secondary" target="_blank" title="Etichette A4"><i class="bi bi-tag"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; /* tab: archivio */ ?>

<?php
$extraJs = '<script>
(function(){
    var CSRF   = ' . json_encode($csrfToken) . ';
    var appUrl = ' . json_encode(APP_URL) . ';

    // Select-all checkbox
    var checkAll = document.getElementById("checkAll");
    if (checkAll) {
        checkAll.addEventListener("change", function(){
            document.querySelectorAll(".sped-check").forEach(function(c){ c.checked = checkAll.checked; });
            updateButtons();
        });
        document.querySelectorAll(".sped-check").forEach(function(c){
            c.addEventListener("change", updateButtons);
        });
    }

    function getSelected(){
        return Array.from(document.querySelectorAll(".sped-check:checked")).map(function(c){ return parseInt(c.value); });
    }

    function updateButtons(){
        var sel = getSelected().length > 0;
        var btnT = document.getElementById("btnTrasmetti");
        var btnB = document.getElementById("btnBordero");
        if (btnT) btnT.disabled = !sel;
        if (btnB) btnB.disabled = !sel;
    }

    // Trasmetti a BRT
    var btnT = document.getElementById("btnTrasmetti");
    if (btnT) {
        btnT.addEventListener("click", function(){
            var ids = getSelected();
            if (!ids.length) return;
            if (!confirm("Trasmettere " + ids.length + " spedizione/i a BRT e generare il bordero?")) return;
            transmit(ids, true);
        });
    }

    // Solo bordero (senza trasmissione BRT)
    var btnB = document.getElementById("btnBordero");
    if (btnB) {
        btnB.addEventListener("click", function(){
            var ids = getSelected();
            if (!ids.length) return;
            openBorderoPrint(ids, null);
        });
    }

    function transmit(ids, callBrt) {
        var btnT = document.getElementById("btnTrasmetti");
        var note = (document.getElementById("borderoNote") || {}).value || "";
        var salva = (document.getElementById("salvaBordero") || {}).checked !== false;
        var result = document.getElementById("transmitResult");

        if (btnT) { btnT.disabled = true; btnT.innerHTML = \'<span class="spinner-border spinner-border-sm me-1"></span>Trasmissione in corso...\'; }
        if (result) result.innerHTML = "";

        fetch(appUrl + "/api/brt_transmit.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({ ids: ids, csrf_token: CSRF, note: note, salva_bordero: salva })
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (btnT) { btnT.disabled = false; btnT.innerHTML = \'<i class="bi bi-send me-1"></i>Trasmetti a BRT\'; }

            if (!data.success) {
                if (result) result.innerHTML = \'<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>\' + (data.error || "Errore") + \'</div>\';
                return;
            }

            // Build result HTML
            var html = \'<div class="alert \' + (data.all_ok ? "alert-success" : "alert-warning") + \'">\';
            html += \'<strong>\' + (data.all_ok ? "✅ Tutte le spedizioni trasmesse con successo!" : "⚠️ Alcune spedizioni non sono state trasmesse.") + \'</strong><ul class="mb-0 mt-2">\';
            (data.results || []).forEach(function(r){
                if (r.skipped) {
                    html += \'<li>Spedizione #\' + r.id + \': già trasmessa — \' + (r.tracking || "no tracking") + \'</li>\';
                } else if (r.ok) {
                    html += \'<li>Spedizione #\' + r.id + \': ✅ tracking <strong>\' + (r.tracking || "—") + \'</strong></li>\';
                } else {
                    html += \'<li class="text-danger">Spedizione #\' + r.id + \': ❌ \' + (r.error || "Errore") + \'</li>\';
                }
            });
            html += \'</ul></div>\';

            // Action buttons after transmission
            var okIds = (data.results || []).filter(function(r){ return r.ok; }).map(function(r){ return r.id; });
            if (okIds.length) {
                html += \'<div class="d-flex gap-2 flex-wrap">\';
                if (data.bordero_id) {
                    html += \'<a href="\' + appUrl + \'/modules/spedizioni/bordero_print.php?id=\' + data.bordero_id + \'" target="_blank" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Stampa Bordero</a>\';
                    html += \'<a href="\' + appUrl + \'/api/brt_labels_a4.php?bordero_id=\' + data.bordero_id + \'" target="_blank" class="btn btn-outline-primary"><i class="bi bi-tag me-1"></i>Stampa Etichette A4</a>\';
                } else {
                    html += \'<a href="\' + appUrl + \'/api/brt_labels_a4.php?ids=\' + okIds.join(",") + \'" target="_blank" class="btn btn-outline-primary"><i class="bi bi-tag me-1"></i>Stampa Etichette A4</a>\';
                }
                html += \'<button onclick="location.reload()" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Aggiorna pagina</button>\';
                html += \'</div>\';
            }

            if (result) result.innerHTML = html;
        })
        .catch(function(err){
            if (btnT) { btnT.disabled = false; btnT.innerHTML = \'<i class="bi bi-send me-1"></i>Trasmetti a BRT\'; }
            if (result) result.innerHTML = \'<div class="alert alert-danger">Errore di connessione: \' + err + \'</div>\';
        });
    }

    function openBorderoPrint(ids, borderoId) {
        var url;
        if (borderoId) {
            url = appUrl + "/modules/spedizioni/bordero_print.php?id=" + borderoId;
        } else {
            url = appUrl + "/modules/spedizioni/bordero_print.php?ids=" + ids.join(",");
        }
        window.open(url, "_blank");
    }
}());
</script>';
?>
<?php include APP_ROOT . '/includes/footer.php'; ?>
