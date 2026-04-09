<?php
/**
 * api/brt_transmit.php
 * Trasmette le spedizioni selezionate a BRT, genera etichette e crea il bordero.
 * POST  { ids: [1,2,3], csrf_token: "...", note: "...", salva_bordero: true }
 * Returns JSON { success, bordero_id, results: [{id, ok, tracking, error}], error }
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brt_api.php';

header('Content-Type: application/json');
requireRole('admin', 'technician');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!validateCsrfToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token non valido']);
    exit;
}

$ids = array_filter(array_map('intval', (array)($input['ids'] ?? [])));
if (!$ids) {
    echo json_encode(['success' => false, 'error' => 'Nessuna spedizione selezionata']);
    exit;
}

$noteBordero  = trim($input['note'] ?? '');
$salvaBordero = !empty($input['salva_bordero']);

$db   = getDB();
$user = currentUser();
$brt  = getBrtApi();

// Load selected shipments
$inList = implode(',', $ids);
$speds  = $db->query("SELECT * FROM spedizioni WHERE id IN ($inList)")->fetchAll();

if (!$speds) {
    echo json_encode(['success' => false, 'error' => 'Spedizioni non trovate']);
    exit;
}

$results        = [];
$transmittedIds = [];

foreach ($speds as $s) {
    $sid    = (int)$s['id'];
    $result = ['id' => $sid, 'ok' => false, 'tracking' => null, 'error' => null];

    // If already transmitted (has parcel ID and status != bozza), skip
    if (!empty($s['brt_parcel_id']) && $s['status'] !== 'bozza') {
        $result['ok']       = true;
        $result['tracking'] = $s['tracking_number'];
        $result['skipped']  = true;
        $results[]          = $result;
        $transmittedIds[]   = $sid;
        continue;
    }

    // BRT transmission
    if ($brt && !empty($s['brt_consignee_json'])) {
        $consigneeData = json_decode($s['brt_consignee_json'], true) ?? [];

        $numColli = max(1, (int)($s['num_colli'] ?? 1));
        $pesoKg   = max(0.1, (float)($s['peso_kg'] ?? 1.0));
        $note     = mb_substr((string)($s['note'] ?? ''), 0, 70);

        // BRT numericSenderReference must be a positive integer ≤ 9999999 (7 digits max per BRT API spec)
        $numericRef = (int)(microtime(true) * 1000) % 9999999;

        $createData = array_merge($consigneeData, [
            'numberOfParcels'        => $numColli,
            'weightKG'               => $pesoKg,
            'numericSenderReference' => $numericRef,
            'notes'                  => $note,
        ]);

        // isAlertRequired is set only if email is present; ensure it carries through
        if (!empty($consigneeData['consigneeEMail']) && !isset($createData['isAlertRequired'])) {
            $createData['isAlertRequired'] = '1';
        }

        $apiResult = $brt->createShipment($createData, true);

        if (!$apiResult['success']) {
            $result['error'] = 'BRT: ' . ($apiResult['error'] ?? 'Errore sconosciuto');
            $results[] = $result;
            continue;
        }

        $cr          = $apiResult['data']['createResponse'] ?? [];
        $tracking    = $cr['parcelNumberFrom'] ?? null;
        $parcelId    = null;
        $labelsJson  = null;

        // Collect ALL labels
        $rawLabels = $cr['labels']['label'] ?? [];
        // Normalise to array of label entries
        if (isset($rawLabels['parcelID'])) {
            $rawLabels = [$rawLabels]; // single label as object
        }
        if (is_array($rawLabels) && !empty($rawLabels)) {
            $labelsList = [];
            foreach ($rawLabels as $lbl) {
                if (!empty($lbl['stream'])) {
                    $labelsList[] = [
                        'parcelID' => $lbl['parcelID'] ?? null,
                        'stream'   => $lbl['stream'],
                    ];
                }
            }
            if ($labelsList) {
                $labelsJson = json_encode($labelsList);
                // First parcel ID for tracking
                $parcelId = $labelsList[0]['parcelID'] ?? null;
            }
        }

        // Legacy single-label support (keep brt_label_stream for backward compat)
        $firstStream = $rawLabels[0]['stream'] ?? (isset($rawLabels['stream']) ? $rawLabels['stream'] : null);

        $db->prepare("UPDATE spedizioni SET
            tracking_number   = COALESCE(tracking_number, ?),
            corriere          = COALESCE(NULLIF(corriere,''), 'BRT'),
            status            = 'da_spedire',
            brt_parcel_id     = ?,
            brt_numeric_ref   = ?,
            brt_label_stream  = COALESCE(brt_label_stream, ?),
            brt_labels_json   = ?,
            transmitted_at    = NOW(),
            data_spedizione   = COALESCE(data_spedizione, CURDATE()),
            updated_at        = NOW()
            WHERE id = ?")
            ->execute([$tracking, $parcelId, $numericRef, $firstStream, $labelsJson, $sid]);

        logActivity($user['id'], 'transmit', 'spedizione', $sid, "Trasmessa a BRT — tracking: $tracking, colli: $numColli");

        $result['ok']       = true;
        $result['tracking'] = $tracking;
        $transmittedIds[]   = $sid;

    } elseif (!$brt) {
        // No BRT API configured — just change status
        $db->prepare("UPDATE spedizioni SET status='da_spedire', transmitted_at=NOW(), updated_at=NOW() WHERE id=?")
           ->execute([$sid]);
        logActivity($user['id'], 'transmit', 'spedizione', $sid, 'Marcata da_spedire (BRT non configurato)');
        $result['ok']       = true;
        $result['tracking'] = $s['tracking_number'];
        $transmittedIds[]   = $sid;

    } else {
        // No BRT consignee data — just change status
        $db->prepare("UPDATE spedizioni SET status='da_spedire', transmitted_at=NOW(), updated_at=NOW() WHERE id=?")
           ->execute([$sid]);
        logActivity($user['id'], 'transmit', 'spedizione', $sid, 'Marcata da_spedire (nessun dato BRT)');
        $result['ok']       = true;
        $result['tracking'] = $s['tracking_number'];
        $transmittedIds[]   = $sid;
    }

    $results[] = $result;
}

// Create bordero record
$borderoId = null;
if ($salvaBordero && $transmittedIds) {
    $stmt = $db->prepare("INSERT INTO borderi (data_bordero, created_by, note, spedizioni_ids, shipped_count) VALUES (CURDATE(), ?, ?, ?, ?)");
    $stmt->execute([
        $user['id'],
        $noteBordero ?: null,
        json_encode(array_values($transmittedIds)),
        count($transmittedIds),
    ]);
    $borderoId = (int)$db->lastInsertId();
    logActivity($user['id'], 'create', 'bordero', $borderoId, 'Bordero creato con ' . count($transmittedIds) . ' spedizioni');
}

$allOk = !array_filter($results, fn($r) => !$r['ok']);

echo json_encode([
    'success'    => true,
    'all_ok'     => (bool)$allOk,
    'bordero_id' => $borderoId,
    'results'    => $results,
]);
