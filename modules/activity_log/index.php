<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');

define('PAGE_TITLE', 'Log Attività');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Log Attività' => '']);

$db = getDB();

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Filters
$filterUser   = (int)($_GET['user_id'] ?? 0);
$filterAction = trim($_GET['action'] ?? '');
$filterEntity = trim($_GET['entity_type'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$filterQ      = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterUser)   { $where[] = 'al.user_id=?';            $params[] = $filterUser; }
if ($filterAction) { $where[] = 'al.action=?';             $params[] = $filterAction; }
if ($filterEntity) { $where[] = 'al.entity_type=?';        $params[] = $filterEntity; }
if ($dateFrom)     { $where[] = 'DATE(al.created_at)>=?';  $params[] = $dateFrom; }
if ($dateTo)       { $where[] = 'DATE(al.created_at)<=?';  $params[] = $dateTo; }
if ($filterQ)      { $where[] = 'al.details LIKE ?';       $params[] = '%'.$filterQ.'%'; }

$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM activity_log al WHERE $whereStr");
$total->execute($params);
$totalRows  = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $db->prepare("
    SELECT al.*, u.full_name, u.role AS user_role
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $whereStr
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Filter options
$users        = $db->query("SELECT DISTINCT al.user_id, u.full_name FROM activity_log al LEFT JOIN users u ON al.user_id=u.id WHERE u.full_name IS NOT NULL ORDER BY u.full_name")->fetchAll();
$actionTypes  = $db->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$entityTypes  = $db->query("SELECT DISTINCT entity_type FROM activity_log WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

// Action → icon mapping
$actionIcons = [
    'create'          => ['icon' => 'bi-plus-circle-fill',    'color' => 'success'],
    'update'          => ['icon' => 'bi-pencil-fill',          'color' => 'primary'],
    'delete'          => ['icon' => 'bi-trash-fill',           'color' => 'danger'],
    'comment'         => ['icon' => 'bi-chat-fill',            'color' => 'secondary'],
    'status_change'   => ['icon' => 'bi-arrow-repeat',         'color' => 'warning'],
    'add_uscita'      => ['icon' => 'bi-person-walking',       'color' => 'info'],
    'profile_update'  => ['icon' => 'bi-person-fill',          'color' => 'primary'],
    'password_change' => ['icon' => 'bi-key-fill',             'color' => 'warning'],
    'login'           => ['icon' => 'bi-box-arrow-in-right',   'color' => 'success'],
    'logout'          => ['icon' => 'bi-box-arrow-right',      'color' => 'secondary'],
    'settings_update' => ['icon' => 'bi-gear-fill',            'color' => 'secondary'],
    'add_componente'  => ['icon' => 'bi-cpu-fill',             'color' => 'info'],
];

$actionLabels = [
    'create'          => 'Creazione',
    'update'          => 'Modifica',
    'delete'          => 'Eliminazione',
    'comment'         => 'Commento',
    'status_change'   => 'Cambio stato',
    'add_uscita'      => 'Uscita tecnico',
    'profile_update'  => 'Profilo aggiornato',
    'password_change' => 'Password modificata',
    'login'           => 'Accesso',
    'logout'          => 'Disconnessione',
    'settings_update' => 'Impostazioni',
    'add_componente'  => 'Componente aggiunto',
];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-journal-text me-2 text-primary"></i>Log Attività di Sistema</h4>
    <span class="badge bg-secondary"><?= number_format($totalRows) ?> voci</span>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-3">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Cerca nei dettagli..." value="<?= h($filterQ) ?>">
            </div>
            <div class="col-md-2">
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">Tutti gli utenti</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $filterUser == $u['user_id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="action" class="form-select form-select-sm">
                    <option value="">Tutte le azioni</option>
                    <?php foreach ($actionTypes as $a): ?>
                    <option value="<?= h($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= h($actionLabels[$a] ?? $a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="entity_type" class="form-select form-select-sm">
                    <option value="">Tutti i tipi</option>
                    <?php foreach ($entityTypes as $e): ?>
                    <option value="<?= h($e) ?>" <?= $filterEntity === $e ? 'selected' : '' ?>><?= h(ucfirst($e)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>" title="Da data">
            </div>
            <div class="col-md-1">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>" title="A data">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-outline-primary btn-sm flex-fill"><i class="bi bi-search"></i></button>
                <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if ($entries): ?>
        <div class="activity-log-list">
            <?php foreach ($entries as $entry): ?>
            <?php $meta = $actionIcons[$entry['action']] ?? ['icon' => 'bi-circle-fill', 'color' => 'secondary']; ?>
            <div class="activity-log-item d-flex align-items-start gap-3 px-4 py-3 border-bottom">
                <div class="activity-icon rounded-circle bg-<?= $meta['color'] ?> bg-opacity-10 p-2 flex-shrink-0">
                    <i class="bi <?= $meta['icon'] ?> text-<?= $meta['color'] ?>"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                        <div>
                            <span class="fw-semibold small"><?= h($entry['full_name'] ?? 'Sistema') ?></span>
                            <span class="text-muted small mx-1">·</span>
                            <span class="small"><?= h($actionLabels[$entry['action']] ?? $entry['action']) ?></span>
                            <?php if ($entry['entity_type']): ?>
                            <span class="badge bg-light text-secondary border ms-1" style="font-size:.65rem">
                                <?= h(ucfirst($entry['entity_type'])) ?><?= $entry['entity_id'] ? ' #'.(int)$entry['entity_id'] : '' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <?php if ($entry['ip_address']): ?>
                            <code class="small text-muted"><?= h($entry['ip_address']) ?></code>
                            <?php endif; ?>
                            <small class="text-muted text-nowrap"><?= formatDate($entry['created_at'], 'd/m/Y H:i') ?></small>
                        </div>
                    </div>
                    <?php if ($entry['details']): ?>
                    <div class="text-muted small mt-1"><?= h($entry['details']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-journal-x fs-1 d-block mb-2 opacity-50"></i>Nessuna voce nel log.
        </div>
        <?php endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Mostra <?= min($offset+1,$totalRows) ?>–<?= min($offset+$perPage,$totalRows) ?> di <?= number_format($totalRows) ?> voci</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>"><i class="bi bi-chevron-left"></i></a></li>
                <?php endif; ?>
                <?php
                $startP = max(1, $page-2);
                $endP   = min($totalPages, $page+2);
                for ($i = $startP; $i <= $endP; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>"><i class="bi bi-chevron-right"></i></a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
