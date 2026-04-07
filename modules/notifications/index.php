<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();

define('PAGE_TITLE', 'Notifiche');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Notifiche' => '']);

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

// Handle mark-all-read via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
    }
    header('Location: ' . APP_URL . '/modules/notifications/index.php');
    exit;
}

// Handle delete-all read via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_read') {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $db->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1')->execute([$uid]);
    }
    header('Location: ' . APP_URL . '/modules/notifications/index.php');
    exit;
}

$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$filter  = $_GET['filter'] ?? 'all'; // all | unread

$whereExtra = $filter === 'unread' ? ' AND is_read = 0' : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?$whereExtra");
$countStmt->execute([$uid]);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare(
    "SELECT * FROM notifications WHERE user_id = ?$whereExtra ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$stmt->execute([$uid, $perPage, $offset]);
$notifications = $stmt->fetchAll();

$unreadCount = getUnreadNotificationCount($uid);

// Notification type → Bootstrap color and icon
$typeMap = [
    'info'     => ['color' => 'info',    'icon' => 'bi-info-circle-fill'],
    'success'  => ['color' => 'success', 'icon' => 'bi-check-circle-fill'],
    'warning'  => ['color' => 'warning', 'icon' => 'bi-exclamation-triangle-fill'],
    'danger'   => ['color' => 'danger',  'icon' => 'bi-x-circle-fill'],
    'ticket'   => ['color' => 'primary', 'icon' => 'bi-ticket-detailed-fill'],
    'comment'  => ['color' => 'secondary','icon' => 'bi-chat-fill'],
    'status'   => ['color' => 'warning', 'icon' => 'bi-arrow-repeat'],
    'assign'   => ['color' => 'info',    'icon' => 'bi-person-fill-check'],
    'part'     => ['color' => 'secondary','icon' => 'bi-tools'],
];

function notifMeta(string $type, array $typeMap): array {
    return $typeMap[$type] ?? ['color' => 'secondary', 'icon' => 'bi-bell-fill'];
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="mb-0">
        <i class="bi bi-bell me-2 text-primary"></i>Centro Notifiche
        <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
        <?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if ($unreadCount > 0): ?>
        <form method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2-all me-1"></i>Segna tutto letto</button>
        </form>
        <?php endif; ?>
        <form method="post" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_read">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Elimina lette</button>
        </form>
    </div>
</div>

<!-- Filter tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">
            Tutte <span class="badge bg-secondary ms-1"><?= $total ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">
            Non lette <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
        </a>
    </li>
</ul>

<?php if ($notifications): ?>
<div class="notification-list">
    <?php foreach ($notifications as $n): ?>
    <?php $meta = notifMeta($n['type'], $typeMap); ?>
    <div class="notification-item card border-0 shadow-sm mb-2 <?= $n['is_read'] ? '' : 'notification-unread' ?>"
         data-notif-id="<?= $n['id'] ?>">
        <div class="card-body d-flex align-items-start gap-3 py-3">
            <div class="notification-icon text-<?= $meta['color'] ?> mt-1">
                <i class="bi <?= $meta['icon'] ?> fs-4"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="fw-semibold<?= $n['is_read'] ? ' text-muted' : '' ?>"><?= h($n['title']) ?></span>
                        <?php if (!$n['is_read']): ?>
                            <span class="badge bg-danger ms-1 align-middle" style="font-size:.6rem">Nuova</span>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted text-nowrap"><?= formatDate($n['created_at'], 'd/m/Y H:i') ?></small>
                </div>
                <?php if ($n['message']): ?>
                <p class="mb-0 text-muted small mt-1"><?= h($n['message']) ?></p>
                <?php endif; ?>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <?php if ($n['url']): ?>
                    <a href="<?= h($n['url']) ?>" class="btn btn-sm btn-outline-primary notif-goto"
                       data-id="<?= $n['id'] ?>"><i class="bi bi-arrow-right me-1"></i>Visualizza</a>
                    <?php endif; ?>
                    <?php if (!$n['is_read']): ?>
                    <button class="btn btn-sm btn-outline-secondary notif-mark-read" data-id="<?= $n['id'] ?>">
                        <i class="bi bi-check me-1"></i>Segna letta
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-danger notif-delete" data-id="<?= $n['id'] ?>">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php else: ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-bell-slash fs-1 d-block mb-3"></i>
    <p class="mb-0">Nessuna notifica<?= $filter === 'unread' ? ' non letta' : '' ?>.</p>
</div>
<?php endif; ?>

<?php
$extraJs = <<<JS
<script>
var notifApiUrl = window.appUrl + '/api/notifications.php';
var csrfToken = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '';

function postNotifAction(data, cb) {
    data.csrf_token = csrfToken;
    $.post(notifApiUrl, data).done(function(r){
        if (typeof r === 'string') r = JSON.parse(r);
        if (r.success && cb) cb(r);
    });
}

// Mark single read
$(document).on('click', '.notif-mark-read', function() {
    var id = $(this).data('id');
    var item = $(this).closest('.notification-item');
    postNotifAction({action:'mark_read', id:id}, function(){
        item.removeClass('notification-unread');
        item.find('.notif-mark-read').remove();
        item.find('.badge.bg-danger.align-middle').remove();
        item.find('.fw-semibold').addClass('text-muted');
        updateBellBadge();
    });
});

// Delete single
$(document).on('click', '.notif-delete', function() {
    var id = $(this).data('id');
    var item = $(this).closest('.notification-item');
    postNotifAction({action:'delete', id:id}, function(){
        item.fadeOut(300, function(){ $(this).remove(); });
        updateBellBadge();
    });
});

// Goto: mark read then navigate
$(document).on('click', '.notif-goto', function(e) {
    var id = $(this).data('id');
    var href = $(this).attr('href');
    e.preventDefault();
    postNotifAction({action:'mark_read', id:id}, function(){
        window.location.href = href;
    });
});

function updateBellBadge() {
    $.get(notifApiUrl, {action:'fetch'}).done(function(r){
        if (typeof r === 'string') r = JSON.parse(r);
        var count = r.unread || 0;
        var badge = $('#notif-bell-badge');
        if (count > 0) { badge.text(count > 99 ? '99+' : count).show(); }
        else { badge.hide(); }
    });
}
</script>
JS;
?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
