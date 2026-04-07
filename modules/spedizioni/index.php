<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Spedizioni');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => '']);

$db = getDB();
$user = currentUser();

$perPage = (int)getSetting('items_per_page', '25');
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if (!empty($_GET['status']))    { $where[] = 's.status=?';      $params[] = $_GET['status']; }
if (!empty($_GET['dealer_id'])){ $where[] = 's.dealer_id=?';   $params[] = (int)$_GET['dealer_id']; }
if (!empty($_GET['q']))         { $where[] = '(s.tracking_number LIKE ? OR s.corriere LIKE ? OR s.note LIKE ?)'; $like = '%'.$_GET['q'].'%'; $params = array_merge($params, [$like,$like,$like]); }

$whereStr  = implode(' AND ', $where);
$totalStmt = $db->prepare("SELECT COUNT(*) FROM spedizioni s WHERE $whereStr");
$totalStmt->execute($params);
$totalRows  = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT s.*,
        d.name AS dealer_name,
        dl.name AS location_name,
        t.title  AS ticket_title,
        u.full_name AS creator_name
    FROM spedizioni s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE $whereStr
    ORDER BY s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$spedizioni = $stmt->fetchAll();

$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$statuses = ['da_spedire','spedita','consegnata','annullata'];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Spedizioni</h4>
    <?php if (isTechnician()): ?>
    <a href="<?= APP_URL ?>/modules/spedizioni/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuova Spedizione</a>
    <?php endif; ?>
</div>

<!-- Filtri -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" name="q" class="form-control" placeholder="Tracking, corriere, note..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= getSpedizioneStatusLabel($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dealer_id" class="form-select">
                    <option value="">Tutti i concessionari</option>
                    <?php foreach ($dealers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($_GET['dealer_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                        <th>#</th>
                        <th>Tracking</th>
                        <th>Corriere</th>
                        <th>Stato</th>
                        <th>Concessionario</th>
                        <th>Ticket</th>
                        <th>Data Spedizione</th>
                        <th>Data Prevista</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($spedizioni): ?>
                    <?php foreach ($spedizioni as $sp): ?>
                    <tr>
                        <td class="text-muted small"><?= $sp['id'] ?></td>
                        <td><a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $sp['id'] ?>" class="fw-bold text-primary text-decoration-none font-monospace"><?= $sp['tracking_number'] ? h($sp['tracking_number']) : '<span class="text-muted">-</span>' ?></a></td>
                        <td class="small"><?= $sp['corriere'] ? h($sp['corriere']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= getSpedizioneStatusBadge($sp['status']) ?></td>
                        <td class="small"><?= $sp['dealer_name'] ? h($sp['dealer_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="small"><?= $sp['ticket_title'] ? '<a href="'.APP_URL.'/modules/tickets/view.php?id='.$sp['ticket_id'].'" class="text-decoration-none">'.h(mb_strimwidth($sp['ticket_title'],0,40,'…')).'</a>' : '<span class="text-muted">-</span>' ?></td>
                        <td class="small text-muted"><?= $sp['data_spedizione'] ? formatDate($sp['data_spedizione'], 'd/m/Y') : '-' ?></td>
                        <td class="small text-muted"><?= $sp['data_prevista_consegna'] ? formatDate($sp['data_prevista_consegna'], 'd/m/Y') : '-' ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $sp['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isTechnician() && !in_array($sp['status'], ['consegnata','annullata'])): ?>
                                <a href="<?= APP_URL ?>/modules/spedizioni/edit.php?id=<?= $sp['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-truck fs-1 d-block mb-2 opacity-50"></i>Nessuna spedizione trovata</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>-<?= min($offset+$perPage,$totalRows) ?> di <?= $totalRows ?> spedizioni</small>
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
