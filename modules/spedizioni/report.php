<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db   = getDB();
$user = currentUser();

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterTicketStatus = trim($_GET['ticket_status'] ?? '');
$filterSped         = trim($_GET['sped_status']   ?? '');
$filterDealerId     = (int)($_GET['dealer_id'] ?? 0);
$filterAssignedTo   = (int)($_GET['assigned_to'] ?? 0);
$filterDateFrom     = trim($_GET['date_from'] ?? '');
$filterDateTo       = trim($_GET['date_to']   ?? '');
$filterCorriere     = trim($_GET['corriere']   ?? '');

/* ── Build WHERE for the spedizioni query ─────────────────────────────────── */
$where  = ['1=1'];
$params = [];

if ($filterTicketStatus) { $where[] = 't.status=?';               $params[] = $filterTicketStatus; }
if ($filterSped)         { $where[] = 's.status=?';               $params[] = $filterSped; }
if ($filterDealerId)     { $where[] = '(s.dealer_id=? OR t.dealer_id=?)'; $params[] = $filterDealerId; $params[] = $filterDealerId; }
if ($filterAssignedTo)   { $where[] = 't.assigned_to=?';          $params[] = $filterAssignedTo; }
if ($filterDateFrom)     { $where[] = 'DATE(s.created_at)>=?';    $params[] = $filterDateFrom; }
if ($filterDateTo)       { $where[] = 'DATE(s.created_at)<=?';    $params[] = $filterDateTo; }
if ($filterCorriere)     { $where[] = 's.corriere LIKE ?';        $params[] = '%' . $filterCorriere . '%'; }

// We always require a linked ticket (reports are ticket-centric)
$where[] = 's.ticket_id IS NOT NULL';

$whereStr = implode(' AND ', $where);

/* ── Main query: one row per spedizione, with all ticket/dealer info ─────── */
$stmt = $db->prepare("
    SELECT
        s.id           AS sped_id,
        s.tracking_number,
        s.corriere,
        s.status       AS sped_status,
        s.data_spedizione,
        s.data_consegna_prevista,
        s.note         AS sped_note,
        s.created_at   AS sped_created,
        t.id           AS ticket_id,
        t.title        AS ticket_title,
        t.status       AS ticket_status,
        t.priority     AS ticket_priority,
        t.created_at   AS ticket_created,
        t.updated_at   AS ticket_updated,
        u_assign.full_name  AS assignee_name,
        u_create.full_name  AS creator_name,
        COALESCE(d_s.name, d_t.name)   AS dealer_name,
        COALESCE(dl_s.name, dl_t.name) AS location_name,
        COALESCE(d_s.city,  d_t.city)  AS dealer_city,
        sp.name  AS part_name,
        sp.sku   AS part_sku,
        spr.quantity AS part_qty
    FROM spedizioni s
    JOIN tickets t ON s.ticket_id = t.id
    LEFT JOIN users u_assign ON t.assigned_to    = u_assign.id
    LEFT JOIN users u_create ON t.created_by     = u_create.id
    LEFT JOIN dealers d_s             ON s.dealer_id          = d_s.id
    LEFT JOIN dealer_locations dl_s   ON s.location_id        = dl_s.id
    LEFT JOIN dealers d_t             ON t.dealer_id          = d_t.id
    LEFT JOIN dealer_locations dl_t   ON t.location_id        = dl_t.id
    LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
    LEFT JOIN spare_parts sp           ON spr.part_id          = sp.id
    WHERE $whereStr
    ORDER BY t.id DESC, s.created_at ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* ── CSV export ───────────────────────────────────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'report_spedizioni_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    // BOM for Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, [
        'Ticket #', 'Titolo Ticket', 'Stato Ticket', 'Priorità',
        'Assegnato a', 'Creato da', 'Concessionario', 'Punto Vendita', 'Città',
        'Spedizione #', 'Tracking', 'Corriere', 'Stato Spedizione',
        'Data Spedizione', 'Consegna Prevista',
        'Ricambio', 'SKU', 'Qtà',
        'Note Spedizione', 'Data Creazione Spedizione'
    ], ';');
    $prefix = getTicketPrefix();
    foreach ($rows as $r) {
        fputcsv($out, [
            $prefix . '-' . str_pad($r['ticket_id'], 4, '0', STR_PAD_LEFT),
            $r['ticket_title'],
            $r['ticket_status'],
            $r['ticket_priority'],
            $r['assignee_name'] ?? '',
            $r['creator_name'] ?? '',
            $r['dealer_name'] ?? '',
            $r['location_name'] ?? '',
            $r['dealer_city'] ?? '',
            '#' . $r['sped_id'],
            $r['tracking_number'] ?? '',
            $r['corriere'] ?? '',
            $r['sped_status'],
            $r['data_spedizione'] ? date('d/m/Y', strtotime($r['data_spedizione'])) : '',
            $r['data_consegna_prevista'] ? date('d/m/Y', strtotime($r['data_consegna_prevista'])) : '',
            $r['part_name'] ?? '',
            $r['part_sku'] ?? '',
            $r['part_qty'] ?? '',
            $r['sped_note'] ?? '',
            date('d/m/Y H:i', strtotime($r['sped_created'])),
        ], ';');
    }
    fclose($out);
    exit;
}

/* ── Group rows by ticket_id for the HTML table ───────────────────────────── */
$byTicket = [];
foreach ($rows as $r) {
    $tid = $r['ticket_id'];
    if (!isset($byTicket[$tid])) {
        $byTicket[$tid] = ['ticket' => $r, 'spedizioni' => []];
    }
    $byTicket[$tid]['spedizioni'][] = $r;
}

/* ── Filter dropdowns ─────────────────────────────────────────────────────── */
$dealers     = $db->query("SELECT id, name FROM dealers WHERE active=1 ORDER BY name")->fetchAll();
$technicians = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin','technician') AND active=1 ORDER BY full_name")->fetchAll();
$corrieri    = $db->query("SELECT DISTINCT corriere FROM spedizioni WHERE corriere IS NOT NULL ORDER BY corriere")->fetchAll(PDO::FETCH_COLUMN);

/* ── Summary stats ───────────────────────────────────────────────────────── */
$totalTickets   = count($byTicket);
$totalSped      = count($rows);
$bySpedStatus   = array_count_values(array_column($rows, 'sped_status'));
$byTicketStatus = array_count_values(array_column($rows, 'ticket_status'));

define('PAGE_TITLE', 'Report Spedizioni Tecnici');
define('BREADCRUMB', [
    'Dashboard'  => APP_URL . '/dashboard.php',
    'Spedizioni' => APP_URL . '/modules/spedizioni/index.php',
    'Report Tecnici' => '',
]);

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>Report Spedizioni per Tecnici</h4>
        <p class="text-muted small mb-0 mt-1">Riepilogo ticket con tracking spedizioni — pianificazione interventi</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Stampa
        </button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm">
            <i class="bi bi-download me-1"></i>Esporta CSV
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1">Stato Ticket</label>
                <select name="ticket_status" class="form-select form-select-sm">
                    <option value="">Tutti</option>
                    <option value="open"        <?= $filterTicketStatus === 'open'        ? 'selected' : '' ?>>Aperto</option>
                    <option value="in_progress" <?= $filterTicketStatus === 'in_progress' ? 'selected' : '' ?>>In Lavorazione</option>
                    <option value="waiting"     <?= $filterTicketStatus === 'waiting'     ? 'selected' : '' ?>>In Attesa</option>
                    <option value="resolved"    <?= $filterTicketStatus === 'resolved'    ? 'selected' : '' ?>>Risolto</option>
                    <option value="closed"      <?= $filterTicketStatus === 'closed'      ? 'selected' : '' ?>>Chiuso</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1">Stato Spedizione</label>
                <select name="sped_status" class="form-select form-select-sm">
                    <option value="">Tutti</option>
                    <option value="da_spedire" <?= $filterSped === 'da_spedire' ? 'selected' : '' ?>>Da Spedire</option>
                    <option value="spedita"    <?= $filterSped === 'spedita'    ? 'selected' : '' ?>>Spedita</option>
                    <option value="consegnata" <?= $filterSped === 'consegnata' ? 'selected' : '' ?>>Consegnata</option>
                    <option value="annullata"  <?= $filterSped === 'annullata'  ? 'selected' : '' ?>>Annullata</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1">Concessionario</label>
                <select name="dealer_id" class="form-select form-select-sm">
                    <option value="">Tutti</option>
                    <?php foreach ($dealers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filterDealerId == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1">Tecnico Assegnato</label>
                <select name="assigned_to" class="form-select form-select-sm">
                    <option value="">Tutti</option>
                    <?php foreach ($technicians as $tech): ?>
                    <option value="<?= $tech['id'] ?>" <?= $filterAssignedTo == $tech['id'] ? 'selected' : '' ?>><?= h($tech['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1">Corriere</label>
                <select name="corriere" class="form-select form-select-sm">
                    <option value="">Tutti</option>
                    <?php foreach ($corrieri as $c): ?>
                    <option value="<?= h($c) ?>" <?= $filterCorriere === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm fw-semibold mb-1">Dal</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($filterDateFrom) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm fw-semibold mb-1">Al</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($filterDateTo) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtra</button>
                <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?= $totalTickets ?></div>
            <div class="text-muted small">Ticket con spedizioni</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-secondary"><?= $totalSped ?></div>
            <div class="text-muted small">Spedizioni totali</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?= $bySpedStatus['da_spedire'] ?? 0 ?></div>
            <div class="text-muted small">Da spedire</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= $bySpedStatus['consegnata'] ?? 0 ?></div>
            <div class="text-muted small">Consegnate</div>
        </div>
    </div>
</div>

<!-- Print header (only visible when printing) -->
<div class="print-only mb-4">
    <h3 class="mb-1">Report Spedizioni – Pianificazione Interventi Tecnici</h3>
    <p class="text-muted mb-0">Generato il <?= date('d/m/Y H:i') ?> da <?= h($user['full_name']) ?>
    <?php if ($filterTicketStatus || $filterSped || $filterDealerId || $filterDateFrom): ?>
    — Filtri attivi<?php endif; ?></p>
    <hr>
</div>

<?php if ($byTicket): ?>
<!-- Report table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center no-print">
        <h6 class="mb-0"><i class="bi bi-list-check me-2 text-primary"></i><?= $totalTickets ?> ticket trovati (<?= $totalSped ?> spedizioni)</h6>
        <small class="text-muted">Ordinati per ticket (dal più recente)</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0 report-table">
                <thead class="table-dark">
                    <tr>
                        <th style="min-width:95px">Ticket</th>
                        <th style="min-width:180px">Titolo</th>
                        <th>Stato Ticket</th>
                        <th>Priorità</th>
                        <th style="min-width:120px">Tecnico</th>
                        <th style="min-width:130px">Concessionario</th>
                        <th class="text-center" style="min-width:120px">Tracking</th>
                        <th>Corriere</th>
                        <th>Stato Sped.</th>
                        <th style="min-width:100px">Data Sped.</th>
                        <th style="min-width:110px">Consegna Prev.</th>
                        <th>Ricambio</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $prefix = getTicketPrefix();
                foreach ($byTicket as $tid => $group):
                    $t   = $group['ticket'];
                    $sps = $group['spedizioni'];
                    $rowCount = count($sps);
                    $first = true;
                    foreach ($sps as $s):
                ?>
                <tr class="<?= in_array($t['ticket_status'], ['open','in_progress']) && ($s['sped_status'] === 'spedita') ? 'table-success' : '' ?>
                           <?= $s['sped_status'] === 'da_spedire' ? 'table-warning' : '' ?>">
                    <?php if ($first): ?>
                    <td rowspan="<?= $rowCount ?>" class="fw-bold font-monospace align-middle">
                        <a href="<?= APP_URL ?>/modules/tickets/view.php?id=<?= $tid ?>" class="text-decoration-none text-primary" target="_blank">
                            <?= h($prefix . '-' . str_pad($tid, 4, '0', STR_PAD_LEFT)) ?>
                        </a>
                    </td>
                    <td rowspan="<?= $rowCount ?>" class="align-middle small fw-semibold">
                        <?= h($t['ticket_title']) ?>
                        <?php if ($t['dealer_city']): ?>
                        <div class="text-muted fw-normal"><i class="bi bi-geo-alt"></i> <?= h($t['dealer_city']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td rowspan="<?= $rowCount ?>" class="align-middle"><?= getStatusBadge($t['ticket_status']) ?></td>
                    <td rowspan="<?= $rowCount ?>" class="align-middle"><?= getPriorityBadge($t['ticket_priority']) ?></td>
                    <td rowspan="<?= $rowCount ?>" class="align-middle small"><?= $t['assignee_name'] ? h($t['assignee_name']) : '<span class="text-muted">-</span>' ?></td>
                    <td rowspan="<?= $rowCount ?>" class="align-middle small">
                        <?= $t['dealer_name'] ? h($t['dealer_name']) : '<span class="text-muted">-</span>' ?>
                        <?php if ($t['location_name']): ?><div class="text-muted"><?= h($t['location_name']) ?></div><?php endif; ?>
                    </td>
                    <?php $first = false; ?>
                    <?php endif; ?>

                    <!-- Spedizione columns (repeat for each) -->
                    <td class="text-center">
                        <?php if ($s['tracking_number']): ?>
                        <a href="<?= APP_URL ?>/modules/spedizioni/view.php?id=<?= $s['sped_id'] ?>"
                           class="fw-bold font-monospace text-decoration-none text-dark" target="_blank"
                           title="Apri spedizione #<?= $s['sped_id'] ?>">
                            <?= h($s['tracking_number']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-muted small fst-italic">N/D</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= $s['corriere'] ? h($s['corriere']) : '<span class="text-muted">-</span>' ?></td>
                    <td><?= getSpedizioneStatusBadge($s['sped_status']) ?></td>
                    <td class="small text-nowrap">
                        <?= $s['data_spedizione'] ? '<i class="bi bi-truck me-1 text-primary"></i>' . date('d/m/Y', strtotime($s['data_spedizione'])) : '<span class="text-muted">-</span>' ?>
                    </td>
                    <td class="small text-nowrap">
                        <?php if ($s['data_consegna_prevista']): ?>
                        <?php $consegna = strtotime($s['data_consegna_prevista']); $today = time(); ?>
                        <span class="<?= ($s['sped_status'] !== 'consegnata' && $consegna < $today) ? 'text-danger fw-semibold' : '' ?>">
                            <i class="bi bi-calendar-check me-1"></i><?= date('d/m/Y', $consegna) ?>
                        </span>
                        <?php if ($s['sped_status'] !== 'consegnata' && $consegna < $today): ?>
                        <span class="badge bg-danger ms-1" style="font-size:.6rem">Scaduta</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($s['part_name']): ?>
                        <?= h($s['part_name']) ?>
                        <?php if ($s['part_sku']): ?><div class="text-muted font-monospace"><?= h($s['part_sku']) ?></div><?php endif; ?>
                        <?php if ($s['part_qty']): ?><div class="text-muted">Qtà: <?= (int)$s['part_qty'] ?></div><?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white text-muted small no-print">
        <?= $totalTickets ?> ticket · <?= $totalSped ?> spedizioni ·
        <?php foreach (['da_spedire' => 'Da spedire', 'spedita' => 'Spedite', 'consegnata' => 'Consegnate', 'annullata' => 'Annullate'] as $sk => $sl): ?>
        <span class="me-3"><?= $sl ?>: <strong><?= $bySpedStatus[$sk] ?? 0 ?></strong></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Legend -->
<div class="mt-3 d-flex gap-3 flex-wrap no-print">
    <span class="small text-muted"><span class="badge text-bg-success">&nbsp;</span> Spedita → ticket attivo</span>
    <span class="small text-muted"><span class="badge text-bg-warning">&nbsp;</span> Da spedire</span>
    <span class="small text-muted"><span class="badge bg-danger">Scaduta</span> Consegna prevista superata</span>
</div>

<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-truck fs-1 d-block mb-3 opacity-50"></i>
        <p class="mb-2">Nessuna spedizione trovata con i filtri selezionati.</p>
        <a href="?" class="btn btn-sm btn-outline-secondary">Rimuovi filtri</a>
    </div>
</div>
<?php endif; ?>

<style>
/* Print styles */
@media print {
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    #sidebar, #sidebarToggle, .navbar, .breadcrumb { display: none !important; }
    #page-content-wrapper { padding: 0 !important; }
    .container-fluid { padding: 0.5rem !important; }
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
    .report-table th, .report-table td { font-size: 9pt !important; padding: 4px 6px !important; }
    a { color: #000 !important; text-decoration: none !important; }
    .badge { border: 1px solid #999; color: #000 !important; background: none !important; }
}
@media screen {
    .print-only { display: none; }
}
.report-table th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: .03em; }
.report-table td { vertical-align: middle; }
</style>

<?php include APP_ROOT . '/includes/footer.php'; ?>
