<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin', 'technician');
if (!isModuleEnabled('reports')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Report');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Report' => '']);

$db = getDB();

// Date range filter
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$dateWhere  = '1=1';
$dateParams = [];
if ($dateFrom) { $dateWhere .= ' AND DATE(created_at) >= ?'; $dateParams[] = $dateFrom; }
if ($dateTo)   { $dateWhere .= ' AND DATE(created_at) <= ?'; $dateParams[] = $dateTo; }

// Stats by status
$byStatus = $db->prepare("SELECT status, COUNT(*) as cnt FROM tickets WHERE $dateWhere GROUP BY status");
$byStatus->execute($dateParams);
$byStatus = $byStatus->fetchAll(PDO::FETCH_KEY_PAIR);
// Stats by priority
$byPriority = $db->prepare("SELECT priority, COUNT(*) as cnt FROM tickets WHERE $dateWhere GROUP BY priority");
$byPriority->execute($dateParams);
$byPriority = $byPriority->fetchAll(PDO::FETCH_KEY_PAIR);
// Monthly tickets (last 6 months, respecting date filter only if no from/to set)
$monthly = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as cnt FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month")->fetchAll();
// Top assigned technicians
$topTech = $db->prepare("SELECT u.full_name, COUNT(t.id) as cnt FROM tickets t JOIN users u ON t.assigned_to=u.id WHERE $dateWhere GROUP BY t.assigned_to ORDER BY cnt DESC LIMIT 5");
$topTech->execute($dateParams);
$topTech = $topTech->fetchAll();
// Low stock count
$lowStock = $db->query("SELECT COUNT(*) FROM spare_parts WHERE quantity <= min_quantity")->fetchColumn();
// Parts requests by status
$reqStats = $db->query("SELECT status, COUNT(*) as cnt FROM spare_parts_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
// Average resolution time (resolved/closed tickets)
$avgTimeStmt = $db->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) FROM tickets WHERE closed_at IS NOT NULL AND $dateWhere");
$avgTimeStmt->execute($dateParams);
$avgTime = $avgTimeStmt->fetchColumn();

// Tickets per dealer (top 8)
$topDealers = $db->prepare("SELECT d.name, COUNT(t.id) as cnt FROM tickets t JOIN dealers d ON t.dealer_id=d.id WHERE $dateWhere GROUP BY t.dealer_id ORDER BY cnt DESC LIMIT 8");
$topDealers->execute($dateParams);
$topDealers = $topDealers->fetchAll();

// Periferiche stats
$periStats = [];
try {
    $periStats = $db->query("SELECT stato, COUNT(*) as cnt FROM periferiche_guaste GROUP BY stato")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* silent */ }

// Spedizioni stats
$spedStats = [];
try {
    $spedStats = $db->query("SELECT status, COUNT(*) as cnt FROM spedizioni GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* silent */ }

// Handle CSV export for tickets
if (isset($_GET['export']) && $_GET['export'] === 'tickets_csv') {
    $rows = $db->query("SELECT t.id, t.title, t.status, t.priority, uc.full_name as creator, ua.full_name as assignee, c.name as category, d.name as dealer, t.created_at, t.closed_at FROM tickets t LEFT JOIN users ua ON t.assigned_to=ua.id LEFT JOIN users uc ON t.created_by=uc.id LEFT JOIN ticket_categories c ON t.category_id=c.id LEFT JOIN dealers d ON t.dealer_id=d.id ORDER BY t.created_at DESC")->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report_tickets_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['ID','Prefisso','Titolo','Stato','Priorità','Categoria','Creato da','Assegnato a','Concessionario','Creato il','Chiuso il'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            getTicketPrefix().'-'.str_pad($r['id'],4,'0',STR_PAD_LEFT),
            $r['title'], getStatusLabel($r['status']), getPriorityLabel($r['priority']),
            $r['category']??'', $r['creator']??'', $r['assignee']??'', $r['dealer']??'',
            $r['created_at'], $r['closed_at']??'',
        ], ';');
    }
    fclose($out);
    exit;
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Report e Statistiche</h4>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <!-- Date range filter -->
        <form method="get" class="d-flex gap-2 align-items-center">
            <div class="input-group input-group-sm" style="width:auto">
                <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>" title="Da data">
                <span class="input-group-text">→</span>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>" title="A data">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel"></i></button>
                <?php if ($dateFrom || $dateTo): ?>
                <a href="?" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
        <a href="?export=tickets_csv<?= $dateFrom ? '&date_from='.urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to='.urlencode($dateTo) : '' ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download me-1"></i>Esporta CSV
        </a>
    </div>
</div>
<?php if ($dateFrom || $dateTo): ?>
<div class="alert alert-info alert-sm py-2 mb-3"><i class="bi bi-filter me-1"></i>Filtro attivo: <?= $dateFrom ? 'dal '.date('d/m/Y', strtotime($dateFrom)) : '' ?><?= $dateTo ? ' al '.date('d/m/Y', strtotime($dateTo)) : '' ?></div>
<?php endif; ?>

<!-- KPI Row -->
<div class="row g-3 mb-4">
    <?php
    $total = array_sum($byStatus);
    $kpis = [
        ['label'=>'Ticket Totali','value'=>$total,'icon'=>'bi-ticket-detailed','color'=>'primary'],
        ['label'=>'Aperti','value'=>$byStatus['open']??0,'icon'=>'bi-envelope-open','color'=>'info'],
        ['label'=>'In Lavorazione','value'=>$byStatus['in_progress']??0,'icon'=>'bi-gear','color'=>'warning'],
        ['label'=>'Risolti','value'=>($byStatus['resolved']??0)+($byStatus['closed']??0),'icon'=>'bi-check-circle','color'=>'success'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-<?= $k['color'] ?> bg-opacity-10 p-3">
                    <i class="bi <?= $k['icon'] ?> fs-4 text-<?= $k['color'] ?>"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold"><?= $k['value'] ?></div>
                    <div class="text-muted small"><?= $k['label'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <!-- Monthly chart -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-graph-up me-2 text-primary"></i>Ticket per Mese (ultimi 6 mesi)</h6></div>
            <div class="card-body">
                <canvas id="monthlyChart" height="100"></canvas>
            </div>
        </div>
    </div>
    <!-- By status chart -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-pie-chart me-2 text-primary"></i>Distribuzione per Stato</h6></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="statusChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Priority chart -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Ticket per Priorità</h6></div>
            <div class="card-body">
                <canvas id="priorityChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <!-- Top technicians -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-person-check me-2 text-success"></i>Top Tecnici per Ticket</h6></div>
            <div class="card-body p-0">
                <?php if ($topTech): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($topTech as $i => $t): ?>
                    <li class="list-group-item py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small"><span class="badge bg-secondary me-2"><?= $i+1 ?></span><?= h($t['full_name']) ?></span>
                            <span class="badge bg-primary"><?= $t['cnt'] ?></span>
                        </div>
                        <div class="progress mt-1" style="height:3px">
                            <div class="progress-bar" style="width:<?= $topTech[0]['cnt'] > 0 ? round($t['cnt'] / $topTech[0]['cnt'] * 100) : 0 ?>%"></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="text-center text-muted py-4">Nessun dato</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Spare parts & other stats -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-tools me-2 text-secondary"></i>Parti di Ricambio</h6></div>
            <div class="card-body">
                <dl class="row small mb-2">
                    <dt class="col-7">Scorte Basse</dt><dd class="col-5 text-danger fw-bold"><?= (int)$lowStock ?></dd>
                    <dt class="col-7">Richieste In Attesa</dt><dd class="col-5"><?= (int)($reqStats['pending']??0) ?></dd>
                    <dt class="col-7">Richieste Approvate</dt><dd class="col-5 text-success"><?= (int)($reqStats['approved']??0) ?></dd>
                    <dt class="col-7">Richieste Evase</dt><dd class="col-5 text-primary"><?= (int)($reqStats['fulfilled']??0) ?></dd>
                    <?php if ($avgTime): ?>
                    <dt class="col-7">Tempo Medio Risoluz.</dt><dd class="col-5"><?= round($avgTime) ?> ore</dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php if ($topDealers): ?>
<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-shop me-2 text-primary"></i>Ticket per Concessionario (Top <?= count($topDealers) ?>)</h6></div>
            <div class="card-body">
                <canvas id="dealerChart" height="60"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($periStats || $spedStats): ?>
<div class="row g-4 mt-0">
    <?php if ($periStats): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-hdd-network me-2 text-info"></i>Periferiche per Stato</h6></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($periStats as $stato => $cnt): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <span class="small"><?= getPerifericaStatoBadge($stato) ?></span>
                        <span class="badge bg-secondary"><?= (int)$cnt ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($spedStats): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-truck me-2 text-primary"></i>Spedizioni per Stato</h6></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($spedStats as $status => $cnt): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <span class="small"><?= getSpedizioneStatusBadge($status) ?></span>
                        <span class="badge bg-secondary"><?= (int)$cnt ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
const monthlyLabels = <?= json_encode(array_column($monthly, 'month')) ?>;
const monthlyData = <?= json_encode(array_map('intval', array_column($monthly, 'cnt'))) ?>;
const statusLabels = <?= json_encode(array_keys($byStatus)) ?>;
const statusData = <?= json_encode(array_map('intval', array_values($byStatus))) ?>;
const priorityLabels = ['Bassa','Media','Alta','Urgente'];
const priorityData = [
    <?= (int)($byPriority['low']??0) ?>,
    <?= (int)($byPriority['medium']??0) ?>,
    <?= (int)($byPriority['high']??0) ?>,
    <?= (int)($byPriority['urgent']??0) ?>
];
<?php if ($topDealers): ?>
const dealerLabels = <?= json_encode(array_column($topDealers, 'name')) ?>;
const dealerData   = <?= json_encode(array_map('intval', array_column($topDealers, 'cnt'))) ?>;
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: { labels: monthlyLabels, datasets: [{ label: 'Ticket', data: monthlyData, backgroundColor: '#0d6efd80', borderColor: '#0d6efd', borderWidth: 1 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: { labels: statusLabels, datasets: [{ data: statusData, backgroundColor: ['#0d6efd','#ffc107','#6c757d','#198754','#212529'] }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
    new Chart(document.getElementById('priorityChart'), {
        type: 'bar',
        data: { labels: priorityLabels, datasets: [{ data: priorityData, backgroundColor: ['#0dcaf0','#6c757d','#ffc107','#dc3545'] }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
    <?php if ($topDealers): ?>
    new Chart(document.getElementById('dealerChart'), {
        type: 'bar',
        data: {
            labels: dealerLabels,
            datasets: [{
                label: 'Ticket',
                data: dealerData,
                backgroundColor: 'rgba(13,110,253,.6)',
                borderColor: '#0d6efd',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
    <?php endif; ?>
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
