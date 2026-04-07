<?php
require_once __DIR__ . '/db.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function requireLogin(): void {
    startSecureSession();
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    $user = currentUser();
    if (!$user || !in_array($user['role'], $roles)) {
        http_response_code(403);
        include APP_ROOT . '/includes/header.php';
        echo '<div class="container mt-5"><div class="alert alert-danger">Accesso non autorizzato. Non hai i permessi necessari per questa pagina.</div></div>';
        include APP_ROOT . '/includes/footer.php';
        exit;
    }
}

function currentUser(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
    return $user ?: null;
}

function login(string $username, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        startSecureSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        return true;
    }
    return false;
}

function logout(): void {
    startSecureSession();
    $_SESSION = [];
    session_destroy();
}

function isLoggedIn(): bool {
    startSecureSession();
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    $user = currentUser();
    return $user && $user['role'] === 'admin';
}

function isTechnician(): bool {
    $user = currentUser();
    return $user && in_array($user['role'], ['admin', 'technician']);
}
