<?php
/**
 * Migration: Add spedizioni (shipping) module
 * Run this script once on existing installations to add the shipping module.
 */
session_start();
$appRoot = dirname(__DIR__);
$configFile = $appRoot . '/config.ini';

if (!file_exists($configFile)) {
    die('config.ini not found. Run the installer first.');
}

$config = parse_ini_file($configFile, true);
$host = $config['database']['host'] ?? 'localhost';
$port = $config['database']['port'] ?? '3306';
$name = $config['database']['name'] ?? '';
$user = $config['database']['user'] ?? '';
$pass = $config['database']['pass'] ?? '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS spedizioni (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NULL,
        request_id INT NULL,
        dealer_id INT NULL,
        location_id INT NULL,
        corriere VARCHAR(100),
        numero_tracking VARCHAR(100),
        status ENUM('da_spedire','spedita','consegnata','annullata') NOT NULL DEFAULT 'da_spedire',
        mittente VARCHAR(255),
        destinatario VARCHAR(255),
        indirizzo_spedizione TEXT,
        note TEXT,
        data_spedizione DATE NULL,
        data_consegna_prevista DATE NULL,
        data_consegna DATE NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id),
        FOREIGN KEY (request_id) REFERENCES spare_parts_requests(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    // Make room at sort_order 6 for the spedizioni module (run before INSERT)
    $pdo->exec("UPDATE modules SET sort_order = sort_order + 1 WHERE slug IN ('users','reports','settings') AND sort_order >= 6");

    $pdo->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order)
        VALUES ('Spedizioni','spedizioni','Gestione spedizioni spare parts e accessori','1.0.0',1,'bi-truck',6)");

    echo '<p style="color:green;font-weight:bold;">✔ Migrazione completata con successo. Il modulo Spedizioni è stato attivato.</p>';
} catch (Exception $e) {
    echo '<p style="color:red;font-weight:bold;">Errore: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>
