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
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <meta name="app-url" content="<?= htmlspecialchars(APP_URL) ?>">
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
                </a>
            </li>
            <?php foreach ($enabledModules as $module): ?>
            <?php
                $modUrl = getModuleUrl($module['slug']);
                $modPath = '/modules/' . $module['slug'] . '/';
                $isActive = strpos($currentPath, $modPath) !== false;
                if (in_array($module['slug'], ['users', 'settings']) && !isAdmin()) continue;
            ?>
            <li class="nav-item">
                <a href="<?= h($modUrl) ?>" class="nav-link text-white <?= $isActive ? 'active' : '' ?>">
                    <i class="bi <?= h($module['icon']) ?> me-2"></i><?= h($module['name']) ?>
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
            <?php
            $notifUnread = getUnreadNotificationCount((int)$currentUser['id']);
            ?>
            <!-- Notification Bell -->
            <div class="dropdown me-2" id="notif-dropdown">
                <a href="#" class="btn btn-outline-secondary position-relative notif-bell-btn"
                   data-bs-toggle="dropdown" aria-expanded="false"
                   title="Notifiche">
                    <i class="bi bi-bell"></i>
                    <span id="notif-bell-badge"
                          class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                          <?= $notifUnread === 0 ? 'style="display:none"' : '' ?>>
                        <?= $notifUnread > 99 ? '99+' : $notifUnread ?>
                    </span>
                </a>
                <div class="dropdown-menu dropdown-menu-end shadow notif-dropdown-menu p-0" style="min-width:360px;max-width:420px">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <strong class="small">Notifiche</strong>
                        <a href="<?= APP_URL ?>/modules/notifications/index.php" class="small text-primary text-decoration-none">Vedi tutte</a>
                    </div>
                    <div id="notif-dropdown-list" style="max-height:380px;overflow-y:auto">
                        <div class="text-center text-muted py-3 small" id="notif-loading">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                        </div>
                    </div>
                    <div class="border-top px-3 py-2 d-flex justify-content-between">
                        <button class="btn btn-sm btn-link text-muted p-0" id="notif-mark-all">
                            <i class="bi bi-check2-all me-1"></i>Segna tutto letto
                        </button>
                        <a href="<?= APP_URL ?>/modules/notifications/index.php" class="btn btn-sm btn-link text-primary p-0">
                            Centro notifiche <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            <span class="badge bg-<?= $currentUser['role'] === 'admin' ? 'danger' : ($currentUser['role'] === 'technician' ? 'warning text-dark' : 'primary') ?> ms-2">
                <?= h(ucfirst($currentUser['role'])) ?>
            </span>
        </nav>
        <div class="container-fluid p-4">
<!-- Toast container for real-time notifications -->
<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1100"></div>
<?php else: ?>
<div class="container-fluid">
<?php endif; ?>
