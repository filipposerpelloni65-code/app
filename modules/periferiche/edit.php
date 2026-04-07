<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('periferiche')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

$db   = getDB();
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM periferiche_guaste WHERE id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

// Cannot edit finalized periferiche
if (in_array($p['stato'], ['restituita','rottamata'])) {
    header('Location: ' . APP_URL . '/modules/periferiche/view.php?id=' . $id);
    exit;
}

define('PAGE_TITLE', 'Modifica Periferica ' . $p['codice']);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Periferiche Guaste' => APP_URL.'/modules/periferiche/index.php', 'Modifica' => '']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }

    $tipo              = trim($_POST['tipo'] ?? '');
    $marca             = trim($_POST['marca'] ?? '');
    $modello           = trim($_POST['modello'] ?? '');
    $seriale           = trim($_POST['seriale'] ?? '');
    $seriale_nuovo     = trim($_POST['seriale_nuovo'] ?? '');
    $descrizione_guasto = trim($_POST['descrizione_guasto'] ?? '');
    $dealer_id         = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id       = (int)($_POST['location_id'] ?? 0) ?: null;
    $ticket_id         = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $tecnico_ritiro_id = (int)($_POST['tecnico_ritiro_id'] ?? 0) ?: null;
    $data_ritiro       = trim($_POST['data_ritiro'] ?? '');
    $note_interne      = trim($_POST['note_interne'] ?? '');

    if (!$tipo)        $errors[] = 'Il tipo dispositivo è obbligatorio.';
    if (!$data_ritiro) $errors[] = 'La data di ritiro è obbligatoria.';

    if (!$errors) {
        $db->prepare("
            UPDATE periferiche_guaste SET
                tipo=?, marca=?, modello=?, seriale=?, seriale_nuovo=?, descrizione_guasto=?,
                dealer_id=?, location_id=?, ticket_id=?, tecnico_ritiro_id=?,
                data_ritiro=?, note_interne=?, updated_at=NOW()
            WHERE id=?
        ")->execute([
            $tipo, $marca, $modello, $seriale, $seriale_nuovo, $descrizione_guasto,
            $dealer_id, $location_id, $ticket_id, $tecnico_ritiro_id,
            $data_ritiro, $note_interne, $id
        ]);
        logActivity($user['id'], 'edit', 'periferica', $id, "Modificata periferica {$p['codice']}");
        header('Location: ' . APP_URL . '/modules/periferiche/view.php?id=' . $id . '&updated=1');
        exit;
    }

    $p = array_merge($p, $_POST);
}

$technicians   = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers       = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$tickets       = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 100")->fetchAll();

$selectedDealer  = (int)($_POST['dealer_id'] ?? $p['dealer_id'] ?? 0);
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-9">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil-square me-2 text-primary"></i>Modifica Periferica <span class="text-muted fs-6 font-monospace"><?= h($p['codice']) ?></span></h4>
    <a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna al dettaglio</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Codice</label>
            <input type="text" class="form-control font-monospace bg-light" value="<?= h($p['codice']) ?>" disabled>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Tipo Dispositivo <span class="text-danger">*</span></label>
            <input type="text" name="tipo" class="form-control" required value="<?= h($p['tipo'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Marca</label>
            <input type="text" name="marca" class="form-control" value="<?= h($p['marca'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Modello</label>
            <input type="text" name="modello" class="form-control" value="<?= h($p['modello'] ?? '') ?>">
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Seriale Periferica <span class="text-muted fw-normal">(guasta)</span></label>
            <input type="text" name="seriale" class="form-control font-monospace" value="<?= h($p['seriale'] ?? '') ?>" placeholder="Seriale dispositivo ritirato">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Seriale Periferica Nuova</label>
            <input type="text" name="seriale_nuovo" class="form-control font-monospace" value="<?= h($p['seriale_nuovo'] ?? '') ?>" placeholder="Seriale nuovo dispositivo installato">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Ritiro <span class="text-danger">*</span></label>
            <input type="date" name="data_ritiro" class="form-control" required value="<?= h($p['data_ritiro'] ?? '') ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Tecnico Ritiro</label>
        <select name="tecnico_ritiro_id" class="form-select">
            <option value="">-- Nessuno --</option>
            <?php foreach ($technicians as $tech): ?>
            <option value="<?= $tech['id'] ?>" <?= (($p['tecnico_ritiro_id'] ?? '') == $tech['id']) ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione Guasto Riportato</label>
        <textarea name="descrizione_guasto" class="form-control" rows="4"><?= h($p['descrizione_guasto'] ?? '') ?></textarea>
    </div>

    <?php if ($dealers): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Concessionario</label>
            <select name="dealer_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Nessun concessionario --</option>
                <?php foreach ($dealers as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($selectedDealer == $d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($dealerLocations): ?>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Punto Vendita</label>
            <select name="location_id" class="form-select">
                <option value="">-- Nessun punto vendita --</option>
                <?php foreach ($dealerLocations as $dl): ?>
                <option value="<?= $dl['id'] ?>" <?= (($p['location_id'] ?? '') == $dl['id']) ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label fw-semibold">Ticket Collegato</label>
        <select name="ticket_id" class="form-select">
            <option value="">-- Nessun ticket --</option>
            <?php foreach ($tickets as $t): ?>
            <option value="<?= $t['id'] ?>" <?= (($p['ticket_id'] ?? '') == $t['id']) ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT).' — '.$t['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Note Interne</label>
        <textarea name="note_interne" class="form-control" rows="2"><?= h($p['note_interne'] ?? '') ?></textarea>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $id ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
