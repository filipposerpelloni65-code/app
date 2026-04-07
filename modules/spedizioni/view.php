<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

$stmt = $db->prepare("SELECT s.*, t.title as ticket_title, d.name as dealer_name, dl.name as location_name, u.full_name as creator_name, spr.id as req_id FROM spedizioni s LEFT JOIN tickets t ON s.ticket_id=t.id LEFT JOIN dealers d ON s.dealer_id=d.id LEFT JOIN dealer_locations dl ON s.location_id=dl.id LEFT JOIN users u ON s.created_by=u.id LEFT JOIN spare_parts_requests spr ON s.request_id=spr.id WHERE s.id=?");
$stmt->execute([$id]);
$spedizione = $stmt->fetch();
if (!$spedizione) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

// Load linked request details
$linkedRequest = null;
if ($spedizione['request_id']) {
    $rs = $db->prepare("SELECT spr.*, sp.name as part_name, sp.sku FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.id=?");
    $rs->execute([$spedizione['request_id']]);
    $linkedRequest = $rs->fetch();
}

define('PAGE_TITLE', 'Spedizione #' . $id);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', '#'.$id => '']);

$errors = [];

// Handle status update (admin/technician)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isTechnician()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token non valido.';
    } else {
        $newStatus        = $_POST['new_status'] ?? $spedizione['status'];
        $corriere         = trim($_POST['corriere'] ?? '');
        $numero_tracking  = trim($_POST['numero_tracking'] ?? '');
        $data_spedizione  = trim($_POST['data_spedizione'] ?? '') ?: null;
        $data_consegna_prevista = trim($_POST['data_consegna_prevista'] ?? '') ?: null;
        $data_consegna    = trim($_POST['data_consegna'] ?? '') ?: null;
        $note             = trim($_POST['note'] ?? '');
        $destinatario     = trim($_POST['destinatario'] ?? '');
        $indirizzo        = trim($_POST['indirizzo_spedizione'] ?? '');
        $mittente         = trim($_POST['mittente'] ?? '');

        if (!in_array($newStatus, ['da_spedire','spedita','consegnata','annullata'])) $newStatus = $spedizione['status'];

        if (!$destinatario) {
            $errors[] = 'Il destinatario è obbligatorio.';
        } else {
            $db->prepare("UPDATE spedizioni SET status=?, corriere=?, numero_tracking=?, data_spedizione=?, data_consegna_prevista=?, data_consegna=?, note=?, destinatario=?, indirizzo_spedizione=?, mittente=?, updated_at=NOW() WHERE id=?")
               ->execute([$newStatus, $corriere ?: null, $numero_tracking ?: null, $data_spedizione, $data_consegna_prevista, $data_consegna, $note ?: null, $destinatario, $indirizzo ?: null, $mittente ?: null, $id]);
            logActivity($user['id'], 'update', 'spedizione', $id, "Stato: $newStatus" . ($numero_tracking ? ", Tracking: $numero_tracking" : ''));
            header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $id . '&updated=1');
            exit;
        }
    }
}

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Spedizione creata con successo. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Spedizione aggiornata. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Main content -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-light text-dark border me-2">SPD-<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></span>
                    <?= getSpedizioneStatusBadge($spedizione['status']) ?>
                </div>
                <span class="small text-muted">Creata il <?= formatDate($spedizione['created_at'], 'd/m/Y H:i') ?> da <?= h($spedizione['creator_name']) ?></span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Destinatario</dt><dd class="col-sm-8"><?= $spedizione['destinatario'] ? h($spedizione['destinatario']) : '-' ?></dd>
                    <dt class="col-sm-4">Indirizzo</dt><dd class="col-sm-8" style="white-space:pre-wrap"><?= $spedizione['indirizzo_spedizione'] ? h($spedizione['indirizzo_spedizione']) : '-' ?></dd>
                    <dt class="col-sm-4">Mittente</dt><dd class="col-sm-8"><?= $spedizione['mittente'] ? h($spedizione['mittente']) : '-' ?></dd>
                    <dt class="col-sm-4">Corriere</dt><dd class="col-sm-8"><?= $spedizione['corriere'] ? h($spedizione['corriere']) : '-' ?></dd>
                    <dt class="col-sm-4">Tracking</dt><dd class="col-sm-8 font-monospace"><?= $spedizione['numero_tracking'] ? h($spedizione['numero_tracking']) : '-' ?></dd>
                    <dt class="col-sm-4">Spedita il</dt><dd class="col-sm-8"><?= $spedizione['data_spedizione'] ? formatDate($spedizione['data_spedizione'], 'd/m/Y') : '-' ?></dd>
                    <dt class="col-sm-4">Consegna Prevista</dt><dd class="col-sm-8"><?= $spedizione['data_consegna_prevista'] ? formatDate($spedizione['data_consegna_prevista'], 'd/m/Y') : '-' ?></dd>
                    <dt class="col-sm-4">Consegnata il</dt><dd class="col-sm-8"><?= $spedizione['data_consegna'] ? formatDate($spedizione['data_consegna'], 'd/m/Y') : '-' ?></dd>
                    <?php if ($spedizione['ticket_title']): ?>
                    <dt class="col-sm-4">Ticket</dt><dd class="col-sm-8"><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $spedizione['ticket_id'] ?>" class="text-decoration-none"><?= h(getTicketPrefix().'-'.str_pad($spedizione['ticket_id'],4,'0',STR_PAD_LEFT)) ?> — <?= h($spedizione['ticket_title']) ?></a></dd>
                    <?php endif; ?>
                    <?php if ($spedizione['dealer_name']): ?>
                    <dt class="col-sm-4">Concessionario</dt><dd class="col-sm-8"><?= h($spedizione['dealer_name']) ?><?= $spedizione['location_name'] ? ' / '.h($spedizione['location_name']) : '' ?></dd>
                    <?php endif; ?>
                    <?php if ($linkedRequest): ?>
                    <dt class="col-sm-4">Parte Ricambio</dt><dd class="col-sm-8"><?= h($linkedRequest['part_name']) ?> <span class="text-muted small">(<?= h($linkedRequest['sku']) ?>)</span> × <?= (int)$linkedRequest['quantity'] ?></dd>
                    <?php endif; ?>
                    <?php if ($spedizione['note']): ?>
                    <dt class="col-sm-4">Note</dt><dd class="col-sm-8" style="white-space:pre-wrap"><?= h($spedizione['note']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <!-- Edit sidebar -->
    <?php if (isTechnician()): ?>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Aggiorna Spedizione</h6></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Stato</label>
                        <select name="new_status" class="form-select form-select-sm">
                            <option value="da_spedire" <?= $spedizione['status']==='da_spedire'?'selected':'' ?>>Da Spedire</option>
                            <option value="spedita" <?= $spedizione['status']==='spedita'?'selected':'' ?>>Spedita</option>
                            <option value="consegnata" <?= $spedizione['status']==='consegnata'?'selected':'' ?>>Consegnata</option>
                            <option value="annullata" <?= $spedizione['status']==='annullata'?'selected':'' ?>>Annullata</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Corriere</label>
                        <input type="text" name="corriere" class="form-control form-control-sm" value="<?= h($spedizione['corriere'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Numero Tracking</label>
                        <input type="text" name="numero_tracking" class="form-control form-control-sm font-monospace" value="<?= h($spedizione['numero_tracking'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Destinatario <span class="text-danger">*</span></label>
                        <input type="text" name="destinatario" class="form-control form-control-sm" required value="<?= h($spedizione['destinatario'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Indirizzo</label>
                        <textarea name="indirizzo_spedizione" class="form-control form-control-sm" rows="2"><?= h($spedizione['indirizzo_spedizione'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Mittente</label>
                        <input type="text" name="mittente" class="form-control form-control-sm" value="<?= h($spedizione['mittente'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Data Spedizione</label>
                        <input type="date" name="data_spedizione" class="form-control form-control-sm" value="<?= h($spedizione['data_spedizione'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Consegna Prevista</label>
                        <input type="date" name="data_consegna_prevista" class="form-control form-control-sm" value="<?= h($spedizione['data_consegna_prevista'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Data Consegna Effettiva</label>
                        <input type="date" name="data_consegna" class="form-control form-control-sm" value="<?= h($spedizione['data_consegna'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Note</label>
                        <textarea name="note" class="form-control form-control-sm" rows="3"><?= h($spedizione['note'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
