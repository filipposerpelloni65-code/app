<?php
require_once __DIR__ . '/db.php';

function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

function logActivity(int $userId = null, string $action = '', string $entityType = '', int $entityId = null, string $details = ''): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $db->prepare('INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$userId, $action, $entityType, $entityId, $details, $ip]);
    } catch (Exception $e) {
        // Fail silently for logging
    }
}

function getSetting(string $key, string $default = ''): string {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? ($row['setting_value'] ?? $default) : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function formatDate(string $date, string $format = 'd/m/Y H:i'): string {
    if (!$date) return '-';
    return date($format, strtotime($date));
}

function getStatusBadge(string $status): string {
    $badges = [
        'open' => '<span class="badge bg-primary">Aperto</span>',
        'in_progress' => '<span class="badge bg-warning text-dark">In Lavorazione</span>',
        'waiting' => '<span class="badge bg-secondary">In Attesa</span>',
        'resolved' => '<span class="badge bg-success">Risolto</span>',
        'closed' => '<span class="badge bg-dark">Chiuso</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($status) . '</span>';
}

function getPriorityBadge(string $priority): string {
    $badges = [
        'low' => '<span class="badge bg-info text-dark">Bassa</span>',
        'medium' => '<span class="badge bg-secondary">Media</span>',
        'high' => '<span class="badge bg-warning text-dark">Alta</span>',
        'urgent' => '<span class="badge bg-danger">Urgente</span>',
    ];
    return $badges[$priority] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($priority) . '</span>';
}

function getStatusLabel(string $status): string {
    $labels = [
        'open' => 'Aperto',
        'in_progress' => 'In Lavorazione',
        'waiting' => 'In Attesa',
        'resolved' => 'Risolto',
        'closed' => 'Chiuso',
    ];
    return $labels[$status] ?? $status;
}

function getPriorityLabel(string $priority): string {
    $labels = [
        'low' => 'Bassa',
        'medium' => 'Media',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ];
    return $labels[$priority] ?? $priority;
}

function getRequestStatusBadge(string $status): string {
    $badges = [
        'pending' => '<span class="badge bg-warning text-dark">In Attesa</span>',
        'approved' => '<span class="badge bg-success">Approvata</span>',
        'rejected' => '<span class="badge bg-danger">Rifiutata</span>',
        'fulfilled' => '<span class="badge bg-primary">Evasa</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function getTicketPrefix(): string {
    return getSetting('ticket_prefix', 'TKT');
}
