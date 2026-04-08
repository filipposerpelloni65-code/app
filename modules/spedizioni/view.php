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
        t.title AS ticket_title,
        spr.id AS request_id, spr.quantity AS req_quantity, sp.name AS part_name, sp.sku AS part_sku,
        d.name AS dealer_name, d.city AS dealer_city,
        dl.name AS location_name,
        uc.full_name AS creator_name
    FROM spedizioni s
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
    LEFT JOIN spare_parts sp ON spr.part_id = sp.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    LEFT JOIN users uc ON s.created_by = uc.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$s = $stmt->fetch();
if (!$s) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

define('PAGE_TITLE', 'Spedizione #' . $id);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', '#'.$id => '']);

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show auto-dismiss"><i class="bi bi-check-circle me-2"></i>Spedizione creata con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show auto-dismiss"><i class="bi bi-check-circle me-2"></i>Spedizione aggiornata con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Spedizione #<?= $id ?>
            <?= getSpedizioneStatusBadge($s['status']) ?>
        </h4>
        <?php if ($s['tracking_number']): ?>
        <span class="font-monospace text-muted"><?= h($s['tracking_number']) ?><?php if ($s['corriere']): ?> &mdash; <?= h($s['corriere']) ?><?php endif; ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if (isTechnician()): ?>
        <a href="<?= APP_URL ?>/modules/spedizioni/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Dettaglio Spedizione</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-bordered mb-0">
                    <tbody>
                        <tr><td class="fw-semibold bg-light" style="width:35%">ID</td><td>#<?= $id ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Stato</td><td><?= getSpedizioneStatusBadge($s['status']) ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Tracking</td><td class="font-monospace"><?= $s['tracking_number'] ? h($s['tracking_number']) : '<span class="text-muted">N/D</span>' ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Corriere</td><td><?= $s['corriere'] ? h($s['corriere']) : '<span class="text-muted">-</span>' ?></td></tr>
                        <?php if ($s['data_spedizione']): ?>
                        <tr><td class="fw-semibold bg-light">Data Spedizione</td><td><?= formatDate($s['data_spedizione'], 'd/m/Y') ?></td></tr>
                        <?php endif; ?>
                        <?php if ($s['data_consegna_prevista']): ?>
                        <tr><td class="fw-semibold bg-light">Consegna Prevista</td><td><?= formatDate($s['data_consegna_prevista'], 'd/m/Y') ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="fw-semibold bg-light">Creata il</td><td><?= formatDate($s['created_at']) ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Creata da</td><td><?= h($s['creator_name'] ?? '') ?></td></tr>
                        <?php if ($s['note']): ?>
                        <tr><td class="fw-semibold bg-light">Note</td><td><?= nl2br(h($s['note'])) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- Ticket collegato -->
        <?php if ($s['ticket_id']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-ticket-detailed me-2 text-primary"></i>Ticket Collegato</h6></div>
            <div class="card-body">
                <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $s['ticket_id'] ?>" class="fw-bold text-decoration-none">
                    <?= h(getTicketPrefix().'-'.str_pad($s['ticket_id'],4,'0',STR_PAD_LEFT)) ?> &mdash; <?= h($s['ticket_title']) ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ricambio -->
        <?php if ($s['part_name']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-tools me-2 text-warning"></i>Ricambio</h6></div>
            <div class="card-body">
                <div class="fw-semibold"><?= h($s['part_name']) ?></div>
                <?php if ($s['part_sku']): ?><div class="small text-muted">SKU: <?= h($s['part_sku']) ?></div><?php endif; ?>
                <div class="small text-muted">Qtà richiesta: <?= (int)($s['req_quantity'] ?? 1) ?></div>
                <?php if ($s['request_id']): ?>
                <a href="<?= APP_URL ?>/modules/spare_parts/requests.php" class="btn btn-sm btn-outline-warning mt-2"><i class="bi bi-arrow-right me-1"></i>Vedi Richiesta #<?= $s['request_id'] ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Destinatario -->
        <?php if ($s['dealer_name'] || $s['location_name']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-shop me-2 text-success"></i>Destinatario</h6></div>
            <div class="card-body">
                <?php if ($s['dealer_name']): ?><div class="fw-semibold"><?= h($s['dealer_name']) ?></div><?php endif; ?>
                <?php if ($s['location_name']): ?><div class="text-muted small"><?= h($s['location_name']) ?></div><?php endif; ?>
                <?php if ($s['dealer_city']): ?><div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= h($s['dealer_city']) ?></div><?php endif; ?>
                <?php if ($s['dealer_id']): ?>
                <a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $s['dealer_id'] ?>" class="btn btn-sm btn-outline-success mt-2"><i class="bi bi-arrow-right me-1"></i>Vedi Concessionario</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
