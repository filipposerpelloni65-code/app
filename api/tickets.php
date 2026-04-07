<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireLogin();
$user = currentUser();
$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        $status = $_GET['status'] ?? '';
        $where = $user['role'] === 'user' ? 'WHERE t.created_by=' . $user['id'] : '';
        if ($status) $where = $where ? "$where AND t.status='$status'" : "WHERE t.status='$status'";
        $rows = $db->query("SELECT t.id, t.title, t.status, t.priority, t.created_at, u.full_name as assignee FROM tickets t LEFT JOIN users u ON t.assigned_to=u.id $where ORDER BY t.created_at DESC LIMIT 50")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT t.*, u.full_name as assignee_name FROM tickets t LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket || ($user['role'] === 'user' && $ticket['created_by'] != $user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Not found']);
        } else {
            echo json_encode(['success' => true, 'data' => $ticket]);
        }
        break;

    case 'update_status':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'CSRF token non valido']); break; }
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['open','in_progress','waiting','resolved','closed'])) {
            echo json_encode(['success'=>false,'error'=>'Invalid status']); break;
        }
        $closedAt = in_array($status, ['resolved','closed']) ? ', closed_at=NOW()' : '';
        $db->prepare("UPDATE tickets SET status=?, updated_at=NOW()$closedAt WHERE id=?")->execute([$status, $id]);
        logActivity($user['id'], 'status_change', 'ticket', $id, "API status -> $status");
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
