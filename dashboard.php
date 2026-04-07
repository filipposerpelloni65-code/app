<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/modules.php';

requireLogin();
define('PAGE_TITLE', 'Dashboard');
define('BREADCRUMB', ['Dashboard' => '']);

$db = getDB();
$user = currentUser();

// Stats
$stats = [];
$stats['open'] = $db->query("SELECT COUNT(*) FROM tickets WHERE status='open'")->fetchColumn();
$stats['in_progress'] = $db->query("SELECT COUNT(*) FROM tickets WHERE status='in_progress'")->fetchColumn();
$stats['resolved_today'] = $db->query("SELECT COUNT(*) FROM tickets WHERE status='resolved' AND DATE(closed_at)=CURDATE()")->fetchColumn();
$stats['low_stock'] = $db->query("SELECT COUNT(*) FROM spare_parts WHERE quantity <= min_quantity")->fetchColumn();

// Extra stats (periferiche + rapportini)
$stats['periferiche_active'] = 0;
$stats['rapportini_draft'] = 0;
$stats['pending_parts'] = 0;
try {
    $stats['periferiche_active'] = (int)$db->query("SELECT COUNT(*) FROM periferiche_guaste WHERE stato NOT IN ('restituita','rottamata','non_riparabile')")->fetchColumn();
    $stats['rapportini_draft']   = (int)$db->query("SELECT COUNT(*) FROM rapportini WHERE status='draft'")->fetchColumn();
    $stats['pending_parts']      = (int)$db->query("SELECT COUNT(*) FROM spare_parts_requests WHERE status='pending'")->fetchColumn();
} catch (Exception $e) { /* tables may not exist yet */ }

// Recent tickets (last 10)
if ($user['role'] === 'user') {
    $stmt = $db->prepare("SELECT t.*, u.full_name as assignee_name, c.name as category_name FROM tickets t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN ticket_categories c ON t.category_id=c.id WHERE t.created_by=? ORDER BY t.created_at DESC LIMIT 10");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $db->prepare("SELECT t.*, u.full_name as assignee_name, c.name as category_name FROM tickets t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN ticket_categories c ON t.category_id=c.id ORDER BY t.created_at DESC LIMIT 10");
    $stmt->execute([]);
}
$recentTickets = $stmt->fetchAll();

// Low stock parts
$lowStock = $db->query("SELECT * FROM spare_parts WHERE quantity <= min_quantity ORDER BY quantity ASC LIMIT 5")->fetchAll();

// My open tickets (for technicians)
$myTickets = [];
if (isTechnician() && $user['role'] !== 'user') {
    $stmt = $db->prepare("SELECT t.*, c.name as category_name FROM tickets t LEFT JOIN ticket_categories c ON t.category_id=c.id WHERE t.assigned_to=? AND t.status NOT IN ('closed','resolved') ORDER BY t.updated_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $myTickets = $stmt->fetchAll();
}

// Recent activity log (admin/tech only)
$activityLog = [];
if (isTechnician()) {
    try {
        $activityLog = $db->query("SELECT al.*, u.full_name FROM activity_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();
    } catch (Exception $e) { /* silent */ }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-white bg-primary">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['open'] ?></div>
                    <div class="opacity-75">Ticket Aperti</div>
                </div>
                <i class="bi bi-ticket-detailed fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/tickets/index.php?status=open" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza tutti</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-dark bg-warning">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['in_progress'] ?></div>
                    <div class="opacity-75">In Lavorazione</div>
                </div>
                <i class="bi bi-gear-wide-connected fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/tickets/index.php?status=in_progress" class="text-dark text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza tutti</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-white bg-success">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['resolved_today'] ?></div>
                    <div class="opacity-75">Risolti Oggi</div>
                </div>
                <i class="bi bi-check-circle fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/tickets/index.php?status=resolved" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza tutti</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-white bg-danger">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['low_stock'] ?></div>
                    <div class="opacity-75">Scorte Basse</div>
                </div>
                <i class="bi bi-exclamation-triangle fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/spare_parts/index.php?filter=low_stock" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza parti</a>
            </div>
        </div>
    </div>
</div>

<?php if (isTechnician()): ?>
<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info bg-opacity-15 p-3">
                    <i class="bi bi-hdd-network fs-4 text-info"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['periferiche_active'] ?></div>
                    <div class="text-muted small">Periferiche Attive</div>
                </div>
                <a href="<?= APP_URL ?>/modules/periferiche/index.php" class="ms-auto btn btn-sm btn-outline-info">Vedi</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-secondary bg-opacity-15 p-3">
                    <i class="bi bi-file-earmark-text fs-4 text-secondary"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['rapportini_draft'] ?></div>
                    <div class="text-muted small">Rapportini in Bozza</div>
                </div>
                <a href="<?= APP_URL ?>/modules/rapportini/index.php" class="ms-auto btn btn-sm btn-outline-secondary">Vedi</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-15 p-3">
                    <i class="bi bi-clock-history fs-4 text-warning"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $stats['pending_parts'] ?></div>
                    <div class="text-muted small">Richieste Ricambi In Attesa</div>
                </div>
                <a href="<?= APP_URL ?>/modules/spare_parts/requests.php" class="ms-auto btn btn-sm btn-outline-warning">Vedi</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Ticket Recenti</h5>
                <a href="<?= APP_URL ?>/modules/tickets/create.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuovo</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Titolo</th>
                                <th>Stato</th>
                                <th>Priorità</th>
                                <th>Assegnato</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recentTickets): ?>
                            <?php foreach ($recentTickets as $t): ?>
                            <tr>
                                <td><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="text-decoration-none text-primary fw-bold"><?= h(getTicketPrefix() . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT)) ?></a></td>
                                <td><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="text-decoration-none text-dark"><?= h($t['title']) ?></a></td>
                                <td><?= getStatusBadge($t['status']) ?></td>
                                <td><?= getPriorityBadge($t['priority']) ?></td>
                                <td><?= $t['assignee_name'] ? h($t['assignee_name']) : '<span class="text-muted">-</span>' ?></td>
                                <td class="text-muted small"><?= formatDate($t['created_at'], 'd/m/Y') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Nessun ticket presente</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <a href="<?= APP_URL ?>/modules/tickets/index.php" class="btn btn-sm btn-outline-primary">Vedi tutti i ticket</a>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($myTickets): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-person-check me-2 text-warning"></i>I Miei Ticket</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($myTickets as $t): ?>
                <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-semibold small"><?= h($t['title']) ?></div>
                        <?= getPriorityBadge($t['priority']) ?>
                    </div>
                    <small class="text-muted"><?= getStatusBadge($t['status']) ?></small>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($lowStock): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Scorte Basse</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($lowStock as $p): ?>
                <a href="<?= APP_URL ?>/modules/spare_parts/edit.php?id=<?= $p['id'] ?>" class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between">
                        <span class="small fw-semibold"><?= h($p['name']) ?></span>
                        <span class="badge bg-danger"><?= (int)$p['quantity'] ?> pz</span>
                    </div>
                    <small class="text-muted">Min: <?= (int)$p['min_quantity'] ?> | SKU: <?= h($p['sku']) ?></small>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="card-footer bg-white text-end">
                <a href="<?= APP_URL ?>/modules/spare_parts/index.php" class="btn btn-sm btn-outline-danger">Gestisci scorte</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($activityLog): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-activity me-2 text-success"></i>Attività Recenti</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($activityLog as $al): ?>
                <div class="list-group-item py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="small fw-semibold"><?= h($al['full_name'] ?? 'Sistema') ?></span>
                        <span class="text-muted" style="font-size:0.75rem"><?= formatDate($al['created_at'], 'd/m H:i') ?></span>
                    </div>
                    <div class="text-muted small"><?= h($al['action']) ?><?= $al['entity_type'] ? ' · ' . h($al['entity_type']) . ' #' . (int)$al['entity_id'] : '' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
