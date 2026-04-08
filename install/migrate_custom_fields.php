<?php
/**
 * Migration: Campi Personalizzati (Custom Fields per Ticket)
 * Run once to create the ticket_custom_fields table.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS ticket_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_label VARCHAR(255) NOT NULL,
    field_name  VARCHAR(100) NOT NULL UNIQUE,
    field_type  ENUM('text','number','date','select','checkbox','textarea') NOT NULL DEFAULT 'text',
    field_options TEXT NULL COMMENT 'JSON array for select options',
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

echo "Migration completed: ticket_custom_fields table created.\n";
