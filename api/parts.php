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

    case 'adjust_stock':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        if ($id < 1) { echo json_encode(['success'=>false,'error'=>'Invalid id']); break; }
        if ($delta === 0) { echo json_encode(['success'=>false,'error'=>'Delta non può essere zero']); break; }
        $db->prepare("UPDATE spare_parts SET quantity=GREATEST(0, quantity + ?), updated_at=NOW() WHERE id=?")->execute([$delta, $id]);
        $qtyStmt = $db->prepare("SELECT quantity FROM spare_parts WHERE id=?");
        $qtyStmt->execute([$id]);
        $newQty = (int)($qtyStmt->fetchColumn() ?? 0);
        logActivity($user['id'], 'adjust_stock', 'spare_part', $id, "Stock rettificato di $delta, nuovo totale: $newQty");
        echo json_encode(['success' => true, 'quantity' => $newQty]);
        break;

    case 'create':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $min_quantity = max(0, (int)($_POST['min_quantity'] ?? 0));
        $unit_price = !empty($_POST['unit_price']) ? (float)str_replace(',','.', $_POST['unit_price']) : null;
        $location = trim($_POST['location'] ?? '');
        if (!$name) { echo json_encode(['success'=>false,'error'=>'Il nome è obbligatorio']); break; }
        if (!$sku) { echo json_encode(['success'=>false,'error'=>'Il codice SKU è obbligatorio']); break; }
        $check = $db->prepare("SELECT id FROM spare_parts WHERE sku=?");
        $check->execute([$sku]);
        if ($check->fetch()) { echo json_encode(['success'=>false,'error'=>'SKU già esistente']); break; }
        $stmt = $db->prepare("INSERT INTO spare_parts (name, sku, description, category_id, quantity, min_quantity, unit_price, location) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$name, $sku, $description, $category_id, $quantity, $min_quantity, $unit_price, $location]);
        $newId = (int)$db->lastInsertId();
        logActivity($user['id'], 'create', 'spare_part', $newId, "API create parte: $name");
        echo json_encode(['success' => true, 'id' => $newId]);
        break;

    case 'update':
        if (!isTechnician()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'ID non valido']); break; }
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $min_quantity = max(0, (int)($_POST['min_quantity'] ?? 0));
        $unit_price = !empty($_POST['unit_price']) ? (float)str_replace(',','.', $_POST['unit_price']) : null;
        $location = trim($_POST['location'] ?? '');
        if (!$name) { echo json_encode(['success'=>false,'error'=>'Il nome è obbligatorio']); break; }
        if (!$sku) { echo json_encode(['success'=>false,'error'=>'Il codice SKU è obbligatorio']); break; }
        $check = $db->prepare("SELECT id FROM spare_parts WHERE sku=? AND id!=?");
        $check->execute([$sku, $id]);
        if ($check->fetch()) { echo json_encode(['success'=>false,'error'=>'SKU già utilizzato']); break; }
        $db->prepare("UPDATE spare_parts SET name=?, sku=?, description=?, category_id=?, quantity=?, min_quantity=?, unit_price=?, location=?, updated_at=NOW() WHERE id=?")
           ->execute([$name, $sku, $description, $category_id, $quantity, $min_quantity, $unit_price, $location, $id]);
        logActivity($user['id'], 'update', 'spare_part', $id, "API update parte: $name");
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        if (!isAdmin()) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'ID non valido']); break; }
        // Check if used in pending/approved requests
        $inUse = $db->prepare("SELECT COUNT(*) FROM spare_parts_requests WHERE part_id=? AND status IN ('pending','approved')");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'error'=>'Parte utilizzata in richieste attive, impossibile eliminare']); break;
        }
        $db->prepare("DELETE FROM spare_parts_requests WHERE part_id=?")->execute([$id]);
        $db->prepare("DELETE FROM spare_parts WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'delete', 'spare_part', $id, "API delete parte $id");
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
