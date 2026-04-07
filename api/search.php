<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$user = currentUser();
$db   = getDB();
$like = '%' . $q . '%';
$results = [];

// Tickets
$ticketWhere = $user['role'] === 'user' ? 'AND t.created_by=' . (int)$user['id'] : '';
$stmt = $db->prepare("SELECT t.id, t.title, t.status FROM tickets t WHERE (t.title LIKE ? OR t.description LIKE ?) $ticketWhere ORDER BY t.updated_at DESC LIMIT 5");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $row) {
    $results[] = [
        'type'  => 'ticket',
        'icon'  => 'bi-ticket-detailed',
        'label' => getTicketPrefix() . '-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT) . ' ' . $row['title'],
        'badge' => getStatusLabel($row['status']),
        'url'   => APP_URL . '/modules/tickets/view.php?id=' . $row['id'],
    ];
}

if (isTechnician()) {
    // Spare parts
    $stmt = $db->prepare("SELECT id, name, sku FROM spare_parts WHERE name LIKE ? OR sku LIKE ? LIMIT 4");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'part',
            'icon'  => 'bi-tools',
            'label' => $row['name'] . ($row['sku'] ? ' [' . $row['sku'] . ']' : ''),
            'badge' => '',
            'url'   => APP_URL . '/modules/spare_parts/edit.php?id=' . $row['id'],
        ];
    }

    // Periferiche
    try {
        $stmt = $db->prepare("SELECT id, codice, tipo, marca, modello FROM periferiche_guaste WHERE codice LIKE ? OR tipo LIKE ? OR marca LIKE ? OR seriale LIKE ? LIMIT 4");
        $stmt->execute([$like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $label = $row['codice'] . ' – ' . $row['tipo'];
            if ($row['marca'] || $row['modello']) $label .= ' (' . trim($row['marca'] . ' ' . $row['modello']) . ')';
            $results[] = [
                'type'  => 'periferica',
                'icon'  => 'bi-hdd-network',
                'label' => $label,
                'badge' => '',
                'url'   => APP_URL . '/modules/periferiche/view.php?id=' . $row['id'],
            ];
        }
    } catch (Exception $e) { /* table may not exist */ }

    // Dealers
    try {
        $stmt = $db->prepare("SELECT id, name, code FROM dealers WHERE name LIKE ? OR code LIKE ? LIMIT 3");
        $stmt->execute([$like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = [
                'type'  => 'dealer',
                'icon'  => 'bi-shop',
                'label' => $row['name'] . ' [' . $row['code'] . ']',
                'badge' => '',
                'url'   => APP_URL . '/modules/dealers/view.php?id=' . $row['id'],
            ];
        }
    } catch (Exception $e) { /* silent */ }
}

echo json_encode(['results' => $results]);
