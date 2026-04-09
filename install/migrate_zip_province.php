<?php
/**
 * Migrazione: aggiunge i campi zip (CAP) e province (provincia) a dealers e dealer_locations.
 * Necessari per il pre-fill automatico dei dati BRT destinatario dal punto vendita.
 */
session_start();
$appRoot = dirname(__DIR__);
require_once $appRoot . '/includes/config.php';
require_once $appRoot . '/includes/db.php';
require_once $appRoot . '/includes/auth.php';

requireRole('admin');

$db  = getDB();
$ok  = [];
$err = [];

$alters = [
    "ALTER TABLE dealers ADD COLUMN zip VARCHAR(10) NULL AFTER city",
    "ALTER TABLE dealers ADD COLUMN province VARCHAR(2) NULL AFTER zip",
    "ALTER TABLE dealer_locations ADD COLUMN zip VARCHAR(10) NULL AFTER city",
    "ALTER TABLE dealer_locations ADD COLUMN province VARCHAR(2) NULL AFTER zip",
];

foreach ($alters as $sql) {
    try {
        $db->exec($sql);
        $ok[] = $sql;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), '1060') || str_contains($e->getMessage(), 'Duplicate column')) {
            $ok[] = $sql . ' [già presente, skip]';
        } else {
            $err[] = $sql . ' → ' . $e->getMessage();
        }
    }
}

define('PAGE_TITLE', 'Migrazione ZIP/Provincia');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Migrazione ZIP/Provincia' => '']);
include APP_ROOT . '/includes/header.php';
?>
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-database-gear me-2 text-primary"></i>Migrazione: CAP e Provincia</h5></div>
    <div class="card-body">
        <?php if (!$err): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Migrazione completata con successo!</div>
        <?php else: ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Si sono verificati degli errori.</div>
        <?php endif; ?>

        <h6 class="mt-3">Operazioni eseguite:</h6>
        <ul class="list-group">
        <?php foreach ($ok as $s): ?>
            <li class="list-group-item list-group-item-success small font-monospace py-1"><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
        <?php foreach ($err as $s): ?>
            <li class="list-group-item list-group-item-danger small font-monospace py-1"><?= htmlspecialchars($s) ?></li>
        <?php endforeach; ?>
        </ul>

        <div class="mt-4">
            <a href="<?= APP_URL ?>/modules/dealers/index.php" class="btn btn-primary"><i class="bi bi-arrow-left me-1"></i>Torna ai Concessionari</a>
        </div>
    </div>
</div>
</div>
</div>
<?php include APP_ROOT . '/includes/footer.php'; ?>
