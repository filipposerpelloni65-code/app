<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';
require_once __DIR__ . '/../../includes/brt_api.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

$stmt = $db->prepare("
    SELECT s.*,
        t.title AS ticket_title,
        spr.id AS request_id, sp.name AS part_name,
        d.name AS dealer_name, dl.name AS location_name
    FROM spedizioni s
    LEFT JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
    LEFT JOIN spare_parts sp ON spr.part_id = sp.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON s.location_id = dl.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$s = $stmt->fetch();
if (!$s) { header('Location: ' . APP_URL . '/modules/spedizioni/index.php'); exit; }

define('PAGE_TITLE', 'Modifica Spedizione #' . $id);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Modifica' => '']);

$errors = [];
$tickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 200")->fetchAll();
$partRequests = $db->query("SELECT spr.id, sp.name AS part_name, spr.quantity FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id WHERE spr.status IN ('approved','pending') ORDER BY spr.id DESC LIMIT 200")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$brtAvailable = (getBrtApi() !== null);
$locations = [];
if ($s['dealer_id']) {
    $lStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $lStmt->execute([$s['dealer_id']]);
    $locations = $lStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }

    $tracking     = trim($_POST['tracking_number'] ?? '');
    $corriere     = trim($_POST['corriere'] ?? '');
    $status       = $_POST['status'] ?? 'da_spedire';
    $ticketId     = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $sprId        = (int)($_POST['spare_parts_request_id'] ?? 0) ?: null;
    $dealerId     = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $locationId   = (int)($_POST['location_id'] ?? 0) ?: null;
    $note         = trim($_POST['note'] ?? '');
    $dataSped     = trim($_POST['data_spedizione'] ?? '') ?: null;
    $dataConsegna = trim($_POST['data_consegna_prevista'] ?? '') ?: null;
    $numColli     = max(1, (int)($_POST['brt_num_parcels'] ?? $s['num_colli'] ?? 1));
    $pesoKg       = max(0.1, (float)($_POST['brt_weight_kg'] ?? $s['peso_kg'] ?? 1.0));

    if (!in_array($status, ['bozza','da_spedire','spedita','consegnata','annullata'])) { $errors[] = 'Stato non valido.'; }

    // Update BRT consignee data if form submitted (only when not yet transmitted)
    $brtConsigneeJson = $s['brt_consignee_json'];
    $canEditBrt = empty($s['brt_parcel_id']); // can only edit if not yet transmitted
    if ($canEditBrt && isset($_POST['update_brt_data'])) {
        $cName    = trim($_POST['brt_consignee_name'] ?? '');
        $cAddress = trim($_POST['brt_consignee_address'] ?? '');
        $cZip     = trim($_POST['brt_consignee_zip'] ?? '');
        $cCity    = trim($_POST['brt_consignee_city'] ?? '');
        $cProv    = trim($_POST['brt_consignee_province'] ?? '');
        $cContact = trim($_POST['brt_consignee_contact'] ?? '');
        $cPhone   = trim($_POST['brt_consignee_phone'] ?? '');
        $cEmail   = trim($_POST['brt_consignee_email'] ?? '');
        if ($cName && $cAddress && $cZip && $cCity) {
            $brtData = [
                'consigneeCompanyName'                  => mb_substr($cName, 0, 70),
                'consigneeAddress'                      => mb_substr($cAddress, 0, 35),
                'consigneeZIPCode'                      => mb_substr($cZip, 0, 9),
                'consigneeCity'                         => mb_substr($cCity, 0, 35),
                'consigneeCountryAbbreviationISOAlpha2' => 'IT',
            ];
            if ($cProv)    $brtData['consigneeProvinceAbbreviation'] = strtoupper(mb_substr($cProv, 0, 2));
            if ($cContact) $brtData['consigneeContactName']          = mb_substr($cContact, 0, 35);
            if ($cPhone)   $brtData['consigneeTelephone']            = mb_substr($cPhone, 0, 20);
            if ($cEmail) {
                $brtData['consigneeEMail']  = mb_substr($cEmail, 0, 80);
                $brtData['isAlertRequired'] = '1';
            }
            $brtConsigneeJson = json_encode($brtData, JSON_UNESCAPED_UNICODE);
            if (empty($corriere)) $corriere = 'BRT';
        }
    }

    if (!$errors) {
        $db->prepare("UPDATE spedizioni SET tracking_number=?, corriere=?, status=?, ticket_id=?, spare_parts_request_id=?, dealer_id=?, location_id=?, note=?, data_spedizione=?, data_consegna_prevista=?, brt_consignee_json=?, num_colli=?, peso_kg=?, updated_at=NOW() WHERE id=?")
           ->execute([$tracking ?: null, $corriere ?: null, $status, $ticketId, $sprId, $dealerId, $locationId, $note ?: null, $dataSped, $dataConsegna, $brtConsigneeJson, $numColli, $pesoKg, $id]);
        logActivity($user['id'], 'edit', 'spedizione', $id, "Modificata spedizione #$id -> $status");
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $id . '&updated=1');
        exit;
    }

    // Re-populate on error
    $s['tracking_number'] = $tracking;
    $s['corriere']         = $corriere;
    $s['status']           = $status;
    $s['ticket_id']        = $ticketId;
    $s['spare_parts_request_id'] = $sprId;
    $s['dealer_id']        = $dealerId;
    $s['location_id']      = $locationId;
    $s['note']             = $note;
    $s['data_spedizione']  = $dataSped;
    $s['data_consegna_prevista'] = $dataConsegna;
    $s['num_colli']        = $numColli;
    $s['peso_kg']          = $pesoKg;
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Modifica Spedizione #<?= $id ?></h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Dettaglio</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Numero Tracking</label>
                    <input type="text" name="tracking_number" class="form-control font-monospace" value="<?= h($s['tracking_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Corriere</label>
                    <input type="text" name="corriere" class="form-control" value="<?= h($s['corriere'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Stato <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="bozza" <?= ($s['status'] ?? '') === 'bozza' ? 'selected' : '' ?>>Bozza</option>
                        <option value="da_spedire" <?= ($s['status'] ?? '') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                        <option value="spedita" <?= ($s['status'] ?? '') === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                        <option value="consegnata" <?= ($s['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                        <option value="annullata" <?= ($s['status'] ?? '') === 'annullata' ? 'selected' : '' ?>>Annullata</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Spedizione</label>
                    <input type="date" name="data_spedizione" class="form-control" value="<?= h($s['data_spedizione'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Consegna Prevista</label>
                    <input type="date" name="data_consegna_prevista" class="form-control" value="<?= h($s['data_consegna_prevista'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ticket Collegato</label>
                    <select name="ticket_id" class="form-select">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($tickets as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($s['ticket_id'] ?? '') == $t['id'] ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT)) ?> — <?= h($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Richiesta Ricambio</label>
                    <select name="spare_parts_request_id" class="form-select">
                        <option value="">-- Nessuna --</option>
                        <?php foreach ($partRequests as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($s['spare_parts_request_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>#<?= $r['id'] ?> — <?= h($r['part_name']) ?> (qty: <?= $r['quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Concessionario</label>
                    <select name="dealer_id" class="form-select" id="dealerSelect">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($dealers as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($s['dealer_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Punto Vendita</label>
                    <select name="location_id" class="form-select" id="locationSelect">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= ($s['location_id'] ?? '') == $l['id'] ? 'selected' : '' ?>><?= h($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Note</label>
                    <textarea name="note" class="form-control" rows="3"><?= h($s['note'] ?? '') ?></textarea>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">N° Colli</label>
                    <input type="number" name="brt_num_parcels" class="form-control" min="1" max="30" value="<?= h($s['num_colli'] ?? 1) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Peso (kg)</label>
                    <input type="number" name="brt_weight_kg" class="form-control" min="0.1" step="0.1" value="<?= h($s['peso_kg'] ?? '1.0') ?>">
                </div>
            </div>

            <?php
            $existingBrt = !empty($s['brt_consignee_json']) ? (json_decode($s['brt_consignee_json'], true) ?? []) : [];
            $canEditBrt  = empty($s['brt_parcel_id']);
            ?>
            <?php if ($canEditBrt): ?>
            <!-- ── BRT Dati Destinatario ──────────────────────────────────────── -->
            <hr class="my-4">
            <div class="card border-primary border-opacity-25 mb-3">
                <div class="card-header bg-primary bg-opacity-10">
                    <h6 class="mb-0 text-primary"><i class="bi bi-truck me-2"></i>Dati Destinatario BRT
                        <small class="text-muted fw-normal ms-2">(modificabili fino alla trasmissione)</small>
                    </h6>
                </div>
                <div class="card-body">
                    <input type="hidden" name="update_brt_data" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ragione Sociale Destinatario</label>
                            <input type="text" name="brt_consignee_name" class="form-control" maxlength="70" value="<?= h($existingBrt['consigneeCompanyName'] ?? '') ?>" placeholder="Es. Mario Rossi S.r.l.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Indirizzo</label>
                            <input type="text" name="brt_consignee_address" class="form-control" maxlength="35" value="<?= h($existingBrt['consigneeAddress'] ?? '') ?>" placeholder="Es. Via Roma 1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">CAP</label>
                            <input type="text" name="brt_consignee_zip" class="form-control" maxlength="9" value="<?= h($existingBrt['consigneeZIPCode'] ?? '') ?>" placeholder="Es. 20100">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Città</label>
                            <input type="text" name="brt_consignee_city" class="form-control" maxlength="35" value="<?= h($existingBrt['consigneeCity'] ?? '') ?>" placeholder="Es. MILANO">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Provincia</label>
                            <input type="text" name="brt_consignee_province" class="form-control text-uppercase" maxlength="2" value="<?= h($existingBrt['consigneeProvinceAbbreviation'] ?? '') ?>" placeholder="MI">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nome Referente</label>
                            <input type="text" name="brt_consignee_contact" class="form-control" maxlength="35" value="<?= h($existingBrt['consigneeContactName'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Telefono</label>
                            <input type="text" name="brt_consignee_phone" class="form-control" maxlength="20" value="<?= h($existingBrt['consigneeTelephone'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Email notifica</label>
                            <input type="email" name="brt_consignee_email" class="form-control" maxlength="80" value="<?= h($existingBrt['consigneeEMail'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif (!empty($existingBrt)): ?>
            <hr class="my-4">
            <div class="card border-secondary border-opacity-25 mb-3">
                <div class="card-header bg-light">
                    <h6 class="mb-0 text-muted"><i class="bi bi-truck me-2"></i>Dati Destinatario BRT
                        <span class="badge bg-success ms-2">Trasmessa</span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-2 small">
                        <div class="col-md-6"><strong>Destinatario:</strong> <?= h($existingBrt['consigneeCompanyName'] ?? '') ?></div>
                        <div class="col-md-6"><strong>Indirizzo:</strong> <?= h($existingBrt['consigneeAddress'] ?? '') ?></div>
                        <div class="col-md-4"><strong>CAP/Città:</strong> <?= h($existingBrt['consigneeZIPCode'] ?? '') ?> <?= h($existingBrt['consigneeCity'] ?? '') ?> <?= h($existingBrt['consigneeProvinceAbbreviation'] ?? '') ?></div>
                        <?php if (!empty($existingBrt['consigneeContactName'])): ?>
                        <div class="col-md-4"><strong>Referente:</strong> <?= h($existingBrt['consigneeContactName']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($existingBrt['consigneeEMail'])): ?>
                        <div class="col-md-4"><strong>Email:</strong> <?= h($existingBrt['consigneeEMail']) ?></div>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mt-2 mb-0"><i class="bi bi-lock me-1"></i>Dati non modificabili dopo la trasmissione a BRT.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
                <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
    window.appUrl = "' . APP_URL . '";
    var locationData = {};

    // Pre-load locations for current dealer on page load
    var initDid = $("#dealerSelect").val();
    if (initDid) {
        $.getJSON(window.appUrl + "/api/parts.php?action=dealer_locations&dealer_id=" + initDid, function(data){
            if (data.success) {
                var selLid = ' . (int)($s['location_id'] ?? 0) . ';
                $.each(data.data, function(i, l){
                    var sel = (l.id == selLid) ? " selected" : "";
                    $("#locationSelect").append("<option value=\""+l.id+"\""+sel+">"+l.name+"</option>");
                    locationData[l.id] = l;
                });
            }
        });
    }

    $("#dealerSelect").on("change", function(){
        var did = $(this).val();
        var $loc = $("#locationSelect");
        $loc.html("<option value=\"\">-- Nessuno --</option>");
        locationData = {};
        if (!did) return;
        $.getJSON(window.appUrl + "/api/parts.php?action=dealer_locations&dealer_id=" + did, function(data){
            if (data.success) {
                $.each(data.data, function(i, l){
                    $loc.append("<option value=\""+l.id+"\">"+l.name+"</option>");
                    locationData[l.id] = l;
                });
            }
        });
    });
});
</script>';
?>
<?php include APP_ROOT . '/includes/footer.php'; ?>
