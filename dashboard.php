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
// Additional stats
try {
    $stats['periferiche_attive'] = $db->query("SELECT COUNT(*) FROM periferiche_guaste WHERE stato NOT IN ('restituita','rottamata')")->fetchColumn();
} catch (Exception $e) { $stats['periferiche_attive'] = 0; }
try {
    $stats['da_spedire'] = $db->query("SELECT COUNT(*) FROM spedizioni WHERE status='da_spedire'")->fetchColumn();
} catch (Exception $e) { $stats['da_spedire'] = 0; }
try {
    $stats['pending_parts'] = $db->query("SELECT COUNT(*) FROM spare_parts_requests WHERE status='pending'")->fetchColumn();
} catch (Exception $e) { $stats['pending_parts'] = 0; }

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

// Data for quick-create ticket modal
$dashCategories  = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$dashTechnicians = isTechnician()
    ? $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll()
    : [];

// Resolution rate
$totalAll     = (int)$db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$totalResolved = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status IN ('resolved','closed')")->fetchColumn();
$resolutionRate = $totalAll > 0 ? round($totalResolved / $totalAll * 100) : 0;

// Weekly trend (this week vs last week)
$thisWeek = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY")->fetchColumn();
$lastWeek = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE(NOW()) - INTERVAL (7 + WEEKDAY(NOW())) DAY AND created_at < DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY")->fetchColumn();

// Recent activity feed (last 12 entries)
$recentActivity = [];
try {
    $actStmt = $db->query("SELECT al.*, u.full_name FROM activity_log al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 12");
    $recentActivity = $actStmt->fetchAll();
} catch (Exception $e) { /* silent */ }

include APP_ROOT . '/includes/header.php';
?>

<script>
window.appUrl = <?= json_encode(APP_URL) ?>;
window.ticketPrefix = <?= json_encode(getTicketPrefix()) ?>;
</script>

<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card card-lift bg-primary border-0 shadow-sm text-white">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-value" data-target="<?= $stats['open'] ?>"><?= $stats['open'] ?></div>
                    <div class="stat-label">Ticket Aperti</div>
                </div>
                <i class="bi bi-ticket-detailed stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/tickets/index.php?status=open" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza tutti</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card card-lift bg-warning border-0 shadow-sm text-dark">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-value" data-target="<?= $stats['in_progress'] ?>"><?= $stats['in_progress'] ?></div>
                    <div class="stat-label">In Lavorazione</div>
                </div>
                <i class="bi bi-gear-wide-connected stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/tickets/index.php?status=in_progress" class="text-dark text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza tutti</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card card-lift bg-success border-0 shadow-sm text-white">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-value" data-target="<?= $stats['resolved_today'] ?>"><?= $stats['resolved_today'] ?></div>
                    <div class="stat-label">Risolti Oggi</div>
                </div>
                <i class="bi bi-check-circle stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/tickets/index.php?status=resolved" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza tutti</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card card-lift bg-danger border-0 shadow-sm text-white">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-value" data-target="<?= $stats['low_stock'] ?>"><?= $stats['low_stock'] ?></div>
                    <div class="stat-label">Scorte Basse</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/spare_parts/index.php?filter=low_stock" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza parti</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php if (isModuleEnabled('periferiche') && $stats['periferiche_attive'] > 0): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-dark bg-info bg-opacity-75">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['periferiche_attive'] ?></div>
                    <div class="opacity-75">Periferiche Attive</div>
                </div>
                <i class="bi bi-hdd-network fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/periferiche/index.php" class="text-dark text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isModuleEnabled('spedizioni') && $stats['da_spedire'] > 0): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-white bg-secondary">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['da_spedire'] ?></div>
                    <div class="opacity-75">Da Spedire</div>
                </div>
                <i class="bi bi-truck fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/spedizioni/index.php?status=da_spedire" class="text-white text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Visualizza</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isTechnician() && $stats['pending_parts'] > 0): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-dark bg-warning bg-opacity-75">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="fs-2 fw-bold"><?= $stats['pending_parts'] ?></div>
                    <div class="opacity-75">Richieste Ricambi In Attesa</div>
                </div>
                <i class="bi bi-cart-check fs-1 opacity-50"></i>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= APP_URL ?>/modules/spare_parts/requests.php?status=pending" class="text-dark text-decoration-none small"><i class="bi bi-arrow-right me-1"></i>Gestisci</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Ticket Recenti</h5>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#quickCreateTicketModal">
                    <i class="bi bi-plus-lg me-1"></i>Nuovo
                </button>
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
        <!-- Resolution rate card -->
        <?php if ($totalAll > 0): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-graph-up me-2 text-success"></i>Tasso di Risoluzione</h5>
            </div>
            <div class="card-body text-center py-3">
                <div class="position-relative d-inline-flex align-items-center justify-content-center mb-2" style="width:96px;height:96px">
                    <svg width="96" height="96" viewBox="0 0 96 96" class="position-absolute top-0 start-0">
                        <circle cx="48" cy="48" r="42" fill="none" stroke="#e9ecef" stroke-width="10"/>
                        <circle cx="48" cy="48" r="42" fill="none" stroke="#198754" stroke-width="10"
                            stroke-dasharray="<?= round(2 * M_PI * 42 * $resolutionRate / 100, 1) ?> 264"
                            stroke-dashoffset="66" stroke-linecap="round"/>
                    </svg>
                    <span class="fs-4 fw-bold text-success"><?= $resolutionRate ?>%</span>
                </div>
                <div class="text-muted small"><?= $totalResolved ?> risolti su <?= $totalAll ?> totali</div>
                <?php if ($lastWeek > 0): ?>
                <div class="mt-2 small">
                    <?php $trend = $thisWeek - $lastWeek; ?>
                    <span class="badge bg-<?= $trend >= 0 ? 'success' : 'danger' ?>-subtle text-<?= $trend >= 0 ? 'success' : 'danger' ?>">
                        <i class="bi bi-<?= $trend >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                        <?= abs($trend) ?> questa settimana <?= $trend >= 0 ? '↑' : '↓' ?> vs settimana scorsa
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

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
    </div>
</div>

<!-- Activity Feed (full width, admin/technician only) -->
<?php if ($recentActivity && isTechnician()): ?>
<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-activity me-2 text-secondary"></i>Attività Recenti nel Sistema</h5>
                <?php if (isAdmin()): ?>
                <a href="<?= APP_URL ?>/modules/activity_log/index.php" class="btn btn-sm btn-outline-secondary">Log completo</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="activity-timeline px-4 py-3">
                    <?php
                    $actActionLabels = [
                        'create' => 'ha creato', 'update' => 'ha modificato', 'delete' => 'ha eliminato',
                        'comment' => 'ha commentato', 'status_change' => 'ha cambiato stato',
                        'add_uscita' => 'ha registrato un\'uscita tecnico',
                        'profile_update' => 'ha aggiornato il profilo', 'password_change' => 'ha cambiato la password',
                        'login' => 'si è connesso', 'settings_update' => 'ha aggiornato le impostazioni',
                        'add_componente' => 'ha aggiunto un componente',
                    ];
                    $actEntityLabels = ['ticket' => 'ticket', 'user' => 'utente', 'settings' => 'impostazioni'];
                    foreach ($recentActivity as $i => $act): ?>
                    <div class="d-flex gap-3 <?= $i < count($recentActivity) - 1 ? 'mb-3' : '' ?>">
                        <div class="flex-shrink-0 text-muted" style="padding-top:2px">
                            <i class="bi bi-dot fs-4 lh-1"></i>
                        </div>
                        <div class="flex-grow-1 small">
                            <span class="fw-semibold"><?= h($act['full_name'] ?? 'Sistema') ?></span>
                            <span class="text-muted"> <?= h($actActionLabels[$act['action']] ?? $act['action']) ?>
                            <?php if ($act['entity_type']): ?>
                                <?= h($actEntityLabels[$act['entity_type']] ?? $act['entity_type']) ?>
                                <?php if ($act['entity_id']): ?><span class="text-primary">#<?= (int)$act['entity_id'] ?></span><?php endif; ?>
                            <?php endif; ?></span>
                            <?php if ($act['details']): ?><span class="text-muted"> – <?= h(substr($act['details'], 0, 60)) ?></span><?php endif; ?>
                        </div>
                        <div class="flex-shrink-0 text-muted text-nowrap" style="font-size:.75rem"><?= formatDate($act['created_at'], 'd/m H:i') ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
     Quick Create Ticket Modal
     ============================================================ -->
<div class="modal fade" id="quickCreateTicketModal" tabindex="-1" aria-labelledby="quickCreateTicketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-semibold" id="quickCreateTicketModalLabel">
                    <i class="bi bi-plus-circle text-primary me-2"></i>Nuovo Ticket
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <form method="post" action="<?= APP_URL ?>/modules/tickets/create.php">
                <?= csrfField() ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Titolo <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="Descrivi brevemente il problema...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Descrizione <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="4" required placeholder="Descrizione dettagliata del problema..."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Priorità</label>
                            <select name="priority" class="form-select">
                                <option value="low">Bassa</option>
                                <option value="medium" selected>Media</option>
                                <option value="high">Alta</option>
                                <option value="urgent">Urgente</option>
                            </select>
                        </div>
                        <?php if ($dashCategories): ?>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Categoria</label>
                            <select name="category_id" class="form-select">
                                <option value="">-- Nessuna --</option>
                                <?php foreach ($dashCategories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($dashTechnicians): ?>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Assegna a</label>
                            <select name="assigned_to" class="form-select">
                                <option value="">-- Non assegnato --</option>
                                <?php foreach ($dashTechnicians as $tech): ?>
                                <option value="<?= $tech['id'] ?>"><?= h($tech['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Codice Ticket Concessionario</label>
                        <input type="text" name="codice_concessionario" class="form-control font-monospace" placeholder="Es. TKT-DEALER-001 (opzionale)">
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Per associare un concessionario o punto vendita, usa il <a href="<?= APP_URL ?>/modules/tickets/create.php" class="text-decoration-none">form completo</a>.
                    </p>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Annulla
                    </button>
                    <a href="<?= APP_URL ?>/modules/tickets/create.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-right me-1"></i>Form Completo
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Crea Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
