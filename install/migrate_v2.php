<?php
/**
 * Migration v2 — adds due_date to tickets and work-time fields to rapportini.
 * Run once via browser or CLI after updating the application files.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

requireRole('admin');

$db = getDB();
$results = [];

$migrations = [
    // Tickets
    "ALTER TABLE tickets ADD COLUMN due_date DATE NULL AFTER priority",
    // Rapportini
    "ALTER TABLE rapportini ADD COLUMN tipo_intervento VARCHAR(50) NULL AFTER notes",
    "ALTER TABLE rapportini ADD COLUMN ora_inizio TIME NULL AFTER tipo_intervento",
    "ALTER TABLE rapportini ADD COLUMN ora_fine TIME NULL AFTER ora_inizio",
    "ALTER TABLE rapportini ADD COLUMN ore_lavorate DECIMAL(5,2) NULL AFTER ora_fine",
    // Ticket attachments columns (filesize, mimetype for richer metadata)
    "ALTER TABLE ticket_attachments ADD COLUMN filesize INT UNSIGNED NULL",
    "ALTER TABLE ticket_attachments ADD COLUMN mimetype VARCHAR(100) NULL",
];

foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => $sql, 'status' => 'OK'];
    } catch (PDOException $e) {
        // 1060 = Duplicate column name — already migrated
        $results[] = ['sql' => $sql, 'status' => strpos($e->getMessage(), '1060') !== false ? 'Already applied' : 'ERROR: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Migration v2</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<h3>Migration v2 Results</h3>
<table class="table table-bordered table-sm">
<thead class="table-light"><tr><th>SQL</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($results as $r): ?>
<tr class="<?= str_starts_with($r['status'], 'ERROR') ? 'table-danger' : ($r['status'] === 'OK' ? 'table-success' : 'table-warning') ?>">
    <td><code><?= htmlspecialchars($r['sql']) ?></code></td>
    <td><?= htmlspecialchars($r['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<a href="<?= APP_URL ?>/dashboard.php" class="btn btn-primary">Torna alla Dashboard</a>
</body>
</html>
