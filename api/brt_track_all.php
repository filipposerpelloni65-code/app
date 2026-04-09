<?php
/**
 * api/brt_track_all.php
 * Bulk-refresh BRT tracking for all shipments with status = 'spedita'.
 *
 * Can be called:
 *   - Via browser by an authenticated admin/technician (no secret required)
 *   - Via cron: GET /api/brt_track_all.php?secret=<auto_close_secret>
 *
 * Returns JSON with updated / unchanged / error counts.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brt_api.php';

header('Content-Type: application/json; charset=utf-8');

// Auth: either valid session technician, or matching secret
$isCron = false;
$secret = getSetting('auto_close_secret', '');
if ($secret && isset($_GET['secret']) && hash_equals($secret, $_GET['secret'])) {
    $isCron = true;
}

if (!$isCron) {
    requireLogin();
    if (!isTechnician()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$brt = getBrtApi();
if (!$brt) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Credenziali BRT non configurate']);
    exit;
}

$db   = getDB();
// Track both 'da_spedire' (transmitted, awaiting pickup) and 'spedita' (in transit) BRT shipments
$rows = $db->query("SELECT id, brt_parcel_id, status FROM spedizioni WHERE status IN ('da_spedire','spedita') AND brt_parcel_id IS NOT NULL AND brt_parcel_id <> ''")->fetchAll();

$stats = ['updated' => 0, 'unchanged' => 0, 'errors' => 0, 'total' => count($rows)];
$userId = $isCron ? 0 : (int)(currentUser()['id'] ?? 0);

// BRT event IDs:
// 01/1 = pickup/collected from sender → spedita
// 05/5/006 = delivered to consignee → consegnata
$pickupEventIds   = ['01', '1'];
$deliveryEventIds = ['05', '5', '006'];

foreach ($rows as $row) {
    $result = $brt->trackParcel($row['brt_parcel_id']);
    if (!$result['success']) {
        $stats['errors']++;
        continue;
    }

    $tracking = $result['data']['spedizione'] ?? $result['data'] ?? [];
    $eventi   = $tracking['eventi']['evento'] ?? [];
    if (!isset($eventi[0])) {
        $eventi = $eventi ? [$eventi] : [];
    }

    $isDelivered = false;
    $isPickedUp  = false;
    foreach ($eventi as $ev) {
        $evId = (string)($ev['id'] ?? '');
        if (in_array($evId, $deliveryEventIds, true)) {
            $isDelivered = true;
            break;
        }
        if (in_array($evId, $pickupEventIds, true)) {
            $isPickedUp = true;
        }
    }

    if ($isDelivered && $row['status'] !== 'consegnata') {
        $db->prepare("UPDATE spedizioni SET status='consegnata', updated_at=NOW() WHERE id=?")->execute([$row['id']]);
        logActivity($userId, 'auto_update', 'spedizione', (int)$row['id'], 'Status aggiornato a consegnata via brt_track_all');
        $stats['updated']++;
    } elseif ($isPickedUp && $row['status'] === 'da_spedire') {
        $db->prepare("UPDATE spedizioni SET status='spedita', updated_at=NOW() WHERE id=?")->execute([$row['id']]);
        logActivity($userId, 'auto_update', 'spedizione', (int)$row['id'], 'Status aggiornato a spedita (ritiro BRT confermato)');
        $stats['updated']++;
    } else {
        $stats['unchanged']++;
    }
}

echo json_encode(['success' => true, 'stats' => $stats]);
