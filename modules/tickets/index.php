<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('tickets')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

// Handle CSV export before page output
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isTechnician()) {
    $db = getDB();
    $user = currentUser();
    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['status']))      { $where[] = 't.status=?';      $params[] = $_GET['status']; }
    if (!empty($_GET['priority']))    { $where[] = 't.priority=?';    $params[] = $_GET['priority']; }
    if (!empty($_GET['category_id'])){ $where[] = 't.category_id=?'; $params[] = (int)$_GET['category_id']; }
    if (!empty($_GET['dealer_id']))   { $where[] = 't.dealer_id=?';   $params[] = (int)$_GET['dealer_id']; }
    if (!empty($_GET['q']))           { $where[] = '(t.title LIKE ? OR t.description LIKE ?)'; $params[] = '%'.$_GET['q'].'%'; $params[] = '%'.$_GET['q'].'%'; }
    if ($user['role'] === 'user')    { $where[] = 't.created_by=?';  $params[] = $user['id']; }
    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("SELECT t.id, t.title, t.status, t.priority, uc.full_name as creator_name, ua.full_name as assignee_name, c.name as category_name, d.name as dealer_name, t.created_at, t.updated_at FROM tickets t LEFT JOIN users ua ON t.assigned_to=ua.id LEFT JOIN users uc ON t.created_by=uc.id LEFT JOIN ticket_categories c ON t.category_id=c.id LEFT JOIN dealers d ON t.dealer_id=d.id WHERE $whereStr ORDER BY t.created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tickets_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['ID','Prefisso','Titolo','Stato','Priorità','Categoria','Creato da','Assegnato a','Concessionario','Creato il','Aggiornato il'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            getTicketPrefix() . '-' . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
            $r['title'],
            getStatusLabel($r['status']),
            getPriorityLabel($r['priority']),
            $r['category_name'] ?? '',
            $r['creator_name'] ?? '',
            $r['assignee_name'] ?? '',
            $r['dealer_name'] ?? '',
            $r['created_at'],
            $r['updated_at'],
        ], ';');
    }
    fclose($out);
    exit;
}

define('PAGE_TITLE', 'Ticket');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Ticket' => '']);

$db = getDB();
$user = currentUser();

// Handle delete (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ticket' && isAdmin()) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $delId = (int)($_POST['ticket_id'] ?? 0);
        if ($delId) {
            $db->prepare("DELETE FROM ticket_comments WHERE ticket_id=?")->execute([$delId]);
            $db->prepare("DELETE FROM ticket_attachments WHERE ticket_id=?")->execute([$delId]);
            $db->prepare("DELETE FROM ticket_uscite WHERE ticket_id=?")->execute([$delId]);
            $db->prepare("UPDATE spare_parts_requests SET ticket_id=NULL WHERE ticket_id=?")->execute([$delId]);
            $db->prepare("UPDATE rapportini SET ticket_id=NULL WHERE ticket_id=?")->execute([$delId]);
            $db->prepare("UPDATE periferiche_guaste SET ticket_id=NULL WHERE ticket_id=?")->execute([$delId]);
            $db->prepare("DELETE FROM tickets WHERE id=?")->execute([$delId]);
            logActivity($user['id'], 'delete', 'ticket', $delId, "Eliminato ticket $delId");
        }
    }
    header('Location: ' . APP_URL . '/modules/tickets/index.php?deleted=1');
    exit;
}

$perPage = (int)getSetting('items_per_page', '25');
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if (!empty($_GET['status'])) { $where[] = 't.status=?'; $params[] = $_GET['status']; }
if (!empty($_GET['priority'])) { $where[] = 't.priority=?'; $params[] = $_GET['priority']; }
if (!empty($_GET['assigned_to'])) { $where[] = 't.assigned_to=?'; $params[] = (int)$_GET['assigned_to']; }
if (!empty($_GET['category_id'])) { $where[] = 't.category_id=?'; $params[] = (int)$_GET['category_id']; }
if (!empty($_GET['dealer_id'])) { $where[] = 't.dealer_id=?'; $params[] = (int)$_GET['dealer_id']; }
if (!empty($_GET['location_id'])) { $where[] = 't.location_id=?'; $params[] = (int)$_GET['location_id']; }
if (!empty($_GET['q'])) { $where[] = '(t.title LIKE ? OR t.description LIKE ?)'; $params[] = '%'.$_GET['q'].'%'; $params[] = '%'.$_GET['q'].'%'; }
if ($user['role'] === 'user') { $where[] = 't.created_by=?'; $params[] = $user['id']; }

$whereStr = implode(' AND ', $where);
$total = $db->prepare("SELECT COUNT(*) FROM tickets t WHERE $whereStr");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("SELECT t.*, u.full_name as assignee_name, uc.full_name as creator_name, c.name as category_name, d.name as dealer_name, dl.name as location_name FROM tickets t LEFT JOIN users u ON t.assigned_to=u.id LEFT JOIN users uc ON t.created_by=uc.id LEFT JOIN ticket_categories c ON t.category_id=c.id LEFT JOIN dealers d ON t.dealer_id=d.id LEFT JOIN dealer_locations dl ON t.location_id=dl.id WHERE $whereStr ORDER BY t.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$dealerLocations = [];
if (!empty($_GET['dealer_id'])) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([(int)$_GET['dealer_id']]);
    $dealerLocations = $dlStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<script>
window.appUrl = <?= json_encode(APP_URL) ?>;
window.ticketPrefix = <?= json_encode(getTicketPrefix()) ?>;
</script>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-ticket-detailed me-2 text-primary"></i>Gestione Ticket</h4>
    <div class="d-flex gap-2">
        <?php if (isTechnician()): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Esporta CSV</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/tickets/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuovo Ticket</a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Ticket eliminato con successo. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Cerca per titolo..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <option value="open" <?= ($_GET['status'] ?? '') === 'open' ? 'selected' : '' ?>>Aperto</option>
                    <option value="in_progress" <?= ($_GET['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Lavorazione</option>
                    <option value="waiting" <?= ($_GET['status'] ?? '') === 'waiting' ? 'selected' : '' ?>>In Attesa</option>
                    <option value="resolved" <?= ($_GET['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>Risolto</option>
                    <option value="closed" <?= ($_GET['status'] ?? '') === 'closed' ? 'selected' : '' ?>>Chiuso</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="priority" class="form-select">
                    <option value="">Tutte le priorità</option>
                    <option value="low" <?= ($_GET['priority'] ?? '') === 'low' ? 'selected' : '' ?>>Bassa</option>
                    <option value="medium" <?= ($_GET['priority'] ?? '') === 'medium' ? 'selected' : '' ?>>Media</option>
                    <option value="high" <?= ($_GET['priority'] ?? '') === 'high' ? 'selected' : '' ?>>Alta</option>
                    <option value="urgent" <?= ($_GET['priority'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgente</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="category_id" class="form-select">
                    <option value="">Tutte le categorie</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-outline-primary flex-fill"><i class="bi bi-search"></i></button>
                <a href="?" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
        <?php if ($dealers): ?>
        <form method="get" class="row g-2 mt-1">
            <?php foreach (['status','priority','category_id','assigned_to','q'] as $k): ?>
            <?php if (!empty($_GET[$k])): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($_GET[$k]) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <div class="col-md-4">
                <select name="dealer_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Tutti i concessionari</option>
                    <?php foreach ($dealers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($_GET['dealer_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($dealerLocations): ?>
            <div class="col-md-4">
                <select name="location_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Tutti i punti vendita</option>
                    <?php foreach ($dealerLocations as $dl): ?>
                    <option value="<?= $dl['id'] ?>" <?= ($_GET['location_id'] ?? '') == $dl['id'] ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:110px">#</th>
                        <th>Titolo</th>
                        <th>Categoria</th>
                        <th>Stato</th>
                        <th>Priorità</th>
                        <th>Assegnato a</th>
                        <th>Creato da</th>
                        <th>Concessionario</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($tickets): ?>
                    <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="fw-bold text-primary text-decoration-none font-monospace"><?= h(getTicketPrefix() . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT)) ?></a></td>
                        <td>
                            <button type="button" class="btn btn-link p-0 text-dark text-decoration-none text-start ticket-quick-view fw-600"
                                    data-ticket-id="<?= $t['id'] ?>" style="font-weight:600">
                                <?= h($t['title']) ?>
                            </button>
                        </td>
                        <td class="small"><?= $t['category_name'] ? h($t['category_name']) : '-' ?></td>
                        <td><?= getStatusBadge($t['status']) ?></td>
                        <td><?= getPriorityBadge($t['priority']) ?></td>
                        <td class="small"><?= $t['assignee_name'] ? h($t['assignee_name']) : '<span class="text-muted">Non assegnato</span>' ?></td>
                        <td class="small"><?= h($t['creator_name'] ?? '') ?></td>
                        <td class="small"><?= $t['dealer_name'] ? '<a href="'.APP_URL.'/modules/dealers/view.php?id='.$t['dealer_id'].'" class="text-decoration-none">'.h($t['dealer_name']).'</a>' : '<span class="text-muted">-</span>' ?></td>
                        <td class="small text-muted"><?= formatDate($t['created_at'], 'd/m/Y') ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isTechnician()): ?>
                                <a href="<?= APP_URL ?>/modules/tickets/edit.php?id=<?= $t['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                                <?php if (!in_array($t['status'], ['closed'])): ?>
                                <button type="button" class="btn btn-outline-info ticket-quick-status"
                                    data-ticket-id="<?= $t['id'] ?>"
                                    data-current-status="<?= h($t['status']) ?>"
                                    title="Cambia Stato Rapido"
                                    data-bs-toggle="tooltip"><i class="bi bi-arrow-repeat"></i></button>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (isAdmin()): ?>
                                <button type="button" class="btn btn-outline-danger"
                                    data-confirm="Eliminare il ticket <?= h(getTicketPrefix() . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT)) ?> definitivamente?"
                                    data-confirm-class="btn-danger"
                                    data-confirm-text="Elimina"
                                    title="Elimina"
                                    onclick="document.getElementById('delform<?= $t['id'] ?>').submit()">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <form id="delform<?= $t['id'] ?>" method="post" style="display:none">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_ticket">
                                    <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nessun ticket trovato</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>-<?= min($offset+$perPage,$totalRows) ?> di <?= $totalRows ?> ticket</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php if (isTechnician()): ?>
<!-- ============================================================
     Quick Status Change Modal
     ============================================================ -->
<div class="modal fade" id="ticketStatusModal" tabindex="-1" aria-labelledby="ticketStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-semibold" id="ticketStatusModalLabel">
                    <i class="bi bi-arrow-repeat text-info me-2"></i>Cambia Stato Ticket
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <form id="quickStatusForm" method="post" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_status">
                <div class="modal-body">
                    <p class="text-muted small mb-3">Ticket: <strong id="statusModalTicketCode"></strong></p>
                    <label class="form-label fw-semibold">Nuovo Stato</label>
                    <select name="new_status" class="form-select" id="statusModalSelect">
                        <option value="open">Aperto</option>
                        <option value="in_progress">In Lavorazione</option>
                        <option value="waiting">In Attesa</option>
                        <option value="resolved">Risolto</option>
                        <option value="closed">Chiuso</option>
                    </select>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Annulla
                    </button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="bi bi-check-lg me-1"></i>Aggiorna Stato
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
window.appUrl = <?= json_encode(APP_URL) ?>;
window.ticketPrefix = <?= json_encode(getTicketPrefix()) ?>;
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
<!-- Quick status change hidden form -->
<form id="quickStatusForm" method="post" action="<?= APP_URL ?>/api/tickets.php" style="display:none">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <input type="hidden" name="id" id="quickStatusTicketId">
    <input type="hidden" name="status" id="quickStatusValue">
</form>
<script>
document.querySelectorAll('.quick-status-change').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var label = this.textContent.trim();
        if (!confirm('Impostare lo stato a "' + label + '"?')) return;
        document.getElementById('quickStatusTicketId').value = this.dataset.ticketId;
        document.getElementById('quickStatusValue').value = this.dataset.status;
        // Use fetch to avoid page reload
        var form = document.getElementById('quickStatusForm');
        var data = new FormData(form);
        fetch(form.action, { method: 'POST', body: data })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (resp.success) { location.reload(); }
                else { alert('Errore: ' + (resp.error || 'Sconosciuto')); }
            })
            .catch(function() { alert('Errore di comunicazione.'); });
    });
});
</script>
