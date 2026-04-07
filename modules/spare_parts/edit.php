<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spare_parts')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/spare_parts/index.php'); exit; }

$part = $db->prepare("SELECT * FROM spare_parts WHERE id=?");
$part->execute([$id]);
$part = $part->fetch();
if (!$part) { header('Location: ' . APP_URL . '/modules/spare_parts/index.php'); exit; }

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
        $check = $db->prepare("SELECT id FROM spare_parts WHERE sku=? AND id!=?");
        $check->execute([$sku, $id]);
        if ($check->fetch()) { $errors[] = 'SKU già utilizzato da un\'altra parte.'; }
    }
    if (!$errors) {
        $stmt = $db->prepare("UPDATE spare_parts SET name=?, sku=?, description=?, category_id=?, quantity=?, min_quantity=?, unit_price=?, location=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$name, $sku, $description, $category_id, $quantity, $min_quantity, $unit_price, $location, $id]);
        logActivity($user['id'], 'edit', 'spare_part', $id, "Modificata parte: $name");
        header('Location: ' . APP_URL . '/modules/spare_parts/index.php?updated=1');
        exit;
    }
    $part = array_merge($part, compact('name','sku','description','category_id','quantity','min_quantity','unit_price','location'));
}

$categories = $db->query("SELECT * FROM spare_parts_categories ORDER BY name")->fetchAll();

define('PAGE_TITLE', 'Modifica Parte');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Parti di Ricambio' => APP_URL.'/modules/spare_parts/index.php', 'Modifica' => '']);

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-7">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2 text-secondary"></i>Modifica Parte di Ricambio</h4>
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
            <input type="text" name="name" class="form-control" required value="<?= h($part['name']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">SKU <span class="text-danger">*</span></label>
            <input type="text" name="sku" class="form-control font-monospace" required value="<?= h($part['sku']) ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione</label>
        <textarea name="description" class="form-control" rows="3"><?= h($part['description']) ?></textarea>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Categoria</label>
            <select name="category_id" class="form-select">
                <option value="">-- Nessuna --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $part['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Posizione/Magazzino</label>
            <input type="text" name="location" class="form-control" value="<?= h($part['location']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Prezzo Unitario (€)</label>
            <input type="text" name="unit_price" class="form-control" value="<?= h($part['unit_price'] ?? '') ?>">
        </div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Quantità in Magazzino</label>
            <input type="number" name="quantity" class="form-control" min="0" value="<?= (int)$part['quantity'] ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Quantità Minima</label>
            <input type="number" name="min_quantity" class="form-control" min="0" value="<?= (int)$part['min_quantity'] ?>">
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/spare_parts/view.php?id=<?= $id ?>" class="btn btn-light">Annulla</a>
        <?php if (isAdmin()): ?>
        <span class="ms-auto"></span>
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="if(confirm('Eliminare definitivamente questa parte?')){document.getElementById('deletePartForm').submit()}"><i class="bi bi-trash me-1"></i>Elimina</button>
        <?php endif; ?>
    </div>
</form>
<?php if (isAdmin()): ?>
<form id="deletePartForm" method="post" action="<?= APP_URL ?>/modules/spare_parts/view.php?id=<?= $id ?>" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
</form>
<?php endif; ?>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
