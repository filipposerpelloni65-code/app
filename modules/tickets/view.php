<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('tickets')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/tickets/index.php'); exit; }

$stmt = $db->prepare("SELECT t.*, uc.full_name as creator_name, ua.full_name as assignee_name, c.name as category_name, d.name as dealer_name, dl.name as location_name FROM tickets t LEFT JOIN users uc ON t.created_by=uc.id LEFT JOIN users ua ON t.assigned_to=ua.id LEFT JOIN ticket_categories c ON t.category_id=c.id LEFT JOIN dealers d ON t.dealer_id=d.id LEFT JOIN dealer_locations dl ON t.location_id=dl.id WHERE t.id=?");
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) { header('Location: ' . APP_URL . '/modules/tickets/index.php'); exit; }

// Access control: users can only see their own tickets
if ($user['role'] === 'user' && $ticket['created_by'] != $user['id']) {
    header('Location: ' . APP_URL . '/modules/tickets/index.php'); exit;
}

$errors = [];

// Handle comment submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    $message = trim($_POST['message'] ?? '');
    $is_internal = isset($_POST['is_internal']) && isTechnician() ? 1 : 0;
    if (!$message) { $errors[] = 'Il commento non può essere vuoto.'; }
    if (!$errors) {
        $stmt2 = $db->prepare("INSERT INTO ticket_comments (ticket_id, user_id, message, is_internal) VALUES (?,?,?,?)");
        $stmt2->execute([$id, $user['id'], $message, $is_internal]);
        // Update ticket updated_at
        $db->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'comment', 'ticket', $id, 'Aggiunto commento');
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $id . '#comments');
        exit;
    }
}

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status' && isTechnician()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    $newStatus = $_POST['new_status'] ?? '';
    if (in_array($newStatus, ['open','in_progress','waiting','resolved','closed'])) {
        $closedAt = in_array($newStatus, ['resolved','closed']) ? ', closed_at=NOW()' : '';
        $db->prepare("UPDATE tickets SET status=?, updated_at=NOW()$closedAt WHERE id=?")->execute([$newStatus, $id]);
        logActivity($user['id'], 'status_change', 'ticket', $id, "Stato cambiato in: $newStatus");
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $id);
        exit;
    }
}

// Fetch comments
$comments = $db->prepare("SELECT tc.*, u.full_name, u.role FROM ticket_comments tc LEFT JOIN users u ON tc.user_id=u.id WHERE tc.ticket_id=? ORDER BY tc.created_at ASC");
$comments->execute([$id]);
$comments = $comments->fetchAll();

// Handle add uscita (technician visit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_uscita' && isTechnician()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    $data_uscita = trim($_POST['data_uscita'] ?? '');
    $tecnico_id  = (int)($_POST['tecnico_id'] ?? $user['id']);
    $note_uscita = trim($_POST['note_uscita'] ?? '');
    if (!$data_uscita) { $errors[] = 'La data uscita è obbligatoria.'; }
    if (!$errors) {
        $db->prepare("INSERT INTO ticket_uscite (ticket_id, tecnico_id, data_uscita, note, created_by) VALUES (?,?,?,?,?)")
           ->execute([$id, $tecnico_id, $data_uscita, $note_uscita ?: null, $user['id']]);
        $db->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'add_uscita', 'ticket', $id, "Aggiunta uscita tecnico del $data_uscita");
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $id . '#uscite');
        exit;
    }
}

// Handle delete uscita
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_uscita' && isTechnician()) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    $uscita_id = (int)($_POST['uscita_id'] ?? 0);
    if ($uscita_id && !$errors) {
        $db->prepare("DELETE FROM ticket_uscite WHERE id=? AND ticket_id=?")->execute([$uscita_id, $id]);
        header('Location: ' . APP_URL . '/modules/tickets/view.php?id=' . $id . '#uscite');
        exit;
    }
}

// Fetch spare parts requests
$partsRequests = $db->prepare("SELECT spr.*, sp.name as part_name, sp.sku, u.full_name as requester_name FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id JOIN users u ON spr.requested_by=u.id WHERE spr.ticket_id=?");
$partsRequests->execute([$id]);
$partsRequests = $partsRequests->fetchAll();

// Fetch shipments linked to this ticket
$spedizioniLinked = [];
if (isModuleEnabled('spedizioni')) {
    $spedStmt = $db->prepare("SELECT s.*, u.full_name as creator_name FROM spedizioni s LEFT JOIN users u ON s.created_by=u.id WHERE s.ticket_id=? ORDER BY s.created_at DESC");
    $spedStmt->execute([$id]);
    $spedizioniLinked = $spedStmt->fetchAll();
}

// Fetch ticket uscite (technician visits)
$uscite = $db->prepare("SELECT tu.*, u.full_name AS tecnico_name FROM ticket_uscite tu JOIN users u ON tu.tecnico_id=u.id WHERE tu.ticket_id=? ORDER BY tu.data_uscita ASC, tu.id ASC");
$uscite->execute([$id]);
$uscite = $uscite->fetchAll();

// Fetch periferiche linked to this ticket
$perifericheLinked = [];
if (isModuleEnabled('periferiche')) {
    $pgStmt = $db->prepare("SELECT p.*, u.full_name AS tecnico_name FROM periferiche_guaste p LEFT JOIN users u ON p.tecnico_ritiro_id=u.id WHERE p.ticket_id=? ORDER BY p.created_at DESC");
    $pgStmt->execute([$id]);
    $perifericheLinked = $pgStmt->fetchAll();
}

define('PAGE_TITLE', 'Ticket ' . getTicketPrefix() . '-' . str_pad($id, 4, '0', STR_PAD_LEFT));
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Ticket' => APP_URL.'/modules/tickets/index.php', 'Dettaglio' => '']);

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>Ticket creato con successo!
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Main content -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-light text-dark border me-2"><?= h(getTicketPrefix() . '-' . str_pad($id, 4, '0', STR_PAD_LEFT)) ?></span>
                    <strong><?= h($ticket['title']) ?></strong>
                </div>
                <?php if (isTechnician()): ?>
                <a href="<?= APP_URL ?>/modules/tickets/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Modifica</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="mb-0" style="white-space:pre-wrap"><?= h($ticket['description']) ?></p>
            </div>
        </div>

        <!-- Comments -->
        <div class="card border-0 shadow-sm mb-4" id="comments">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-chat-text me-2"></i>Commenti (<?= count($comments) ?>)</h6></div>
            <div class="card-body p-0">
                <?php if ($comments): ?>
                    <?php foreach ($comments as $c): ?>
                    <?php $isInternal = (int)$c['is_internal']; ?>
                    <?php if ($isInternal && $user['role'] === 'user') continue; ?>
                    <div class="p-3 border-bottom <?= $isInternal ? 'bg-warning bg-opacity-10' : '' ?>">
                        <div class="d-flex gap-2 align-items-start">
                            <div class="user-avatar user-avatar-sm flex-shrink-0"><?= strtoupper(substr($c['full_name'], 0, 1)) ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <strong class="small"><?= h($c['full_name']) ?></strong>
                                    <div>
                                        <?php if ($isInternal): ?><span class="badge bg-warning text-dark small me-1">Interno</span><?php endif; ?>
                                        <span class="text-muted small"><?= formatDate($c['created_at']) ?></span>
                                    </div>
                                </div>
                                <p class="mb-0 mt-1 small" style="white-space:pre-wrap"><?= h($c['message']) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($comments)): ?>
                    <div class="text-center text-muted py-4">Nessun commento</div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="p-4 text-center text-muted">Nessun commento ancora.</div>
                <?php endif; ?>
            </div>
            <?php if (!in_array($ticket['status'], ['closed'])): ?>
            <div class="card-footer bg-white">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_comment">
                    <div class="mb-2">
                        <textarea name="message" class="form-control" rows="3" placeholder="Scrivi un commento..." required></textarea>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <?php if (isTechnician()): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="is_internal" id="is_internal">
                            <label class="form-check-label small" for="is_internal"><i class="bi bi-lock me-1"></i>Nota interna</label>
                        </div>
                        <?php else: ?><div></div><?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Invia</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Uscite Tecnico -->
        <?php if (isTechnician()): ?>
        <div class="card border-0 shadow-sm mb-4" id="uscite">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-person-walking me-2 text-success"></i>Uscite Tecnico (<?= count($uscite) ?>)</h6>
            </div>
            <?php if ($uscite): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Data</th><th>Tecnico</th><th>Note</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($uscite as $u): ?>
                    <tr>
                        <td class="small fw-semibold text-nowrap"><?= formatDate($u['data_uscita'], 'd/m/Y') ?></td>
                        <td class="small"><?= h($u['tecnico_name']) ?></td>
                        <td class="small text-muted"><?= $u['note'] ? h($u['note']) : '-' ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Eliminare questa uscita?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_uscita">
                                <input type="hidden" name="uscita_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Elimina"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (!in_array($ticket['status'], ['closed'])): ?>
            <div class="card-footer bg-white">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_uscita">
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Data Uscita <span class="text-danger">*</span></label>
                            <input type="date" name="data_uscita" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Tecnico</label>
                            <?php $technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll(); ?>
                            <select name="tecnico_id" class="form-select form-select-sm">
                                <?php foreach ($technicians as $tech): ?>
                                <option value="<?= $tech['id'] ?>" <?= $tech['id'] == $user['id'] ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Note</label>
                            <input type="text" name="note_uscita" class="form-control form-control-sm" placeholder="Intervento eseguito...">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Aggiungi Uscita</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Spare Parts Requests -->
        <?php if ($partsRequests): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-tools me-2"></i>Parti di Ricambio Richieste</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Parte</th><th>SKU</th><th>Qtà</th><th>Stato</th></tr></thead>
                    <tbody>
                    <?php foreach ($partsRequests as $r): ?>
                    <tr>
                        <td><?= h($r['part_name']) ?></td>
                        <td class="small text-muted"><?= h($r['sku']) ?></td>
                        <td><?= (int)$r['quantity'] ?></td>
                        <td><?= getRequestStatusBadge($r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Spedizioni Collegate -->
        <?php if (isModuleEnabled('spedizioni')): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Spedizioni (<?= count($spedizioniLinked) ?>)</h6>
                <?php if (isTechnician()): ?>
                <a href="<?= APP_URL ?>/modules/spedizioni/create.php?ticket_id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Nuova Spedizione</a>
                <?php endif; ?>
            </div>
            <?php if ($spedizioniLinked): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>ID</th><th>Stato</th><th>Destinatario</th><th>Corriere</th><th>Tracking</th><th>Data</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($spedizioniLinked as $sp): ?>
                    <tr>
                        <td class="small text-muted font-monospace">SPD-<?= str_pad($sp['id'],4,'0',STR_PAD_LEFT) ?></td>
                        <td><?= getSpedizioneStatusBadge($sp['status']) ?></td>
                        <td class="small"><?= $sp['destinatario'] ? h($sp['destinatario']) : '-' ?></td>
                        <td class="small"><?= $sp['corriere'] ? h($sp['corriere']) : '-' ?></td>
                        <td class="small font-monospace"><?= $sp['numero_tracking'] ? h($sp['numero_tracking']) : '-' ?></td>
                        <td class="small text-muted"><?= $sp['data_spedizione'] ? formatDate($sp['data_spedizione'], 'd/m/Y') : '-' ?></td>
                        <td><a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $sp['id'] ?>" class="btn btn-outline-primary btn-sm py-0"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body text-center text-muted small py-3">
                <i class="bi bi-truck d-block mb-1 fs-4 opacity-50"></i>Nessuna spedizione collegata a questo ticket.
                <?php if (isTechnician()): ?>
                <div class="mt-2"><a href="<?= APP_URL ?>/modules/spedizioni/create.php?ticket_id=<?= $id ?>">Crea la prima spedizione</a></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Periferiche Collegate -->
        <?php if (isModuleEnabled('periferiche')): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-hdd-network me-2 text-primary"></i>Periferiche Collegate (<?= count($perifericheLinked) ?>)</h6>
                <?php if (isTechnician()): ?>
                <a href="<?= APP_URL ?>/modules/periferiche/create.php?ticket_id=<?= $id ?><?= $ticket['dealer_id'] ? '&dealer_id='.$ticket['dealer_id'] : '' ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Registra Periferica</a>
                <?php endif; ?>
            </div>
            <?php if ($perifericheLinked): ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Codice</th><th>Dispositivo</th><th>Stato</th><th>Tecnico</th><th>Ritiro</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($perifericheLinked as $pg): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $pg['id'] ?>" class="fw-bold text-primary text-decoration-none font-monospace small"><?= h($pg['codice']) ?></a></td>
                        <td class="small"><?= h($pg['tipo']) ?><?= $pg['marca'] ? ' '.h($pg['marca']) : '' ?><?= $pg['modello'] ? ' '.h($pg['modello']) : '' ?></td>
                        <td><?= getPerifericaStatoBadge($pg['stato']) ?></td>
                        <td class="small"><?= $pg['tecnico_name'] ? h($pg['tecnico_name']) : '-' ?></td>
                        <td class="small text-muted"><?= formatDate($pg['data_ritiro'], 'd/m/Y') ?></td>
                        <td><a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $pg['id'] ?>" class="btn btn-outline-primary btn-sm py-0"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body text-center text-muted small py-3">
                <i class="bi bi-hdd-network d-block mb-1 fs-4 opacity-50"></i>Nessuna periferica registrata per questo ticket.
                <?php if (isTechnician()): ?>
                <div class="mt-2"><a href="<?= APP_URL ?>/modules/periferiche/create.php?ticket_id=<?= $id ?><?= $ticket['dealer_id'] ? '&dealer_id='.$ticket['dealer_id'] : '' ?>">Registra la prima periferica</a></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar info -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0">Dettagli Ticket</h6></div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-5">Stato</dt><dd class="col-sm-7"><?= getStatusBadge($ticket['status']) ?></dd>
                    <dt class="col-sm-5">Priorità</dt><dd class="col-sm-7"><?= getPriorityBadge($ticket['priority']) ?></dd>
                    <dt class="col-sm-5">Categoria</dt><dd class="col-sm-7"><?= $ticket['category_name'] ? h($ticket['category_name']) : '-' ?></dd>
                    <?php if (!empty($ticket['codice_concessionario'])): ?>
                    <dt class="col-sm-5">Rif. Concessionario</dt><dd class="col-sm-7 font-monospace"><?= h($ticket['codice_concessionario']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-5">Creato da</dt><dd class="col-sm-7"><?= h($ticket['creator_name'] ?? '') ?></dd>
                    <dt class="col-sm-5">Assegnato a</dt><dd class="col-sm-7"><?= $ticket['assignee_name'] ? h($ticket['assignee_name']) : '<span class="text-muted">-</span>' ?></dd>
                    <?php if ($ticket['dealer_name']): ?>
                    <dt class="col-sm-5">Concessionario</dt><dd class="col-sm-7"><a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $ticket['dealer_id'] ?>" class="text-decoration-none"><?= h($ticket['dealer_name']) ?></a></dd>
                    <?php endif; ?>
                    <?php if ($ticket['location_name']): ?>
                    <dt class="col-sm-5">Punto Vendita</dt><dd class="col-sm-7"><?= h($ticket['location_name']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-5">Creato il</dt><dd class="col-sm-7"><?= formatDate($ticket['created_at']) ?></dd>
                    <dt class="col-sm-5">Aggiornato</dt><dd class="col-sm-7"><?= formatDate($ticket['updated_at']) ?></dd>
                    <?php if ($ticket['closed_at']): ?>
                    <dt class="col-sm-5">Chiuso il</dt><dd class="col-sm-7"><?= formatDate($ticket['closed_at']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (isTechnician()): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0">Cambia Stato</h6></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <select name="new_status" class="form-select form-select-sm mb-2">
                        <option value="open" <?= $ticket['status']==='open'?'selected':'' ?>>Aperto</option>
                        <option value="in_progress" <?= $ticket['status']==='in_progress'?'selected':'' ?>>In Lavorazione</option>
                        <option value="waiting" <?= $ticket['status']==='waiting'?'selected':'' ?>>In Attesa</option>
                        <option value="resolved" <?= $ticket['status']==='resolved'?'selected':'' ?>>Risolto</option>
                        <option value="closed" <?= $ticket['status']==='closed'?'selected':'' ?>>Chiuso</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Aggiorna Stato</button>
                </form>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Azioni</h6></div>
            <div class="card-body d-grid gap-2">
                <a href="<?= APP_URL ?>/modules/spare_parts/request.php?ticket_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tools me-1"></i>Richiedi Parte</a>
                <?php if (isModuleEnabled('spedizioni')): ?>
                <a href="<?= APP_URL ?>/modules/spedizioni/create.php?ticket_id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-truck me-1"></i>Nuova Spedizione</a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/modules/tickets/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Modifica Ticket</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>.user-avatar-sm{width:28px;height:28px;font-size:.75rem;line-height:28px;border-radius:50%;background:var(--bs-primary);color:#fff;text-align:center;display:inline-block;}</style>

<?php include APP_ROOT . '/includes/footer.php'; ?>
