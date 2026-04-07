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

// Stats by status
$byStatus = $db->query("SELECT status, COUNT(*) as cnt FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
// Stats by priority
$byPriority = $db->query("SELECT priority, COUNT(*) as cnt FROM tickets GROUP BY priority")->fetchAll(PDO::FETCH_KEY_PAIR);
// Monthly tickets (last 6 months)
$monthly = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as cnt FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month")->fetchAll();
// Top assigned technicians
$topTech = $db->query("SELECT u.full_name, COUNT(t.id) as cnt FROM tickets t JOIN users u ON t.assigned_to=u.id GROUP BY t.assigned_to ORDER BY cnt DESC LIMIT 5")->fetchAll();
// Low stock count
$lowStock = $db->query("SELECT COUNT(*) FROM spare_parts WHERE quantity <= min_quantity")->fetchColumn();
// Parts requests by status
$reqStats = $db->query("SELECT status, COUNT(*) as cnt FROM spare_parts_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
// Average resolution time (resolved/closed tickets)
$avgTime = $db->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) FROM tickets WHERE closed_at IS NOT NULL")->fetchColumn();

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Report e Statistiche</h4>
</div>

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
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <span class="small"><span class="badge bg-secondary me-2"><?= $i+1 ?></span><?= h($t['full_name']) ?></span>
                        <span class="badge bg-primary"><?= $t['cnt'] ?></span>
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
});
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
