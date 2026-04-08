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
        $q = trim($_GET['q'] ?? '');
        $params = [];
        $where = '1=1';
        if ($q) { $where .= ' AND (name LIKE ? OR sku LIKE ?)'; $params = ["%$q%","%$q%"]; }
        $rows = $db->prepare("SELECT id, name, sku, quantity, min_quantity, unit_price, location FROM spare_parts WHERE $where ORDER BY name LIMIT 100");
        $rows->execute($params);
        echo json_encode(['success' => true, 'data' => $rows->fetchAll()]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM spare_parts WHERE id=?");
        $stmt->execute([$id]);
        $part = $stmt->fetch();
        if (!$part) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Not found']); break; }
        echo json_encode(['success' => true, 'data' => $part]);
        break;

    case 'update_stock':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);
        if ($id < 1) { echo json_encode(['success'=>false,'error'=>'Invalid id']); break; }
        $db->prepare("UPDATE spare_parts SET quantity=?, updated_at=NOW() WHERE id=?")->execute([max(0,$qty), $id]);
        logActivity($user['id'], 'update_stock', 'spare_part', $id, "Stock aggiornato a $qty");
        echo json_encode(['success' => true]);
        break;

    case 'low_stock':
        $rows = $db->query("SELECT id, name, sku, quantity, min_quantity FROM spare_parts WHERE quantity <= min_quantity ORDER BY quantity ASC")->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'dealer_locations':
        $dealerId = (int)($_GET['dealer_id'] ?? 0);
        if (!$dealerId) { echo json_encode(['success'=>false,'error'=>'Missing dealer_id']); break; }
        $rows = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
        $rows->execute([$dealerId]);
        echo json_encode(['success' => true, 'data' => $rows->fetchAll()]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
