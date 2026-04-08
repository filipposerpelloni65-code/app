<?php
/**
 * Migration: Settings v2 — add all new customization defaults
 * Run once after upgrading. Safe to run multiple times (INSERT IGNORE).
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

requireRole('admin');

$db = getDB();
$inserted = 0;

$defaults = [
    // Appearance
    'theme_primary_color'  => '#3b82f6',
    'theme_sidebar_top'    => '#0f172a',
    'theme_sidebar_bottom' => '#1a1f35',
    'theme_bg_color'       => '#f1f5f9',
    'theme_radius'         => 'md',
    'theme_font_size'      => 'default',
    'company_logo_url'     => '',
    // Company info (for PDF / rapportini)
    'company_address'      => '',
    'company_city'         => '',
    'company_vat'          => '',
    'company_phone'        => '',
    'company_email'        => '',
    'pdf_footer_text'      => '',
    // Ticket settings
    'ticket_default_priority' => 'medium',
    'ticket_max_file_mb'      => '10',
    'ticket_allowed_ext'      => 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip',
    'ticket_tech_create'      => '1',
    // Security
    'session_timeout_mins' => '0',
    'min_password_length'  => '8',
    'max_login_attempts'   => '0',
    'login_lockout_mins'   => '15',
    // Locale
    'app_timezone'         => 'Europe/Rome',
    'date_format'          => 'd/m/Y H:i',
    'currency_symbol'      => '€',
    // Notification triggers
    'notif_new_ticket'      => '1',
    'notif_ticket_assign'   => '1',
    'notif_ticket_comment'  => '1',
    'notif_ticket_resolved' => '1',
];

$stmt = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)");
foreach ($defaults as $key => $value) {
    $stmt->execute([$key, $value]);
    $inserted += $stmt->rowCount();
}

echo '<pre>Migrazione Settings v2 completata. Righe inserite: ' . $inserted . '</pre>';
echo '<a href="' . APP_URL . '/modules/settings/index.php">Vai alle Impostazioni</a>';
