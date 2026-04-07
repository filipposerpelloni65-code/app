<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/modules.php';
startSecureSession();
$currentUser = currentUser();
$appName = defined('APP_NAME') ? APP_NAME : 'HelpDesk';
$enabledModules = getEnabledModules();
$currentScript = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$notifCounts = $currentUser ? getNotificationCounts() : [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= defined('PAGE_TITLE') ? h(PAGE_TITLE) . ' - ' : '' ?><?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>
<?php if ($currentUser): ?>
<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-3 text-white">
        <a href="<?= APP_URL ?>/dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none">
            <i class="bi bi-headset me-2 fs-4"></i>
            <span class="fs-5 fw-semibold"><?= h($appName) ?></span>
        </a>
        <hr>
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/dashboard.php" class="nav-link text-white <?= strpos($currentPath, 'dashboard.php') !== false ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    <?php if (!empty($notifCounts['total']) && $notifCounts['total'] > 0 && strpos($currentPath, 'dashboard.php') === false): ?>
                    <span class="badge bg-danger ms-1"><?= (int)$notifCounts['total'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php foreach ($enabledModules as $module): ?>
            <?php
                $modUrl = getModuleUrl($module['slug']);
                $modPath = '/modules/' . $module['slug'] . '/';
                $isActive = strpos($currentPath, $modPath) !== false;
                if (in_array($module['slug'], ['users', 'settings']) && !isAdmin()) continue;
                // Badge counts per module
                $modBadge = '';
                if ($module['slug'] === 'tickets' && !empty($notifCounts['open_tickets'])) {
                    $modBadge = '<span class="badge bg-primary ms-1">' . (int)$notifCounts['open_tickets'] . '</span>';
                } elseif ($module['slug'] === 'spare_parts' && !empty($notifCounts['pending_requests'])) {
                    $modBadge = '<span class="badge bg-warning text-dark ms-1">' . (int)$notifCounts['pending_requests'] . '</span>';
                } elseif ($module['slug'] === 'periferiche' && !empty($notifCounts['periferiche_active'])) {
                    $modBadge = '<span class="badge bg-info text-dark ms-1">' . (int)$notifCounts['periferiche_active'] . '</span>';
                }
            ?>
            <li class="nav-item">
                <a href="<?= h($modUrl) ?>" class="nav-link text-white <?= $isActive ? 'active' : '' ?>">
                    <i class="bi <?= h($module['icon']) ?> me-2"></i><?= h($module['name']) ?><?= $modBadge ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <hr>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                <div class="user-avatar me-2"><?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?></div>
                <strong class="text-truncate" style="max-width:120px"><?= h($currentUser['full_name']) ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow">
                <li><span class="dropdown-item-text text-muted small"><?= h($currentUser['email']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Esci</a></li>
            </ul>
        </div>
    </nav>
    <!-- Page Content -->
    <div id="page-content-wrapper" class="flex-grow-1">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm px-3">
            <button id="sidebarToggle" class="btn btn-outline-secondary me-3">
                <i class="bi bi-list"></i>
            </button>
            <nav aria-label="breadcrumb" class="flex-grow-1">
                <ol class="breadcrumb mb-0">
                    <?php if (defined('BREADCRUMB')): ?>
                        <?php foreach (BREADCRUMB as $label => $url): ?>
                            <?php if ($url): ?>
                                <li class="breadcrumb-item"><a href="<?= h($url) ?>"><?= h($label) ?></a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item active"><?= h($label) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ol>
            </nav>
            <!-- Global Search -->
            <div class="me-2 d-none d-md-block position-relative" style="width:260px">
                <div class="input-group input-group-sm">
                    <input type="text" id="globalSearchInput" class="form-control" placeholder="Cerca ticket, ricambi, periferiche...">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                </div>
                <div id="globalSearchResults" class="position-absolute bg-white border rounded shadow-sm w-100" style="z-index:1050;display:none;max-height:320px;overflow-y:auto;top:calc(100% + 4px);left:0;"></div>
            </div>
            <span class="badge bg-<?= $currentUser['role'] === 'admin' ? 'danger' : ($currentUser['role'] === 'technician' ? 'warning text-dark' : 'primary') ?> ms-2">
                <?= h(ucfirst($currentUser['role'])) ?>
            </span>
        </nav>
        <div class="container-fluid p-4">
        <script>window.appUrl = '<?= APP_URL ?>';</script>
<?php else: ?>
<div class="container-fluid">
<?php endif; ?>
