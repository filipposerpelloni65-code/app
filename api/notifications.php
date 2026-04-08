<?php
/**
 * Notifications API
 * GET  ?action=fetch            – returns unread count + recent notifications (JSON)
 * GET  ?action=poll&since=ID    – returns only notifications newer than given ID
 * POST action=mark_read         – marks a single notification as read
 * POST action=mark_all_read     – marks all notifications of current user as read
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db  = getDB();
$uid = (int)$user['id'];

$action = $_REQUEST['action'] ?? 'fetch';

// ── GET: fetch / poll ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'poll') {
        // Return only notifications newer than the given last-known ID
        $sinceId = (int)($_GET['since'] ?? 0);
        $stmt = $db->prepare(
            'SELECT id, type, title, message, entity_type, entity_id, url, is_read, created_at
             FROM notifications
             WHERE user_id = ? AND id > ?
             ORDER BY id DESC
             LIMIT 20'
        );
        $stmt->execute([$uid, $sinceId]);
        $rows = $stmt->fetchAll();

        $unread = getUnreadNotificationCount($uid);
        echo json_encode(['success' => true, 'unread' => $unread, 'notifications' => $rows]);
        exit;
    }

    // Default: fetch latest + unread count
    $stmt = $db->prepare(
        'SELECT id, type, title, message, entity_type, entity_id, url, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 30'
    );
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();

    $unread = getUnreadNotificationCount($uid);
    echo json_encode(['success' => true, 'unread' => $unread, 'notifications' => $rows]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token non valido']);
        exit;
    }

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
               ->execute([$id, $uid]);
        }
        echo json_encode(['success' => true, 'unread' => getUnreadNotificationCount($uid)]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
        echo json_encode(['success' => true, 'unread' => 0]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')
               ->execute([$id, $uid]);
        }
        echo json_encode(['success' => true, 'unread' => getUnreadNotificationCount($uid)]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Azione non riconosciuta']);
