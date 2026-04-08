<?php
/**
 * Migrazione: aggiunge la tabella spedizioni e il modulo spedizioni
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
$errors = [];
$messages = [];

// 1. Create spedizioni table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS spedizioni (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tracking_number VARCHAR(100) NULL,
        corriere VARCHAR(100) NULL,
        status ENUM('da_spedire','spedita','consegnata','annullata') NOT NULL DEFAULT 'da_spedire',
        ticket_id INT NULL,
        spare_parts_request_id INT NULL,
        dealer_id INT NULL,
        location_id INT NULL,
        note TEXT NULL,
        data_spedizione DATE NULL,
        data_consegna_prevista DATE NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
        FOREIGN KEY (spare_parts_request_id) REFERENCES spare_parts_requests(id) ON DELETE SET NULL,
        FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
    $messages[] = '✅ Tabella <code>spedizioni</code> creata (o già esistente).';
} catch (Exception $e) {
    $errors[] = 'Errore creazione tabella spedizioni: ' . $e->getMessage();
}

// 2. Insert spedizioni module
try {
    $db->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order) VALUES ('Spedizioni','spedizioni','Gestione spedizioni ricambi e materiali','1.0.0',1,'bi-truck',6)");
    // Shift sort_order of subsequent modules
    $db->exec("UPDATE modules SET sort_order = sort_order + 1 WHERE slug IN ('users','reports','settings')");
    $messages[] = '✅ Modulo <strong>Spedizioni</strong> aggiunto.';
} catch (Exception $e) {
    $errors[] = 'Errore inserimento modulo: ' . $e->getMessage();
}

// 3. Add auto_close settings
try {
    $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_close_days','0'),('auto_close_secret','')");
    $messages[] = '✅ Impostazioni auto-chiusura ticket aggiunte.';
} catch (Exception $e) {
    $errors[] = 'Errore inserimento settings: ' . $e->getMessage();
}

// 4. Add auto_assign setting
try {
    $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('auto_assign','0')");
    $messages[] = '✅ Impostazione auto-assegnazione aggiunta.';
} catch (Exception $e) {
    $errors[] = 'Errore inserimento setting auto_assign: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Migrazione Spedizioni</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Migrazione: Modulo Spedizioni</h5>
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
            <div class="alert alert-success mt-3 mb-0"><strong>Migrazione completata!</strong> Puoi ora accedere al modulo Spedizioni dall'applicazione.</div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-end">
            <a href="<?= defined('APP_URL') ? APP_URL : '..' ?>/dashboard.php" class="btn btn-primary btn-sm"><i class="bi bi-house me-1"></i>Torna alla Dashboard</a>
        </div>
    </div>
</div>
</body>
</html>
