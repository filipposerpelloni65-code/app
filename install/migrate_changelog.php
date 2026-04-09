<?php
/**
 * install/migrate_changelog.php
 * Creates the ticket_changelog table for full audit trail.
 * Safe to run on existing installs.
 */

$appRoot = dirname(__DIR__);
if (!file_exists($appRoot . '/includes/config.php')) {
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}
require_once $appRoot . '/includes/config.php';
require_once $appRoot . '/includes/db.php';

header('Content-Type: application/json');

try {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS ticket_changelog (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id   INT NOT NULL,
        user_id     INT NULL,
        action      VARCHAR(50) NOT NULL,
        field       VARCHAR(100) NULL,
        old_value   TEXT NULL,
        new_value   TEXT NULL,
        note        TEXT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE SET NULL,
        INDEX idx_changelog_ticket (ticket_id),
        INDEX idx_changelog_created (created_at)
    )");

    echo json_encode(['success' => true, 'message' => 'Migrazione changelog completata.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
