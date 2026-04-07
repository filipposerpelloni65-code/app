<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('rapportini')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Rapportini');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Rapportini' => '']);

$db = getDB();
$user = currentUser();

$perPage = (int)getSetting('items_per_page', '25');
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if (!empty($_GET['status'])) { $where[] = 'r.status=?'; $params[] = $_GET['status']; }
if (!empty($_GET['q'])) {
    $q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $_GET['q']);
    $where[] = '(r.title LIKE ? OR r.work_description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (!empty($_GET['technician_id'])) { $where[] = 'r.technician_id=?'; $params[] = (int)$_GET['technician_id']; }
if ($user['role'] === 'user') { $where[] = '(r.created_by=? OR r.technician_id=?)'; $params[] = $user['id']; $params[] = $user['id']; }

$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM rapportini r WHERE $whereStr");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT r.*,
        ut.full_name AS technician_name,
        uc.full_name AS creator_name,
        d.name AS dealer_name,
        t.title AS ticket_title
    FROM rapportini r
    LEFT JOIN users ut ON r.technician_id = ut.id
    LEFT JOIN users uc ON r.created_by = uc.id
    LEFT JOIN dealers d ON r.dealer_id = d.id
    LEFT JOIN tickets t ON r.ticket_id = t.id
    WHERE $whereStr
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rapportini = $stmt->fetchAll();

$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Rapportini di Lavoro</h4>
    <?php if (isTechnician()): ?>
    <a href="<?= APP_URL ?>/modules/rapportini/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuovo Rapportino</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Cerca per titolo o descrizione..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <option value="draft" <?= ($_GET['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Bozza</option>
                    <option value="signed" <?= ($_GET['status'] ?? '') === 'signed' ? 'selected' : '' ?>>Firmato</option>
                    <option value="archived" <?= ($_GET['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archiviato</option>
                </select>
            </div>
            <?php if (isTechnician()): ?>
            <div class="col-md-3">
                <select name="technician_id" class="form-select">
                    <option value="">Tutti i tecnici</option>
                    <?php foreach ($technicians as $tech): ?>
                    <option value="<?= $tech['id'] ?>" <?= ($_GET['technician_id'] ?? '') == $tech['id'] ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-outline-primary flex-fill"><i class="bi bi-search"></i></button>
                <a href="?" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:90px">#</th>
                        <th>Titolo</th>
                        <th>Tecnico</th>
                        <th>Data Intervento</th>
                        <th>Concessionario</th>
                        <th>Stato</th>
                        <th>Ticket</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rapportini): ?>
                    <?php foreach ($rapportini as $r): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/modules/rapportini/view.php?id=<?= $r['id'] ?>" class="fw-bold text-primary text-decoration-none">RAP-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></a></td>
                        <td><a href="<?= APP_URL ?>/modules/rapportini/view.php?id=<?= $r['id'] ?>" class="text-dark text-decoration-none"><?= h($r['title']) ?></a></td>
                        <td class="small"><?= h($r['technician_name'] ?? '-') ?></td>
                        <td class="small"><?= formatDate($r['intervention_date'], 'd/m/Y') ?></td>
                        <td class="small"><?= $r['dealer_name'] ? h($r['dealer_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= getRapportinoStatusBadge($r['status']) ?></td>
                        <td class="small"><?= $r['ticket_title'] ? '<a href="'.APP_URL.'/modules/tickets/view.php?id='.$r['ticket_id'].'" class="text-decoration-none">'.h(getTicketPrefix().'-'.str_pad($r['ticket_id'],4,'0',STR_PAD_LEFT)).'</a>' : '<span class="text-muted">-</span>' ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/rapportini/view.php?id=<?= $r['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isTechnician() && $r['status'] === 'draft'): ?>
                                <a href="<?= APP_URL ?>/modules/rapportini/edit.php?id=<?= $r['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-file-earmark-x fs-1 d-block mb-2"></i>Nessun rapportino trovato</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>-<?= min($offset+$perPage,$totalRows) ?> di <?= $totalRows ?> rapportini</small>
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

<?php include APP_ROOT . '/includes/footer.php'; ?>
