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

// ── Apply timezone from settings ──────────────────────────────────────────
$_tz = getSetting('app_timezone', 'Europe/Rome');
if ($_tz && in_array($_tz, DateTimeZone::listIdentifiers())) {
    date_default_timezone_set($_tz);
}

// ── Load theme settings ───────────────────────────────────────────────────
$_themePrimary       = getSetting('theme_primary_color', '#3b82f6');
$_themeSidebarTop    = getSetting('theme_sidebar_top', '#0f172a');
$_themeSidebarBottom = getSetting('theme_sidebar_bottom', '#1a1f35');
$_themeBg            = getSetting('theme_bg_color', '#f1f5f9');
$_themeRadius        = getSetting('theme_radius', 'md');
$_themeFontSize      = getSetting('theme_font_size', 'default');
$_companyLogoUrl     = getSetting('company_logo_url', '');

$_radiusMap = ['none' => '0px', 'sm' => '8px', 'md' => '12px', 'lg' => '16px', 'xl' => '24px'];
$_radiusVal = $_radiusMap[$_themeRadius] ?? '12px';
$_fontSizeMap = ['small' => '0.8rem', 'default' => '0.9rem', 'large' => '1rem'];
$_fontSizeVal = $_fontSizeMap[$_themeFontSize] ?? '0.9rem';

// Validate colors (must be hex to avoid XSS)
function _validHex(string $c, string $fallback): string {
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $fallback;
}
$_themePrimary       = _validHex($_themePrimary, '#3b82f6');
$_themeSidebarTop    = _validHex($_themeSidebarTop, '#0f172a');
$_themeSidebarBottom = _validHex($_themeSidebarBottom, '#1a1f35');
$_themeBg            = _validHex($_themeBg, '#f1f5f9');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <meta name="app-url" content="<?= htmlspecialchars(APP_URL) ?>">
    <title><?= defined('PAGE_TITLE') ? h(PAGE_TITLE) . ' - ' : '' ?><?= h($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <style>
        :root {
            --color-primary:     <?= $_themePrimary ?>;
            --sidebar-bg:        <?= $_themeSidebarTop ?>;
            --color-bg:          <?= $_themeBg ?>;
            --radius-sm:         <?= $_radiusVal ?>;
            --radius-md:         <?= $_radiusVal ?>;
            --radius-lg:         <?= $_radiusVal ?>;
        }
        body { font-size: <?= $_fontSizeVal ?>; }
        .sidebar { background: linear-gradient(180deg, <?= $_themeSidebarTop ?> 0%, <?= $_themeSidebarBottom ?> 100%) !important; }
        .sidebar .nav-link.active { background-color: <?= $_themePrimary ?> !important; }
        .btn-primary { background-color: <?= $_themePrimary ?>; border-color: <?= $_themePrimary ?>; }
        .btn-primary:hover { filter: brightness(0.9); }
        .btn-outline-primary { color: <?= $_themePrimary ?>; border-color: <?= $_themePrimary ?>; }
        .btn-outline-primary:hover { background-color: <?= $_themePrimary ?>; border-color: <?= $_themePrimary ?>; color:#fff; }
        .text-primary { color: <?= $_themePrimary ?> !important; }
        .badge.bg-primary { background-color: <?= $_themePrimary ?> !important; }
        .nav-link.active[data-bs-toggle="tab"] { color: <?= $_themePrimary ?> !important; border-bottom-color: <?= $_themePrimary ?> !important; }
        .form-check-input:checked { background-color: <?= $_themePrimary ?>; border-color: <?= $_themePrimary ?>; }
        a { color: <?= $_themePrimary ?>; }
        a:hover { color: <?= $_themePrimary ?>; filter: brightness(0.8); }
        .card { border-radius: <?= $_radiusVal ?> !important; }
        .card-header { border-radius: <?= $_radiusVal ?> <?= $_radiusVal ?> 0 0 !important; }
    </style>
</head>
<body>
<?php if ($currentUser): ?>
<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-3 text-white">
        <a href="<?= APP_URL ?>/dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none">
            <?php if (!empty($_companyLogoUrl)): ?>
            <img src="<?= h($_companyLogoUrl) ?>" alt="Logo" style="max-height:32px;max-width:40px;object-fit:contain;" class="me-2">
            <?php else: ?>
            <i class="bi bi-headset me-2 fs-4"></i>
            <?php endif; ?>
            <span class="fs-5 fw-semibold"><?= h($appName) ?></span>
        </a>
        <hr>
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/dashboard.php" class="nav-link text-white <?= strpos($currentPath, 'dashboard.php') !== false ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    <?php if (($notifCounts['open_tickets'] ?? 0) > 0): ?>
                    <span class="badge bg-primary ms-auto"><?= $notifCounts['open_tickets'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php foreach ($enabledModules as $module): ?>
            <?php
                $modUrl = getModuleUrl($module['slug']);
                $modPath = '/modules/' . $module['slug'] . '/';
                $isActive = strpos($currentPath, $modPath) !== false;
                if (in_array($module['slug'], ['users', 'settings']) && !isAdmin()) continue;
                // Notification badge per modulo
                $badge = '';
                if ($module['slug'] === 'spare_parts' && ($notifCounts['pending_parts'] ?? 0) > 0) {
                    $badge = '<span class="badge bg-warning text-dark ms-auto">' . $notifCounts['pending_parts'] . '</span>';
                } elseif ($module['slug'] === 'spedizioni' && ($notifCounts['da_spedire'] ?? 0) > 0) {
                    $badge = '<span class="badge bg-primary ms-auto">' . $notifCounts['da_spedire'] . '</span>';
                } elseif ($module['slug'] === 'periferiche' && ($notifCounts['periferiche_wait'] ?? 0) > 0) {
                    $badge = '<span class="badge bg-info text-dark ms-auto">' . $notifCounts['periferiche_wait'] . '</span>';
                }
            ?>
            <li class="nav-item">
                <a href="<?= h($modUrl) ?>" class="nav-link text-white <?= $isActive ? 'active' : '' ?>">
                    <i class="bi <?= h($module['icon']) ?> me-2"></i><?= h($module['name']) ?><?= $badge ?>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if (isAdmin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/modules/activity_log/index.php" class="nav-link text-white <?= strpos($currentPath, '/activity_log/') !== false ? 'active' : '' ?>">
                    <i class="bi bi-journal-text me-2"></i>Log Attività
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/modules/profile/index.php" class="nav-link text-white <?= strpos($currentPath, '/profile/') !== false ? 'active' : '' ?>">
                    <i class="bi bi-person-circle me-2"></i>Profilo
                </a>
            </li>
        </ul>
        <hr>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                <div class="user-avatar me-2"><?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?></div>
                <strong class="text-truncate" style="max-width:120px"><?= h($currentUser['full_name']) ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow">
                <li><span class="dropdown-item-text text-muted small"><?= h($currentUser['email'] ?? $currentUser['username']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/profile/index.php"><i class="bi bi-person-circle me-2"></i>Il mio profilo</a></li>
                <?php if (isAdmin()): ?>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/activity_log/index.php"><i class="bi bi-journal-text me-2"></i>Log Attività</a></li>
                <?php endif; ?>
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
            <!-- Global search -->
            <form class="d-none d-md-flex me-2 position-relative" id="globalSearchForm" autocomplete="off">
                <div class="input-group input-group-sm" style="width:240px">
                    <input type="search" class="form-control" id="globalSearchInput" placeholder="Cerca..." aria-label="Cerca">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                </div>
                <div id="searchResults" class="dropdown-menu shadow-sm p-0" style="display:none;min-width:320px;position:absolute;top:100%;right:0;z-index:1050"></div>
            </form>
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
