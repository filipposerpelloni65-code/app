<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spare_parts')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/spare_parts/index.php'); exit; }

$stmt = $db->prepare("SELECT p.*, c.name as category_name FROM spare_parts p LEFT JOIN spare_parts_categories c ON p.category_id=c.id WHERE p.id=?");
$stmt->execute([$id]);
$part = $stmt->fetch();
if (!$part) { header('Location: ' . APP_URL . '/modules/spare_parts/index.php'); exit; }

// Handle delete (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAdmin()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $deleteError = 'Token non valido.';
    } else {
        $inUse = $db->prepare("SELECT COUNT(*) FROM spare_parts_requests WHERE part_id=? AND status IN ('pending','approved')");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            $deleteError = 'Impossibile eliminare: la parte è presente in richieste attive (in attesa o approvate).';
        } else {
            $db->prepare("DELETE FROM spare_parts_requests WHERE part_id=?")->execute([$id]);
            $db->prepare("DELETE FROM spare_parts WHERE id=?")->execute([$id]);
            logActivity($user['id'], 'delete', 'spare_part', $id, "Eliminata parte: " . $part['name']);
            header('Location: ' . APP_URL . '/modules/spare_parts/index.php?deleted=1');
            exit;
        }
    }
}

// Handle quick stock adjustment (technician)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock' && isTechnician()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $stockError = 'Token non valido.';
    } else {
        $delta = (int)($_POST['delta'] ?? 0);
        if ($delta !== 0) {
            $db->prepare("UPDATE spare_parts SET quantity=GREATEST(0, quantity + ?), updated_at=NOW() WHERE id=?")->execute([$delta, $id]);
            logActivity($user['id'], 'adjust_stock', 'spare_part', $id, "Rettifica scorte: $delta");
        }
        header('Location: ' . APP_URL . '/modules/spare_parts/view.php?id=' . $id . '&stock_updated=1');
        exit;
    }
}

// Reload part after possible stock update
$stmt = $db->prepare("SELECT p.*, c.name as category_name FROM spare_parts p LEFT JOIN spare_parts_categories c ON p.category_id=c.id WHERE p.id=?");
$stmt->execute([$id]);
$part = $stmt->fetch();

// Fetch recent requests for this part
$requests = $db->prepare("SELECT spr.*, u.full_name as requester_name, t.title as ticket_title FROM spare_parts_requests spr JOIN users u ON spr.requested_by=u.id LEFT JOIN tickets t ON spr.ticket_id=t.id WHERE spr.part_id=? ORDER BY spr.created_at DESC LIMIT 20");
$requests->execute([$id]);
$requests = $requests->fetchAll();

$lowStock = (int)$part['quantity'] <= (int)$part['min_quantity'];

define('PAGE_TITLE', h($part['name']));
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Parti di Ricambio' => APP_URL.'/modules/spare_parts/index.php', h($part['name']) => '']);

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['stock_updated'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Scorta aggiornata. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($deleteError)): ?>
<div class="alert alert-danger"><?= h($deleteError) ?></div>
<?php endif; ?>
<?php if (!empty($stockError)): ?>
<div class="alert alert-danger"><?= h($stockError) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-tools me-2 text-primary"></i><?= h($part['name']) ?></h4>
    <div class="d-flex gap-2">
        <?php if (isTechnician()): ?>
        <a href="<?= APP_URL ?>/modules/spare_parts/request.php?part_id=<?= $id ?>" class="btn btn-success btn-sm"><i class="bi bi-cart-plus me-1"></i>Richiedi</a>
        <a href="<?= APP_URL ?>/modules/spare_parts/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/spare_parts/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0">Dettagli Parte</h6></div>
            <div class="card-body">
                <?php if ($lowStock): ?>
                <div class="alert alert-danger py-2 px-3 mb-3 small"><i class="bi bi-exclamation-triangle me-1"></i>Scorta Bassa</div>
                <?php endif; ?>
                <dl class="row mb-0 small">
                    <dt class="col-sm-5">SKU</dt>
                    <dd class="col-sm-7 font-monospace"><?= h($part['sku']) ?></dd>
                    <dt class="col-sm-5">Categoria</dt>
                    <dd class="col-sm-7"><?= $part['category_name'] ? h($part['category_name']) : '-' ?></dd>
                    <dt class="col-sm-5">Posizione</dt>
                    <dd class="col-sm-7"><?= $part['location'] ? h($part['location']) : '-' ?></dd>
                    <dt class="col-sm-5">Prezzo</dt>
                    <dd class="col-sm-7"><?= $part['unit_price'] ? '€ ' . number_format((float)$part['unit_price'], 2, ',', '.') : '-' ?></dd>
                    <dt class="col-sm-5">Quantità</dt>
                    <dd class="col-sm-7"><span class="fw-bold fs-5 <?= $lowStock ? 'text-danger' : 'text-success' ?>"><?= (int)$part['quantity'] ?></span></dd>
                    <dt class="col-sm-5">Min. Scorta</dt>
                    <dd class="col-sm-7"><?= (int)$part['min_quantity'] ?></dd>
                    <dt class="col-sm-5">Creata il</dt>
                    <dd class="col-sm-7"><?= formatDate($part['created_at'], 'd/m/Y') ?></dd>
                    <dt class="col-sm-5">Aggiornata</dt>
                    <dd class="col-sm-7"><?= formatDate($part['updated_at'], 'd/m/Y') ?></dd>
                </dl>
            </div>
        </div>

        <?php if (isTechnician()): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0">Rettifica Scorte</h6></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="adjust_stock">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Quantità da aggiungere (+) o sottrarre (-)</label>
                        <input type="number" name="delta" class="form-control" value="1" placeholder="Es. +5 o -2" required>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus-slash-minus me-1"></i>Applica Rettifica</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <div class="card shadow-sm border-danger">
            <div class="card-header bg-white"><h6 class="mb-0 text-danger">Zona Pericolosa</h6></div>
            <div class="card-body">
                <form method="post" onsubmit="return confirm('Eliminare definitivamente questa parte di ricambio? L\'operazione non è reversibile.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm w-100"><i class="bi bi-trash me-1"></i>Elimina Parte</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <?php if ($part['description']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0">Descrizione</h6></div>
            <div class="card-body small" style="white-space:pre-wrap"><?= h($part['description']) ?></div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Richieste Recenti (<?= count($requests) ?>)</h6>
                <?php if (isTechnician()): ?>
                <a href="<?= APP_URL ?>/modules/spare_parts/requests.php" class="btn btn-outline-secondary btn-sm">Tutte le richieste</a>
                <?php endif; ?>
            </div>
            <?php if ($requests): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Richiedente</th><th>Qtà</th><th>Ticket</th><th>Stato</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td class="small text-muted"><?= $r['id'] ?></td>
                        <td class="small"><?= h($r['requester_name']) ?></td>
                        <td><?= (int)$r['quantity'] ?></td>
                        <td class="small"><?= $r['ticket_title'] ? '<a href="'.APP_URL.'/modules/tickets/view.php?id='.$r['ticket_id'].'" class="text-decoration-none">'.h(getTicketPrefix().'-'.str_pad($r['ticket_id'],4,'0',STR_PAD_LEFT)).'</a>' : '-' ?></td>
                        <td><?= getRequestStatusBadge($r['status']) ?></td>
                        <td class="small text-muted"><?= formatDate($r['created_at'], 'd/m/Y') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body text-center text-muted py-4"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>Nessuna richiesta per questa parte.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
