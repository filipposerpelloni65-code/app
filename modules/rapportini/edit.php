<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('rapportini')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit; }

$db = getDB();
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit; }

$stmt = $db->prepare("
    SELECT r.*,
        ut.full_name AS technician_name,
        d.name AS dealer_name,
        dl.name AS location_name,
        t.title AS ticket_title
    FROM rapportini r
    LEFT JOIN users ut ON r.technician_id = ut.id
    LEFT JOIN dealers d ON r.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON r.location_id = dl.id
    LEFT JOIN tickets t ON r.ticket_id = t.id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$rapportino = $stmt->fetch();
if (!$rapportino) { header('Location: ' . APP_URL . '/modules/rapportini/index.php'); exit; }

if ($rapportino['status'] !== 'draft') {
    header('Location: ' . APP_URL . '/modules/rapportini/view.php?id=' . $id);
    exit;
}

define('PAGE_TITLE', 'Modifica Rapportino');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Rapportini' => APP_URL.'/modules/rapportini/index.php', 'Modifica' => '']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }

    $title            = trim($_POST['title'] ?? '');
    $work_description = trim($_POST['work_description'] ?? '');
    $parts_used       = trim($_POST['parts_used'] ?? '');
    $intervention_date = trim($_POST['intervention_date'] ?? '');
    $technician_id    = (int)($_POST['technician_id'] ?? 0);
    $ticket_id        = (int)($_POST['ticket_id'] ?? 0) ?: null;
    $dealer_id        = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id      = (int)($_POST['location_id'] ?? 0) ?: null;
    $periferica_id    = (int)($_POST['periferica_id'] ?? 0) ?: null;
    $customer_name    = trim($_POST['customer_name'] ?? '');
    $customer_contact = trim($_POST['customer_contact'] ?? '');
    $notes            = trim($_POST['notes'] ?? '');
    $tipo_intervento  = trim($_POST['tipo_intervento'] ?? '') ?: null;
    $ora_inizio       = trim($_POST['ora_inizio'] ?? '') ?: null;
    $ora_fine         = trim($_POST['ora_fine'] ?? '') ?: null;
    $ore_lavorate     = strlen(trim($_POST['ore_lavorate'] ?? '')) ? (float)str_replace(',', '.', $_POST['ore_lavorate']) : null;

    if (!$title)             $errors[] = 'Il titolo è obbligatorio.';
    if (!$work_description)  $errors[] = 'La descrizione del lavoro è obbligatoria.';
    if (!$intervention_date) $errors[] = 'La data di intervento è obbligatoria.';
    if (!$technician_id)     $errors[] = 'Il tecnico è obbligatorio.';
    if ($ore_lavorate !== null && ($ore_lavorate < 0 || $ore_lavorate > 999)) $errors[] = 'Ore lavorate non valide.';

    if (!$errors) {
        $stmt = $db->prepare("
            UPDATE rapportini SET
                title=?, work_description=?, parts_used=?, intervention_date=?,
                technician_id=?, ticket_id=?, dealer_id=?, location_id=?, periferica_id=?,
                customer_name=?, customer_contact=?, notes=?,
                tipo_intervento=?, ora_inizio=?, ora_fine=?, ore_lavorate=?,
                updated_at=NOW()
            WHERE id=? AND status='draft'
        ");
        $stmt->execute([
            $title, $work_description, $parts_used, $intervention_date,
            $technician_id, $ticket_id, $dealer_id, $location_id, $periferica_id,
            $customer_name, $customer_contact, $notes,
            $tipo_intervento, $ora_inizio, $ora_fine, $ore_lavorate,
            $id
        ]);
        // Sync periferica back-link
        if ($periferica_id) {
            $db->prepare("UPDATE periferiche_guaste SET rapportino_id=?, updated_at=NOW() WHERE id=?")->execute([$id, $periferica_id]);
        }
        logActivity($user['id'], 'edit', 'rapportino', $id, "Modificato rapportino: $title");
        header('Location: ' . APP_URL . '/modules/rapportini/view.php?id=' . $id . '&saved=1');
        exit;
    }

    // Re-populate for re-display on error
    $rapportino = array_merge($rapportino, $_POST);
}

$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers     = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$tickets     = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 100")->fetchAll();

// Periferiche available for linking
$periferiche = [];
if (isModuleEnabled('periferiche')) {
    $pgSql = "SELECT p.id, p.codice, p.tipo, p.marca, p.modello FROM periferiche_guaste p WHERE (p.rapportino_id IS NULL OR p.rapportino_id=?) AND p.stato IN ('in_riparazione','riparata','in_diagnosi') ORDER BY p.codice";
    $pgStmt = $db->prepare($pgSql);
    $pgStmt->execute([$id]);
    $periferiche = $pgStmt->fetchAll();
}

$selectedDealer = (int)($_POST['dealer_id'] ?? $rapportino['dealer_id'] ?? 0);
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
    <h4 class="mb-0"><i class="bi bi-pencil-square me-2 text-primary"></i>Modifica Rapportino <span class="text-muted fs-6">RAP-<?= str_pad($id, 4, '0', STR_PAD_LEFT) ?></span></h4>
    <a href="<?= APP_URL ?>/modules/rapportini/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna al rapportino</a>
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

    <div class="mb-3">
        <label class="form-label fw-semibold">Titolo <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= h($rapportino['title'] ?? '') ?>">
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Intervento <span class="text-danger">*</span></label>
            <input type="date" name="intervention_date" class="form-control" required value="<?= h($rapportino['intervention_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Tecnico <span class="text-danger">*</span></label>
            <select name="technician_id" class="form-select" required>
                <?php foreach ($technicians as $tech): ?>
                <option value="<?= $tech['id'] ?>" <?= (($rapportino['technician_id'] ?? '') == $tech['id']) ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Ticket Collegato</label>
            <select name="ticket_id" class="form-select">
                <option value="">-- Nessun ticket --</option>
                <?php foreach ($tickets as $t): ?>
                <option value="<?= $t['id'] ?>" <?= (($rapportino['ticket_id'] ?? '') == $t['id']) ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT).' - '.$t['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Tipo Intervento</label>
            <select name="tipo_intervento" class="form-select">
                <option value="">-- Non specificato --</option>
                <?php $tipi = ['In loco'=>'In loco','Remoto'=>'Remoto','In laboratorio'=>'In laboratorio','Garanzia'=>'Garanzia','A pagamento'=>'A pagamento','Contratto'=>'Contratto']; ?>
                <?php foreach ($tipi as $v => $l): ?>
                <option value="<?= h($v) ?>" <?= ($rapportino['tipo_intervento'] ?? '') === $v ? 'selected' : '' ?>><?= h($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Ora Inizio</label>
            <input type="time" name="ora_inizio" class="form-control" value="<?= h(substr($rapportino['ora_inizio'] ?? '', 0, 5)) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Ora Fine</label>
            <input type="time" name="ora_fine" class="form-control" value="<?= h(substr($rapportino['ora_fine'] ?? '', 0, 5)) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Ore Lavorate</label>
            <div class="input-group">
                <input type="number" name="ore_lavorate" class="form-control" min="0" max="999" step="0.25" placeholder="Es. 2.5" value="<?= h($rapportino['ore_lavorate'] ?? '') ?>">
                <span class="input-group-text">h</span>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Nome Cliente / Referente</label>
            <input type="text" name="customer_name" class="form-control" value="<?= h($rapportino['customer_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Contatto Cliente</label>
            <input type="text" name="customer_contact" class="form-control" value="<?= h($rapportino['customer_contact'] ?? '') ?>">
        </div>
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
                <option value="<?= $dl['id'] ?>" <?= (($rapportino['location_id'] ?? '') == $dl['id']) ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($periferiche): ?>
    <div class="mb-3">
        <label class="form-label fw-semibold"><i class="bi bi-hdd-network me-1 text-primary"></i>Periferica Guasta Collegata</label>
        <select name="periferica_id" class="form-select">
            <option value="">-- Nessuna periferica --</option>
            <?php foreach ($periferiche as $pg): ?>
            <option value="<?= $pg['id'] ?>" <?= (($rapportino['periferica_id'] ?? '') == $pg['id']) ? 'selected' : '' ?>><?= h($pg['codice'].' — '.$pg['tipo'].($pg['marca'] ? ' '.$pg['marca'] : '').($pg['modello'] ? ' '.$pg['modello'] : '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione Lavoro Eseguito <span class="text-danger">*</span></label>
        <textarea name="work_description" class="form-control" rows="6" required><?= h($rapportino['work_description'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Parti / Materiali Utilizzati</label>
        <textarea name="parts_used" class="form-control" rows="3"><?= h($rapportino['parts_used'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Note Aggiuntive</label>
        <textarea name="notes" class="form-control" rows="2"><?= h($rapportino['notes'] ?? '') ?></textarea>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/rapportini/view.php?id=<?= $id ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
