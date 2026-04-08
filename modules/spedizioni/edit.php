<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

$stmt = $db->prepare("
    SELECT s.*,
        t.title AS ticket_title,
        spr.id AS request_id, sp.name AS part_name,
        d.name AS dealer_name, dl.name AS location_name
    FROM spedizioni s
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
    LEFT JOIN spare_parts sp ON spr.part_id = sp.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$s = $stmt->fetch();
if (!$s) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

define('PAGE_TITLE', 'Modifica Spedizione #' . $id);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Modifica' => '']);

$errors = [];
$tickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 200")->fetchAll();
$partRequests = $db->query("SELECT spr.id, sp.name AS part_name, spr.quantity FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.status IN ('approved','pending') ORDER BY spr.id DESC LIMIT 200")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$locations = [];
if ($s['dealer_id']) {
    $lStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $lStmt->execute([$s['dealer_id']]);
    $locations = $lStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }

    $tracking     = trim($_POST['tracking_number'] ?? '');
    $corriere     = trim($_POST['corriere'] ?? '');
    $status       = $_POST['status'] ?? 'da_spedire';
    $ticketId     = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $sprId        = (int)($_POST['spare_parts_request_id'] ?? 0) ?: null;
    $dealerId     = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $locationId   = (int)($_POST['location_id'] ?? 0) ?: null;
    $note         = trim($_POST['note'] ?? '');
    $dataSped     = trim($_POST['data_spedizione'] ?? '') ?: null;
    $dataConsegna = trim($_POST['data_consegna_prevista'] ?? '') ?: null;

    if (!in_array($status, ['bozza','da_spedire','spedita','consegnata','annullata'])) { $errors[] = 'Stato non valido.'; }

    if (!$errors) {
        $db->prepare("UPDATE spedizioni SET tracking_number=?, corriere=?, status=?, ticket_id=?, spare_parts_request_id=?, dealer_id=?, location_id=?, note=?, data_spedizione=?, data_consegna_prevista=?, updated_at=NOW() WHERE id=?")
           ->execute([$tracking ?: null, $corriere ?: null, $status, $ticketId, $sprId, $dealerId, $locationId, $note ?: null, $dataSped, $dataConsegna, $id]);
        logActivity($user['id'], 'edit', 'spedizione', $id, "Modificata spedizione #$id -> $status");
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $id . '&updated=1');
        exit;
    }

    // Re-populate on error
    $s['tracking_number'] = $tracking;
    $s['corriere']         = $corriere;
    $s['status']           = $status;
    $s['ticket_id']        = $ticketId;
    $s['spare_parts_request_id'] = $sprId;
    $s['dealer_id']        = $dealerId;
    $s['location_id']      = $locationId;
    $s['note']             = $note;
    $s['data_spedizione']  = $dataSped;
    $s['data_consegna_prevista'] = $dataConsegna;
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Modifica Spedizione #<?= $id ?></h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Dettaglio</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Numero Tracking</label>
                    <input type="text" name="tracking_number" class="form-control font-monospace" value="<?= h($s['tracking_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Corriere</label>
                    <input type="text" name="corriere" class="form-control" value="<?= h($s['corriere'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Stato <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="bozza" <?= ($s['status'] ?? '') === 'bozza' ? 'selected' : '' ?>>Bozza</option>
                        <option value="da_spedire" <?= ($s['status'] ?? '') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                        <option value="spedita" <?= ($s['status'] ?? '') === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                        <option value="consegnata" <?= ($s['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                        <option value="annullata" <?= ($s['status'] ?? '') === 'annullata' ? 'selected' : '' ?>>Annullata</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Spedizione</label>
                    <input type="date" name="data_spedizione" class="form-control" value="<?= h($s['data_spedizione'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Consegna Prevista</label>
                    <input type="date" name="data_consegna_prevista" class="form-control" value="<?= h($s['data_consegna_prevista'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ticket Collegato</label>
                    <select name="ticket_id" class="form-select">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($tickets as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($s['ticket_id'] ?? '') == $t['id'] ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT)) ?> — <?= h($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Richiesta Ricambio</label>
                    <select name="spare_parts_request_id" class="form-select">
                        <option value="">-- Nessuna --</option>
                        <?php foreach ($partRequests as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($s['spare_parts_request_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>#<?= $r['id'] ?> — <?= h($r['part_name']) ?> (qty: <?= $r['quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Concessionario</label>
                    <select name="dealer_id" class="form-select" id="dealerSelect">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($dealers as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($s['dealer_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Punto Vendita</label>
                    <select name="location_id" class="form-select" id="locationSelect">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= ($s['location_id'] ?? '') == $l['id'] ? 'selected' : '' ?>><?= h($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Note</label>
                    <textarea name="note" class="form-control" rows="3"><?= h($s['note'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
                <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
    window.appUrl = "' . APP_URL . '";
    $("#dealerSelect").on("change", function(){
        var did = $(this).val();
        var $loc = $("#locationSelect");
        $loc.html("<option value=\"\">-- Nessuno --</option>");
        if (!did) return;
        $.getJSON(window.appUrl + "/api/parts.php?action=dealer_locations&dealer_id=" + did, function(data){
            if (data.success) {
                $.each(data.data, function(i, l){
                    $loc.append("<option value=\""+l.id+"\">"+l.name+"</option>");
                });
            }
        });
    });
});
</script>';
?>
<?php include APP_ROOT . '/includes/footer.php'; ?>
