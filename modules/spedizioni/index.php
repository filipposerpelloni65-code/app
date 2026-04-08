<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Spedizioni');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Spedizioni' => '']);

$db = getDB();
$user = currentUser();

$perPage = (int)getSetting('items_per_page', '25');
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if (!empty($_GET['status'])) { $where[] = 's.status=?'; $params[] = $_GET['status']; }
if (!empty($_GET['corriere'])) { $where[] = 's.corriere LIKE ?'; $params[] = '%' . $_GET['corriere'] . '%'; }
if (!empty($_GET['q'])) { $where[] = '(s.tracking_number LIKE ? OR s.note LIKE ? OR sp.name LIKE ?)'; $params[] = '%'.$_GET['q'].'%'; $params[] = '%'.$_GET['q'].'%'; $params[] = '%'.$_GET['q'].'%'; }

$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM spedizioni s LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id=spr.id LEFT JOIN spare_parts sp ON spr.part_id=sp.id WHERE $whereStr");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT s.*,
        t.title AS ticket_title,
        spr.id AS request_id,
        sp.name AS part_name,
        d.name AS dealer_name,
        dl.name AS location_name
    FROM spedizioni s
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
    LEFT JOIN spare_parts sp ON spr.part_id = sp.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    WHERE $whereStr
    ORDER BY s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$spedizioni = $stmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Gestione Spedizioni</h4>
    <?php if (isTechnician()): ?>
    <a href="<?= APP_URL ?>/modules/spedizioni/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuova Spedizione</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Cerca tracking, ricambio, note..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Tutti gli stati</option>
                    <option value="da_spedire" <?= ($_GET['status'] ?? '') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                    <option value="spedita" <?= ($_GET['status'] ?? '') === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                    <option value="consegnata" <?= ($_GET['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                    <option value="annullata" <?= ($_GET['status'] ?? '') === 'annullata' ? 'selected' : '' ?>>Annullata</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="text" name="corriere" class="form-control form-control-sm" placeholder="Corriere..." value="<?= h($_GET['corriere'] ?? '') ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-outline-primary flex-fill"><i class="bi bi-search"></i></button>
                <a href="?" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Status summary badges -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php
    $statusCounts = $db->query("SELECT status, COUNT(*) AS cnt FROM spedizioni GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $statusList = ['da_spedire' => 'Da Spedire', 'spedita' => 'Spedita', 'consegnata' => 'Consegnata', 'annullata' => 'Annullata'];
    foreach ($statusList as $sk => $sl):
        $cnt = $statusCounts[$sk] ?? 0;
    ?>
    <a href="?status=<?= $sk ?>" class="text-decoration-none">
        <?= getSpedizioneStatusBadge($sk) ?> <small class="text-muted"><?= $cnt ?></small>
    </a>
    <?php endforeach; ?>
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
                        <th>Ricambio / Richiesta</th>
                        <th>Ticket</th>
                        <th>Concessionario</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($spedizioni): ?>
                    <?php foreach ($spedizioni as $s): ?>
                    <tr>
                        <td class="text-muted small"><?= $s['id'] ?></td>
                        <td>
                            <?php if ($s['tracking_number']): ?>
                            <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $s['id'] ?>" class="fw-bold text-decoration-none font-monospace"><?= h($s['tracking_number']) ?></a>
                            <?php else: ?>
                            <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $s['id'] ?>" class="text-muted small">N/D</a>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $s['corriere'] ? h($s['corriere']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= getSpedizioneStatusBadge($s['status']) ?></td>
                        <td class="small"><?= $s['part_name'] ? h($s['part_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="small">
                            <?php if ($s['ticket_id']): ?>
                            <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $s['ticket_id'] ?>" class="text-decoration-none"><?= h(getTicketPrefix() . '-' . str_pad($s['ticket_id'], 4, '0', STR_PAD_LEFT)) ?></a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $s['dealer_name'] ? h($s['dealer_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="small text-muted"><?= formatDate($s['created_at'], 'd/m/Y') ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isTechnician()): ?>
                                <a href="<?= APP_URL ?>/modules/spedizioni/edit.php?id=<?= $s['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-truck fs-1 d-block mb-2"></i>Nessuna spedizione trovata</td></tr>
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
