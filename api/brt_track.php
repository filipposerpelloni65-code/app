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

// Auto-update status to consegnata if BRT says delivered
$eventi = $tracking['eventi']['evento'] ?? [];
if (!isset($eventi[0])) { $eventi = [$eventi]; } // normalize single evento to array
$isDelivered = false;
foreach ($eventi as $ev) {
    // BRT event ID for "consegnato" is typically "05"
    if (isset($ev['id']) && in_array($ev['id'], ['05', '5', '006'])) {
        $isDelivered = true;
        break;
    }
}
if ($isDelivered && $row['status'] !== 'consegnata') {
    $db->prepare("UPDATE spedizioni SET status='consegnata', updated_at=NOW() WHERE id=?")->execute([$id]);
    $user = currentUser();
    logActivity($user['id'], 'auto_update', 'spedizione', $id, 'Status aggiornato a consegnata via BRT tracking');
}

echo json_encode(['success' => true, 'data' => $tracking, 'isDelivered' => $isDelivered]);
