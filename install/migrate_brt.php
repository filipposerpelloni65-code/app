<?php
/**
 * Migrazione: integrazione BRT REST API
 * Aggiunge colonne BRT alla tabella spedizioni e le impostazioni necessarie.
 * Eseguire solo una volta su installazioni già esistenti.
 */
session_start();
if (!file_exists(dirname(__DIR__) . '/.installed')) {
    http_response_code(403);
    echo 'Applicazione non installata.';
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$db = getDB();
$errors   = [];
$messages = [];

// 1. Add BRT columns to spedizioni
$columns = [
    'brt_parcel_id'    => "ALTER TABLE spedizioni ADD COLUMN brt_parcel_id VARCHAR(18) NULL COMMENT 'BRT parcelID (per tracking)'",
    'brt_numeric_ref'  => "ALTER TABLE spedizioni ADD COLUMN brt_numeric_ref INT NULL COMMENT 'numericSenderReference BRT (per cancellazione)'",
    'brt_label_stream' => "ALTER TABLE spedizioni ADD COLUMN brt_label_stream LONGTEXT NULL COMMENT 'Etichetta BRT base64 PDF'",
];

foreach ($columns as $col => $sql) {
    // Check if column already exists
    $exists = $db->query("SHOW COLUMNS FROM spedizioni LIKE '$col'")->fetch();
    if ($exists) {
        $messages[] = "ℹ️ Colonna <code>$col</code> già presente — saltata.";
        continue;
    }
    try {
        $db->exec($sql);
        $messages[] = "✅ Colonna <code>$col</code> aggiunta alla tabella <code>spedizioni</code>.";
    } catch (Exception $e) {
        $errors[] = "Errore aggiunta colonna $col: " . $e->getMessage();
    }
}

// 2. Insert BRT settings (with defaults)
$settings = [
    'brt_user_id'             => '',
    'brt_password'            => '',
    'brt_departure_depot'     => '0',
    'brt_sender_customer_code'=> '0',
    'brt_freight_type'        => 'DAP',
];

try {
    $stmt = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)");
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $messages[] = '✅ Impostazioni BRT aggiunte (se non già presenti).';
} catch (Exception $e) {
    $errors[] = 'Errore inserimento settings BRT: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Migrazione BRT API</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Migrazione: Integrazione BRT API</h5>
        </div>
        <div class="card-body">
            <?php if ($errors): ?>
            <div class="alert alert-danger">
                <strong>Errori:</strong><ul class="mb-0">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php foreach ($messages as $m): ?>
            <p class="mb-1"><?= $m ?></p>
            <?php endforeach; ?>
            <?php if (!$errors): ?>
            <div class="alert alert-success mt-3 mb-0">
                <strong>Migrazione completata!</strong> Configura le credenziali BRT in <em>Impostazioni → BRT API</em>.
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-end">
            <a href="<?= defined('APP_URL') ? APP_URL : '..' ?>/modules/settings/index.php" class="btn btn-outline-secondary btn-sm me-2"><i class="bi bi-gear me-1"></i>Impostazioni</a>
            <a href="<?= defined('APP_URL') ? APP_URL : '..' ?>/dashboard.php" class="btn btn-primary btn-sm"><i class="bi bi-house me-1"></i>Dashboard</a>
        </div>
    </div>
</div>
</body>
</html>
