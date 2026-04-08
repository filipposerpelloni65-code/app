<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('tickets')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/tickets/index.php'); exit; }

$ticket = $db->prepare("SELECT * FROM tickets WHERE id=?");
$ticket->execute([$id]);
$ticket = $ticket->fetch();
if (!$ticket) { header('Location: ' . APP_URL . '/modules/tickets/index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $priority = $_POST['priority'] ?? 'medium';
    $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
    $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $codice_concessionario = trim($_POST['codice_concessionario'] ?? '') ?: null;
    if (!$title) $errors[] = 'Il titolo è obbligatorio.';
    if (!in_array($status, ['open','in_progress','waiting','resolved','closed'])) $errors[] = 'Stato non valido.';
    if (!in_array($priority, ['low','medium','high','urgent'])) $errors[] = 'Priorità non valida.';
    if (!$errors) {
        $closedAt = in_array($status, ['resolved','closed']) && !$ticket['closed_at'] ? ', closed_at=NOW()' : '';
        $stmt = $db->prepare("UPDATE tickets SET title=?, description=?, status=?, priority=?, category_id=?, assigned_to=?, codice_concessionario=?, updated_at=NOW()$closedAt WHERE id=?");
        $stmt->execute([$title, $description, $status, $priority, $category_id, $assigned_to, $codice_concessionario, $id]);
        logActivity($user['id'], 'edit', 'ticket', $id, "Modificato ticket: $title");
        $ticketUrl = APP_URL . '/modules/tickets/view.php?id=' . $id;
        $prefix = getTicketPrefix() . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
        // Notify on assignment change
        $prevAssigned = $ticket['assigned_to'] ? (int)$ticket['assigned_to'] : 0;
        if ($assigned_to && $assigned_to != $prevAssigned && $assigned_to != $user['id']) {
            createNotification($assigned_to, 'assign', 'Ticket assegnato a te: ' . $prefix, $title, 'ticket', $id, $ticketUrl);
        }
        // Notify on status change
        if ($status !== $ticket['status']) {
            $statusLabel = getStatusLabel($status);
            $notifTitle  = 'Stato ticket ' . $prefix . ' → ' . $statusLabel;
            $notified    = [$user['id']];
            if ($ticket['created_by'] && !in_array((int)$ticket['created_by'], $notified)) {
                createNotification((int)$ticket['created_by'], 'status', $notifTitle, '', 'ticket', $id, $ticketUrl);
                $notified[] = (int)$ticket['created_by'];
            }
            if ($assigned_to && !in_array($assigned_to, $notified)) {
                createNotification($assigned_to, 'status', $notifTitle, '', 'ticket', $id, $ticketUrl);
            }
        }
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $id . '&updated=1');
        exit;
    }
    $ticket = array_merge($ticket, ['title'=>$title,'description'=>$description,'status'=>$status,'priority'=>$priority,'category_id'=>$category_id,'assigned_to'=>$assigned_to,'codice_concessionario'=>$codice_concessionario]);
}

$categories = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();

define('PAGE_TITLE', 'Modifica Ticket');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Ticket' => APP_URL.'/modules/tickets/index.php', 'Modifica' => '']);

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2 text-secondary"></i>Modifica Ticket <span class="text-muted fs-5"><?= h(getTicketPrefix() . '-' . str_pad($id, 4, '0', STR_PAD_LEFT)) ?></span></h4>
    <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Visualizza</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Titolo <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= h($ticket['title']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Codice Ticket Concessionario</label>
        <input type="text" name="codice_concessionario" class="form-control font-monospace" value="<?= h($ticket['codice_concessionario'] ?? '') ?>" placeholder="Riferimento del concessionario">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione</label>
        <textarea name="description" class="form-control" rows="6"><?= h($ticket['description']) ?></textarea>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Stato</label>
            <select name="status" class="form-select">
                <option value="open" <?= $ticket['status']==='open'?'selected':'' ?>>Aperto</option>
                <option value="in_progress" <?= $ticket['status']==='in_progress'?'selected':'' ?>>In Lavorazione</option>
                <option value="waiting" <?= $ticket['status']==='waiting'?'selected':'' ?>>In Attesa</option>
                <option value="resolved" <?= $ticket['status']==='resolved'?'selected':'' ?>>Risolto</option>
                <option value="closed" <?= $ticket['status']==='closed'?'selected':'' ?>>Chiuso</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Priorità</label>
            <select name="priority" class="form-select">
                <option value="low" <?= $ticket['priority']==='low'?'selected':'' ?>>Bassa</option>
                <option value="medium" <?= $ticket['priority']==='medium'?'selected':'' ?>>Media</option>
                <option value="high" <?= $ticket['priority']==='high'?'selected':'' ?>>Alta</option>
                <option value="urgent" <?= $ticket['priority']==='urgent'?'selected':'' ?>>Urgente</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Categoria</label>
            <select name="category_id" class="form-select">
                <option value="">-- Nessuna --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $ticket['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Assegnato a</label>
            <select name="assigned_to" class="form-select">
                <option value="">-- Nessuno --</option>
                <?php foreach ($technicians as $tech): ?>
                <option value="<?= $tech['id'] ?>" <?= $ticket['assigned_to'] == $tech['id'] ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
        <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $id ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
