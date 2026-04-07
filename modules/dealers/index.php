<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('dealers')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Concessionari');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Concessionari' => '']);

$db = getDB();

$q = trim($_GET['q'] ?? '');
$filterActive = $_GET['active'] ?? '';

$where = ['1=1'];
$params = [];
if ($q) { $where[] = '(d.name LIKE ? OR d.code LIKE ? OR d.city LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($filterActive !== '') { $where[] = 'd.active=?'; $params[] = (int)$filterActive; }

$whereStr = implode(' AND ', $where);
$stmt = $db->prepare("SELECT d.*, COUNT(DISTINCT dl.id) as location_count, COUNT(DISTINCT t.id) as ticket_count FROM dealers d LEFT JOIN dealer_locations dl ON dl.dealer_id=d.id AND dl.active=1 LEFT JOIN tickets t ON t.dealer_id=d.id WHERE $whereStr GROUP BY d.id ORDER BY d.name");
$stmt->execute($params);
$dealers = $stmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-shop me-2 text-primary"></i>Concessionari</h4>
    <?php if (isAdmin()): ?>
    <a href="<?= APP_URL ?>/modules/dealers/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuovo Concessionario</a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-6">
                <input type="text" name="q" class="form-control" placeholder="Cerca per nome, codice, città..." value="<?= h($q) ?>">
            </div>
            <div class="col-md-3">
                <select name="active" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>Attivi</option>
                    <option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>Inattivi</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-1">
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
                        <th>Codice</th>
                        <th>Nome</th>
                        <th>Città</th>
                        <th>Telefono</th>
                        <th>Punti Vendita</th>
                        <th>Ticket Aperti</th>
                        <th>Stato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($dealers): ?>
                    <?php foreach ($dealers as $d): ?>
                    <tr>
                        <td class="font-monospace small fw-semibold"><?= h($d['code']) ?></td>
                        <td><a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $d['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= h($d['name']) ?></a></td>
                        <td class="small"><?= $d['city'] ? h($d['city']) : '-' ?></td>
                        <td class="small"><?= $d['phone'] ? h($d['phone']) : '-' ?></td>
                        <td><span class="badge bg-secondary"><?= (int)$d['location_count'] ?></span></td>
                        <td><a href="<?= APP_URL ?>/modules/tickets/index.php?dealer_id=<?= $d['id'] ?>" class="badge bg-primary text-decoration-none"><?= (int)$d['ticket_count'] ?></a></td>
                        <td><?= $d['active'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Inattivo</span>' ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $d['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isAdmin()): ?>
                                <a href="<?= APP_URL ?>/modules/dealers/edit.php?id=<?= $d['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-shop fs-1 d-block mb-2"></i>Nessun concessionario trovato</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
