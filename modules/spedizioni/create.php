<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

define('PAGE_TITLE', 'Nuova Spedizione');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Nuova' => '']);

$db = getDB();
$user = currentUser();
$errors = [];

// Pre-fill from ticket or spare_parts_request
$preTicketId  = (int)($_GET['ticket_id'] ?? 0);
$preRequestId = (int)($_GET['request_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }

    $tracking    = trim($_POST['tracking_number'] ?? '') ?: null;
    $corriere    = trim($_POST['corriere'] ?? '') ?: null;
    $status      = $_POST['status'] ?? 'da_spedire';
    $ticket_id   = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $request_id  = (int)($_POST['spare_parts_request_id'] ?? 0) ?: null;
    $dealer_id   = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id = (int)($_POST['location_id'] ?? 0) ?: null;
    $data_sped   = trim($_POST['data_spedizione'] ?? '') ?: null;
    $data_prev   = trim($_POST['data_prevista_consegna'] ?? '') ?: null;
    $note        = trim($_POST['note'] ?? '') ?: null;

    if (!in_array($status, ['da_spedire','spedita','consegnata','annullata'])) $errors[] = 'Stato non valido.';

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO spedizioni (tracking_number, corriere, status, ticket_id, spare_parts_request_id, dealer_id, location_id, data_spedizione, data_prevista_consegna, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$tracking, $corriere, $status, $ticket_id, $request_id, $dealer_id, $location_id, $data_sped, $data_prev, $note, $user['id']]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'spedizione', $newId, "Creata spedizione" . ($tracking ? " tracking $tracking" : ''));
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$selectedDealer = (int)($_POST['dealer_id'] ?? 0);
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

// Open tickets
$openTickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed','resolved') ORDER BY created_at DESC LIMIT 100")->fetchAll();

// Pending parts requests
$pendingReqs = $db->query("SELECT spr.id, sp.name as part_name, spr.quantity FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.status='approved' ORDER BY spr.created_at DESC LIMIT 50")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Nuova Spedizione</h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post" id="spedizioneForm">
    <?= csrfField() ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Numero Tracking</label>
            <input type="text" name="tracking_number" class="form-control font-monospace" value="<?= h($_POST['tracking_number'] ?? '') ?>" placeholder="Es. 1Z999AA10123456784">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Corriere</label>
            <input type="text" name="corriere" class="form-control" list="corriereList" value="<?= h($_POST['corriere'] ?? '') ?>" placeholder="Es. BRT, DHL, UPS...">
            <datalist id="corriereList">
                <option value="BRT"><option value="DHL"><option value="UPS"><option value="GLS"><option value="SDA"><option value="TNT"><option value="FedEx"><option value="Poste Italiane">
            </datalist>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Stato</label>
            <select name="status" class="form-select">
                <option value="da_spedire" <?= ($_POST['status'] ?? 'da_spedire') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                <option value="spedita"    <?= ($_POST['status'] ?? '') === 'spedita'    ? 'selected' : '' ?>>Spedita</option>
                <option value="consegnata" <?= ($_POST['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                <option value="annullata"  <?= ($_POST['status'] ?? '') === 'annullata'  ? 'selected' : '' ?>>Annullata</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Spedizione</label>
            <input type="date" name="data_spedizione" class="form-control" value="<?= h($_POST['data_spedizione'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Prevista Consegna</label>
            <input type="date" name="data_prevista_consegna" class="form-control" value="<?= h($_POST['data_prevista_consegna'] ?? '') ?>">
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Ticket Collegato</label>
            <select name="ticket_id" class="form-select">
                <option value="">-- Nessun ticket --</option>
                <?php foreach ($openTickets as $t): ?>
                <option value="<?= $t['id'] ?>" <?= (($_POST['ticket_id'] ?? $preTicketId) == $t['id']) ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT).' '.$t['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Richiesta Ricambio</label>
            <select name="spare_parts_request_id" class="form-select">
                <option value="">-- Nessuna richiesta --</option>
                <?php foreach ($pendingReqs as $r): ?>
                <option value="<?= $r['id'] ?>" <?= (($_POST['spare_parts_request_id'] ?? $preRequestId) == $r['id']) ? 'selected' : '' ?>>#<?= $r['id'] ?> – <?= h($r['part_name']) ?> (<?= $r['quantity'] ?> pz)</option>
                <?php endforeach; ?>
            </select>
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
                <option value="<?= $dl['id'] ?>" <?= ($_POST['location_id'] ?? '') == $dl['id'] ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="mb-4">
        <label class="form-label fw-semibold">Note</label>
        <textarea name="note" class="form-control" rows="3"><?= h($_POST['note'] ?? '') ?></textarea>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Crea Spedizione</button>
        <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
