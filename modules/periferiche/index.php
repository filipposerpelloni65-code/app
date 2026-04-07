<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('periferiche')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Periferiche Guaste');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Periferiche Guaste' => '']);

$db = getDB();
$user = currentUser();

$perPage = (int)getSetting('items_per_page', '25');
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if (!empty($_GET['stato']))     { $where[] = 'p.stato=?';     $params[] = $_GET['stato']; }
if (!empty($_GET['dealer_id'])) { $where[] = 'p.dealer_id=?'; $params[] = (int)$_GET['dealer_id']; }
if (!empty($_GET['tipo']))      { $where[] = 'p.tipo LIKE ?'; $params[] = '%'.$_GET['tipo'].'%'; }
if (!empty($_GET['q']))         { $where[] = '(p.codice LIKE ? OR p.tipo LIKE ? OR p.marca LIKE ? OR p.modello LIKE ? OR p.seriale LIKE ?)'; $params = array_merge($params, array_fill(0, 5, '%'.$_GET['q'].'%')); }

$whereStr  = implode(' AND ', $where);
$totalStmt = $db->prepare("SELECT COUNT(*) FROM periferiche_guaste p WHERE $whereStr");
$totalStmt->execute($params);
$totalRows  = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT p.*,
        d.name AS dealer_name,
        dl.name AS location_name,
        t.title AS ticket_title,
        u.full_name AS tecnico_name
    FROM periferiche_guaste p
    LEFT JOIN dealers d ON p.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON p.location_id = dl.id
    LEFT JOIN tickets t ON p.ticket_id = t.id
    LEFT JOIN users u ON p.tecnico_ritiro_id = u.id
    WHERE $whereStr
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$periferiche = $stmt->fetchAll();

$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();

$statiAll = [
    'in_giacenza','in_diagnosi','in_riparazione',
    'riparata','non_riparabile','restituita','rottamata',
];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-hdd-network me-2 text-primary"></i>Periferiche Guaste</h4>
    <?php if (isTechnician()): ?>
    <a href="<?= APP_URL ?>/modules/periferiche/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Registra Periferica</a>
    <?php endif; ?>
</div>

<!-- Filtri -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" name="q" class="form-control" placeholder="Codice, tipo, marca, seriale..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <select name="stato" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <?php foreach ($statiAll as $s): ?>
                    <option value="<?= $s ?>" <?= ($_GET['stato'] ?? '') === $s ? 'selected' : '' ?>><?= getPerifericaStatoLabel($s) ?></option>
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
            <div class="col-md-2">
                <input type="text" name="tipo" class="form-control" placeholder="Tipo dispositivo..." value="<?= h($_GET['tipo'] ?? '') ?>">
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
                        <th>Codice</th>
                        <th>Tipo / Dispositivo</th>
                        <th>Seriale</th>
                        <th>Concessionario</th>
                        <th>Stato</th>
                        <th>Tecnico</th>
                        <th>Ritiro</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($periferiche): ?>
                    <?php foreach ($periferiche as $p): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $p['id'] ?>" class="fw-bold text-primary text-decoration-none font-monospace"><?= h($p['codice']) ?></a></td>
                        <td>
                            <div class="fw-semibold small"><?= h($p['tipo']) ?></div>
                            <?php if ($p['marca'] || $p['modello']): ?>
                            <div class="text-muted small"><?= h(trim($p['marca'] . ' ' . $p['modello'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small font-monospace text-muted"><?= $p['seriale'] ? h($p['seriale']) : '-' ?></td>
                        <td class="small"><?= $p['dealer_name'] ? h($p['dealer_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= getPerifericaStatoBadge($p['stato']) ?></td>
                        <td class="small"><?= $p['tecnico_name'] ? h($p['tecnico_name']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="small text-muted"><?= formatDate($p['data_ritiro'], 'd/m/Y') ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Visualizza"><i class="bi bi-eye"></i></a>
                                <?php if (isTechnician() && !in_array($p['stato'], ['restituita','rottamata'])): ?>
                                <a href="<?= APP_URL ?>/modules/periferiche/diagnosi.php?id=<?= $p['id'] ?>" class="btn btn-outline-warning" title="Aggiorna stato"><i class="bi bi-arrow-repeat"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-hdd-network fs-1 d-block mb-2 opacity-50"></i>Nessuna periferica trovata</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>-<?= min($offset+$perPage,$totalRows) ?> di <?= $totalRows ?> periferiche</small>
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
