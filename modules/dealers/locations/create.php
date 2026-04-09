<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('dealers')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$dealerId = (int)($_GET['dealer_id'] ?? $_POST['dealer_id'] ?? 0);
if (!$dealerId) { header('Location: ' . APP_URL . '/modules/dealers/index.php'); exit; }

$dStmt = $db->prepare("SELECT * FROM dealers WHERE id=?");
$dStmt->execute([$dealerId]);
$dealer = $dStmt->fetch();
if (!$dealer) { header('Location: ' . APP_URL . '/modules/dealers/index.php'); exit; }

define('PAGE_TITLE', 'Nuovo Punto Vendita');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Concessionari' => APP_URL.'/modules/dealers/index.php', $dealer['name'] => APP_URL.'/modules/dealers/view.php?id='.$dealerId, 'Nuovo Punto Vendita' => '']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }
    $name           = trim($_POST['name'] ?? '');
    $code           = trim($_POST['code'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $city           = trim($_POST['city'] ?? '');
    $zip            = trim($_POST['zip'] ?? '');
    $province       = strtoupper(trim($_POST['province'] ?? ''));
    $phone          = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $active         = isset($_POST['active']) ? 1 : 0;
    $notes          = trim($_POST['notes'] ?? '');
    $codice_aams      = trim($_POST['codice_aams'] ?? '');
    $id_punto_vendita = trim($_POST['id_punto_vendita'] ?? '');

    if (!$name) $errors[] = 'Il nome è obbligatorio.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida.';

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO dealer_locations (dealer_id, name, code, address, city, zip, province, phone, email, contact_person, active, notes, codice_aams, id_punto_vendita) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$dealerId, $name, $code, $address, $city, $zip ?: null, $province ?: null, $phone, $email, $contact_person, $active, $notes, $codice_aams ?: null, $id_punto_vendita ?: null]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'dealer_location', $newId, "Creato punto vendita: $name");
        header('Location: ' . APP_URL . '/modules/dealers/view.php?id=' . $dealerId . '&updated=1');
        exit;
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2 text-success"></i>Nuovo Punto Vendita</h4>
    <a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $dealerId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna al concessionario</a>
</div>

<div class="alert alert-info small py-2"><i class="bi bi-shop me-2"></i>Stai aggiungendo un punto vendita per: <strong><?= h($dealer['name']) ?></strong></div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="dealer_id" value="<?= $dealerId ?>">
    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <label class="form-label fw-semibold">Nome Punto Vendita <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required value="<?= h($_POST['name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Codice</label>
            <input type="text" name="code" class="form-control" value="<?= h($_POST['code'] ?? '') ?>" placeholder="es. PV-001">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Indirizzo</label>
        <input type="text" name="address" class="form-control" value="<?= h($_POST['address'] ?? '') ?>">
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Città</label>
            <input type="text" name="city" class="form-control" value="<?= h($_POST['city'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">CAP</label>
            <input type="text" name="zip" class="form-control" maxlength="10" value="<?= h($_POST['zip'] ?? '') ?>" placeholder="Es. 20100">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Prov.</label>
            <input type="text" name="province" class="form-control text-uppercase" maxlength="2" value="<?= h($_POST['province'] ?? '') ?>" placeholder="MI">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Referente</label>
            <input type="text" name="contact_person" class="form-control" value="<?= h($_POST['contact_person'] ?? '') ?>">
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Telefono</label>
            <input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Note</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea>
    </div>
    <hr class="my-3">
    <div class="mb-2">
        <span class="form-section-title"><i class="bi bi-shield-check me-1"></i>Dati Regolatori (AAMS/ADM)</span>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Codice AAMS <span class="text-muted fw-normal">(opzionale)</span></label>
            <input type="text" name="codice_aams" class="form-control font-monospace" value="<?= h($_POST['codice_aams'] ?? '') ?>" placeholder="Es. 12345/ADM">
            <div class="form-text">Codice concessione ADM (ex AAMS)</div>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">ID Punto Vendita <span class="text-muted fw-normal">(opzionale)</span></label>
            <input type="text" name="id_punto_vendita" class="form-control font-monospace" value="<?= h($_POST['id_punto_vendita'] ?? '') ?>" placeholder="Es. PV-00001">
            <div class="form-text">Identificativo univoco punto vendita</div>
        </div>
    </div>
    <div class="mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" id="active" <?= ($_POST['active'] ?? '1') ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Punto vendita attivo</label>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Aggiungi Punto Vendita</button>
        <a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $dealerId ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
