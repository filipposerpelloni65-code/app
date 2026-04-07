<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('periferiche')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

define('PAGE_TITLE', 'Registra Periferica Guasta');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Periferiche Guaste' => APP_URL.'/modules/periferiche/index.php', 'Nuova Periferica' => '']);

$db   = getDB();
$user = currentUser();

$errors  = [];
$success = false;

// Pre-fill from GET params (e.g. when coming from a ticket or dealer page)
$preTicketId = (int)($_GET['ticket_id'] ?? 0);
$preDealerId = (int)($_GET['dealer_id'] ?? 0);

// Generate next code suggestion (best-effort; UNIQUE constraint is the real guard)
function nextPerifericaCodice($db): string {
    $row = $db->query("SELECT codice FROM periferiche_guaste ORDER BY id DESC LIMIT 1")->fetch();
    if ($row && preg_match('/PG-(\d+)$/', $row['codice'], $m)) {
        return 'PG-' . str_pad((int)$m[1] + 1, 4, '0', STR_PAD_LEFT);
    }
    return 'PG-0001';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }

    $codice            = strtoupper(trim($_POST['codice'] ?? ''));
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

    if (!$codice)      $errors[] = 'Il codice è obbligatorio.';
    if (!$tipo)        $errors[] = 'Il tipo dispositivo è obbligatorio.';
    if (!$data_ritiro) $errors[] = 'La data di ritiro è obbligatoria.';

    // Check unique codice (UNIQUE constraint is the definitive guard against concurrent inserts)
    if ($codice && !$errors) {
        $chk = $db->prepare("SELECT id FROM periferiche_guaste WHERE codice=?");
        $chk->execute([$codice]);
        if ($chk->fetch()) $errors[] = 'Il codice ' . h($codice) . ' è già utilizzato. Scegli un codice diverso.';
    }

    if (!$errors) {
        try {
            $stmt = $db->prepare("
                INSERT INTO periferiche_guaste
                    (codice, tipo, marca, modello, seriale, seriale_nuovo, descrizione_guasto,
                     dealer_id, location_id, ticket_id, tecnico_ritiro_id,
                     data_ritiro, note_interne, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $codice, $tipo, $marca, $modello, $seriale, $seriale_nuovo, $descrizione_guasto,
                $dealer_id, $location_id, $ticket_id, $tecnico_ritiro_id,
                $data_ritiro, $note_interne, $user['id']
            ]);
        } catch (PDOException $ex) {
            // Duplicate entry (race condition on UNIQUE codice)
            if ($ex->getCode() === '23000') {
                $errors[] = 'Il codice ' . h($codice) . ' è già in uso (inserito concorrentemente). Modifica il codice e riprova.';
            } else {
                throw $ex;
            }
        }
    }
    if (!$errors) {
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'periferica', $newId, "Registrata periferica: $codice ($tipo)");
        header('Location: ' . APP_URL . '/modules/periferiche/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

$technicians   = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers       = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$tickets       = $db->query("SELECT id, title FROM tickets WHERE status NOT IN ('closed') ORDER BY id DESC LIMIT 100")->fetchAll();

$selectedDealer  = (int)($_POST['dealer_id'] ?? $preDealerId);
$dealerLocations = [];
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

$suggestedCode = $_POST['codice'] ?? nextPerifericaCodice($db);

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-9">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-hdd-network me-2 text-primary"></i>Registra Periferica Guasta</h4>
    <a href="<?= APP_URL ?>/modules/periferiche/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
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

    <!-- Codice + tipo -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Codice <span class="text-danger">*</span></label>
            <input type="text" name="codice" class="form-control font-monospace text-uppercase" required value="<?= h($suggestedCode) ?>" placeholder="PG-0001">
            <div class="form-text">Generato automaticamente, modificabile.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Tipo Dispositivo <span class="text-danger">*</span></label>
            <input type="text" name="tipo" class="form-control" required value="<?= h($_POST['tipo'] ?? '') ?>" placeholder="Stampante, PC, Monitor...">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Marca</label>
            <input type="text" name="marca" class="form-control" value="<?= h($_POST['marca'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Modello</label>
            <input type="text" name="modello" class="form-control" value="<?= h($_POST['modello'] ?? '') ?>">
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Seriale Periferica <span class="text-muted fw-normal">(guasta)</span></label>
            <input type="text" name="seriale" class="form-control font-monospace" value="<?= h($_POST['seriale'] ?? '') ?>" placeholder="Seriale dispositivo ritirato">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Seriale Periferica Nuova</label>
            <input type="text" name="seriale_nuovo" class="form-control font-monospace" value="<?= h($_POST['seriale_nuovo'] ?? '') ?>" placeholder="Seriale nuovo dispositivo installato">
            <div class="form-text">Seriale del dispositivo sostitutivo installato in loco.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Data Ritiro <span class="text-danger">*</span></label>
            <input type="date" name="data_ritiro" class="form-control" required value="<?= h($_POST['data_ritiro'] ?? date('Y-m-d')) ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Tecnico Ritiro</label>
        <select name="tecnico_ritiro_id" class="form-select">
            <option value="">-- Nessuno --</option>
            <?php foreach ($technicians as $tech): ?>
            <option value="<?= $tech['id'] ?>" <?= (($_POST['tecnico_ritiro_id'] ?? $user['id']) == $tech['id']) ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione Guasto Riportato</label>
        <textarea name="descrizione_guasto" class="form-control" rows="4" placeholder="Descrizione del problema comunicato dal concessionario..."><?= h($_POST['descrizione_guasto'] ?? '') ?></textarea>
    </div>

    <!-- Dealer -->
    <?php if ($dealers): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Concessionario</label>
            <select name="dealer_id" class="form-select" id="dealerSelect" onchange="this.form.submit()">
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
                <option value="<?= $dl['id'] ?>" <?= (($_POST['location_id'] ?? '') == $dl['id']) ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Ticket collegato -->
    <div class="mb-3">
        <label class="form-label fw-semibold">Ticket Collegato</label>
        <select name="ticket_id" class="form-select">
            <option value="">-- Nessun ticket --</option>
            <?php foreach ($tickets as $t): ?>
            <option value="<?= $t['id'] ?>" <?= (($_POST['ticket_id'] ?? $preTicketId) == $t['id']) ? 'selected' : '' ?>><?= h(getTicketPrefix().'-'.str_pad($t['id'],4,'0',STR_PAD_LEFT).' — '.$t['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Ticket aperto dall'admin in seguito alla comunicazione del concessionario.</div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Note Interne</label>
        <textarea name="note_interne" class="form-control" rows="2" placeholder="Note visibili solo agli operatori interni..."><?= h($_POST['note_interne'] ?? '') ?></textarea>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Registra Periferica</button>
        <a href="<?= APP_URL ?>/modules/periferiche/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
