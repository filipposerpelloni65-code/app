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

    case 'create':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $dealer_id = (int)($_POST['dealer_id'] ?? 0) ?: null;
        $location_id = (int)($_POST['location_id'] ?? 0) ?: null;
        $codice_concessionario = trim($_POST['codice_concessionario'] ?? '') ?: null;
        if (!$title) { echo json_encode(['success'=>false,'error'=>'Il titolo è obbligatorio']); break; }
        if (!$description) { echo json_encode(['success'=>false,'error'=>'La descrizione è obbligatoria']); break; }
        if (!in_array($priority, ['low','medium','high','urgent'])) { echo json_encode(['success'=>false,'error'=>'Priorità non valida']); break; }
        $stmt = $db->prepare("INSERT INTO tickets (title, description, priority, category_id, created_by, assigned_to, dealer_id, location_id, codice_concessionario, status) VALUES (?,?,?,?,?,?,?,?,?,'open')");
        $stmt->execute([$title, $description, $priority, $category_id, $user['id'], $assigned_to, $dealer_id, $location_id, $codice_concessionario]);
        $newId = (int)$db->lastInsertId();
        logActivity($user['id'], 'create', 'ticket', $newId, "API create ticket: $title");
        echo json_encode(['success' => true, 'id' => $newId]);
        break;

    case 'assign':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
        if (!$id) { echo json_encode(['success'=>false,'error'=>'ID non valido']); break; }
        $db->prepare("UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$assigned_to, $id]);
        logActivity($user['id'], 'assign', 'ticket', $id, "API assign -> " . ($assigned_to ?? 'nessuno'));
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        if (!isAdmin()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'ID non valido']); break; }
        $db->prepare("DELETE FROM ticket_comments WHERE ticket_id=?")->execute([$id]);
        $db->prepare("DELETE FROM ticket_attachments WHERE ticket_id=?")->execute([$id]);
        $db->prepare("DELETE FROM ticket_uscite WHERE ticket_id=?")->execute([$id]);
        $db->prepare("UPDATE spare_parts_requests SET ticket_id=NULL WHERE ticket_id=?")->execute([$id]);
        $db->prepare("UPDATE rapportini SET ticket_id=NULL WHERE ticket_id=?")->execute([$id]);
        $db->prepare("UPDATE periferiche_guaste SET ticket_id=NULL WHERE ticket_id=?")->execute([$id]);
        $db->prepare("DELETE FROM tickets WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'delete', 'ticket', $id, "API delete ticket $id");
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
