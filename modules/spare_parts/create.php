<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spare_parts')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuova Parte');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Parti di Ricambio' => APP_URL.'/modules/spare_parts/index.php', 'Nuova Parte' => '']);

$db = getDB();
$user = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $errors[] = 'Token non valido.';
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));
    $min_quantity = max(0, (int)($_POST['min_quantity'] ?? 0));
    $unit_price = !empty($_POST['unit_price']) ? (float)str_replace(',','.', $_POST['unit_price']) : null;
    $location = trim($_POST['location'] ?? '');
    if (!$name) $errors[] = 'Il nome è obbligatorio.';
    if (!$sku) $errors[] = 'Il codice SKU è obbligatorio.';
    if (!$errors) {
        // Check duplicate SKU
        $check = $db->prepare("SELECT id FROM spare_parts WHERE sku=?");
        $check->execute([$sku]);
        if ($check->fetch()) { $errors[] = 'SKU già esistente.'; }
    }
    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO spare_parts (name, sku, description, category_id, quantity, min_quantity, unit_price, location) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$name, $sku, $description, $category_id, $quantity, $min_quantity, $unit_price, $location]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'spare_part', $newId, "Creata parte: $name");
        header('Location: ' . APP_URL . '/modules/spare_parts/index.php?created=1');
        exit;
    }
}

$categories = $db->query("SELECT * FROM spare_parts_categories ORDER BY name")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-7">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Nuova Parte di Ricambio</h4>
    <a href="<?= APP_URL ?>/modules/spare_parts/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
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
            <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required value="<?= h($_POST['name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">SKU <span class="text-danger">*</span></label>
            <input type="text" name="sku" class="form-control font-monospace" required value="<?= h($_POST['sku'] ?? '') ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione</label>
        <textarea name="description" class="form-control" rows="3"><?= h($_POST['description'] ?? '') ?></textarea>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Categoria</label>
            <select name="category_id" class="form-select">
                <option value="">-- Nessuna --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($_POST['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Posizione/Magazzino</label>
            <input type="text" name="location" class="form-control" value="<?= h($_POST['location'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Prezzo Unitario (€)</label>
            <input type="text" name="unit_price" class="form-control" placeholder="0.00" value="<?= h($_POST['unit_price'] ?? '') ?>">
        </div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Quantità in Magazzino</label>
            <input type="number" name="quantity" class="form-control" min="0" value="<?= h($_POST['quantity'] ?? '0') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Quantità Minima (alert scorte)</label>
            <input type="number" name="min_quantity" class="form-control" min="0" value="<?= h($_POST['min_quantity'] ?? '5') ?>">
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Parte</button>
        <a href="<?= APP_URL ?>/modules/spare_parts/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
