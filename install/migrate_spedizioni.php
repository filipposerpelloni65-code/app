<?php
/**
 * Migration: Spedizioni module
 * Creates the spedizioni table and registers the module.
 *
 * Run this script once after upgrading an existing installation.
 * New installations: this migration is included in install.php.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$db = getDB();

// Create spedizioni table
$db->exec("CREATE TABLE IF NOT EXISTS spedizioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number VARCHAR(100) NULL,
    corriere VARCHAR(100) NULL,
    status ENUM('da_spedire','spedita','consegnata','annullata') NOT NULL DEFAULT 'da_spedire',
    ticket_id INT NULL,
    spare_parts_request_id INT NULL,
    dealer_id INT NULL,
    location_id INT NULL,
    data_spedizione DATE NULL,
    data_prevista_consegna DATE NULL,
    note TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    FOREIGN KEY (spare_parts_request_id) REFERENCES spare_parts_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

// Register module (ignore if already exists)
$db->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order) VALUES
    ('Spedizioni', 'spedizioni', 'Gestione spedizioni collegate a ticket e richieste ricambi', '1.0.0', 1, 'bi-truck', 9)");

// Add auto-close settings if not present
$db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('auto_close_days','7'),
    ('auto_close_secret','')");

echo "Migrazione spedizioni completata.\n";
