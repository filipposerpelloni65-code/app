<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM spedizioni WHERE id=?");
$stmt->execute([$id]);
$sp = $stmt->fetch();
if (!$sp) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

define('PAGE_TITLE', 'Modifica Spedizione #' . $sp['id']);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Spedizione #'.$sp['id'] => APP_URL.'/modules/spedizioni/view.php?id='.$sp['id'], 'Modifica' => '']);

$errors = [];

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
        $db->prepare("UPDATE spedizioni SET tracking_number=?, corriere=?, status=?, ticket_id=?, spare_parts_request_id=?, dealer_id=?, location_id=?, data_spedizione=?, data_prevista_consegna=?, note=?, updated_at=NOW() WHERE id=?")
           ->execute([$tracking, $corriere, $status, $ticket_id, $request_id, $dealer_id, $location_id, $data_sped, $data_prev, $note, $id]);
        logActivity($user['id'], 'update', 'spedizione', $id, "Aggiornata spedizione status=$status");
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $id . '&updated=1');
        exit;
    }
}

$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$selectedDealer = (int)($_POST['dealer_id'] ?? $sp['dealer_id']);
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

$openTickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed','resolved') ORDER BY created_at DESC LIMIT 100")->fetchAll();
$pendingReqs = $db->query("SELECT spr.id, sp.name as part_name, spr.quantity FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.status IN ('approved','pending') ORDER BY spr.created_at DESC LIMIT 50")->fetchAll();

// Merge current values with POST
$vals = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $sp;

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2 text-primary"></i>Modifica Spedizione #<?= $sp['id'] ?></h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $sp['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Dettaglio</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Numero Tracking</label>
            <input type="text" name="tracking_number" class="form-control font-monospace" value="<?= h($vals['tracking_number'] ?? '') ?>" placeholder="Es. 1Z999AA10123456784">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Corriere</label>
            <input type="text" name="corriere" class="form-control" list="corriereList" value="<?= h($vals['corriere'] ?? '') ?>" placeholder="Es. BRT, DHL, UPS...">
            <datalist id="corriereList">
                <option value="BRT"><option value="DHL"><option value="UPS"><option value="GLS"><option value="SDA"><option value="TNT"><option value="FedEx"><option value="Poste Italiane">
            </datalist>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Stato</label>
            <select name="status" class="form-select">
                <?php foreach (['da_spedire'=>'Da Spedire','spedita'=>'Spedita','consegnata'=>'Consegnata','annullata'=>'Annullata'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= ($vals['status'] ?? '') === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Spedizione</label>
            <input type="date" name="data_spedizione" class="form-control" value="<?= h($vals['data_spedizione'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Prevista Consegna</label>
            <input type="date" name="data_prevista_consegna" class="form-control" value="<?= h($vals['data_prevista_consegna'] ?? '') ?>">
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Ticket Collegato</label>
            <select name="ticket_id" class="form-select">
                <option value="">-- Nessun ticket --</option>
                <?php foreach ($openTickets as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($vals['ticket_id'] ?? '') == $t['id'] ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT).' '.$t['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Richiesta Ricambio</label>
            <select name="spare_parts_request_id" class="form-select">
                <option value="">-- Nessuna richiesta --</option>
                <?php foreach ($pendingReqs as $r): ?>
                <option value="<?= $r['id'] ?>" <?= ($vals['spare_parts_request_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>#<?= $r['id'] ?> – <?= h($r['part_name']) ?> (<?= $r['quantity'] ?> pz)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if ($dealers): ?>
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
                <option value="<?= $dl['id'] ?>" <?= ($vals['location_id'] ?? '') == $dl['id'] ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="mb-4">
        <label class="form-label fw-semibold">Note</label>
        <textarea name="note" class="form-control" rows="3"><?= h($vals['note'] ?? '') ?></textarea>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $sp['id'] ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
