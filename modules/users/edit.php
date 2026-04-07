<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('users')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$me = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/users/index.php'); exit; }

$target = $db->prepare("SELECT * FROM users WHERE id=?");
$target->execute([$id]);
$target = $target->fetch();
if (!$target) { header('Location: ' . APP_URL . '/modules/users/index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $errors[] = 'Token non valido.';
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    if (!$full_name) $errors[] = 'Il nome completo è obbligatorio.';
    if (!$username) $errors[] = 'Lo username è obbligatorio.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida.';
    if (!in_array($role, ['admin','technician','user'])) $errors[] = 'Ruolo non valido.';
    if ($password && strlen($password) < 6) $errors[] = 'La password deve essere di almeno 6 caratteri.';
    if ($password && $password !== $password2) $errors[] = 'Le password non corrispondono.';
    if (!$errors) {
        $check = $db->prepare("SELECT id FROM users WHERE (username=? OR email=?) AND id!=?");
        $check->execute([$username, $email, $id]);
        if ($check->fetch()) { $errors[] = 'Username o email già in uso.'; }
    }
    if (!$errors) {
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, password_hash=? WHERE id=?")->execute([$full_name, $username, $email, $role, $hash, $id]);
        } else {
            $db->prepare("UPDATE users SET full_name=?, username=?, email=?, role=? WHERE id=?")->execute([$full_name, $username, $email, $role, $id]);
        }
        logActivity($me['id'], 'edit', 'user', $id, "Modificato utente: $username");
        header('Location: ' . APP_URL . '/modules/users/index.php?updated=1');
        exit;
    }
    $target = array_merge($target, compact('full_name','username','email','role'));
}

define('PAGE_TITLE', 'Modifica Utente');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Utenti' => APP_URL.'/modules/users/index.php', 'Modifica' => '']);

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-6">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-person-gear me-2 text-secondary"></i>Modifica Utente</h4>
    <a href="<?= APP_URL ?>/modules/users/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Nome Completo <span class="text-danger">*</span></label>
        <input type="text" name="full_name" class="form-control" required value="<?= h($target['full_name']) ?>">
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" required value="<?= h($target['username']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Ruolo</label>
            <select name="role" class="form-select" <?= $id === $me['id'] ? 'disabled' : '' ?>>
                <option value="user" <?= $target['role']==='user'?'selected':'' ?>>Utente</option>
                <option value="technician" <?= $target['role']==='technician'?'selected':'' ?>>Tecnico</option>
                <option value="admin" <?= $target['role']==='admin'?'selected':'' ?>>Admin</option>
            </select>
            <?php if ($id === $me['id']): ?><input type="hidden" name="role" value="<?= h($target['role']) ?>"><?php endif; ?>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
        <input type="email" name="email" class="form-control" required value="<?= h($target['email']) ?>">
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Nuova Password <span class="text-muted fw-normal small">(lascia vuoto per non cambiare)</span></label>
            <input type="password" name="password" class="form-control" minlength="6">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Conferma Password</label>
            <input type="password" name="password2" class="form-control" minlength="6">
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/users/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
