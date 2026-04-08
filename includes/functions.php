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

function formatDate(string $date, string $format = ''): string {
    if (!$date) return '-';
    if ($format === '') {
        $format = getSetting('date_format', 'd/m/Y H:i');
    }
    return date($format, strtotime($date));
}

function getCurrencySymbol(): string {
    return getSetting('currency_symbol', '€');
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

function getRapportinoStatusBadge(string $status): string {
    $badges = [
        'draft'    => '<span class="badge bg-secondary">Bozza</span>',
        'signed'   => '<span class="badge bg-success">Firmato</span>',
        'archived' => '<span class="badge bg-dark">Archiviato</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($status) . '</span>';
}

function getPerifericaStatoBadge(string $stato): string {
    $badges = [
        'in_giacenza'     => '<span class="badge bg-secondary">In Giacenza</span>',
        'in_diagnosi'     => '<span class="badge bg-info text-dark">In Diagnosi</span>',
        'in_riparazione'  => '<span class="badge bg-warning text-dark">In Riparazione</span>',
        'riparata'        => '<span class="badge bg-success">Riparata</span>',
        'non_riparabile'  => '<span class="badge bg-danger">Non Riparabile</span>',
        'restituita'      => '<span class="badge bg-primary">Restituita</span>',
        'rottamata'       => '<span class="badge bg-dark">Rottamata</span>',
    ];
    return $badges[$stato] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($stato) . '</span>';
}

function getPerifericaStatoLabel(string $stato): string {
    $labels = [
        'in_giacenza'     => 'In Giacenza',
        'in_diagnosi'     => 'In Diagnosi',
        'in_riparazione'  => 'In Riparazione',
        'riparata'        => 'Riparata',
        'non_riparabile'  => 'Non Riparabile',
        'restituita'      => 'Restituita',
        'rottamata'       => 'Rottamata',
    ];
    return $labels[$stato] ?? $stato;
}

/**
 * Return available spare parts (quantity > 0) for modal selects.
 */
function getModalSpareParts(): array {
    $db = getDB();
    return $db->query("SELECT id, name, sku, quantity FROM spare_parts WHERE quantity > 0 ORDER BY name")->fetchAll();
}

/**
 * Return open tickets (not closed) for modal selects.
 */
function getModalOpenTickets(int $limit = 100): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getSpedizioneStatusBadge(string $status): string {
    $badges = [
        'da_spedire'  => '<span class="badge bg-secondary">Da Spedire</span>',
        'spedita'     => '<span class="badge bg-primary">Spedita</span>',
        'consegnata'  => '<span class="badge bg-success">Consegnata</span>',
        'annullata'   => '<span class="badge bg-danger">Annullata</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($status) . '</span>';
}

function getSpedizioneStatusLabel(string $status): string {
    $labels = [
        'da_spedire' => 'Da Spedire',
        'spedita'    => 'Spedita',
        'consegnata' => 'Consegnata',
        'annullata'  => 'Annullata',
    ];
    return $labels[$status] ?? $status;
}

function getNotificationCounts(): array {
    try {
        $db = getDB();
        $counts = [
            'pending_parts'    => (int)$db->query("SELECT COUNT(*) FROM spare_parts_requests WHERE status='pending'")->fetchColumn(),
            'open_tickets'     => (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status='open'")->fetchColumn(),
            'da_spedire'       => (int)$db->query("SELECT COUNT(*) FROM spedizioni WHERE status='da_spedire'")->fetchColumn(),
            'periferiche_wait' => (int)$db->query("SELECT COUNT(*) FROM periferiche_guaste WHERE stato IN ('in_giacenza','in_diagnosi')")->fetchColumn(),
        ];
        return $counts;
    } catch (Exception $e) {
        return ['pending_parts' => 0, 'open_tickets' => 0, 'da_spedire' => 0, 'periferiche_wait' => 0];
    }
}

function getAutoAssignee(): ?int {
    try {
        $db = getDB();
        // Round-robin: pick the technician with fewest open tickets
        $stmt = $db->query("
            SELECT u.id, COUNT(t.id) AS open_count
            FROM users u
            LEFT JOIN tickets t ON t.assigned_to = u.id AND t.status NOT IN ('resolved','closed')
            WHERE u.role IN ('admin','technician') AND u.active = 1
            GROUP BY u.id
            ORDER BY open_count ASC, u.id ASC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function getComponenteTipoBadge(string $tipo): string {
    $badges = [
        'periferica' => '<span class="badge bg-primary">Periferica</span>',
        'accessorio' => '<span class="badge bg-info text-dark">Accessorio</span>',
        'cavo'       => '<span class="badge bg-secondary">Cavo</span>',
    ];
    return $badges[$tipo] ?? '<span class="badge bg-light text-dark">' . htmlspecialchars($tipo) . '</span>';
}

function getComponenteTipoLabel(string $tipo): string {
    $labels = [
        'periferica' => 'Periferica',
        'accessorio' => 'Accessorio',
        'cavo'       => 'Cavo',
    ];
    return $labels[$tipo] ?? $tipo;
}

function getTipoInterventoBadge(string $tipo): string {
    $badges = [
        'onsite'              => '<span class="badge bg-info text-dark"><i class="bi bi-house-door me-1"></i>Onsite</span>',
        'onsite_sostituzione' => '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i>Onsite + Sostituzione</span>',
        'solo_spedizione'     => '<span class="badge bg-primary"><i class="bi bi-truck me-1"></i>Solo Spedizione</span>',
    ];
    return $badges[$tipo] ?? '<span class="badge bg-secondary">' . htmlspecialchars($tipo) . '</span>';
}

function getTipoInterventoLabel(string $tipo): string {
    $labels = [
        'onsite'              => 'Onsite',
        'onsite_sostituzione' => 'Onsite + Sostituzione',
        'solo_spedizione'     => 'Solo Spedizione',
    ];
    return $labels[$tipo] ?? $tipo;
}

// ─── Notifications ────────────────────────────────────────────────────────────

function createNotification(int $userId, string $type, string $title, string $message = '', string $entityType = '', int $entityId = 0, string $url = ''): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO notifications (user_id, type, title, message, entity_type, entity_id, url) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([
            $userId,
            $type,
            $title,
            $message,
            $entityType ?: null,
            $entityId ?: null,
            $url ?: null,
        ]);
    } catch (Exception $e) {
        // Fail silently — notifications must not break main flow
    }
}

function getUnreadNotificationCount(int $userId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function notifyAdmins(string $type, string $title, string $message = '', string $entityType = '', int $entityId = 0, string $url = '', int $excludeUserId = 0): void {
    try {
        $db = getDB();
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll();
        foreach ($admins as $admin) {
            if ($admin['id'] == $excludeUserId) continue;
            createNotification((int)$admin['id'], $type, $title, $message, $entityType, $entityId, $url);
        }
    } catch (Exception $e) {
        // Fail silently
    }
}
