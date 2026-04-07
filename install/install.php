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
    $adminUser = $_SESSION['admin_username'] ?? 'admin';
    $adminEmail = $_SESSION['admin_email'] ?? '';
    $adminPass = $_SESSION['admin_password'] ?? '';
    $companyName = $_SESSION['app_name'] ?? 'HelpDesk Aziendale';

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
        "CREATE TABLE IF NOT EXISTS activity_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(100) NOT NULL, entity_type VARCHAR(50), entity_id INT, details TEXT, ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS dealers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, code VARCHAR(50) NOT NULL UNIQUE, email VARCHAR(100), phone VARCHAR(50), address VARCHAR(255), city VARCHAR(100), region VARCHAR(100), active TINYINT(1) NOT NULL DEFAULT 1, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS dealer_locations (id INT AUTO_INCREMENT PRIMARY KEY, dealer_id INT NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(50), address VARCHAR(255), city VARCHAR(100), phone VARCHAR(50), email VARCHAR(100), contact_person VARCHAR(100), active TINYINT(1) NOT NULL DEFAULT 1, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE)",
        "CREATE TABLE IF NOT EXISTS dealer_users (dealer_id INT NOT NULL, location_id INT, user_id INT NOT NULL, PRIMARY KEY (dealer_id, user_id), FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "CREATE TABLE IF NOT EXISTS rapportini (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, work_description TEXT NOT NULL, parts_used TEXT, notes TEXT, intervention_date DATE NOT NULL, technician_id INT NOT NULL, ticket_id INT NULL, dealer_id INT NULL, location_id INT NULL, periferica_id INT NULL, customer_name VARCHAR(100), customer_contact VARCHAR(100), status ENUM('draft','signed','archived') NOT NULL DEFAULT 'draft', signature_data MEDIUMTEXT, signed_by_name VARCHAR(100), signed_at TIMESTAMP NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (technician_id) REFERENCES users(id), FOREIGN KEY (created_by) REFERENCES users(id))",
        "CREATE TABLE IF NOT EXISTS periferiche_guaste (id INT AUTO_INCREMENT PRIMARY KEY, codice VARCHAR(30) NOT NULL UNIQUE, tipo VARCHAR(100) NOT NULL, marca VARCHAR(100), modello VARCHAR(100), seriale VARCHAR(100), descrizione_guasto TEXT, dealer_id INT NULL, location_id INT NULL, ticket_id INT NULL, tecnico_ritiro_id INT NULL, data_ritiro DATE NOT NULL, stato ENUM('in_giacenza','in_diagnosi','in_riparazione','riparata','non_riparabile','restituita','rottamata') NOT NULL DEFAULT 'in_giacenza', note_diagnosi TEXT, note_interne TEXT, rapportino_id INT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (tecnico_ritiro_id) REFERENCES users(id), FOREIGN KEY (created_by) REFERENCES users(id))",
        "CREATE TABLE IF NOT EXISTS spedizioni (id INT AUTO_INCREMENT PRIMARY KEY, tracking_number VARCHAR(100) NULL, corriere VARCHAR(100) NULL, status ENUM('da_spedire','spedita','consegnata','annullata') NOT NULL DEFAULT 'da_spedire', ticket_id INT NULL, spare_parts_request_id INT NULL, dealer_id INT NULL, location_id INT NULL, data_spedizione DATE NULL, data_prevista_consegna DATE NULL, note TEXT NULL, created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL, FOREIGN KEY (spare_parts_request_id) REFERENCES spare_parts_requests(id) ON DELETE SET NULL, FOREIGN KEY (created_by) REFERENCES users(id))"
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // Add dealer columns to tickets (safe for existing installs)
    foreach ([
        "ALTER TABLE tickets ADD COLUMN dealer_id INT NULL",
        "ALTER TABLE tickets ADD COLUMN location_id INT NULL",
        "ALTER TABLE tickets ADD COLUMN codice_concessionario VARCHAR(50) NULL",
        "ALTER TABLE spare_parts_requests ADD COLUMN dealer_id INT NULL",
        "ALTER TABLE spare_parts_requests ADD COLUMN location_id INT NULL",
        "ALTER TABLE periferiche_guaste ADD COLUMN seriale_nuovo VARCHAR(100) NULL",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (Exception $e) { /* column already exists */ }
    }

    // ticket_uscite table (multiple technician visits per ticket)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_uscite (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        tecnico_id INT NOT NULL,
        data_uscita DATE NOT NULL,
        note TEXT,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (tecnico_id) REFERENCES users(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    $pdo->exec("INSERT IGNORE INTO modules (name, slug, description, version, enabled, icon, sort_order) VALUES
        ('Gestione Ticket','tickets','Sistema di gestione ticket','1.0.0',1,'bi-ticket-detailed',1),
        ('Parti di Ricambio','spare_parts','Gestione magazzino parti di ricambio','1.0.0',1,'bi-tools',2),
        ('Concessionari','dealers','Gestione concessionari e punti vendita','1.0.0',1,'bi-shop',3),
        ('Rapportini','rapportini','Rapportini di lavoro con firma digitale e PDF','1.0.0',1,'bi-file-earmark-text',4),
        ('Periferiche','periferiche','Gestione periferiche guaste e flusso riparazione','1.0.0',1,'bi-hdd-network',5),
        ('Spedizioni','spedizioni','Gestione spedizioni collegate a ticket e richieste ricambi','1.0.0',1,'bi-truck',6),
        ('Utenti','users','Gestione utenti del sistema','1.0.0',1,'bi-people',7),
        ('Report','reports','Report e statistiche','1.0.0',1,'bi-bar-chart',8),
        ('Impostazioni','settings','Configurazione sistema','1.0.0',1,'bi-gear',9)");

    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('company_name','" . addslashes($companyName) . "'),
        ('ticket_prefix','TKT'),('items_per_page','25'),
        ('email_notifications','0'),('smtp_host',''),
        ('smtp_port','587'),('smtp_user',''),('smtp_pass',''),
        ('auto_close_days','7'),('auto_close_secret','')");

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
