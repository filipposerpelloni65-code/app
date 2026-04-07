<?php
/**
 * Migration: Aggiungi modulo Rapportini
 *
 * Eseguire questo script una sola volta sulle installazioni esistenti
 * per aggiungere la tabella rapportini e la voce nel menu moduli.
 *
 * Accesso: solo admin, da browser o CLI.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();
if (!currentUser() || currentUser()['role'] !== 'admin') {
    http_response_code(403);
    die('Accesso negato. Effettuare il login come amministratore.');
}

$db  = getDB();
$log = [];

// 1. Create rapportini table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS rapportini (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            title            VARCHAR(255) NOT NULL,
            work_description TEXT NOT NULL,
            parts_used       TEXT,
            notes            TEXT,
            intervention_date DATE NOT NULL,
            technician_id    INT NOT NULL,
            ticket_id        INT NULL,
            dealer_id        INT NULL,
            location_id      INT NULL,
            customer_name    VARCHAR(100),
            customer_contact VARCHAR(100),
            status           ENUM('draft','signed','archived') NOT NULL DEFAULT 'draft',
            signature_data   MEDIUMTEXT,
            signed_by_name   VARCHAR(100),
            signed_at        TIMESTAMP NULL,
            created_by       INT NOT NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (technician_id) REFERENCES users(id),
            FOREIGN KEY (created_by)   REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = ['ok', 'Tabella <code>rapportini</code> creata (o già esistente).'];
} catch (Exception $e) {
    $log[] = ['err', 'Errore creazione tabella rapportini: ' . htmlspecialchars($e->getMessage())];
}

// 2. Insert module entry (INSERT IGNORE is safe to run multiple times)
try {
    $db->exec("
        INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order)
        VALUES ('Rapportini','rapportini','Rapportini di lavoro con firma digitale e PDF','1.0.0',1,'bi-file-earmark-text',4)
    ");
    // Shift sort_order for modules displaced by the new rapportini entry (sort_order 4)
    $db->exec("UPDATE modules SET sort_order = sort_order + 1 WHERE slug IN ('users','reports','settings') AND sort_order >= 4");
    $log[] = ['ok', 'Modulo <code>rapportini</code> inserito nel database.'];
} catch (Exception $e) {
    $log[] = ['err', 'Errore inserimento modulo: ' . htmlspecialchars($e->getMessage())];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Migrazione Rapportini</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <h4 class="mb-4"><i class="bi bi-database-add me-2"></i>Migrazione: Modulo Rapportini</h4>
    <?php foreach ($log as [$type, $msg]): ?>
    <div class="alert alert-<?= $type === 'ok' ? 'success' : 'danger' ?>">
        <i class="bi bi-<?= $type === 'ok' ? 'check-circle' : 'x-circle' ?> me-2"></i><?= $msg ?>
    </div>
    <?php endforeach; ?>
    <a href="../dashboard.php" class="btn btn-primary mt-2">Torna alla Dashboard</a>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</body>
</html>
