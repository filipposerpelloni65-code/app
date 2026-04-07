<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('dealers')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/dealers/index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM dealers WHERE id=?");
$stmt->execute([$id]);
$dealer = $stmt->fetch();
if (!$dealer) { header('Location: ' . APP_URL . '/modules/dealers/index.php'); exit; }

define('PAGE_TITLE', 'Modifica Concessionario');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Concessionari' => APP_URL.'/modules/dealers/index.php', h($dealer['name']) => APP_URL.'/modules/dealers/view.php?id='.$id, 'Modifica' => '']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }
    $name    = trim($_POST['name'] ?? '');
    $code    = strtoupper(trim($_POST['code'] ?? ''));
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city    = trim($_POST['city'] ?? '');
    $region  = trim($_POST['region'] ?? '');
    $active  = isset($_POST['active']) ? 1 : 0;
    $notes   = trim($_POST['notes'] ?? '');

    if (!$name) $errors[] = 'Il nome è obbligatorio.';
    if (!$code) $errors[] = 'Il codice è obbligatorio.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida.';

    if (!$errors) {
        try {
            $stmt = $db->prepare("UPDATE dealers SET name=?, code=?, email=?, phone=?, address=?, city=?, region=?, active=?, notes=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$name, $code, $email, $phone, $address, $city, $region, $active, $notes, $id]);
            logActivity($user['id'], 'update', 'dealer', $id, "Modificato concessionario: $name");
            header('Location: ' . APP_URL . '/modules/dealers/view.php?id=' . $id . '&updated=1');
            exit;
        } catch (Exception $e) {
            $errors[] = strpos($e->getMessage(), 'Duplicate') !== false ? 'Il codice concessionario è già in uso.' : 'Errore durante il salvataggio.';
        }
    }
} else {
    // Pre-fill from DB
    $_POST = $dealer;
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2 text-secondary"></i>Modifica Concessionario</h4>
    <a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla scheda</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <label class="form-label fw-semibold">Nome Concessionario <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required value="<?= h($_POST['name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Codice <span class="text-danger">*</span></label>
            <input type="text" name="code" class="form-control text-uppercase" required value="<?= h($_POST['code'] ?? '') ?>">
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Telefono</label>
            <input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Indirizzo</label>
        <input type="text" name="address" class="form-control" value="<?= h($_POST['address'] ?? '') ?>">
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Città</label>
            <input type="text" name="city" class="form-control" value="<?= h($_POST['city'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Regione</label>
            <input type="text" name="region" class="form-control" value="<?= h($_POST['region'] ?? '') ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Note</label>
        <textarea name="notes" class="form-control" rows="3"><?= h($_POST['notes'] ?? '') ?></textarea>
    </div>
    <div class="mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($_POST['active'] ?? 0) ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Concessionario attivo</label>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $id ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
