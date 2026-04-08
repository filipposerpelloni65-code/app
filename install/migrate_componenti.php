<?php
/**
 * Migration: Componenti (modelli periferiche, accessori, cavi) + ticket_componenti
 * Run this once on existing installations.
 */
session_start();
header('Content-Type: text/html; charset=utf-8');

$appRoot = dirname(__DIR__);
$configFile = $appRoot . '/config.ini';

if (!file_exists($configFile)) {
    die('config.ini non trovato. Esegui prima l\'installazione.');
}

$cfg = parse_ini_file($configFile, true);
$host = $cfg['database']['host'] ?? 'localhost';
$port = $cfg['database']['port'] ?? '3306';
$name = $cfg['database']['name'] ?? '';
$user = $cfg['database']['user'] ?? '';
$pass = $cfg['database']['pass'] ?? '';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $steps = [];

    // 1. Create modelli_componenti table
    $pdo->exec("CREATE TABLE IF NOT EXISTS modelli_componenti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('periferica','accessorio','cavo') NOT NULL,
        nome VARCHAR(255) NOT NULL,
        marca VARCHAR(100),
        descrizione TEXT,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $steps[] = '✅ Tabella <code>modelli_componenti</code> creata (o già esistente).';

    // 2. Create ticket_componenti table
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_componenti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        modello_id INT NOT NULL,
        tipo ENUM('periferica','accessorio','cavo') NOT NULL,
        seriale_nuovo VARCHAR(100) NULL,
        quantita INT NOT NULL DEFAULT 1,
        note TEXT,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (modello_id) REFERENCES modelli_componenti(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
    $steps[] = '✅ Tabella <code>ticket_componenti</code> creata (o già esistente).';

    // 3. Insert module entry
    $pdo->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order) VALUES
        ('Componenti','componenti','Catalogo modelli periferiche, accessori e cavi','1.0.0',1,'bi-cpu',6)");
    // Fix sort order for subsequent modules
    $pdo->exec("UPDATE modules SET sort_order=7 WHERE slug='users'");
    $pdo->exec("UPDATE modules SET sort_order=8 WHERE slug='reports'");
    $pdo->exec("UPDATE modules SET sort_order=9 WHERE slug='settings'");
    $steps[] = '✅ Modulo <strong>Componenti</strong> registrato.';

    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Migrazione Componenti</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head>
    <body class="p-4"><div class="container" style="max-width:600px">
    <h4 class="mb-3"><i class="bi bi-cpu"></i> Migrazione Componenti</h4>
    <div class="list-group mb-4">';
    foreach ($steps as $s) {
        echo '<div class="list-group-item">' . $s . '</div>';
    }
    echo '</div>
    <div class="alert alert-success">Migrazione completata con successo!</div>
    <a href="../dashboard.php" class="btn btn-primary">Vai alla Dashboard</a>
    </div></body></html>';

} catch (Exception $e) {
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Errore</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head>
    <body class="p-4"><div class="container" style="max-width:600px">
    <div class="alert alert-danger"><strong>Errore:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>
    </div></body></html>';
}
