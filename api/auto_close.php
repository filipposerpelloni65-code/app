<?php
/**
 * Auto-close resolved tickets after N days of inactivity.
 * Call this endpoint via a cron job (e.g., daily at midnight).
 *
 * Usage (from cron):
 *   curl -s "https://your-domain.com/api/auto_close.php?secret=YOUR_SECRET"
 *
 * Configure the secret in Settings or as an environment variable.
 * Default: resolved tickets older than 7 days get auto-closed.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Simple secret key protection
$expectedSecret = getSetting('auto_close_secret', '');
$providedSecret = $_GET['secret'] ?? $_SERVER['HTTP_X_AUTO_CLOSE_SECRET'] ?? '';

// Secret must be configured (non-empty) and must match; CLI runs are always allowed
if (php_sapi_name() !== 'cli') {
    if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
        http_response_code(403);
        $reason = $expectedSecret === '' ? 'Secret not configured. Set auto_close_secret in Settings.' : 'Invalid secret.';
        echo json_encode(['success' => false, 'error' => $reason]);
        exit;
    }
}

$days = max(1, (int)getSetting('auto_close_days', '7'));

try {
    $db = getDB();
    // Find resolved tickets older than $days days
    $stmt = $db->prepare("SELECT id FROM tickets WHERE status='resolved' AND closed_at IS NOT NULL AND closed_at <= NOW() - INTERVAL ? DAY");
    $stmt->execute([$days]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE tickets SET status='closed', updated_at=NOW() WHERE id IN ($placeholders)")->execute($ids);
        foreach ($ids as $id) {
            logActivity(null, 'auto_close', 'ticket', (int)$id, "Auto-chiuso dopo $days giorni in stato risolto");
        }
    }

    echo json_encode([
        'success' => true,
        'closed'  => count($ids),
        'days'    => $days,
        'message' => count($ids) . ' ticket chiusi automaticamente.',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
