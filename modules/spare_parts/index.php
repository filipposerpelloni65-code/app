<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spare_parts')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Parti di Ricambio');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Parti di Ricambio' => '']);

$db = getDB();
$user = currentUser();

$perPage = (int)getSetting('items_per_page', '25');
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if (!empty($_GET['q'])) { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?)'; $params = array_merge($params, ['%'.$_GET['q'].'%','%'.$_GET['q'].'%','%'.$_GET['q'].'%']); }
if (!empty($_GET['category_id'])) { $where[] = 'p.category_id=?'; $params[] = (int)$_GET['category_id']; }
if (($_GET['filter'] ?? '') === 'low_stock') { $where[] = 'p.quantity <= p.min_quantity'; }
$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM spare_parts p WHERE $whereStr");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("SELECT p.*, c.name as category_name FROM spare_parts p LEFT JOIN spare_parts_categories c ON p.category_id=c.id WHERE $whereStr ORDER BY p.name LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$parts = $stmt->fetchAll();

$categories = $db->query("SELECT * FROM spare_parts_categories ORDER BY name")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-tools me-2 text-primary"></i>Parti di Ricambio</h4>
    <?php if (isTechnician()): ?>
    <a href="<?= APP_URL ?>/modules/spare_parts/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuova Parte</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Parte creata con successo. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Parte aggiornata con successo. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Parte eliminata con successo. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-5">
                <input type="text" name="q" class="form-control" placeholder="Cerca per nome o SKU..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="category_id" class="form-select">
                    <option value="">Tutte le categorie</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="filter" class="form-select">
                    <option value="">Tutte</option>
                    <option value="low_stock" <?= ($_GET['filter'] ?? '') === 'low_stock' ? 'selected' : '' ?>>Scorte Basse</option>
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
                        <th>Nome</th>
                        <th>SKU</th>
                        <th>Categoria</th>
                        <th>Posizione</th>
                        <th>Quantità</th>
                        <th>Min.</th>
                        <th>Prezzo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($parts): ?>
                    <?php foreach ($parts as $p): ?>
                    <?php $lowStock = (int)$p['quantity'] <= (int)$p['min_quantity']; ?>
                    <tr class="<?= $lowStock ? 'table-danger' : '' ?>">
                        <td>
                            <strong><?= h($p['name']) ?></strong>
                            <?php if ($lowStock): ?><span class="badge bg-danger ms-1">Scorta Bassa</span><?php endif; ?>
                            <?php if ($p['description']): ?><div class="small text-muted text-truncate" style="max-width:200px"><?= h($p['description']) ?></div><?php endif; ?>
                        </td>
                        <td class="small text-muted font-monospace"><?= h($p['sku']) ?></td>
                        <td class="small"><?= $p['category_name'] ? h($p['category_name']) : '-' ?></td>
                        <td class="small"><?= $p['location'] ? h($p['location']) : '-' ?></td>
                        <td>
                            <span class="fw-bold <?= $lowStock ? 'text-danger' : 'text-success' ?>"><?= (int)$p['quantity'] ?></span>
                        </td>
                        <td class="small text-muted"><?= (int)$p['min_quantity'] ?></td>
                        <td class="small"><?= $p['unit_price'] ? '€ ' . number_format($p['unit_price'], 2, ',', '.') : '-' ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/spare_parts/view.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isTechnician()): ?>
                                <button type="button"
                                    class="btn btn-outline-success part-request-modal-btn"
                                    data-part-id="<?= $p['id'] ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#partRequestModal"
                                    title="Richiedi"><i class="bi bi-cart-plus"></i></button>
                                <a href="<?= APP_URL ?>/modules/spare_parts/edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-tools fs-1 d-block mb-2"></i>Nessuna parte trovata</td></tr>
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

<?php if (isTechnician()): ?>
<?php
$modalParts   = getModalSpareParts();
$modalTickets = getModalOpenTickets();
?>
<!-- ============================================================
     Parts Request Modal
     ============================================================ -->
<div class="modal fade" id="partRequestModal" tabindex="-1" aria-labelledby="partRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-semibold" id="partRequestModalLabel">
                    <i class="bi bi-cart-plus text-success me-2"></i>Richiedi Parte di Ricambio
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <form method="post" action="<?= APP_URL ?>/modules/spare_parts/request.php">
                <?= csrfField() ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Parte <span class="text-danger">*</span></label>
                        <select name="part_id" class="form-select" id="modalPartSelect" required>
                            <option value="">-- Seleziona parte --</option>
                            <?php foreach ($modalParts as $mp): ?>
                            <option value="<?= $mp['id'] ?>">
                                <?= h($mp['name']) ?> (<?= h($mp['sku']) ?>) — <?= (int)$mp['quantity'] ?> disponibili
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantità</label>
                        <input type="number" name="quantity" id="modalPartQty" class="form-control" value="1" min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ticket associato</label>
                        <select name="ticket_id" class="form-select">
                            <option value="">-- Nessun ticket --</option>
                            <?php foreach ($modalTickets as $mt): ?>
                            <option value="<?= $mt['id'] ?>">
                                <?= h(getTicketPrefix() . '-' . str_pad($mt['id'], 4, '0', STR_PAD_LEFT) . ' — ' . $mt['title']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Note</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Note aggiuntive..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Annulla
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send me-1"></i>Invia Richiesta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>window.appUrl = <?= json_encode(APP_URL) ?>;</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
