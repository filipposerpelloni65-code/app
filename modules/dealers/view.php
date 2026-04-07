<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('dealers')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/dealers/index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM dealers WHERE id=?");
$stmt->execute([$id]);
$dealer = $stmt->fetch();
if (!$dealer) { header('Location: ' . APP_URL . '/modules/dealers/index.php'); exit; }

define('PAGE_TITLE', $dealer['name']);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Concessionari' => APP_URL.'/modules/dealers/index.php', $dealer['name'] => '']);

// Locations
$locsStmt = $db->prepare("SELECT * FROM dealer_locations WHERE dealer_id=? ORDER BY name");
$locsStmt->execute([$id]);
$locations = $locsStmt->fetchAll();

// Recent tickets for this dealer
$tStmt = $db->prepare("SELECT t.*, uc.full_name as creator_name, ua.full_name as assignee_name, dl.name as location_name FROM tickets t LEFT JOIN users uc ON t.created_by=uc.id LEFT JOIN users ua ON t.assigned_to=ua.id LEFT JOIN dealer_locations dl ON t.location_id=dl.id WHERE t.dealer_id=? ORDER BY t.created_at DESC LIMIT 15");
$tStmt->execute([$id]);
$tickets = $tStmt->fetchAll();

// Recent spare parts requests for this dealer
$prStmt = $db->prepare("SELECT spr.*, sp.name as part_name, sp.sku, u.full_name as requester_name, dl.name as location_name FROM spare_parts_requests spr JOIN spare_parts sp ON spr.part_id=sp.id JOIN users u ON spr.requested_by=u.id LEFT JOIN dealer_locations dl ON spr.location_id=dl.id WHERE spr.dealer_id=? ORDER BY spr.created_at DESC LIMIT 10");
$prStmt->execute([$id]);
$partsReqs = $prStmt->fetchAll();

// Periferiche for this dealer
$periferiche = [];
if (isModuleEnabled('periferiche')) {
    $pgStmt = $db->prepare("SELECT p.*, u.full_name AS tecnico_name FROM periferiche_guaste p LEFT JOIN users u ON p.tecnico_ritiro_id=u.id WHERE p.dealer_id=? ORDER BY p.created_at DESC LIMIT 15");
    $pgStmt->execute([$id]);
    $periferiche = $pgStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Concessionario creato con successo! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Modifiche salvate con successo! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0">
            <i class="bi bi-shop me-2 text-primary"></i><?= h($dealer['name']) ?>
            <span class="badge bg-secondary ms-2 fs-6 fw-normal font-monospace"><?= h($dealer['code']) ?></span>
            <?= $dealer['active'] ? '<span class="badge bg-success ms-1">Attivo</span>' : '<span class="badge bg-secondary ms-1">Inattivo</span>' ?>
        </h4>
    </div>
    <?php if (isAdmin()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/dealers/locations/create.php?dealer_id=<?= $id ?>" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuovo Punto Vendita</a>
        <a href="<?= APP_URL ?>/modules/dealers/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
    </div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Left: info + locations -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0">Dati Concessionario</h6></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <?php if ($dealer['email']): ?><dt class="col-5">Email</dt><dd class="col-7"><a href="mailto:<?= h($dealer['email']) ?>"><?= h($dealer['email']) ?></a></dd><?php endif; ?>
                    <?php if ($dealer['phone']): ?><dt class="col-5">Telefono</dt><dd class="col-7"><?= h($dealer['phone']) ?></dd><?php endif; ?>
                    <?php if ($dealer['address']): ?><dt class="col-5">Indirizzo</dt><dd class="col-7"><?= h($dealer['address']) ?></dd><?php endif; ?>
                    <?php if ($dealer['city']): ?>
                    <dt class="col-5">Città</dt>
                    <dd class="col-7"><?= h($dealer['city']) ?><?= $dealer['region'] ? ' (' . h($dealer['region']) . ')' : '' ?></dd>
                    <?php endif; ?>
                    <dt class="col-5">Creato il</dt><dd class="col-7"><?= formatDate($dealer['created_at'], 'd/m/Y') ?></dd>
                    <?php if ($dealer['notes']): ?><dt class="col-5">Note</dt><dd class="col-7 text-muted"><?= h($dealer['notes']) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Punti Vendita -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-geo-alt me-2 text-success"></i>Punti Vendita (<?= count($locations) ?>)</h6>
                <?php if (isAdmin()): ?>
                <a href="<?= APP_URL ?>/modules/dealers/locations/create.php?dealer_id=<?= $id ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-lg"></i></a>
                <?php endif; ?>
            </div>
            <?php if ($locations): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($locations as $loc): ?>
                <div class="list-group-item py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold small"><?= h($loc['name']) ?></div>
                            <?php if ($loc['city']): ?><div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= h($loc['city']) ?></div><?php endif; ?>
                            <?php if ($loc['contact_person']): ?><div class="text-muted small"><i class="bi bi-person me-1"></i><?= h($loc['contact_person']) ?></div><?php endif; ?>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <?= $loc['active'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Inattivo</span>' ?>
                            <?php if (isAdmin()): ?>
                            <a href="<?= APP_URL ?>/modules/dealers/locations/edit.php?id=<?= $loc['id'] ?>&dealer_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="card-body text-center text-muted small py-4">
                <i class="bi bi-geo-alt fs-2 d-block mb-2 opacity-50"></i>Nessun punto vendita.<br>
                <?php if (isAdmin()): ?><a href="<?= APP_URL ?>/modules/dealers/locations/create.php?dealer_id=<?= $id ?>">Aggiungine uno</a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: tickets + parts -->
    <div class="col-lg-8">
        <!-- Tickets -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-ticket-detailed me-2 text-primary"></i>Ticket (<?= count($tickets) ?>)</h6>
                <a href="<?= APP_URL ?>/modules/tickets/index.php?dealer_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Vedi tutti</a>
            </div>
            <div class="card-body p-0">
                <?php if ($tickets): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Titolo</th><th>Punto Vendita</th><th>Stato</th><th>Priorità</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="text-primary fw-bold text-decoration-none small"><?= h(getTicketPrefix() . '-' . str_pad($t['id'], 4, '0', STR_PAD_LEFT)) ?></a></td>
                            <td class="small"><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $t['id'] ?>" class="text-dark text-decoration-none"><?= h($t['title']) ?></a></td>
                            <td class="small text-muted"><?= $t['location_name'] ? h($t['location_name']) : '-' ?></td>
                            <td><?= getStatusBadge($t['status']) ?></td>
                            <td><?= getPriorityBadge($t['priority']) ?></td>
                            <td class="small text-muted"><?= formatDate($t['created_at'], 'd/m/Y') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4 text-center text-muted small"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>Nessun ticket per questo concessionario.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Spare Parts Requests -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-tools me-2 text-secondary"></i>Richieste Parti di Ricambio (<?= count($partsReqs) ?>)</h6>
                <a href="<?= APP_URL ?>/modules/spare_parts/requests.php?dealer_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Vedi tutte</a>
            </div>
            <div class="card-body p-0">
                <?php if ($partsReqs): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>Parte</th><th>Punto Vendita</th><th>Qtà</th><th>Stato</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($partsReqs as $r): ?>
                        <tr>
                            <td class="small"><?= h($r['part_name']) ?> <span class="text-muted font-monospace"><?= h($r['sku']) ?></span></td>
                            <td class="small text-muted"><?= $r['location_name'] ? h($r['location_name']) : '-' ?></td>
                            <td><?= (int)$r['quantity'] ?></td>
                            <td><?= getRequestStatusBadge($r['status']) ?></td>
                            <td class="small text-muted"><?= formatDate($r['created_at'], 'd/m/Y') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4 text-center text-muted small"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>Nessuna richiesta parti per questo concessionario.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Periferiche Guaste -->
        <?php if (isModuleEnabled('periferiche')): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-hdd-network me-2 text-primary"></i>Periferiche Guaste (<?= count($periferiche) ?>)</h6>
                <div class="d-flex gap-2">
                    <a href="<?= APP_URL ?>/modules/periferiche/index.php?dealer_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">Vedi tutte</a>
                    <?php if (isTechnician()): ?>
                    <a href="<?= APP_URL ?>/modules/periferiche/create.php?dealer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Registra</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if ($periferiche): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>Codice</th><th>Dispositivo</th><th>Stato</th><th>Tecnico</th><th>Ritiro</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($periferiche as $pg): ?>
                        <tr>
                            <td><a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $pg['id'] ?>" class="fw-bold text-primary text-decoration-none font-monospace small"><?= h($pg['codice']) ?></a></td>
                            <td class="small"><?= h($pg['tipo']) ?><?= $pg['marca'] ? ' '.h($pg['marca']) : '' ?></td>
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
                <div class="p-4 text-center text-muted small"><i class="bi bi-hdd-network fs-2 d-block mb-2 opacity-50"></i>Nessuna periferica per questo concessionario.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
