<?php
/**
 * Auto-close API - da invocare via cron
 * Uso: GET /api/auto_close.php?secret=<auto_close_secret>
 * Chiude automaticamente i ticket risolti da più di auto_close_days giorni.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Secret key check
$secret = getSetting('auto_close_secret', '');
$provided = $_GET['secret'] ?? $_SERVER['HTTP_X_AUTO_CLOSE_SECRET'] ?? '';

if (empty($secret) || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$days = (int)getSetting('auto_close_days', '0');
if ($days < 1) {
    echo json_encode(['success' => true, 'closed' => 0, 'message' => 'Auto-close disabilitato (auto_close_days=0)']);
    exit;
}

$db = getDB();

// Find resolved tickets older than $days days
$stmt = $db->prepare("
    SELECT id, title FROM tickets
    WHERE status = 'resolved'
      AND closed_at IS NOT NULL
      AND closed_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$stmt->execute([$days]);
$toClose = $stmt->fetchAll();

$closed = 0;
foreach ($toClose as $t) {
    $db->prepare("UPDATE tickets SET status='closed', updated_at=NOW() WHERE id=?")->execute([$t['id']]);
    // Log with user_id=null (system action)
    $db->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (NULL,'auto_close','ticket',?,?,?)")
       ->execute([$t['id'], "Auto-chiusura dopo $days giorni", 'cron']);
    $closed++;
}

echo json_encode(['success' => true, 'closed' => $closed, 'days' => $days]);
