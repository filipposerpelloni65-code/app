<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'install') {
    echo json_encode(['success' => false, 'error' => 'Richiesta non valida']);
    exit;
}

$appRoot = dirname(__DIR__);

try {
    $host = $_SESSION['db_host'] ?? 'localhost';
    $port = $_SESSION['db_port'] ?? '3306';
    $name = $_SESSION['db_name'] ?? '';
    $user = $_SESSION['db_user'] ?? '';
    $pass = $_SESSION['db_pass'] ?? '';
    $adminUser = $_SESSION['admin_user'] ?? 'admin';
    $adminEmail = $_SESSION['admin_email'] ?? '';
    $adminPass = $_SESSION['admin_pass'] ?? '';
    $companyName = $_SESSION['company_name'] ?? 'HelpDesk Aziendale';

    if (!$name || !$user) throw new Exception('Credenziali DB mancanti dalla sessione');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $tables = [
        "CREATE TABLE IF NOT EXISTS settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, email VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role ENUM('admin','technician','user') NOT NULL DEFAULT 'user', full_name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, active TINYINT(1) NOT NULL DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS ticket_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT, module_id INT)",
        "CREATE TABLE IF NOT EXISTS tickets (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT NOT NULL, status ENUM('open','in_progress','waiting','resolved','closed') NOT NULL DEFAULT 'open', priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium', category_id INT, created_by INT NOT NULL, assigned_to INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, closed_at TIMESTAMP NULL, FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (assigned_to) REFERENCES users(id), FOREIGN KEY (category_id) REFERENCES ticket_categories(id))",
        "CREATE TABLE IF NOT EXISTS ticket_comments (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, user_id INT NOT NULL, message TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_internal TINYINT(1) NOT NULL DEFAULT 0, FOREIGN KEY (ticket_id) REFERENCES tickets(id), FOREIGN KEY (user_id) REFERENCES users(id))",
        "CREATE TABLE IF NOT EXISTS ticket_attachments (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, filename VARCHAR(255) NOT NULL, filepath VARCHAR(500) NOT NULL, uploaded_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES tickets(id), FOREIGN KEY (uploaded_by) REFERENCES users(id))",
        "CREATE TABLE IF NOT EXISTS spare_parts_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description TEXT)",
        "CREATE TABLE IF NOT EXISTS spare_parts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, sku VARCHAR(100), description TEXT, category_id INT, quantity INT NOT NULL DEFAULT 0, min_quantity INT NOT NULL DEFAULT 0, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, location VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (category_id) REFERENCES spare_parts_categories(id))",
        "CREATE TABLE IF NOT EXISTS spare_parts_requests (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT, part_id INT NOT NULL, requested_by INT NOT NULL, quantity INT NOT NULL DEFAULT 1, status ENUM('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending', notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES tickets(id), FOREIGN KEY (part_id) REFERENCES spare_parts(id), FOREIGN KEY (requested_by) REFERENCES users(id))",
        "CREATE TABLE IF NOT EXISTS modules (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE, description TEXT, version VARCHAR(20) DEFAULT '1.0.0', enabled TINYINT(1) NOT NULL DEFAULT 1, icon VARCHAR(100), sort_order INT DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS activity_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(100) NOT NULL, entity_type VARCHAR(50), entity_id INT, details TEXT, ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order) VALUES
        ('Gestione Ticket','tickets','Sistema di gestione ticket','1.0.0',1,'bi-ticket-detailed',1),
        ('Parti di Ricambio','spare_parts','Gestione magazzino parti di ricambio','1.0.0',1,'bi-tools',2),
        ('Utenti','users','Gestione utenti del sistema','1.0.0',1,'bi-people',3),
        ('Report','reports','Report e statistiche','1.0.0',1,'bi-bar-chart',4),
        ('Impostazioni','settings','Configurazione sistema','1.0.0',1,'bi-gear',5)");

    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('company_name','" . addslashes($companyName) . "'),
        ('ticket_prefix','TKT'),('items_per_page','25'),
        ('email_notifications','0'),('smtp_host',''),
        ('smtp_port','587'),('smtp_user',''),('smtp_pass','')");

    $pdo->exec("INSERT IGNORE INTO ticket_categories (name, description) VALUES
        ('Hardware','Problemi hardware'),('Software','Problemi software'),
        ('Rete','Problemi di rete'),('Altro','Altro')");

    $pdo->exec("INSERT IGNORE INTO spare_parts_categories (name, description) VALUES
        ('Componenti PC','Componenti per computer'),('Stampanti','Parti per stampanti'),
        ('Reti','Cavi e apparati di rete'),('Altro','Materiale vario')");

    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, email, password_hash, role, full_name) VALUES (?,?,?,'admin',?)");
    $stmt->execute([$adminUser, $adminEmail, $hash, 'Amministratore']);

    $ini = "[database]\nhost = {$host}\nport = {$port}\nname = {$name}\nuser = {$user}\npass = {$pass}\n\n[app]\nname = " . $companyName . "\n";
    file_put_contents($appRoot . '/config.ini', $ini);
    file_put_contents($appRoot . '/.installed', date('Y-m-d H:i:s'));

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
