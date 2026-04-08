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

include APP_ROOT . '/includes/header.php';
?>

<script>window.appUrl = <?= json_encode(APP_URL) ?>;</script>

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
        <div class="card border-0 shadow-sm">
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

<!-- ============================================================
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
