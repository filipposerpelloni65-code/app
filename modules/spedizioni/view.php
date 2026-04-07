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

$stmt = $db->prepare("
    SELECT s.*,
        d.name AS dealer_name, dl.name AS location_name,
        t.title AS ticket_title, t.status AS ticket_status,
        uc.full_name AS creator_name
    FROM spedizioni s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN users uc ON s.created_by = uc.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$sp = $stmt->fetch();
if (!$sp) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

// Spare parts request details
$partRequest = null;
if ($sp['spare_parts_request_id']) {
    $prStmt = $db->prepare("SELECT spr.*, sp.name as part_name, sp.sku FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.id=?");
    $prStmt->execute([$sp['spare_parts_request_id']]);
    $partRequest = $prStmt->fetch();
}

define('PAGE_TITLE', 'Spedizione #' . $sp['id']);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Spedizione #'.$sp['id'] => '']);

$createdMsg = !empty($_GET['created']) ? 'Spedizione creata con successo.' : '';
$updatedMsg = !empty($_GET['updated']) ? 'Spedizione aggiornata.' : '';

include APP_ROOT . '/includes/header.php';
?>

<?php if ($createdMsg): ?>
<div class="alert alert-success alert-dismissible fade show auto-dismiss"><i class="bi bi-check-circle me-2"></i><?= h($createdMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($updatedMsg): ?>
<div class="alert alert-success alert-dismissible fade show auto-dismiss"><i class="bi bi-check-circle me-2"></i><?= h($updatedMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Spedizione #<?= $sp['id'] ?></h4>
    <div class="d-flex gap-2">
        <?php if (isTechnician() && !in_array($sp['status'], ['consegnata','annullata'])): ?>
        <a href="<?= APP_URL ?>/modules/spedizioni/edit.php?id=<?= $sp['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Dettagli Spedizione</h5>
                <?= getSpedizioneStatusBadge($sp['status']) ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Numero Tracking</dt>
                    <dd class="col-sm-8 font-monospace fw-bold"><?= $sp['tracking_number'] ? h($sp['tracking_number']) : '<span class="text-muted">Non ancora disponibile</span>' ?></dd>

                    <dt class="col-sm-4">Corriere</dt>
                    <dd class="col-sm-8"><?= $sp['corriere'] ? h($sp['corriere']) : '<span class="text-muted">-</span>' ?></dd>

                    <dt class="col-sm-4">Stato</dt>
                    <dd class="col-sm-8"><?= getSpedizioneStatusBadge($sp['status']) ?></dd>

                    <dt class="col-sm-4">Data Spedizione</dt>
                    <dd class="col-sm-8"><?= $sp['data_spedizione'] ? formatDate($sp['data_spedizione'], 'd/m/Y') : '<span class="text-muted">-</span>' ?></dd>

                    <dt class="col-sm-4">Data Prevista Consegna</dt>
                    <dd class="col-sm-8"><?= $sp['data_prevista_consegna'] ? formatDate($sp['data_prevista_consegna'], 'd/m/Y') : '<span class="text-muted">-</span>' ?></dd>

                    <dt class="col-sm-4">Creato da</dt>
                    <dd class="col-sm-8"><?= h($sp['creator_name'] ?? '') ?></dd>

                    <dt class="col-sm-4">Creato il</dt>
                    <dd class="col-sm-8"><?= formatDate($sp['created_at']) ?></dd>

                    <dt class="col-sm-4">Aggiornato il</dt>
                    <dd class="col-sm-8"><?= formatDate($sp['updated_at']) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($sp['note']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>Note</h5></div>
            <div class="card-body">
                <p class="mb-0"><?= nl2br(h($sp['note'])) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <?php if ($sp['dealer_name']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-shop me-2 text-primary"></i>Concessionario</h6></div>
            <div class="card-body">
                <div class="fw-semibold"><?= h($sp['dealer_name']) ?></div>
                <?php if ($sp['location_name']): ?>
                <div class="text-muted small"><?= h($sp['location_name']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($sp['ticket_title']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-ticket-detailed me-2 text-primary"></i>Ticket Collegato</h6></div>
            <div class="card-body">
                <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $sp['ticket_id'] ?>" class="text-decoration-none">
                    <div class="fw-semibold"><?= h(getTicketPrefix().'-'.str_pad($sp['ticket_id'],4,'0',STR_PAD_LEFT)) ?></div>
                    <div class="small text-muted"><?= h($sp['ticket_title']) ?></div>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($partRequest): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-tools me-2 text-secondary"></i>Ricambio Collegato</h6></div>
            <div class="card-body">
                <div class="fw-semibold"><?= h($partRequest['part_name']) ?></div>
                <?php if ($partRequest['sku']): ?>
                <div class="small text-muted font-monospace">SKU: <?= h($partRequest['sku']) ?></div>
                <?php endif; ?>
                <div class="small text-muted">Quantità: <?= (int)$partRequest['quantity'] ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
