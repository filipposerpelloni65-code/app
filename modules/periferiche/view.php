<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('periferiche')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db   = getDB();
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

$stmt = $db->prepare("
    SELECT p.*,
        d.name AS dealer_name, d.address AS dealer_address, d.city AS dealer_city,
        dl.name AS location_name,
        t.title AS ticket_title,
        u.full_name AS tecnico_name,
        uc.full_name AS creator_name,
        r.title AS rapportino_title
    FROM periferiche_guaste p
    LEFT JOIN dealers d ON p.dealer_id = d.id
    LEFT JOIN dealer_locations dl ON p.location_id = dl.id
    LEFT JOIN tickets t ON p.ticket_id = t.id
    LEFT JOIN users u ON p.tecnico_ritiro_id = u.id
    LEFT JOIN users uc ON p.created_by = uc.id
    LEFT JOIN rapportini r ON p.rapportino_id = r.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

define('PAGE_TITLE', 'Periferica ' . h($p['codice']));
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Periferiche Guaste' => APP_URL.'/modules/periferiche/index.php', h($p['codice']) => '']);

// Stato finale: no più azioni
$isFinale = in_array($p['stato'], ['restituita', 'rottamata']);

include APP_ROOT . '/includes/header.php';
?>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Periferica registrata con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Periferica aggiornata con successo!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-hdd-network me-2 text-primary"></i><?= h($p['codice']) ?></h4>
        <small class="text-muted"><?= h($p['tipo']) ?><?= ($p['marca'] || $p['modello']) ? ' — '.h(trim($p['marca'].' '.$p['modello'])) : '' ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (isTechnician() && !$isFinale): ?>
        <a href="<?= APP_URL ?>/modules/periferiche/diagnosi.php?id=<?= $id ?>" class="btn btn-warning btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Aggiorna Stato</a>
        <a href="<?= APP_URL ?>/modules/periferiche/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Modifica</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/periferiche/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Lista</a>
    </div>
</div>

<div class="row g-4">
    <!-- Dettaglio principale -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Dati Periferica</h6></div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tbody>
                        <tr><td class="fw-semibold bg-light" style="width:35%">Codice</td><td class="font-monospace"><?= h($p['codice']) ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Tipo</td><td><?= h($p['tipo']) ?></td></tr>
                        <?php if ($p['marca']): ?><tr><td class="fw-semibold bg-light">Marca</td><td><?= h($p['marca']) ?></td></tr><?php endif; ?>
                        <?php if ($p['modello']): ?><tr><td class="fw-semibold bg-light">Modello</td><td><?= h($p['modello']) ?></td></tr><?php endif; ?>
                        <?php if ($p['seriale']): ?><tr><td class="fw-semibold bg-light">Seriale (guasto)</td><td class="font-monospace"><?= h($p['seriale']) ?></td></tr><?php endif; ?>
                        <?php if ($p['seriale_nuovo']): ?><tr><td class="fw-semibold bg-light">Seriale Nuovo Installato</td><td class="font-monospace text-success"><?= h($p['seriale_nuovo']) ?></td></tr><?php endif; ?>
                        <tr><td class="fw-semibold bg-light">Data Ritiro</td><td><?= formatDate($p['data_ritiro'], 'd/m/Y') ?></td></tr>
                        <tr><td class="fw-semibold bg-light">Tecnico Ritiro</td><td><?= $p['tecnico_name'] ? h($p['tecnico_name']) : '<span class="text-muted">-</span>' ?></td></tr>
                        <?php if ($p['dealer_name']): ?>
                        <tr><td class="fw-semibold bg-light">Concessionario</td><td><a href="<?= APP_URL ?>/modules/dealers/view.php?id=<?= $p['dealer_id'] ?>"><?= h($p['dealer_name']) ?></a><?= $p['dealer_city'] ? ' — '.h($p['dealer_city']) : '' ?></td></tr>
                        <?php endif; ?>
                        <?php if ($p['location_name']): ?>
                        <tr><td class="fw-semibold bg-light">Punto Vendita</td><td><?= h($p['location_name']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($p['ticket_title']): ?>
                        <tr><td class="fw-semibold bg-light">Ticket Collegato</td><td><a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $p['ticket_id'] ?>"><?= h(getTicketPrefix().'-'.str_pad($p['ticket_id'],4,'0',STR_PAD_LEFT)) ?></a> — <?= h($p['ticket_title']) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($p['descrizione_guasto']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Guasto Riportato</h6></div>
            <div class="card-body"><p class="mb-0" style="white-space:pre-wrap"><?= h($p['descrizione_guasto']) ?></p></div>
        </div>
        <?php endif; ?>

        <?php if ($p['note_diagnosi']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-search me-2 text-info"></i>Note Diagnosi</h6></div>
            <div class="card-body"><p class="mb-0" style="white-space:pre-wrap"><?= h($p['note_diagnosi']) ?></p></div>
        </div>
        <?php endif; ?>

        <?php if ($p['note_interne'] && isTechnician()): ?>
        <div class="card border-0 shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning bg-opacity-10"><h6 class="mb-0"><i class="bi bi-lock me-2 text-warning"></i>Note Interne</h6></div>
            <div class="card-body"><p class="mb-0" style="white-space:pre-wrap"><?= h($p['note_interne']) ?></p></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0">Stato Corrente</h6></div>
            <div class="card-body">
                <div class="mb-3"><?= getPerifericaStatoBadge($p['stato']) ?></div>
                <dl class="row small mb-0">
                    <dt class="col-sm-5">Registrata da</dt><dd class="col-sm-7"><?= h($p['creator_name'] ?? '-') ?></dd>
                    <dt class="col-sm-5">Creata il</dt><dd class="col-sm-7"><?= formatDate($p['created_at'], 'd/m/Y H:i') ?></dd>
                    <dt class="col-sm-5">Aggiornata</dt><dd class="col-sm-7"><?= formatDate($p['updated_at'], 'd/m/Y H:i') ?></dd>
                </dl>
            </div>
        </div>

        <!-- Flusso visivo -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-diagram-3 me-1"></i>Flusso Riparazione</h6></div>
            <div class="card-body p-2">
                <?php
                $flusso = [
                    'in_giacenza'    => ['label'=>'In Giacenza',   'icon'=>'bi-inbox'],
                    'in_diagnosi'    => ['label'=>'In Diagnosi',   'icon'=>'bi-search'],
                    'in_riparazione' => ['label'=>'In Riparazione','icon'=>'bi-tools'],
                    'riparata'       => ['label'=>'Riparata',      'icon'=>'bi-check-circle'],
                    'restituita'     => ['label'=>'Restituita',    'icon'=>'bi-box-arrow-right'],
                ];
                $statiNorm = array_keys($flusso);
                $statoAttualeIdx = array_search($p['stato'], $statiNorm);
                foreach ($flusso as $s => $info):
                    $idx = array_search($s, $statiNorm);
                    $past    = ($statoAttualeIdx !== false && $idx < $statoAttualeIdx);
                    $current = ($p['stato'] === $s);
                    $future  = ($statoAttualeIdx !== false && $idx > $statoAttualeIdx);
                ?>
                <div class="d-flex align-items-center gap-2 py-1 px-2 rounded mb-1 <?= $current ? 'bg-primary bg-opacity-10' : '' ?>">
                    <i class="bi <?= $info['icon'] ?> <?= $current ? 'text-primary' : ($past ? 'text-success' : 'text-muted') ?>"></i>
                    <span class="small <?= $current ? 'fw-bold text-primary' : ($past ? 'text-success' : 'text-muted') ?>"><?= $info['label'] ?></span>
                    <?php if ($past): ?><i class="bi bi-check2 text-success ms-auto"></i><?php endif; ?>
                    <?php if ($current): ?><span class="badge bg-primary ms-auto small">Ora</span><?php endif; ?>
                </div>
                <?php if ($idx < count($flusso)-1): ?>
                <div class="ms-3 text-muted" style="font-size:.7rem">│</div>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($p['stato'] === 'non_riparabile'): ?>
                <div class="alert alert-danger py-1 px-2 mt-2 small mb-0"><i class="bi bi-x-circle me-1"></i>Non riparabile<?= $p['stato'] === 'rottamata' ? ' → Rottamata' : '' ?></div>
                <?php endif; ?>
                <?php if ($p['stato'] === 'rottamata'): ?>
                <div class="alert alert-dark py-1 px-2 mt-2 small mb-0"><i class="bi bi-trash me-1"></i>Rottamata</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rapportino collegato -->
        <?php if ($p['rapportino_id']): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-file-earmark-text me-1 text-success"></i>Rapportino di Riparazione</h6></div>
            <div class="card-body">
                <p class="small mb-2"><?= h($p['rapportino_title'] ?? 'RAP-'.str_pad($p['rapportino_id'],4,'0',STR_PAD_LEFT)) ?></p>
                <a href="<?= APP_URL ?>/modules/rapportini/view.php?id=<?= $p['rapportino_id'] ?>" class="btn btn-sm btn-outline-success w-100"><i class="bi bi-eye me-1"></i>Visualizza Rapportino</a>
            </div>
        </div>
        <?php elseif (isTechnician() && in_array($p['stato'], ['in_riparazione','riparata'])): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-file-earmark-plus me-1 text-primary"></i>Rapportino</h6></div>
            <div class="card-body">
                <p class="small text-muted mb-2">Nessun rapportino collegato. Crea il rapportino di riparazione per questa periferica.</p>
                <a href="<?= APP_URL ?>/modules/rapportini/create.php?periferica_id=<?= $id ?><?= $p['ticket_id'] ? '&ticket_id='.$p['ticket_id'] : '' ?>" class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-plus me-1"></i>Crea Rapportino</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isTechnician() && !$isFinale): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0">Azioni Rapide</h6></div>
            <div class="card-body d-grid gap-2">
                <a href="<?= APP_URL ?>/modules/periferiche/diagnosi.php?id=<?= $id ?>" class="btn btn-sm btn-warning"><i class="bi bi-arrow-repeat me-1"></i>Aggiorna Stato / Diagnosi</a>
                <a href="<?= APP_URL ?>/modules/periferiche/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Modifica Dati</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
