<?php
/**
 * Global Search API
 * GET /api/search.php?q=<query>
 * Returns JSON results across tickets, dealers, spare_parts, periferiche, spedizioni
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$db = getDB();
$user = currentUser();
$like = '%' . $q . '%';
$results = [];

// Tickets
$tWhere = $user['role'] === 'user' ? 'AND t.created_by=' . $user['id'] : '';
$tStmt = $db->prepare("SELECT 'ticket' AS type, t.id, CONCAT('" . getTicketPrefix() . "-', LPAD(t.id,4,'0')) AS code, t.title AS label, t.status AS meta FROM tickets t WHERE (t.title LIKE ? OR t.description LIKE ?) $tWhere ORDER BY t.created_at DESC LIMIT 5");
$tStmt->execute([$like, $like]);
foreach ($tStmt->fetchAll() as $row) {
    $results[] = [
        'type'  => 'Ticket',
        'icon'  => 'bi-ticket-detailed',
        'code'  => $row['code'],
        'label' => $row['label'],
        'meta'  => getStatusLabel($row['meta']),
        'url'   => APP_URL . '/modules/tickets/view.php?id=' . $row['id'],
    ];
}

// Dealers
if ($user['role'] !== 'user') {
    $dStmt = $db->prepare("SELECT id, name, city FROM dealers WHERE (name LIKE ? OR code LIKE ? OR city LIKE ?) AND active=1 ORDER BY name LIMIT 4");
    $dStmt->execute([$like, $like, $like]);
    foreach ($dStmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Concessionario',
            'icon'  => 'bi-shop',
            'code'  => '',
            'label' => $row['name'],
            'meta'  => $row['city'] ?? '',
            'url'   => APP_URL . '/modules/dealers/view.php?id=' . $row['id'],
        ];
    }
}

// Spare parts
if ($user['role'] !== 'user') {
    $spStmt = $db->prepare("SELECT id, name, sku, quantity FROM spare_parts WHERE (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 4");
    $spStmt->execute([$like, $like]);
    foreach ($spStmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Ricambio',
            'icon'  => 'bi-tools',
            'code'  => $row['sku'] ?? '',
            'label' => $row['name'],
            'meta'  => 'Qta: ' . (int)$row['quantity'],
            'url'   => APP_URL . '/modules/spare_parts/edit.php?id=' . $row['id'],
        ];
    }
}

// Periferiche
if ($user['role'] !== 'user') {
    $pgStmt = $db->prepare("SELECT id, codice, tipo, marca, modello, stato FROM periferiche_guaste WHERE (codice LIKE ? OR tipo LIKE ? OR marca LIKE ? OR modello LIKE ? OR seriale LIKE ?) ORDER BY created_at DESC LIMIT 4");
    $pgStmt->execute([$like, $like, $like, $like, $like]);
    foreach ($pgStmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Periferica',
            'icon'  => 'bi-hdd-network',
            'code'  => $row['codice'],
            'label' => trim($row['tipo'] . ' ' . ($row['marca'] ?? '') . ' ' . ($row['modello'] ?? '')),
            'meta'  => getPerifericaStatoLabel($row['stato']),
            'url'   => APP_URL . '/modules/periferiche/view.php?id=' . $row['id'],
        ];
    }
}

// Spedizioni
if ($user['role'] !== 'user') {
    $sStmt = $db->prepare("SELECT id, tracking_number, corriere, status FROM spedizioni WHERE (tracking_number LIKE ? OR corriere LIKE ? OR note LIKE ?) ORDER BY created_at DESC LIMIT 4");
    $sStmt->execute([$like, $like, $like]);
    foreach ($sStmt->fetchAll() as $row) {
        $results[] = [
            'type'  => 'Spedizione',
            'icon'  => 'bi-truck',
            'code'  => $row['tracking_number'] ?? '',
            'label' => $row['corriere'] ? 'Corriere: ' . $row['corriere'] : 'Spedizione #' . $row['id'],
            'meta'  => getSpedizioneStatusLabel($row['status']),
            'url'   => APP_URL . '/modules/spedizioni/view.php?id=' . $row['id'],
        ];
    }
}

echo json_encode(['success' => true, 'results' => $results]);
