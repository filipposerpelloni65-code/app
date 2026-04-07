<?php
/**
 * Migrazione: Modulo Periferiche Guaste
 * Eseguire una sola volta su installazioni esistenti (prima versione dell'app).
 * Le nuove installazioni non necessitano di questo script.
 */

$appRoot = dirname(__DIR__);
$configFile = $appRoot . '/config.ini';

if (!file_exists($configFile)) {
    die("config.ini non trovato. Eseguire prima l'installazione.\n");
}

$config = parse_ini_file($configFile, true);
$host = $config['database']['host'] ?? 'localhost';
$port = $config['database']['port'] ?? '3306';
$name = $config['database']['name'] ?? '';
$user = $config['database']['user'] ?? '';
$pass = $config['database']['pass'] ?? '';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $steps = [];

    // 1. Add periferica_id column to rapportini
    try {
        $pdo->exec("ALTER TABLE rapportini ADD COLUMN periferica_id INT NULL");
        $steps[] = "✓ Colonna rapportini.periferica_id aggiunta.";
    } catch (Exception $e) {
        $steps[] = "- rapportini.periferica_id già presente (skip).";
    }

    // 2. Create periferiche_guaste table
    $pdo->exec("CREATE TABLE IF NOT EXISTS periferiche_guaste (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codice VARCHAR(30) NOT NULL UNIQUE,
        tipo VARCHAR(100) NOT NULL,
        marca VARCHAR(100),
        modello VARCHAR(100),
        seriale VARCHAR(100),
        descrizione_guasto TEXT,
        dealer_id INT NULL,
        location_id INT NULL,
        ticket_id INT NULL,
        tecnico_ritiro_id INT NULL,
        data_ritiro DATE NOT NULL,
        stato ENUM('in_giacenza','in_diagnosi','in_riparazione','riparata','non_riparabile','restituita','rottamata') NOT NULL DEFAULT 'in_giacenza',
        note_diagnosi TEXT,
        note_interne TEXT,
        rapportino_id INT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tecnico_ritiro_id) REFERENCES users(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
    $steps[] = "✓ Tabella periferiche_guaste creata (o già esistente).";

    // 3. Insert module entry
    $pdo->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order)
        VALUES ('Periferiche','periferiche','Gestione periferiche guaste e flusso riparazione','1.0.0',1,'bi-hdd-network',5)");
    // Update sort_order of existing modules to make room
    $pdo->exec("UPDATE modules SET sort_order=6 WHERE slug='users' AND sort_order=5");
    $pdo->exec("UPDATE modules SET sort_order=7 WHERE slug='reports' AND sort_order=6");
    $pdo->exec("UPDATE modules SET sort_order=8 WHERE slug='settings' AND sort_order=7");
    $steps[] = "✓ Modulo 'periferiche' registrato.";

    echo "Migrazione completata:\n";
    foreach ($steps as $s) {
        echo "  $s\n";
    }

} catch (Exception $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
    exit(1);
}
