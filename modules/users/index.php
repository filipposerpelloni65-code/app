<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('users')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Utenti');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Utenti' => '']);

$db = getDB();
$user = currentUser();

// Handle toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $uid = (int)$_POST['user_id'];
        if ($uid !== $user['id']) { // prevent self-disable
            $db->prepare("UPDATE users SET active = NOT active WHERE id=?")->execute([$uid]);
        }
    }
    header('Location: ' . APP_URL . '/modules/users/index.php');
    exit;
}

$q = trim($_GET['q'] ?? '');
$role = $_GET['role'] ?? '';
$where = '1=1';
$params = [];
if ($q) { $where .= ' AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)'; $params = array_merge($params, ["%$q%","%$q%","%$q%"]); }
if ($role) { $where .= ' AND role=?'; $params[] = $role; }

$stmt = $db->prepare("SELECT * FROM users WHERE $where ORDER BY full_name");
$stmt->execute($params);
$users = $stmt->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Gestione Utenti</h4>
    <a href="<?= APP_URL ?>/modules/users/create.php" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nuovo Utente</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2">
            <div class="col-md-5">
                <input type="text" name="q" class="form-control" placeholder="Cerca per nome, username, email..." value="<?= h($q) ?>">
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select">
                    <option value="">Tutti i ruoli</option>
                    <option value="admin" <?= $role==='admin'?'selected':'' ?>>Admin</option>
                    <option value="technician" <?= $role==='technician'?'selected':'' ?>>Tecnico</option>
                    <option value="user" <?= $role==='user'?'selected':'' ?>>Utente</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button class="btn btn-outline-primary flex-fill"><i class="bi bi-search"></i></button>
                <a href="?" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Nome</th><th>Username</th><th>Email</th><th>Ruolo</th><th>Stato</th><th>Registrato</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($users): ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="user-avatar user-avatar-sm"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
                            <?= h($u['full_name']) ?>
                        </div>
                    </td>
                    <td class="small font-monospace"><?= h($u['username']) ?></td>
                    <td class="small"><?= h($u['email']) ?></td>
                    <td>
                        <?php $roleColors = ['admin'=>'danger','technician'=>'warning text-dark','user'=>'primary']; ?>
                        <span class="badge bg-<?= $roleColors[$u['role']] ?? 'secondary' ?>"><?= h(ucfirst($u['role'])) ?></span>
                    </td>
                    <td><?= $u['active'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Disabilitato</span>' ?></td>
                    <td class="small text-muted"><?= formatDate($u['created_at'], 'd/m/Y') ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?= APP_URL ?>/modules/users/edit.php?id=<?= $u['id'] ?>" class="btn btn-outline-secondary" title="Modifica"><i class="bi bi-pencil"></i></a>
                            <?php if ($u['id'] !== $user['id']): ?>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" name="toggle_user" value="1" class="btn btn-outline-<?= $u['active']?'warning':'success' ?>" title="<?= $u['active']?'Disabilita':'Abilita' ?>"><i class="bi bi-<?= $u['active']?'person-dash':'person-check' ?>"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="text-center text-muted py-5">Nessun utente trovato</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>.user-avatar-sm{width:32px;height:32px;font-size:.85rem;line-height:32px;border-radius:50%;background:var(--bs-primary);color:#fff;text-align:center;display:inline-block;flex-shrink:0;}</style>

<?php include APP_ROOT . '/includes/footer.php'; ?>
