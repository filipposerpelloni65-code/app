<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spare_parts')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Richiedi Parte');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Parti di Ricambio' => APP_URL.'/modules/spare_parts/index.php', 'Richiesta' => '']);

$db = getDB();
$user = currentUser();

$errors = [];
$preTicketId = (int)($_GET['ticket_id'] ?? 0);
$prePartId = (int)($_GET['part_id'] ?? 0);
$preDealerId = (int)($_GET['dealer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $errors[] = 'Token non valido.';
    $ticket_id   = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $part_id     = (int)($_POST['part_id'] ?? 0);
    $quantity    = max(1, (int)($_POST['quantity'] ?? 1));
    $notes       = trim($_POST['notes'] ?? '');
    $dealer_id   = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id = (int)($_POST['location_id'] ?? 0) ?: null;
    if (!$part_id) $errors[] = 'Seleziona una parte.';
    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO spare_parts_requests (ticket_id, part_id, requested_by, quantity, notes, dealer_id, location_id, status) VALUES (?,?,?,?,?,?,?,'pending')");
        $stmt->execute([$ticket_id, $part_id, $user['id'], $quantity, $notes, $dealer_id, $location_id]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'request', 'spare_part', $part_id, "Richiesta parte per ticket $ticket_id");
        header('Location: ' . APP_URL . '/modules/spare_parts/requests.php?created=1');
        exit;
    }
}

$parts = $db->query("SELECT * FROM spare_parts WHERE quantity > 0 ORDER BY name")->fetchAll();
$tickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 100")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$selectedDealer = (int)($_POST['dealer_id'] ?? $preDealerId);
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
    // Also pre-fill dealer from linked ticket
    if (!$preDealerId && $preTicketId) {
        $tStmt = $db->prepare("SELECT dealer_id FROM tickets WHERE id=?");
        $tStmt->execute([$preTicketId]);
        $tRow = $tStmt->fetch();
        if ($tRow && $tRow['dealer_id']) {
            $selectedDealer = (int)$tRow['dealer_id'];
        }
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-6">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-cart-plus me-2 text-success"></i>Richiedi Parte di Ricambio</h4>
    <a href="<?= APP_URL ?>/modules/spare_parts/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Parte di Ricambio <span class="text-danger">*</span></label>
        <select name="part_id" class="form-select" required>
            <option value="">-- Seleziona una parte --</option>
            <?php foreach ($parts as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($prePartId == $p['id'] || ($_POST['part_id'] ?? 0) == $p['id']) ? 'selected' : '' ?>>
                <?= h($p['name']) ?> (SKU: <?= h($p['sku']) ?>) — Disponibili: <?= (int)$p['quantity'] ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Quantità</label>
        <input type="number" name="quantity" class="form-control" min="1" value="<?= (int)($_POST['quantity'] ?? 1) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Ticket associato (opzionale)</label>
        <select name="ticket_id" class="form-select">
            <option value="">-- Nessun ticket --</option>
            <?php foreach ($tickets as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($preTicketId == $t['id'] || ($_POST['ticket_id'] ?? 0) == $t['id']) ? 'selected' : '' ?>>
                <?= h(getTicketPrefix() . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT) . ' — ' . $t['title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-4">
        <label class="form-label fw-semibold">Note</label>
        <textarea name="notes" class="form-control" rows="3"><?= h($_POST['notes'] ?? '') ?></textarea>
    </div>
    <?php if ($dealers): ?>
    <hr>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Concessionario</label>
            <select name="dealer_id" class="form-select" onchange="this.form.submit()">
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
        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Invia Richiesta</button>
        <a href="<?= APP_URL ?>/modules/spare_parts/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
