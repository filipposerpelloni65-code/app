<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('tickets')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuovo Ticket');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Ticket' => APP_URL.'/modules/tickets/index.php', 'Nuovo Ticket' => '']);

$db = getDB();
$user = currentUser();

$errors = [];
$success = false;

// Pre-fill dealer from GET (e.g. linked from dealer view)
$preDealerId = (int)($_GET['dealer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
    $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $dealer_id = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id = (int)($_POST['location_id'] ?? 0) ?: null;
    $codice_concessionario = trim($_POST['codice_concessionario'] ?? '') ?: null;
    if (!$title) $errors[] = 'Il titolo è obbligatorio.';
    if (!$description) $errors[] = 'La descrizione è obbligatoria.';
    if (!in_array($priority, ['low','medium','high','urgent'])) $errors[] = 'Priorità non valida.';

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO tickets (title, description, priority, category_id, created_by, assigned_to, dealer_id, location_id, codice_concessionario, status) VALUES (?,?,?,?,?,?,?,?,?,'open')");
        $stmt->execute([$title, $description, $priority, $category_id, $user['id'], $assigned_to, $dealer_id, $location_id, $codice_concessionario]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'ticket', $newId, "Creato ticket: $title");
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

$categories = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$dealerLocations = [];
$selectedDealer = (int)($_POST['dealer_id'] ?? $preDealerId);
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Nuovo Ticket</h4>
    <a href="<?= APP_URL ?>/modules/tickets/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Titolo <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= h($_POST['title'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Codice Ticket Concessionario</label>
        <input type="text" name="codice_concessionario" class="form-control font-monospace" value="<?= h($_POST['codice_concessionario'] ?? '') ?>" placeholder="Es. TKT-DEALER-001">
        <div class="form-text">Riferimento del ticket comunicato dal concessionario via email (opzionale).</div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="6" required><?= h($_POST['description'] ?? '') ?></textarea>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Priorità</label>
            <select name="priority" class="form-select">
                <option value="low" <?= ($_POST['priority'] ?? 'medium') === 'low' ? 'selected' : '' ?>>Bassa</option>
                <option value="medium" <?= ($_POST['priority'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Media</option>
                <option value="high" <?= ($_POST['priority'] ?? 'medium') === 'high' ? 'selected' : '' ?>>Alta</option>
                <option value="urgent" <?= ($_POST['priority'] ?? 'medium') === 'urgent' ? 'selected' : '' ?>>Urgente</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Categoria</label>
            <select name="category_id" class="form-select">
                <option value="">-- Nessuna categoria --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($_POST['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (isTechnician()): ?>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Assegna a</label>
            <select name="assigned_to" class="form-select">
                <option value="">-- Non assegnato --</option>
                <?php foreach ($technicians as $tech): ?>
                <option value="<?= $tech['id'] ?>" <?= ($_POST['assigned_to'] ?? '') == $tech['id'] ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($dealers): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Concessionario</label>
            <select name="dealer_id" class="form-select" id="dealerSelect" onchange="this.form.submit()">
                <option value="">-- Nessun concessionario --</option>
                <?php foreach ($dealers as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($selectedDealer == $d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($dealerLocations): ?>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Punto Vendita</label>
            <select name="location_id" class="form-select">
                <option value="">-- Nessun punto vendita --</option>
                <?php foreach ($dealerLocations as $dl): ?>
                <option value="<?= $dl['id'] ?>" <?= ($_POST['location_id'] ?? '') == $dl['id'] ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea Ticket</button>
        <a href="<?= APP_URL ?>/modules/tickets/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
