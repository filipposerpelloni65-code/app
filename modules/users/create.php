<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('users')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuovo Utente');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Utenti' => APP_URL.'/modules/users/index.php', 'Nuovo Utente' => '']);

$db = getDB();
$user = currentUser();
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
    if (strlen($password) < 6) $errors[] = 'La password deve essere di almeno 6 caratteri.';
    if ($password !== $password2) $errors[] = 'Le password non corrispondono.';
    if (!$errors) {
        $check = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->execute([$username, $email]);
        if ($check->fetch()) { $errors[] = 'Username o email già in uso.'; }
    }
    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (full_name, username, email, password_hash, role, active) VALUES (?,?,?,?,?,1)");
        $stmt->execute([$full_name, $username, $email, $hash, $role]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'user', $newId, "Creato utente: $username");
        header('Location: ' . APP_URL . '/modules/users/index.php?created=1');
        exit;
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-6">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>Nuovo Utente</h4>
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
        <input type="text" name="full_name" class="form-control" required value="<?= h($_POST['full_name'] ?? '') ?>">
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" required value="<?= h($_POST['username'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Ruolo</label>
            <select name="role" class="form-select">
                <option value="user" <?= ($_POST['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>Utente</option>
                <option value="technician" <?= ($_POST['role'] ?? '') === 'technician' ? 'selected' : '' ?>>Tecnico</option>
                <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
        <input type="email" name="email" class="form-control" required value="<?= h($_POST['email'] ?? '') ?>">
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" required minlength="6">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Conferma Password <span class="text-danger">*</span></label>
            <input type="password" name="password2" class="form-control" required minlength="6">
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea Utente</button>
        <a href="<?= APP_URL ?>/modules/users/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
