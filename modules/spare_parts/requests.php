<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spare_parts')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Richieste Parti');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Parti di Ricambio' => APP_URL.'/modules/spare_parts/index.php', 'Richieste' => '']);

$db = getDB();
$user = currentUser();

// Handle status update (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        // ignore silently
    } else {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($reqId && in_array($newStatus, ['pending','approved','rejected','fulfilled'])) {
            // If fulfilled, decrement stock
            if ($newStatus === 'fulfilled') {
                $r = $db->prepare("SELECT part_id, quantity FROM spare_parts_requests WHERE id=?");
                $r->execute([$reqId]);
                $row = $r->fetch();
                if ($row) {
                    $db->prepare("UPDATE spare_parts SET quantity=GREATEST(0, quantity-?), updated_at=NOW() WHERE id=?")->execute([$row['quantity'], $row['part_id']]);
                }
            }
            $db->prepare("UPDATE spare_parts_requests SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $reqId]);
            logActivity($user['id'], 'update_request', 'spare_part_request', $reqId, "Stato aggiornato: $newStatus");
        }
    }
    header('Location: ' . APP_URL . '/modules/spare_parts/requests.php');
    exit;
}

$filterStatus = $_GET['status'] ?? '';
$filterDealer = (int)($_GET['dealer_id'] ?? 0);
$where = '1=1';
$params = [];
if ($filterStatus) { $where .= ' AND spr.status=?'; $params[] = $filterStatus; }
if ($filterDealer) { $where .= ' AND spr.dealer_id=?'; $params[] = $filterDealer; }
// Technician sees only their requests unless admin
if (!isAdmin()) { $where .= ' AND spr.requested_by=?'; $params[] = $user['id']; }

$stmt = $db->prepare("SELECT spr.*, sp.name as part_name, sp.sku, u.full_name as requester_name, t.title as ticket_title, d.name as dealer_name, dl.name as location_name FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id JOIN users u ON spr.requested_by=u.id LEFT JOIN tickets t ON spr.ticket_id=t.id LEFT JOIN dealers d ON spr.dealer_id=d.id LEFT JOIN dealer_locations dl ON spr.location_id=dl.id WHERE $where ORDER BY spr.created_at DESC");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-cart-check me-2 text-primary"></i>Richieste Parti di Ricambio</h4>
    <a href="<?= APP_URL ?>/modules/spare_parts/request.php" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuova Richiesta</a>
</div>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Richiesta inviata con successo. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <div class="d-flex gap-2 flex-wrap">
            <a href="?" class="btn btn-sm <?= !$filterStatus ? 'btn-primary' : 'btn-outline-secondary' ?>">Tutte</a>
            <a href="?status=pending" class="btn btn-sm <?= $filterStatus === 'pending' ? 'btn-warning' : 'btn-outline-secondary' ?>">In Attesa</a>
            <a href="?status=approved" class="btn btn-sm <?= $filterStatus === 'approved' ? 'btn-success' : 'btn-outline-secondary' ?>">Approvate</a>
            <a href="?status=rejected" class="btn btn-sm <?= $filterStatus === 'rejected' ? 'btn-danger' : 'btn-outline-secondary' ?>">Rifiutate</a>
            <a href="?status=fulfilled" class="btn btn-sm <?= $filterStatus === 'fulfilled' ? 'btn-primary' : 'btn-outline-secondary' ?>">Evase</a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Parte</th>
                        <th>SKU</th>
                        <th>Qtà</th>
                        <th>Ticket</th>
                        <th>Richiedente</th>
                        <th>Stato</th>
                        <th>Note</th>
                        <th>Data</th>
                        <?php if (isAdmin()): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($requests): ?>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td class="small text-muted"><?= $r['id'] ?></td>
                        <td><?= h($r['part_name']) ?></td>
                        <td class="small font-monospace text-muted"><?= h($r['sku']) ?></td>
                        <td><?= (int)$r['quantity'] ?></td>
                        <td class="small"><?= $r['ticket_title'] ? '<a href="'.APP_URL.'/modules/tickets/view.php?id='.$r['ticket_id'].'" class="text-decoration-none">'.h(getTicketPrefix().'-'.str_pad($r['ticket_id'],4,'0',STR_PAD_LEFT)).'</a>' : '-' ?></td>
                        <td class="small"><?= h($r['requester_name']) ?></td>
                        <td><?= getRequestStatusBadge($r['status']) ?></td>
                        <td class="small text-muted"><?= $r['notes'] ? h(substr($r['notes'],0,50)).(strlen($r['notes'])>50?'…':'') : '-' ?></td>
                        <td class="small text-muted"><?= formatDate($r['created_at'], 'd/m/Y') ?></td>
                        <?php if (isAdmin()): ?>
                        <td>
                            <div class="d-flex gap-1 align-items-center">
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <select name="new_status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                        <option value="pending" <?= $r['status']==='pending'?'selected':'' ?>>In Attesa</option>
                                        <option value="approved" <?= $r['status']==='approved'?'selected':'' ?>>Approvata</option>
                                        <option value="rejected" <?= $r['status']==='rejected'?'selected':'' ?>>Rifiutata</option>
                                        <option value="fulfilled" <?= $r['status']==='fulfilled'?'selected':'' ?>>Evasa</option>
                                    </select>
                                </form>
                                <?php if (in_array($r['status'], ['approved','pending']) && isModuleEnabled('spedizioni')): ?>
                                <a href="<?= APP_URL ?>/modules/spedizioni/create.php?request_id=<?= $r['id'] ?><?= $r['ticket_id'] ? '&ticket_id='.$r['ticket_id'] : '' ?>" class="btn btn-outline-primary btn-sm py-0" title="Crea Spedizione"><i class="bi bi-truck"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nessuna richiesta trovata</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
