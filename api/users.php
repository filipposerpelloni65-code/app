<?php
/**
 * api/users.php
 * AJAX API for Users: create.
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

function uJsonOk(array $data = []): void {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}
function uJsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function uCsrfCheck(): void {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        uJsonErr('Token CSRF non valido.', 403);
    }
}

switch ($action) {

    case 'create':
        uCsrfCheck();
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = $_POST['role'] ?? 'user';
        $password  = $_POST['password'] ?? '';
        if (!$full_name) uJsonErr('Il nome completo è obbligatorio.');
        if (!$username)  uJsonErr('Lo username è obbligatorio.');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) uJsonErr('Email non valida.');
        if (!in_array($role, ['admin','technician','user'])) uJsonErr('Ruolo non valido.');
        if (strlen($password) < 6) uJsonErr('La password deve essere di almeno 6 caratteri.');
        // Check uniqueness
        $check = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->execute([$username, $email]);
        if ($check->fetch()) uJsonErr('Username o email già in uso.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (full_name, username, email, password_hash, role, active) VALUES (?,?,?,?,?,1)");
        $stmt->execute([$full_name, $username, $email, $hash, $role]);
        $id = (int)$db->lastInsertId();
        logActivity($user['id'], 'create', 'user', $id, "Creato utente (API): $username");
        uJsonOk(['id' => $id]);

    default:
        uJsonErr('Azione non riconosciuta.', 400);
}
