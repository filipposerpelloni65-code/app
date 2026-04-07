<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuova Spedizione');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Nuova' => '']);

$db = getDB();
$user = currentUser();

// Pre-fill from query string (e.g. coming from spare_parts request or ticket)
$preRequestId = (int)($_GET['request_id'] ?? 0);
$preTicketId  = (int)($_GET['ticket_id'] ?? 0);

$errors = [];
$success = false;

// Pre-load spare part request if provided
$preRequest = null;
if ($preRequestId) {
    $s = $db->prepare("SELECT spr.*, sp.name as part_name, sp.sku, d.name as dealer_name, dl.name as location_name, dl.address as location_address, dl.city as location_city, d.id as d_id, dl.id as dl_id FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id LEFT JOIN dealers d ON spr.dealer_id=d.id LEFT JOIN dealer_locations dl ON spr.location_id=dl.id WHERE spr.id=?");
    $s->execute([$preRequestId]);
    $preRequest = $s->fetch();
    if ($preRequest && !$preTicketId) $preTicketId = (int)($preRequest['ticket_id'] ?? 0);
}

// Pre-load ticket dealer info
$preTicket = null;
if ($preTicketId) {
    $t = $db->prepare("SELECT t.*, d.name as dealer_name, dl.name as location_name, dl.address as location_address, dl.city as location_city FROM tickets t LEFT JOIN dealers d ON t.dealer_id=d.id LEFT JOIN dealer_locations dl ON t.location_id=dl.id WHERE t.id=?");
    $t->execute([$preTicketId]);
    $preTicket = $t->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) $errors[] = 'Token non valido.';

    $ticket_id             = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $request_id            = (int)($_POST['request_id'] ?? 0) ?: null;
    $dealer_id             = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id           = (int)($_POST['location_id'] ?? 0) ?: null;
    $corriere              = trim($_POST['corriere'] ?? '');
    $numero_tracking       = trim($_POST['numero_tracking'] ?? '');
    $destinatario          = trim($_POST['destinatario'] ?? '');
    $indirizzo_spedizione  = trim($_POST['indirizzo_spedizione'] ?? '');
    $mittente              = trim($_POST['mittente'] ?? '');
    $note                  = trim($_POST['note'] ?? '');
    $data_spedizione       = trim($_POST['data_spedizione'] ?? '') ?: null;
    $data_consegna_prevista = trim($_POST['data_consegna_prevista'] ?? '') ?: null;
    $status                = $_POST['status'] ?? 'da_spedire';
    if (!in_array($status, ['da_spedire','spedita','consegnata','annullata'])) $status = 'da_spedire';

    if (!$destinatario) $errors[] = 'Il destinatario è obbligatorio.';

    if (!$errors) {
        $ins = $db->prepare("INSERT INTO spedizioni (ticket_id, request_id, dealer_id, location_id, corriere, numero_tracking, status, mittente, destinatario, indirizzo_spedizione, note, data_spedizione, data_consegna_prevista, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([$ticket_id, $request_id, $dealer_id, $location_id, $corriere ?: null, $numero_tracking ?: null, $status, $mittente ?: null, $destinatario, $indirizzo_spedizione ?: null, $note ?: null, $data_spedizione, $data_consegna_prevista, $user['id']]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'spedizione', $newId, "Nuova spedizione creata" . ($ticket_id ? " per ticket $ticket_id" : ''));
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

$tickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 200")->fetchAll();
$requests = $db->query("SELECT spr.id, sp.name as part_name, sp.sku, spr.quantity, spr.status FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.status IN ('approved','pending') ORDER BY spr.created_at DESC LIMIT 200")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();

// Dealer locations for pre-selected dealer
$selectedDealer = (int)($_POST['dealer_id'] ?? ($preRequest['d_id'] ?? ($preTicket['dealer_id'] ?? 0)));
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

// Auto-fill address from pre-data
$defaultDestinatario = '';
$defaultIndirizzo = '';
if ($preRequest) {
    $defaultDestinatario = $preRequest['dealer_name'] ?? '';
    $defaultIndirizzo = trim(($preRequest['location_address'] ?? '') . ' ' . ($preRequest['location_city'] ?? ''));
} elseif ($preTicket) {
    $defaultDestinatario = $preTicket['dealer_name'] ?? '';
    $defaultIndirizzo = trim(($preTicket['location_address'] ?? '') . ' ' . ($preTicket['location_city'] ?? ''));
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-7">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Nuova Spedizione</h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($preRequest): ?>
<div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i>Spedizione collegata alla richiesta parte <strong><?= h($preRequest['part_name']) ?></strong> (SKU: <?= h($preRequest['sku']) ?>, Qtà: <?= (int)$preRequest['quantity'] ?>)</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="request_id" value="<?= $preRequestId ?: (int)($_POST['request_id'] ?? 0) ?>">

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Stato</label>
            <select name="status" class="form-select">
                <option value="da_spedire" <?= ($_POST['status'] ?? 'da_spedire') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                <option value="spedita" <?= ($_POST['status'] ?? '') === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                <option value="consegnata" <?= ($_POST['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                <option value="annullata" <?= ($_POST['status'] ?? '') === 'annullata' ? 'selected' : '' ?>>Annullata</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Corriere</label>
            <input type="text" name="corriere" class="form-control" placeholder="Es. BRT, GLS, DHL..." value="<?= h($_POST['corriere'] ?? '') ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Numero Tracking</label>
        <input type="text" name="numero_tracking" class="form-control font-monospace" placeholder="Numero di tracciamento" value="<?= h($_POST['numero_tracking'] ?? '') ?>">
    </div>

    <hr>

    <div class="mb-3">
        <label class="form-label fw-semibold">Destinatario <span class="text-danger">*</span></label>
        <input type="text" name="destinatario" class="form-control" required placeholder="Nome destinatario" value="<?= h($_POST['destinatario'] ?? $defaultDestinatario) ?>">
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Indirizzo di Spedizione</label>
        <textarea name="indirizzo_spedizione" class="form-control" rows="2" placeholder="Via, Cap, Città"><?= h($_POST['indirizzo_spedizione'] ?? $defaultIndirizzo) ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Mittente</label>
        <input type="text" name="mittente" class="form-control" placeholder="Mittente / magazzino" value="<?= h($_POST['mittente'] ?? '') ?>">
    </div>

    <hr>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Data Spedizione</label>
            <input type="date" name="data_spedizione" class="form-control" value="<?= h($_POST['data_spedizione'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Consegna Prevista</label>
            <input type="date" name="data_consegna_prevista" class="form-control" value="<?= h($_POST['data_consegna_prevista'] ?? '') ?>">
        </div>
    </div>

    <hr>

    <div class="mb-3">
        <label class="form-label fw-semibold">Ticket Associato</label>
        <select name="ticket_id" class="form-select">
            <option value="">-- Nessun ticket --</option>
            <?php foreach ($tickets as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($preTicketId == $t['id'] || ($_POST['ticket_id'] ?? 0) == $t['id']) ? 'selected' : '' ?>>
                <?= h(getTicketPrefix() . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT) . ' — ' . $t['title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (!$preRequestId): ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Richiesta Parte Collegata</label>
        <select name="request_id" class="form-select">
            <option value="">-- Nessuna richiesta --</option>
            <?php foreach ($requests as $r): ?>
            <option value="<?= $r['id'] ?>" <?= ($_POST['request_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>>
                #<?= $r['id'] ?> — <?= h($r['part_name']) ?> (<?= h($r['sku']) ?>) × <?= (int)$r['quantity'] ?> [<?= h($r['status']) ?>]
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($dealers): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Concessionario</label>
            <select name="dealer_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Nessun concessionario --</option>
                <?php foreach ($dealers as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $selectedDealer == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($dealerLocations): ?>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Punto Vendita</label>
            <select name="location_id" class="form-select">
                <option value="">-- Nessun punto vendita --</option>
                <?php foreach ($dealerLocations as $dl): ?>
                <option value="<?= $dl['id'] ?>" <?= (($_POST['location_id'] ?? ($preRequest['dl_id'] ?? ($preTicket['location_id'] ?? 0))) == $dl['id']) ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mb-4">
        <label class="form-label fw-semibold">Note</label>
        <textarea name="note" class="form-control" rows="3" placeholder="Note sulla spedizione..."><?= h($_POST['note'] ?? '') ?></textarea>
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
