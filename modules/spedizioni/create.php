<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuova Spedizione');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Nuova' => '']);

$db = getDB();
$user = currentUser();
$errors = [];

// Pre-fill ticket_id from query string (e.g. when linked from ticket view)
$preTicketId = (int)($_GET['ticket_id'] ?? 0);

// Load selects
$tickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 200")->fetchAll();
$partRequests = $db->query("SELECT spr.id, sp.name AS part_name, spr.quantity, t.title AS ticket_title FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id LEFT JOIN tickets t ON spr.ticket_id=t.id WHERE spr.status IN ('approved','pending') ORDER BY spr.id DESC LIMIT 200")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }

    $tracking        = trim($_POST['tracking_number'] ?? '');
    $corriere        = trim($_POST['corriere'] ?? '');
    $status          = $_POST['status'] ?? 'da_spedire';
    $ticketId        = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $sprId           = (int)($_POST['spare_parts_request_id'] ?? 0) ?: null;
    $dealerId        = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $locationId      = (int)($_POST['location_id'] ?? 0) ?: null;
    $note            = trim($_POST['note'] ?? '');
    $dataSped        = trim($_POST['data_spedizione'] ?? '') ?: null;
    $dataConsegna    = trim($_POST['data_consegna_prevista'] ?? '') ?: null;

    if (!in_array($status, ['da_spedire','spedita','consegnata','annullata'])) { $errors[] = 'Stato non valido.'; }

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO spedizioni (tracking_number, corriere, status, ticket_id, spare_parts_request_id, dealer_id, location_id, note, data_spedizione, data_consegna_prevista, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$tracking ?: null, $corriere ?: null, $status, $ticketId, $sprId, $dealerId, $locationId, $note ?: null, $dataSped, $dataConsegna, $user['id']]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'spedizione', (int)$newId, "Creata spedizione tracking: $tracking");
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Nuova Spedizione</h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
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
                    <input type="text" name="tracking_number" class="form-control font-monospace" value="<?= h($_POST['tracking_number'] ?? '') ?>" placeholder="Es. GLS1234567890">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Corriere</label>
                    <input type="text" name="corriere" class="form-control" value="<?= h($_POST['corriere'] ?? '') ?>" placeholder="Es. GLS, BRT, DHL...">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Stato <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="da_spedire" <?= ($_POST['status'] ?? 'da_spedire') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                        <option value="spedita" <?= ($_POST['status'] ?? '') === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                        <option value="consegnata" <?= ($_POST['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                        <option value="annullata" <?= ($_POST['status'] ?? '') === 'annullata' ? 'selected' : '' ?>>Annullata</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Spedizione</label>
                    <input type="date" name="data_spedizione" class="form-control" value="<?= h($_POST['data_spedizione'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Consegna Prevista</label>
                    <input type="date" name="data_consegna_prevista" class="form-control" value="<?= h($_POST['data_consegna_prevista'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ticket Collegato</label>
                    <select name="ticket_id" class="form-select">
                        <option value="">-- Nessuno --</option>
                        <?php
                        $selTicket = (int)($_POST['ticket_id'] ?? $preTicketId);
                        foreach ($tickets as $t):
                        ?>
                        <option value="<?= $t['id'] ?>" <?= $selTicket == $t['id'] ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT)) ?> — <?= h($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Richiesta Ricambio</label>
                    <select name="spare_parts_request_id" class="form-select">
                        <option value="">-- Nessuna --</option>
                        <?php foreach ($partRequests as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($_POST['spare_parts_request_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>#<?= $r['id'] ?> — <?= h($r['part_name']) ?> (qty: <?= $r['quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Concessionario</label>
                    <select name="dealer_id" class="form-select" id="dealerSelect">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($dealers as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($_POST['dealer_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Punto Vendita</label>
                    <select name="location_id" class="form-select" id="locationSelect">
                        <option value="">-- Nessuno --</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Note</label>
                    <textarea name="note" class="form-control" rows="3" placeholder="Note aggiuntive sulla spedizione..."><?= h($_POST['note'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Spedizione</button>
                <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary">Annulla</a>
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
