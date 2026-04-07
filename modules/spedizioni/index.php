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
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterStatus = $_GET['status'] ?? '';
$filterDealer = (int)($_GET['dealer_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 's.status=?'; $params[] = $filterStatus; }
if ($filterDealer) { $where[] = 's.dealer_id=?'; $params[] = $filterDealer; }
if ($q) { $where[] = '(s.numero_tracking LIKE ? OR s.destinatario LIKE ? OR s.corriere LIKE ?)'; $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]); }
$whereStr = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM spedizioni s WHERE $whereStr");
$totalStmt->execute($params);
$totalRows = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("SELECT s.*, t.title as ticket_title, d.name as dealer_name, dl.name as location_name, u.full_name as creator_name FROM spedizioni s LEFT JOIN tickets t ON s.ticket_id=t.id LEFT JOIN dealers d ON s.dealer_id=d.id LEFT JOIN dealer_locations dl ON s.location_id=dl.id LEFT JOIN users u ON s.created_by=u.id WHERE $whereStr ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$spedizioni = $stmt->fetchAll();

$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Spedizioni</h4>
    <?php if (isTechnician()): ?>
    <a href="<?= APP_URL ?>/modules/spedizioni/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuova Spedizione</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Tracking, destinatario, corriere..." value="<?= h($q) ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Tutti gli stati</option>
                    <option value="da_spedire" <?= $filterStatus === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                    <option value="spedita" <?= $filterStatus === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                    <option value="consegnata" <?= $filterStatus === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                    <option value="annullata" <?= $filterStatus === 'annullata' ? 'selected' : '' ?>>Annullata</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="dealer_id" class="form-select form-select-sm">
                    <option value="">Tutti i concessionari</option>
                    <?php foreach ($dealers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filterDealer == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-outline-primary btn-sm flex-fill"><i class="bi bi-search"></i></button>
                <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
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
                        <th>Stato</th>
                        <th>Destinatario</th>
                        <th>Corriere</th>
                        <th>Tracking</th>
                        <th>Ticket</th>
                        <th>Concessionario</th>
                        <th>Spedita il</th>
                        <th>Consegna Prev.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($spedizioni): ?>
                    <?php foreach ($spedizioni as $s): ?>
                    <tr>
                        <td class="small text-muted font-monospace">SPD-<?= str_pad($s['id'],4,'0',STR_PAD_LEFT) ?></td>
                        <td><?= getSpedizioneStatusBadge($s['status']) ?></td>
                        <td><?= $s['destinatario'] ? h($s['destinatario']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="small"><?= $s['corriere'] ? h($s['corriere']) : '-' ?></td>
                        <td class="small font-monospace"><?= $s['numero_tracking'] ? h($s['numero_tracking']) : '-' ?></td>
                        <td class="small"><?= $s['ticket_title'] ? '<a href="'.APP_URL.'/modules/tickets/view.php?id='.$s['ticket_id'].'" class="text-decoration-none">'.h(getTicketPrefix().'-'.str_pad($s['ticket_id'],4,'0',STR_PAD_LEFT)).'</a>' : '-' ?></td>
                        <td class="small"><?= $s['dealer_name'] ? h($s['dealer_name']) : '-' ?></td>
                        <td class="small text-muted"><?= $s['data_spedizione'] ? formatDate($s['data_spedizione'], 'd/m/Y') : '-' ?></td>
                        <td class="small text-muted"><?= $s['data_consegna_prevista'] ? formatDate($s['data_consegna_prevista'], 'd/m/Y') : '-' ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary btn-sm py-0" title="Dettaglio"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-truck fs-1 d-block mb-2"></i>Nessuna spedizione trovata</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>-<?= min($offset+$perPage,$totalRows) ?> di <?= $totalRows ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
