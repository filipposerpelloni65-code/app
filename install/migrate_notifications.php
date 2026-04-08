<?php
/**
 * Migration: create notifications table and register notifications module
 * Run once after initial install to add notification support.
 */

$appRoot = dirname(__DIR__);
$installed = $appRoot . '/.installed';
if (!file_exists($installed)) {
    die('App not installed. Run the installer first.');
}

require_once $appRoot . '/includes/config.php';
require_once $appRoot . '/includes/db.php';

$pdo = getDB();

$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    url VARCHAR(500) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created (created_at)
)");

$pdo->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order) VALUES
    ('Notifiche','notifications','Centro notifiche in tempo reale','1.0.0',1,'bi-bell',9)");

echo "Migration completed successfully.\n";
