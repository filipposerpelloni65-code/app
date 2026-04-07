<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('rapportini')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit; }

$stmt = $db->prepare("
    SELECT r.*,
        ut.full_name AS technician_name,
        uc.full_name AS creator_name,
        d.name AS dealer_name, d.address AS dealer_address, d.city AS dealer_city,
        dl.name AS location_name,
        t.title AS ticket_title
    FROM rapportini r
    LEFT JOIN users ut ON r.technician_id = ut.id
    LEFT JOIN users uc ON r.created_by = uc.id
    LEFT JOIN dealers d ON r.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON r.location_id = dl.id
    LEFT JOIN tickets t ON r.ticket_id = t.id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit; }

// Access control: regular users can only see rapportini they created or are assigned as technician
if ($user['role'] === 'user' && $r['created_by'] != $user['id'] && $r['technician_id'] != $user['id']) {
    header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit;
}

$errors = [];

// Handle sign action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sign') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }
    $signatureData = trim($_POST['signature_data'] ?? '');
    $signedByName  = trim($_POST['signed_by_name'] ?? '');
    if (!$signatureData || strpos($signatureData, 'data:image/png;base64,') !== 0) {
        $errors[] = 'La firma è obbligatoria. Disegnare la firma nel riquadro.';
    }
    if (!$signedByName) { $errors[] = 'Il nome del firmatario è obbligatorio.'; }
    if (!$errors && $r['status'] === 'draft') {
        $stmt2 = $db->prepare("UPDATE rapportini SET status='signed', signature_data=?, signed_by_name=?, signed_at=NOW(), updated_at=NOW() WHERE id=?");
        $stmt2->execute([$signatureData, $signedByName, $id]);
        logActivity($user['id'], 'sign', 'rapportino', $id, "Rapportino firmato da: $signedByName");
        header('Location: ' . APP_URL . '/modules/rapportini/view.php?id=' . $id . '&signed=1');
        exit;
    }
}

// Handle archive action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive' && isTechnician()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }
    if (!$errors && $r['status'] === 'signed') {
        $db->prepare("UPDATE rapportini SET status='archived', updated_at=NOW() WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'archive', 'rapportino', $id, 'Rapportino archiviato');
        header('Location: ' . APP_URL . '/modules/rapportini/view.php?id=' . $id . '&archived=1');
        exit;
    }
}

// Reload after post to get fresh data
$stmt->execute([$id]);
$r = $stmt->fetch();

$appName = defined('APP_NAME') ? APP_NAME : 'HelpDesk';
$rapNum  = 'RAP-' . str_pad($id, 4, '0', STR_PAD_LEFT);

define('PAGE_TITLE', 'Rapportino ' . $rapNum);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Rapportini' => APP_URL.'/modules/rapportini/index.php', 'Dettaglio' => '']);

$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Signature Pad setup
    var canvas = document.getElementById("signatureCanvas");
    if (canvas) {
        var signaturePad = new SignaturePad(canvas, { backgroundColor: "rgb(255,255,255)" });
        function resizeCanvas() {
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            var rect  = canvas.getBoundingClientRect();
            canvas.width  = rect.width  * ratio;
            canvas.height = rect.height * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear();
        }
        window.addEventListener("resize", resizeCanvas);
        resizeCanvas();
        document.getElementById("clearSignature").addEventListener("click", function(e) {
            e.preventDefault(); signaturePad.clear();
        });
        document.getElementById("signForm").addEventListener("submit", function(e) {
            if (signaturePad.isEmpty()) {
                e.preventDefault();
                alert("Per favore, disegna la firma prima di procedere.");
                return;
            }
            document.getElementById("signatureDataInput").value = signaturePad.toDataURL("image/png");
        });
    }

    // PDF generation
    var pdfBtn = document.getElementById("downloadPdf");
    if (pdfBtn) {
        pdfBtn.addEventListener("click", function() {
            var element = document.getElementById("rapportinoPrint");
            var filename = "' . addslashes($rapNum) . '.pdf";
            var opt = {
                margin: [10, 10, 10, 10],
                filename: filename,
                image: { type: "jpeg", quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: "mm", format: "a4", orientation: "portrait" }
            };
            pdfBtn.disabled = true;
            pdfBtn.innerHTML = \'<span class="spinner-border spinner-border-sm me-1"></span>Generazione...\';
            html2pdf().set(opt).from(element).save().then(function() {
                pdfBtn.disabled = false;
                pdfBtn.innerHTML = \'<i class="bi bi-file-pdf me-1"></i>Scarica PDF\';
            });
        });
    }
});
</script>
<style>
#signatureCanvas {
    border: 2px dashed #6c757d; border-radius: 4px;
    width: 100%; height: 160px; cursor: crosshair; touch-action: none; background: #fff;
}
#rapportinoPrint { font-family: Arial, sans-serif; color: #000; font-size: 13px; }
#rapportinoPrint .sec-title { font-weight: bold; border-left: 4px solid #0d6efd; padding-left: 8px; margin: 14px 0 8px; font-size: 14px; }
#rapportinoPrint .info-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
#rapportinoPrint .info-table td { padding: 5px 10px; border: 1px solid #ccc; }
#rapportinoPrint .info-table td:first-child { font-weight: bold; background: #f8f8f8; width: 35%; }
#rapportinoPrint .work-box { border: 1px solid #ccc; padding: 10px; min-height: 80px; white-space: pre-wrap; }
#rapportinoPrint .sig-box { border: 1px solid #999; min-height: 80px; display: flex; align-items: center; justify-content: center; padding: 4px; }
</style>
';

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Rapportino creato con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Modifiche salvate con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['signed'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-pen me-2"></i>Rapportino firmato digitalmente con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['archived'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-archive me-2"></i>Rapportino archiviato con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i><?= h($rapNum) ?></h4>
        <small class="text-muted"><?= h($r['title']) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button id="downloadPdf" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-pdf me-1"></i>Scarica PDF</button>
        <?php if (isTechnician() && $r['status'] === 'draft'): ?>
        <a href="<?= APP_URL ?>/modules/rapportini/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/rapportini/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    </div>
</div>

<div class="row g-4">
    <!-- Main printable content -->
    <div class="col-lg-8">

        <div id="rapportinoPrint" class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">

                <!-- Document Header -->
                <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
                    <div>
                        <div class="fw-bold fs-5"><?= h($appName) ?></div>
                        <div class="text-muted small">Rapportino di Lavoro</div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold fs-5 text-primary"><?= h($rapNum) ?></div>
                        <div class="small text-muted">Del <?= formatDate($r['created_at'], 'd/m/Y') ?></div>
                    </div>
                </div>

                <!-- Dati Intervento -->
                <div class="sec-title"><i class="bi bi-info-circle me-1"></i>Dati Intervento</div>
                <table class="info-table table table-sm table-bordered">
                    <tbody>
                        <tr><td class="fw-semibold bg-light" style="width:35%">Data Intervento</td><td><?= formatDate($r['intervention_date'], 'd/m/Y') ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Tecnico</td><td><?= h($r['technician_name'] ?? '-') ?></td></tr>
                        <?php if ($r['customer_name']): ?>
                        <tr><td class="fw-semibold bg-light">Cliente / Referente</td><td><?= h($r['customer_name']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['customer_contact']): ?>
                        <tr><td class="fw-semibold bg-light">Contatto Cliente</td><td><?= h($r['customer_contact']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['dealer_name']): ?>
                        <tr><td class="fw-semibold bg-light">Concessionario</td><td><?= h($r['dealer_name']) . ($r['dealer_city'] ? ' — ' . h($r['dealer_city']) : '') ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['location_name']): ?>
                        <tr><td class="fw-semibold bg-light">Punto Vendita</td><td><?= h($r['location_name']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($r['ticket_title']): ?>
                        <tr><td class="fw-semibold bg-light">Ticket Collegato</td><td><?= h(getTicketPrefix() . '-' . str_pad($r['ticket_id'], 4, '0', STR_PAD_LEFT)) ?> — <?= h($r['ticket_title']) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Descrizione Lavoro -->
                <div class="sec-title"><i class="bi bi-tools me-1"></i>Descrizione Lavoro Eseguito</div>
                <div class="work-box border rounded p-3 mb-3" style="white-space:pre-wrap"><?= h($r['work_description']) ?></div>

                <!-- Parti Utilizzate -->
                <?php if ($r['parts_used']): ?>
                <div class="sec-title"><i class="bi bi-box-seam me-1"></i>Parti / Materiali Utilizzati</div>
                <div class="work-box border rounded p-3 mb-3" style="white-space:pre-wrap"><?= h($r['parts_used']) ?></div>
                <?php endif; ?>

                <!-- Note -->
                <?php if ($r['notes']): ?>
                <div class="sec-title"><i class="bi bi-sticky me-1"></i>Note</div>
                <div class="work-box border rounded p-3 mb-3" style="white-space:pre-wrap"><?= h($r['notes']) ?></div>
                <?php endif; ?>

                <!-- Firma -->
                <div class="sec-title"><i class="bi bi-pen me-1"></i>Firma Cliente</div>
                <?php if ($r['status'] !== 'draft' && $r['signature_data']): ?>
                <div class="sig-box border rounded p-2 mb-1 text-center">
                    <img src="<?= h($r['signature_data']) ?>" alt="Firma" style="max-height:100px; max-width:100%">
                </div>
                <div class="small text-muted mb-3">
                    Firmato da: <strong><?= h($r['signed_by_name']) ?></strong>
                    il <?= formatDate($r['signed_at']) ?>
                </div>
                <?php else: ?>
                <div class="sig-box border rounded p-3 mb-3 text-muted text-center" style="min-height:70px">
                    <em>Non ancora firmato</em>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">

        <!-- Status card -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0">Stato Rapportino</h6></div>
            <div class="card-body">
                <div class="mb-2"><?= getRapportinoStatusBadge($r['status']) ?></div>
                <dl class="row small mb-0">
                    <dt class="col-sm-5">Creato da</dt><dd class="col-sm-7"><?= h($r['creator_name'] ?? '-') ?></dd>
                    <dt class="col-sm-5">Creato il</dt><dd class="col-sm-7"><?= formatDate($r['created_at'], 'd/m/Y H:i') ?></dd>
                    <dt class="col-sm-5">Aggiornato</dt><dd class="col-sm-7"><?= formatDate($r['updated_at'], 'd/m/Y H:i') ?></dd>
                    <?php if ($r['signed_at']): ?>
                    <dt class="col-sm-5">Firmato il</dt><dd class="col-sm-7"><?= formatDate($r['signed_at'], 'd/m/Y H:i') ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Sign form (only for draft) -->
        <?php if ($r['status'] === 'draft'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-pen me-1 text-primary"></i>Firma Digitale</h6></div>
            <div class="card-body">
                <form id="signForm" method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="sign">
                    <input type="hidden" name="signature_data" id="signatureDataInput">
                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Nome Firmatario <span class="text-danger">*</span></label>
                        <input type="text" name="signed_by_name" class="form-control form-control-sm" placeholder="Nome e cognome del cliente" required value="<?= h($r['customer_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Firma <span class="text-danger">*</span></label>
                        <canvas id="signatureCanvas"></canvas>
                        <button id="clearSignature" class="btn btn-sm btn-outline-secondary mt-1 w-100"><i class="bi bi-eraser me-1"></i>Cancella firma</button>
                    </div>
                    <button type="submit" class="btn btn-success w-100 mt-2"><i class="bi bi-check2-circle me-1"></i>Firma e Conferma</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Archive action (signed only, technician) -->
        <?php if ($r['status'] === 'signed' && isTechnician()): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-archive me-1 text-secondary"></i>Archiviazione</h6></div>
            <div class="card-body">
                <p class="small text-muted">Il rapportino è stato firmato. Puoi archiviarlo per completare il processo.</p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="archive">
                    <button type="submit" class="btn btn-outline-secondary w-100" onclick="return confirm('Archiviare il rapportino? Questa azione non può essere annullata.')"><i class="bi bi-archive me-1"></i>Archivia</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
