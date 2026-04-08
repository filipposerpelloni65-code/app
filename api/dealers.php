<?php
/**
 * api/dealers.php
 * AJAX API for Dealers: create, edit (quick), toggle active.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireRole('admin');

$db   = getDB();
$user = currentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function dJsonOk(array $data = []): void {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}
function dJsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function dCsrfCheck(): void {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        dJsonErr('Token CSRF non valido.', 403);
    }
}

switch ($action) {

    case 'create':
        dCsrfCheck();
        $name    = trim($_POST['name'] ?? '');
        $code    = strtoupper(trim($_POST['code'] ?? ''));
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        if (!$name) dJsonErr('Il nome è obbligatorio.');
        if (!$code) dJsonErr('Il codice è obbligatorio.');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) dJsonErr('Email non valida.');
        try {
            $stmt = $db->prepare("INSERT INTO dealers (name, code, email, phone, address, city, active) VALUES (?,?,?,?,?,?,1)");
            $stmt->execute([$name, $code, $email, $phone, $address, $city]);
            $id = (int)$db->lastInsertId();
            logActivity($user['id'], 'create', 'dealer', $id, "Creato concessionario (API): $name");
            dJsonOk(['id' => $id, 'name' => $name]);
        } catch (Exception $e) {
            $msg = strpos($e->getMessage(), 'Duplicate') !== false
                ? 'Il codice concessionario è già in uso.'
                : 'Errore durante il salvataggio.';
            dJsonErr($msg);
        }

    default:
        dJsonErr('Azione non riconosciuta.', 400);
}
