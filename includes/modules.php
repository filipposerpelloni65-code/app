<?php
require_once __DIR__ . '/db.php';

function isModuleEnabled(string $slug): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT enabled FROM modules WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row && (int)$row['enabled'] === 1;
    } catch (Exception $e) {
        return false;
    }
}

function getEnabledModules(): array {
    try {
        $db = getDB();
        $stmt = $db->query('SELECT * FROM modules WHERE enabled = 1 ORDER BY sort_order');
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getAllModules(): array {
    try {
        $db = getDB();
        $stmt = $db->query('SELECT * FROM modules ORDER BY sort_order');
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getModuleUrl(string $slug): string {
    $urls = [
        'tickets' => APP_URL . '/modules/tickets/index.php',
        'spare_parts' => APP_URL . '/modules/spare_parts/index.php',
        'dealers' => APP_URL . '/modules/dealers/index.php',
        'users' => APP_URL . '/modules/users/index.php',
        'reports' => APP_URL . '/modules/reports/index.php',
        'settings' => APP_URL . '/modules/settings/index.php',
        'rapportini' => APP_URL . '/modules/rapportini/index.php',
        'periferiche' => APP_URL . '/modules/periferiche/index.php',
        'componenti' => APP_URL . '/modules/componenti/index.php',
    ];
    return $urls[$slug] ?? '#';
}
