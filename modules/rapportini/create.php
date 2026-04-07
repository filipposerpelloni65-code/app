<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('rapportini')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit; }

define('PAGE_TITLE', 'Nuovo Rapportino');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Rapportini' => APP_URL.'/modules/rapportini/index.php', 'Nuovo Rapportino' => '']);

$db = getDB();
$user = currentUser();

$errors = [];

$preTicketId = (int)($_GET['ticket_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }

    $title            = trim($_POST['title'] ?? '');
    $work_description = trim($_POST['work_description'] ?? '');
    $parts_used       = trim($_POST['parts_used'] ?? '');
    $intervention_date = trim($_POST['intervention_date'] ?? '');
    $technician_id    = (int)($_POST['technician_id'] ?? $user['id']);
    $ticket_id        = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $dealer_id        = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id      = (int)($_POST['location_id'] ?? 0) ?: null;
    $customer_name    = trim($_POST['customer_name'] ?? '');
    $customer_contact = trim($_POST['customer_contact'] ?? '');
    $notes            = trim($_POST['notes'] ?? '');

    if (!$title)             $errors[] = 'Il titolo è obbligatorio.';
    if (!$work_description)  $errors[] = 'La descrizione del lavoro è obbligatoria.';
    if (!$intervention_date) $errors[] = 'La data di intervento è obbligatoria.';
    if (!$technician_id)     $errors[] = 'Il tecnico è obbligatorio.';

    if (!$errors) {
        $stmt = $db->prepare("
            INSERT INTO rapportini
                (title, work_description, parts_used, intervention_date, technician_id,
                 ticket_id, dealer_id, location_id, customer_name, customer_contact, notes,
                 created_by, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'draft')
        ");
        $stmt->execute([
            $title, $work_description, $parts_used, $intervention_date, $technician_id,
            $ticket_id, $dealer_id, $location_id, $customer_name, $customer_contact, $notes,
            $user['id']
        ]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'rapportino', $newId, "Creato rapportino: $title");
        header('Location: ' . APP_URL . '/modules/rapportini/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

$technicians   = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers       = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$tickets       = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 100")->fetchAll();

$selectedDealer = (int)($_POST['dealer_id'] ?? 0);
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-9">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Nuovo Rapportino</h4>
    <a href="<?= APP_URL ?>/modules/rapportini/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
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

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Intervento <span class="text-danger">*</span></label>
            <input type="date" name="intervention_date" class="form-control" required value="<?= h($_POST['intervention_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Tecnico <span class="text-danger">*</span></label>
            <select name="technician_id" class="form-select" required>
                <?php foreach ($technicians as $tech): ?>
                <option value="<?= $tech['id'] ?>" <?= (($_POST['technician_id'] ?? $user['id']) == $tech['id']) ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Ticket Collegato</label>
            <select name="ticket_id" class="form-select">
                <option value="">-- Nessun ticket --</option>
                <?php foreach ($tickets as $t): ?>
                <option value="<?= $t['id'] ?>" <?= (($_POST['ticket_id'] ?? $preTicketId) == $t['id']) ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT).' - '.$t['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Nome Cliente / Referente</label>
            <input type="text" name="customer_name" class="form-control" value="<?= h($_POST['customer_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Contatto Cliente</label>
            <input type="text" name="customer_contact" class="form-control" placeholder="Email o telefono" value="<?= h($_POST['customer_contact'] ?? '') ?>">
        </div>
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
                <option value="<?= $dl['id'] ?>" <?= (($_POST['location_id'] ?? '') == $dl['id']) ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione Lavoro Eseguito <span class="text-danger">*</span></label>
        <textarea name="work_description" class="form-control" rows="6" required><?= h($_POST['work_description'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Parti / Materiali Utilizzati</label>
        <textarea name="parts_used" class="form-control" rows="3" placeholder="Elenco parti e materiali utilizzati..."><?= h($_POST['parts_used'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Note Aggiuntive</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea Rapportino</button>
        <a href="<?= APP_URL ?>/modules/rapportini/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
