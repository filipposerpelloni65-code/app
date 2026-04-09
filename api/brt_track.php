<?php
/**
 * api/brt_track.php
 * AJAX: refresh BRT tracking for a spedizione.
 * POST /api/brt_track.php  { id: <spedizione_id>, csrf_token: <token> }
 * Returns JSON with tracking events and updates spedizione status when consegnata.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brt_api.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token non valido']);
    exit;
}

$id = (int)($data['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID mancante']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT brt_parcel_id, status FROM spedizioni WHERE id = ?");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row || empty($row['brt_parcel_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nessun parcel ID BRT per questa spedizione']);
    exit;
}

$brt = getBrtApi();
if (!$brt) {
    echo json_encode(['success' => false, 'error' => 'Credenziali BRT non configurate']);
    exit;
}

$result = $brt->trackParcel($row['brt_parcel_id']);
if (!$result['success']) {
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

$tracking = $result['data']['spedizione'] ?? $result['data'] ?? [];

// Auto-update status based on BRT tracking data
$eventi = $tracking['eventi']['evento'] ?? [];
if (!isset($eventi[0])) { $eventi = $eventi ? [$eventi] : []; }

$isDelivered  = false;
$isInTransit  = (count($eventi) > 0); // any event means picked up
$currentStatus = $row['status'];

// Detect delivery: event id 05 OR data_consegna_merce populated
foreach ($eventi as $ev) {
    if (isset($ev['id']) && in_array($ev['id'], ['05', '5', '006'], true)) {
        $isDelivered = true;
        break;
    }
}
// Also check dati_consegna.data_consegna_merce
$dataCons = $tracking['dati_consegna'] ?? [];
if (!empty($dataCons['data_consegna_merce'])) {
    $isDelivered = true;
}

$user = currentUser();

if ($isDelivered && $currentStatus !== 'consegnata') {
    $db->prepare("UPDATE spedizioni SET status='consegnata', updated_at=NOW() WHERE id=?")->execute([$id]);
    logActivity($user['id'], 'auto_update', 'spedizione', $id, 'Status aggiornato a consegnata via BRT tracking');
} elseif ($isInTransit && !$isDelivered && $currentStatus === 'da_spedire') {
    // Package has been picked up by BRT — mark as spedita
    $db->prepare("UPDATE spedizioni SET status='spedita', updated_at=NOW() WHERE id=?")->execute([$id]);
    logActivity($user['id'], 'auto_update', 'spedizione', $id, 'Status aggiornato a spedita via BRT tracking (collo ritirato)');
}

echo json_encode(['success' => true, 'data' => $tracking, 'isDelivered' => $isDelivered, 'isInTransit' => $isInTransit]);
