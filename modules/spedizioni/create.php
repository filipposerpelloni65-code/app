<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';
require_once __DIR__ . '/../../includes/brt_api.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuova Spedizione');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Spedizioni' => APP_URL.'/modules/spedizioni/index.php', 'Nuova' => '']);

$db = getDB();
$user = currentUser();
$errors = [];

// Pre-fill ticket_id from query string (e.g. when linked from ticket view)
$preTicketId = (int)($_GET['ticket_id'] ?? 0);

// Load selects
$tickets = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 200")->fetchAll();
$partRequests = $db->query("SELECT spr.id, sp.name AS part_name, spr.quantity, t.title AS ticket_title FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id LEFT JOIN tickets t ON spr.ticket_id=t.id WHERE spr.status IN ('approved','pending') ORDER BY spr.id DESC LIMIT 200")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();

$brtAvailable = (getBrtApi() !== null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }

    $tracking        = trim($_POST['tracking_number'] ?? '');
    $corriere        = trim($_POST['corriere'] ?? '');
    $status          = $_POST['status'] ?? 'da_spedire';
    $ticketId        = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $sprId           = (int)($_POST['spare_parts_request_id'] ?? 0) ?: null;
    $dealerId        = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $locationId      = (int)($_POST['location_id'] ?? 0) ?: null;
    $note            = trim($_POST['note'] ?? '');
    $dataSped        = trim($_POST['data_spedizione'] ?? '') ?: null;
    $dataConsegna    = trim($_POST['data_consegna_prevista'] ?? '') ?: null;
    $useBrt          = isset($_POST['use_brt']) && $brtAvailable;

    if (!in_array($status, ['da_spedire','spedita','consegnata','annullata'])) { $errors[] = 'Stato non valido.'; }

    // BRT API call
    $brtParcelId    = null;
    $brtLabelStream = null;
    $brtNumericRef  = null;

    if (!$errors && $useBrt) {
        $brt = getBrtApi();

        // Resolve consignee data from dealer/location
        $consigneeName    = trim($_POST['brt_consignee_name'] ?? '');
        $consigneeAddress = trim($_POST['brt_consignee_address'] ?? '');
        $consigneeZip     = trim($_POST['brt_consignee_zip'] ?? '');
        $consigneeCity    = trim($_POST['brt_consignee_city'] ?? '');
        $consigneeProv    = trim($_POST['brt_consignee_province'] ?? '');
        $numParcels       = max(1, (int)($_POST['brt_num_parcels'] ?? 1));
        $weightKg         = max(0.1, (float)($_POST['brt_weight_kg'] ?? 1.0));
        $brtNumericRef    = (int)($_POST['brt_numeric_ref'] ?? 0) ?: (int)(microtime(true) * 1000) % 9999999;

        if (!$consigneeName || !$consigneeAddress || !$consigneeZip || !$consigneeCity) {
            $errors[] = 'Compila i campi BRT: Ragione Sociale, Indirizzo, CAP e Città del destinatario.';
        }

        if (!$errors) {
            $createData = [
                'consigneeCompanyName'                  => mb_substr($consigneeName, 0, 70),
                'consigneeAddress'                      => mb_substr($consigneeAddress, 0, 35),
                'consigneeZIPCode'                      => mb_substr($consigneeZip, 0, 9),
                'consigneeCity'                         => mb_substr($consigneeCity, 0, 35),
                'consigneeCountryAbbreviationISOAlpha2' => 'IT',
                'numberOfParcels'                       => $numParcels,
                'weightKG'                              => $weightKg,
                'numericSenderReference'                => $brtNumericRef,
                'notes'                                 => mb_substr($note, 0, 70),
            ];
            if ($consigneeProv) {
                $createData['consigneeProvinceAbbreviation'] = strtoupper(mb_substr($consigneeProv, 0, 2));
            }

            $result = $brt->createShipment($createData, true);

            if (!$result['success']) {
                $errMsg = 'BRT: ' . ($result['error'] ?? 'Errore sconosciuto');
                // Append raw BRT response for admin debugging
                if (!empty($result['data'])) {
                    $errMsg .= ' [Risposta: ' . json_encode($result['data'], JSON_UNESCAPED_UNICODE) . ']';
                }
                $errors[] = $errMsg;
            } else {
                $cr = $result['data']['createResponse'] ?? [];
                $brtParcelId = $cr['labels']['label'][0]['parcelID'] ?? null;
                if (!$brtParcelId && isset($cr['labels']['label']['parcelID'])) {
                    $brtParcelId = $cr['labels']['label']['parcelID'];
                }
                // tracking number = parcelNumberFrom
                if (empty($tracking) && !empty($cr['parcelNumberFrom'])) {
                    $tracking = $cr['parcelNumberFrom'];
                }
                if (empty($corriere)) { $corriere = 'BRT'; }
                if ($status === 'da_spedire') { $status = 'spedita'; }
                if (empty($dataSped)) { $dataSped = date('Y-m-d'); }

                // Store label PDF stream (first label)
                $labelEntry = $cr['labels']['label'][0] ?? $cr['labels']['label'] ?? null;
                if ($labelEntry && !empty($labelEntry['stream'])) {
                    $brtLabelStream = $labelEntry['stream'];
                }
            }
        }
    }

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO spedizioni (tracking_number, corriere, status, ticket_id, spare_parts_request_id, dealer_id, location_id, note, data_spedizione, data_consegna_prevista, brt_parcel_id, brt_numeric_ref, brt_label_stream, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$tracking ?: null, $corriere ?: null, $status, $ticketId, $sprId, $dealerId, $locationId, $note ?: null, $dataSped, $dataConsegna, $brtParcelId, $brtNumericRef, $brtLabelStream, $user['id']]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'spedizione', (int)$newId, "Creata spedizione tracking: $tracking" . ($brtParcelId ? " (BRT parcel: $brtParcelId)" : ''));
        header('Location: ' . APP_URL . '/modules/spedizioni/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Nuova Spedizione</h4>
    <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" id="spedForm">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Numero Tracking</label>
                    <input type="text" name="tracking_number" class="form-control font-monospace" value="<?= h($_POST['tracking_number'] ?? '') ?>" placeholder="Es. GLS1234567890">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Corriere</label>
                    <input type="text" name="corriere" class="form-control" value="<?= h($_POST['corriere'] ?? '') ?>" placeholder="Es. GLS, BRT, DHL...">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Stato <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="da_spedire" <?= ($_POST['status'] ?? 'da_spedire') === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                        <option value="spedita" <?= ($_POST['status'] ?? '') === 'spedita' ? 'selected' : '' ?>>Spedita</option>
                        <option value="consegnata" <?= ($_POST['status'] ?? '') === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                        <option value="annullata" <?= ($_POST['status'] ?? '') === 'annullata' ? 'selected' : '' ?>>Annullata</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Spedizione</label>
                    <input type="date" name="data_spedizione" class="form-control" value="<?= h($_POST['data_spedizione'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Data Consegna Prevista</label>
                    <input type="date" name="data_consegna_prevista" class="form-control" value="<?= h($_POST['data_consegna_prevista'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ticket Collegato</label>
                    <select name="ticket_id" class="form-select">
                        <option value="">-- Nessuno --</option>
                        <?php
                        $selTicket = (int)($_POST['ticket_id'] ?? $preTicketId);
                        foreach ($tickets as $t):
                        ?>
                        <option value="<?= $t['id'] ?>" <?= $selTicket == $t['id'] ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT)) ?> — <?= h($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Richiesta Ricambio</label>
                    <select name="spare_parts_request_id" class="form-select">
                        <option value="">-- Nessuna --</option>
                        <?php foreach ($partRequests as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($_POST['spare_parts_request_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>#<?= $r['id'] ?> — <?= h($r['part_name']) ?> (qty: <?= $r['quantity'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Concessionario</label>
                    <select name="dealer_id" class="form-select" id="dealerSelect">
                        <option value="">-- Nessuno --</option>
                        <?php foreach ($dealers as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($_POST['dealer_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Punto Vendita</label>
                    <select name="location_id" class="form-select" id="locationSelect">
                        <option value="">-- Nessuno --</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Note</label>
                    <textarea name="note" class="form-control" rows="3" placeholder="Note aggiuntive sulla spedizione..."><?= h($_POST['note'] ?? '') ?></textarea>
                </div>
            </div>

            <?php if ($brtAvailable): ?>
            <!-- ── BRT API Integration ──────────────────────────────────────── -->
            <hr class="my-4">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="use_brt" id="useBrt" <?= isset($_POST['use_brt']) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="useBrt">
                    <i class="bi bi-truck text-primary me-1"></i>Crea spedizione tramite BRT API
                </label>
                <small class="d-block text-muted">Registra la spedizione in BRT, genera etichetta PDF e compila il tracking automaticamente.</small>
            </div>
            <div id="brtFields" class="<?= isset($_POST['use_brt']) ? '' : 'd-none' ?>">
                <div class="card border-primary border-opacity-25 mb-3">
                    <div class="card-header bg-primary bg-opacity-10">
                        <h6 class="mb-0 text-primary"><i class="bi bi-truck me-2"></i>Dati destinatario BRT</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Ragione Sociale Destinatario <span class="text-danger">*</span></label>
                                <input type="text" name="brt_consignee_name" class="form-control" maxlength="70" value="<?= h($_POST['brt_consignee_name'] ?? '') ?>" placeholder="Es. Mario Rossi S.r.l.">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Indirizzo <span class="text-danger">*</span></label>
                                <input type="text" name="brt_consignee_address" class="form-control" maxlength="35" value="<?= h($_POST['brt_consignee_address'] ?? '') ?>" placeholder="Es. Via Roma 1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">CAP <span class="text-danger">*</span></label>
                                <input type="text" name="brt_consignee_zip" class="form-control" maxlength="9" value="<?= h($_POST['brt_consignee_zip'] ?? '') ?>" placeholder="Es. 20100">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold">Città <span class="text-danger">*</span></label>
                                <input type="text" name="brt_consignee_city" class="form-control" maxlength="35" value="<?= h($_POST['brt_consignee_city'] ?? '') ?>" placeholder="Es. MILANO">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Provincia</label>
                                <input type="text" name="brt_consignee_province" class="form-control" maxlength="2" value="<?= h($_POST['brt_consignee_province'] ?? '') ?>" placeholder="MI">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">N° Colli</label>
                                <input type="number" name="brt_num_parcels" class="form-control" min="1" max="85" value="<?= h($_POST['brt_num_parcels'] ?? '1') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Peso (kg)</label>
                                <input type="number" name="brt_weight_kg" class="form-control" min="0.1" step="0.1" value="<?= h($_POST['brt_weight_kg'] ?? '1.0') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Rif. Numerico Mittente</label>
                                <input type="number" name="brt_numeric_ref" class="form-control" min="1" max="9999999" value="<?= h($_POST['brt_numeric_ref'] ?? '') ?>" placeholder="Autogenerato">
                                <small class="text-muted">Lascia vuoto per generare automaticamente.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Spedizione</button>
                <a href="<?= APP_URL ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary">Annulla</a>
            </div>
        </form>
    </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
    window.appUrl = "' . APP_URL . '";
    $("#dealerSelect").on("change", function(){
        var did = $(this).val();
        var $loc = $("#locationSelect");
        $loc.html("<option value=\"\">-- Nessuno --</option>");
        if (!did) return;
        $.getJSON(window.appUrl + "/api/parts.php?action=dealer_locations&dealer_id=" + did, function(data){
            if (data.success) {
                $.each(data.data, function(i, l){
                    $loc.append("<option value=\""+l.id+"\">"+l.name+"</option>");
                });
            }
        });
    });

    // BRT toggle
    $("#useBrt").on("change", function(){
        if ($(this).is(":checked")) {
            $("#brtFields").removeClass("d-none");
            var $req = $("#brtFields input[name=brt_consignee_name], #brtFields input[name=brt_consignee_address], #brtFields input[name=brt_consignee_zip], #brtFields input[name=brt_consignee_city]");
            $req.prop("required", true);
        } else {
            $("#brtFields").addClass("d-none");
            $req.prop("required", false);
        }
    });
    // Set required on load if checked
    if ($("#useBrt").is(":checked")) {
        $("#brtFields input[name=brt_consignee_name], #brtFields input[name=brt_consignee_address], #brtFields input[name=brt_consignee_zip], #brtFields input[name=brt_consignee_city]").prop("required", true);
    }
});
</script>';
?>
<?php include APP_ROOT . '/includes/footer.php'; ?>
