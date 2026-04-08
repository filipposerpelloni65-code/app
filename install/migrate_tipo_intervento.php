<?php
/**
 * Migration: Add tipo_intervento column to tickets table.
 * Run this script once on existing installations.
 */
$appRoot = dirname(__DIR__);

if (!file_exists($appRoot . '/.installed')) {
    die('App non installata. Esegui prima l\'installazione.');
}

$ini = parse_ini_file($appRoot . '/config.ini', true);
$host = $ini['database']['host'] ?? 'localhost';
$port = $ini['database']['port'] ?? '3306';
$name = $ini['database']['name'] ?? '';
$user = $ini['database']['user'] ?? '';
$pass = $ini['database']['pass'] ?? '';

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Add tipo_intervento column (safe for re-run)
    try {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN tipo_intervento ENUM('onsite','onsite_sostituzione','solo_spedizione') NOT NULL DEFAULT 'onsite'");
        echo "✓ Colonna tipo_intervento aggiunta alla tabella tickets.<br>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "ℹ Colonna tipo_intervento già presente, nessuna modifica.<br>";
        } else {
            throw $e;
        }
    }

    echo "<strong>Migrazione completata con successo.</strong>";
} catch (Exception $e) {
    echo "<strong style='color:red'>Errore migrazione:</strong> " . htmlspecialchars($e->getMessage());
}
