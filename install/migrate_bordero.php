<?php
/**
 * Migrazione: Workflow Bordero BRT
 * - Aggiunge stato 'bozza' alla tabella spedizioni
 * - Aggiunge colonne per dati BRT differiti e multi-etichette
 * - Crea tabella borderi per l'archivio dei bordero
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

// 1. Modify status ENUM to include 'bozza'
try {
    $colInfo = $db->query("SHOW COLUMNS FROM spedizioni LIKE 'status'")->fetch();
    if ($colInfo && strpos($colInfo['Type'], 'bozza') === false) {
        $db->exec("ALTER TABLE spedizioni MODIFY COLUMN status ENUM('bozza','da_spedire','spedita','consegnata','annullata') NOT NULL DEFAULT 'bozza'");
        $messages[] = "✅ Colonna <code>status</code> aggiornata con il valore <code>bozza</code>.";
    } else {
        $messages[] = "ℹ️ Colonna <code>status</code> già aggiornata — saltata.";
    }
} catch (Exception $e) {
    $errors[] = "Errore modifica colonna status: " . $e->getMessage();
}

// 2. Add new columns to spedizioni
$newColumns = [
    'brt_consignee_json' => "ALTER TABLE spedizioni ADD COLUMN brt_consignee_json TEXT NULL COMMENT 'Dati destinatario BRT (JSON) per trasmissione differita'",
    'num_colli'          => "ALTER TABLE spedizioni ADD COLUMN num_colli INT NOT NULL DEFAULT 1 COMMENT 'Numero di colli'",
    'peso_kg'            => "ALTER TABLE spedizioni ADD COLUMN peso_kg DECIMAL(8,2) NOT NULL DEFAULT 1.00 COMMENT 'Peso totale in kg'",
    'brt_labels_json'    => "ALTER TABLE spedizioni ADD COLUMN brt_labels_json LONGTEXT NULL COMMENT 'JSON array di tutte le etichette BRT (base64 PDF per collo)'",
    'transmitted_at'     => "ALTER TABLE spedizioni ADD COLUMN transmitted_at TIMESTAMP NULL COMMENT 'Data/ora trasmissione a BRT'",
];

foreach ($newColumns as $col => $sql) {
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

// 3. Create borderi table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS borderi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data_bordero DATE NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        note TEXT NULL,
        spedizioni_ids TEXT NOT NULL COMMENT 'JSON array di ID spedizioni incluse',
        shipped_count INT NOT NULL DEFAULT 0,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
    $messages[] = "✅ Tabella <code>borderi</code> creata (o già esistente).";
} catch (Exception $e) {
    $errors[] = "Errore creazione tabella borderi: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Migrazione Bordero BRT</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:680px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Migrazione: Workflow Bordero BRT</h5>
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
                <strong>Migrazione completata!</strong> Il workflow bordero BRT è ora attivo. Le nuove spedizioni vengono create in bozza e trasmesse a BRT dalla pagina "Gestione Bordero".
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-end">
            <a href="<?= defined('APP_URL') ? APP_URL : '..' ?>/modules/spedizioni/index.php" class="btn btn-outline-secondary btn-sm me-2"><i class="bi bi-truck me-1"></i>Spedizioni</a>
            <a href="<?= defined('APP_URL') ? APP_URL : '..' ?>/dashboard.php" class="btn btn-primary btn-sm"><i class="bi bi-house me-1"></i>Dashboard</a>
        </div>
    </div>
</div>
</body>
</html>
