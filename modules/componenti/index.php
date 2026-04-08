<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('componenti')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Catalogo Componenti');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Componenti' => '']);

$db = getDB();
$user = currentUser();

$perPage = (int)getSetting('items_per_page', '25');
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if (!empty($_GET['tipo']))   { $where[] = 'tipo=?';         $params[] = $_GET['tipo']; }
if (!empty($_GET['q']))      { $where[] = '(nome LIKE ? OR marca LIKE ?)'; $params[] = '%'.$_GET['q'].'%'; $params[] = '%'.$_GET['q'].'%'; }
if (isset($_GET['active']) && $_GET['active'] !== '') { $where[] = 'active=?'; $params[] = (int)$_GET['active']; }

$whereStr  = implode(' AND ', $where);

$cntStmt = $db->prepare("SELECT COUNT(*) FROM modelli_componenti WHERE $whereStr");
$cntStmt->execute($params);
$totalRows  = (int)$cntStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("SELECT * FROM modelli_componenti WHERE $whereStr ORDER BY tipo, nome LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$modelli = $stmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-cpu me-2 text-primary"></i>Catalogo Componenti</h4>
    <?php if (isAdmin()): ?>
    <a href="<?= APP_URL ?>/modules/componenti/create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuovo Modello</a>
    <?php endif; ?>
</div>

<!-- Filtri -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Nome, marca..." value="<?= h($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <select name="tipo" class="form-select">
                    <option value="">Tutti i tipi</option>
                    <option value="periferica" <?= ($_GET['tipo'] ?? '') === 'periferica' ? 'selected' : '' ?>>Periferica</option>
                    <option value="accessorio" <?= ($_GET['tipo'] ?? '') === 'accessorio' ? 'selected' : '' ?>>Accessorio</option>
                    <option value="cavo" <?= ($_GET['tipo'] ?? '') === 'cavo' ? 'selected' : '' ?>>Cavo</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="active" class="form-select">
                    <option value="">Tutti</option>
                    <option value="1" <?= ($_GET['active'] ?? '') === '1' ? 'selected' : '' ?>>Attivi</option>
                    <option value="0" <?= ($_GET['active'] ?? '') === '0' ? 'selected' : '' ?>>Disattivi</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-outline-primary flex-fill"><i class="bi bi-search"></i> Cerca</button>
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
                        <th>Tipo</th>
                        <th>Nome Modello</th>
                        <th>Marca</th>
                        <th>Ha Seriale</th>
                        <th>Stato</th>
                        <?php if (isAdmin()): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($modelli): ?>
                    <?php foreach ($modelli as $m): ?>
                    <tr>
                        <td><?= getComponenteTipoBadge($m['tipo']) ?></td>
                        <td class="fw-semibold"><?= h($m['nome']) ?></td>
                        <td class="small text-muted"><?= $m['marca'] ? h($m['marca']) : '-' ?></td>
                        <td>
                            <?php if ($m['tipo'] === 'periferica'): ?>
                            <span class="badge bg-success"><i class="bi bi-upc-scan me-1"></i>Sì</span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border">No</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $m['active'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Disattivo</span>' ?></td>
                        <?php if (isAdmin()): ?>
                        <td>
                            <a href="<?= APP_URL ?>/modules/componenti/edit.php?id=<?= $m['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Modifica"><i class="bi bi-pencil"></i></a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">
                        <i class="bi bi-cpu fs-1 d-block mb-2 opacity-50"></i>Nessun modello trovato.
                        <?php if (isAdmin()): ?><div class="mt-2"><a href="<?= APP_URL ?>/modules/componenti/create.php">Aggiungi il primo modello</a></div><?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>-<?= min($offset+$perPage,$totalRows) ?> di <?= $totalRows ?> modelli</small>
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
