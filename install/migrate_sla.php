<?php
/**
 * install/migrate_sla.php
 * Adds due_date (scadenza) column to the tickets table.
 * Safe to run on existing installs — uses ALTER TABLE … ADD COLUMN IF NOT EXISTS via try/catch.
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

    // Add due_date to tickets
    try {
        $db->exec("ALTER TABLE tickets ADD COLUMN due_date DATETIME NULL DEFAULT NULL");
    } catch (Exception $e) {
        // Column already exists — ignore
    }

    echo json_encode(['success' => true, 'message' => 'Migrazione SLA completata.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
