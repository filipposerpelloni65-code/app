<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('tickets')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuovo Ticket');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Ticket' => APP_URL.'/modules/tickets/index.php', 'Nuovo Ticket' => '']);

$db = getDB();
$user = currentUser();

$errors = [];
$success = false;

// Pre-fill dealer from GET (e.g. linked from dealer view)
$preDealerId = (int)($_GET['dealer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
    $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $dealer_id = (int)($_POST['dealer_id'] ?? 0) ?: null;
    $location_id = (int)($_POST['location_id'] ?? 0) ?: null;
    $codice_concessionario = trim($_POST['codice_concessionario'] ?? '') ?: null;
    if (!$title) $errors[] = 'Il titolo è obbligatorio.';
    if (!$description) $errors[] = 'La descrizione è obbligatoria.';
    if (!in_array($priority, ['low','medium','high','urgent'])) $errors[] = 'Priorità non valida.';

    if (!$errors) {
        // Auto-assign if enabled and no assignee selected
        if (!$assigned_to && getSetting('auto_assign', '0') === '1') {
            $assigned_to = getAutoAssignee();
        }
        $stmt = $db->prepare("INSERT INTO tickets (title, description, priority, category_id, created_by, assigned_to, dealer_id, location_id, codice_concessionario, status) VALUES (?,?,?,?,?,?,?,?,?,'open')");
        $stmt->execute([$title, $description, $priority, $category_id, $user['id'], $assigned_to, $dealer_id, $location_id, $codice_concessionario]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'ticket', $newId, "Creato ticket: $title");
        $ticketUrl = APP_URL . '/modules/tickets/view.php?id=' . $newId;
        $prefix = getTicketPrefix() . '-' . str_pad($newId, 4, '0', STR_PAD_LEFT);
        // Notify admins (excluding the creator if they are admin)
        notifyAdmins('ticket', 'Nuovo ticket aperto: ' . $prefix, $title, 'ticket', $newId, $ticketUrl, $user['id']);
        // Notify assigned technician
        if ($assigned_to && $assigned_to != $user['id']) {
            createNotification($assigned_to, 'assign', 'Ticket assegnato a te: ' . $prefix, $title, 'ticket', $newId, $ticketUrl);
        }
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $newId . '&created=1');
        exit;
    }
}

$categories = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$dealers = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$dealerLocations = [];
$selectedDealer = (int)($_POST['dealer_id'] ?? $preDealerId);
if ($selectedDealer) {
    $dlStmt = $db->prepare("SELECT id, name FROM dealer_locations WHERE dealer_id=? AND active=1 ORDER BY name");
    $dlStmt->execute([$selectedDealer]);
    $dealerLocations = $dlStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<style>
.create-ticket-header {
    background: linear-gradient(135deg, #0f172a, #1e293b);
    border-radius: 16px;
    padding: 2rem;
    color: white;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.create-ticket-header::before {
    content: '';
    position: absolute;
    top: -50px; right: -50px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(59,130,246,.12);
}
</style>

<div class="create-ticket-header animate-fade-in">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <div class="d-flex align-items-center gap-3 mb-1">
                <div style="width:48px;height:48px;border-radius:12px;background:rgba(59,130,246,.25);display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-plus-circle-fill text-primary fs-4"></i>
                </div>
                <div>
                    <h4 class="mb-0 fw-bold text-white">Nuovo Ticket</h4>
                    <div class="text-white opacity-75 small">Inserisci i dettagli del nuovo ticket di assistenza</div>
                </div>
            </div>
        </div>
        <a href="<?= APP_URL ?>/modules/tickets/index.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Torna alla lista
        </a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger animate-slide-down">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" id="createTicketForm">
<?= csrfField() ?>

<!-- Sezione 1: Informazioni principali -->
<div class="form-section animate-fade-in" style="animation-delay:.05s">
    <div class="form-section-title">
        <i class="bi bi-card-text"></i> Informazioni Principali
    </div>
    <div class="mb-3">
        <label class="form-label">Titolo <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control form-control-lg"
               required value="<?= h($_POST['title'] ?? '') ?>"
               placeholder="Descrivi brevemente il problema...">
    </div>
    <div class="mb-0">
        <label class="form-label">Codice Ticket Concessionario</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-hash"></i></span>
            <input type="text" name="codice_concessionario" class="form-control font-monospace"
                   value="<?= h($_POST['codice_concessionario'] ?? '') ?>"
                   placeholder="Es. TKT-DEALER-001">
        </div>
        <div class="form-text">Riferimento del ticket comunicato dal concessionario via email (opzionale).</div>
    </div>
</div>

<!-- Sezione 2: Descrizione -->
<div class="form-section animate-fade-in" style="animation-delay:.1s">
    <div class="form-section-title">
        <i class="bi bi-chat-text"></i> Descrizione del Problema
    </div>
    <textarea name="description" class="form-control" rows="7" required
              placeholder="Descrivi dettagliatamente il problema, i passaggi per riprodurlo e qualsiasi informazione rilevante..."><?= h($_POST['description'] ?? '') ?></textarea>
</div>

<!-- Sezione 3: Priorità -->
<div class="form-section animate-fade-in" style="animation-delay:.15s">
    <div class="form-section-title">
        <i class="bi bi-speedometer2"></i> Priorità
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php
        $priorities = [
            'low'    => ['label' => 'Bassa',   'icon' => 'bi-arrow-down-circle',         'desc' => 'Nessuna urgenza'],
            'medium' => ['label' => 'Media',   'icon' => 'bi-dash-circle',               'desc' => 'Normale'],
            'high'   => ['label' => 'Alta',    'icon' => 'bi-exclamation-circle',         'desc' => 'Richiede attenzione'],
            'urgent' => ['label' => 'Urgente', 'icon' => 'bi-exclamation-triangle-fill', 'desc' => 'Blocca l\'attività'],
        ];
        $currentPriority = $_POST['priority'] ?? 'medium';
        foreach ($priorities as $val => $p): ?>
        <input type="radio" name="priority" value="<?= $val ?>" id="prio_<?= $val ?>"
               class="priority-option" <?= $currentPriority === $val ? 'checked' : '' ?>>
        <label for="prio_<?= $val ?>">
            <i class="bi <?= $p['icon'] ?>"></i>
            <strong><?= $p['label'] ?></strong>
            <span style="font-size:.7rem;opacity:.75;font-weight:400"><?= $p['desc'] ?></span>
        </label>
        <?php endforeach; ?>
    </div>
</div>

<!-- Sezione 4: Classificazione -->
<div class="form-section animate-fade-in" style="animation-delay:.2s">
    <div class="form-section-title">
        <i class="bi bi-tags"></i> Classificazione
    </div>
    <div class="row g-3">
        <div class="col-md-<?= isTechnician() ? '6' : '12' ?>">
            <label class="form-label">Categoria</label>
            <select name="category_id" class="form-select">
                <option value="">— Nessuna categoria —</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($_POST['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (isTechnician()): ?>
        <div class="col-md-6">
            <label class="form-label">Assegna a</label>
            <div class="input-group">
                <select name="assigned_to" class="form-select" id="assignedToSelect">
                    <option value="">— Non assegnato —</option>
                    <?php foreach ($technicians as $tech): ?>
                    <option value="<?= $tech['id'] ?>" <?= ($_POST['assigned_to'] ?? '') == $tech['id'] ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" id="autoAssignBtn" title="Auto-assegna al tecnico meno carico" data-bs-toggle="tooltip">
                    <i class="bi bi-magic"></i>
                </button>
            </div>
            <div class="form-text">Clicca <i class="bi bi-magic"></i> per auto-assegnare al tecnico meno carico.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($dealers): ?>
<!-- Sezione 5: Concessionario -->
<div class="form-section animate-fade-in" style="animation-delay:.25s">
    <div class="form-section-title">
        <i class="bi bi-building"></i> Concessionario
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Concessionario</label>
            <select name="dealer_id" class="form-select" id="dealerSelect" onchange="this.form.submit()">
                <option value="">— Nessun concessionario —</option>
                <?php foreach ($dealers as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($selectedDealer == $d['id']) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($dealerLocations): ?>
        <div class="col-md-6">
            <label class="form-label">Punto Vendita</label>
            <select name="location_id" class="form-select">
                <option value="">— Nessun punto vendita —</option>
                <?php foreach ($dealerLocations as $dl): ?>
                <option value="<?= $dl['id'] ?>" <?= ($_POST['location_id'] ?? '') == $dl['id'] ? 'selected' : '' ?>><?= h($dl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Azioni -->
<div class="d-flex gap-3 animate-fade-in" style="animation-delay:.3s">
    <button type="submit" class="btn btn-primary btn-lg px-4" id="submitBtn">
        <i class="bi bi-check-lg me-2"></i>Crea Ticket
    </button>
    <a href="<?= APP_URL ?>/modules/tickets/index.php" class="btn btn-outline-secondary btn-lg">
        <i class="bi bi-x-lg me-1"></i>Annulla
    </a>
</div>

</form>

<?php
$autoAssigneeId = isTechnician() ? getAutoAssignee() : null;
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var autoAssignBtn = document.getElementById('autoAssignBtn');
    if (autoAssignBtn) {
        var autoId = <?= json_encode($autoAssigneeId) ?>;
        autoAssignBtn.addEventListener('click', function() {
            if (autoId) {
                document.getElementById('assignedToSelect').value = autoId;
                autoAssignBtn.classList.add('btn-success');
                autoAssignBtn.classList.remove('btn-outline-secondary');
                setTimeout(function() {
                    autoAssignBtn.classList.remove('btn-success');
                    autoAssignBtn.classList.add('btn-outline-secondary');
                }, 1500);
            } else {
                alert('Nessun tecnico disponibile.');
            }
        });
    }
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
