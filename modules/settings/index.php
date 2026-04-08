<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('settings')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Impostazioni');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Impostazioni' => '']);

$db = getDB();
$user = currentUser();
$success = '';
$errors = [];

// ── Helper: save a list of setting keys from POST ──────────────────────────
function saveSettings(PDO $db, array $keys, array $post, array $checkboxes = []): void {
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
    foreach ($keys as $k) {
        $v = trim($post[$k] ?? '');
        $stmt->execute([$k, $v, $v]);
    }
    foreach ($checkboxes as $k) {
        $v = isset($post[$k]) ? '1' : '0';
        $stmt->execute([$k, $v, $v]);
    }
}

// ── POST handlers ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Riprova.';
    } else {

        if ($section === 'general') {
            $fields = ['company_name','ticket_prefix','items_per_page','email_notifications','smtp_host','smtp_port','smtp_user','auto_close_days','auto_close_secret','auto_assign'];
            saveSettings($db, $fields, $_POST);
            if (!empty($_POST['smtp_pass'])) {
                $v = $_POST['smtp_pass'];
                $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute(['smtp_pass',$v,$v]);
            }
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Impostazioni generali aggiornate');
            $success = 'Impostazioni generali salvate.';
        }

        elseif ($section === 'appearance') {
            $fields = ['theme_primary_color','theme_sidebar_top','theme_sidebar_bottom','theme_bg_color','theme_radius','theme_font_size','company_logo_url'];
            saveSettings($db, $fields, $_POST);
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Impostazioni aspetto aggiornate');
            $success = 'Impostazioni aspetto salvate.';
        }

        elseif ($section === 'company') {
            $fields = ['company_address','company_city','company_vat','company_phone','company_email','pdf_footer_text'];
            saveSettings($db, $fields, $_POST);
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Informazioni azienda aggiornate');
            $success = 'Informazioni azienda salvate.';
        }

        elseif ($section === 'ticket_settings') {
            $fields = ['ticket_default_priority','ticket_max_file_mb','ticket_allowed_ext'];
            $checks = ['ticket_tech_create'];
            saveSettings($db, $fields, $_POST, $checks);
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Impostazioni ticket aggiornate');
            $success = 'Impostazioni ticket salvate.';
        }

        elseif ($section === 'security') {
            $fields = ['session_timeout_mins','min_password_length','max_login_attempts','login_lockout_mins'];
            saveSettings($db, $fields, $_POST);
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Impostazioni sicurezza aggiornate');
            $success = 'Impostazioni sicurezza salvate.';
        }

        elseif ($section === 'locale') {
            $fields = ['app_timezone','date_format','currency_symbol'];
            saveSettings($db, $fields, $_POST);
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Impostazioni localizzazione aggiornate');
            $success = 'Impostazioni localizzazione salvate.';
        }

        elseif ($section === 'notif_triggers') {
            $checks = ['notif_new_ticket','notif_ticket_assign','notif_ticket_comment','notif_ticket_resolved'];
            saveSettings($db, [], $_POST, $checks);
            logActivity($user['id'], 'settings_update', 'settings', 0, 'Trigger notifiche aggiornati');
            $success = 'Trigger notifiche salvati.';
        }

        elseif ($section === 'modules') {
            foreach ($db->query("SELECT id, slug FROM modules")->fetchAll() as $m) {
                $enabled = isset($_POST['module_'.$m['slug']]) ? 1 : 0;
                $db->prepare("UPDATE modules SET enabled=? WHERE id=?")->execute([$enabled, $m['id']]);
            }
            logActivity($user['id'], 'settings_update', 'modules', 0, 'Moduli aggiornati');
            $success = 'Moduli aggiornati.';
        }

        elseif ($section === 'add_cat') {
            if (trim($_POST['cat_name'] ?? '')) {
                $db->prepare("INSERT INTO ticket_categories (name, description) VALUES (?,?)")->execute([trim($_POST['cat_name']), trim($_POST['cat_desc']??'')]);
                $success = 'Categoria aggiunta.';
            }
        }

        elseif ($section === 'del_cat') {
            $db->prepare("DELETE FROM ticket_categories WHERE id=?")->execute([(int)$_POST['cat_id']]);
            $success = 'Categoria eliminata.';
        }

        elseif ($section === 'add_pcat') {
            if (trim($_POST['pcat_name'] ?? '')) {
                $db->prepare("INSERT INTO spare_parts_categories (name, description) VALUES (?,?)")->execute([trim($_POST['pcat_name']), trim($_POST['pcat_desc']??'')]);
                $success = 'Categoria ricambi aggiunta.';
            }
        }
    }
}

$settingsRaw = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings = [];
foreach ($settingsRaw as $s) { $settings[$s['setting_key']] = $s['setting_value']; }

// Defaults for new settings (in case migration hasn't run yet)
$settingDefaults = [
    'theme_primary_color'     => '#3b82f6',
    'theme_sidebar_top'       => '#0f172a',
    'theme_sidebar_bottom'    => '#1a1f35',
    'theme_bg_color'          => '#f1f5f9',
    'theme_radius'            => 'md',
    'theme_font_size'         => 'default',
    'company_logo_url'        => '',
    'company_address'         => '',
    'company_city'            => '',
    'company_vat'             => '',
    'company_phone'           => '',
    'company_email'           => '',
    'pdf_footer_text'         => '',
    'ticket_default_priority' => 'medium',
    'ticket_max_file_mb'      => '10',
    'ticket_allowed_ext'      => 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip',
    'ticket_tech_create'      => '1',
    'session_timeout_mins'    => '0',
    'min_password_length'     => '8',
    'max_login_attempts'      => '0',
    'login_lockout_mins'      => '15',
    'app_timezone'            => 'Europe/Rome',
    'date_format'             => 'd/m/Y H:i',
    'currency_symbol'         => '€',
    'notif_new_ticket'        => '1',
    'notif_ticket_assign'     => '1',
    'notif_ticket_comment'    => '1',
    'notif_ticket_resolved'   => '1',
];
foreach ($settingDefaults as $k => $v) {
    if (!isset($settings[$k])) $settings[$k] = $v;
}

$allModules = $db->query("SELECT * FROM modules ORDER BY sort_order")->fetchAll();
$ticketCats = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$partsCats  = $db->query("SELECT * FROM spare_parts_categories ORDER BY name")->fetchAll();

// Determine active tab from POST or default
$activeTab = 'tab-general';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sectionTabMap = [
        'general'        => 'tab-general',
        'appearance'     => 'tab-appearance',
        'company'        => 'tab-company',
        'ticket_settings'=> 'tab-ticket',
        'security'       => 'tab-security',
        'locale'         => 'tab-locale',
        'notif_triggers' => 'tab-notif',
        'modules'        => 'tab-modules',
        'add_cat'        => 'tab-cats',
        'del_cat'        => 'tab-cats',
        'add_pcat'       => 'tab-pcats',
    ];
    $activeTab = $sectionTabMap[$_POST['section'] ?? ''] ?? 'tab-general';
}

include APP_ROOT . '/includes/header.php';
?>

<div class="mb-4"><h4 class="mb-0"><i class="bi bi-gear me-2 text-primary"></i>Impostazioni Sistema</h4></div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4 flex-wrap" id="settingsTabs">
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-general'?'active':'' ?>" data-bs-toggle="tab" href="#tab-general"><i class="bi bi-sliders me-1"></i>Generali</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-appearance'?'active':'' ?>" data-bs-toggle="tab" href="#tab-appearance"><i class="bi bi-palette me-1"></i>Aspetto</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-company'?'active':'' ?>" data-bs-toggle="tab" href="#tab-company"><i class="bi bi-building me-1"></i>Azienda</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-ticket'?'active':'' ?>" data-bs-toggle="tab" href="#tab-ticket"><i class="bi bi-ticket-detailed me-1"></i>Ticket</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-security'?'active':'' ?>" data-bs-toggle="tab" href="#tab-security"><i class="bi bi-shield-lock me-1"></i>Sicurezza</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-locale'?'active':'' ?>" data-bs-toggle="tab" href="#tab-locale"><i class="bi bi-translate me-1"></i>Localizzazione</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-notif'?'active':'' ?>" data-bs-toggle="tab" href="#tab-notif"><i class="bi bi-bell me-1"></i>Notifiche</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-modules'?'active':'' ?>" data-bs-toggle="tab" href="#tab-modules"><i class="bi bi-puzzle me-1"></i>Moduli</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-cats'?'active':'' ?>" data-bs-toggle="tab" href="#tab-cats"><i class="bi bi-tags me-1"></i>Categorie Ticket</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-pcats'?'active':'' ?>" data-bs-toggle="tab" href="#tab-pcats"><i class="bi bi-tools me-1"></i>Categorie Ricambi</a></li>
    <li class="nav-item"><a class="nav-link <?= $activeTab==='tab-custom'?'active':'' ?>" data-bs-toggle="tab" href="#tab-custom"><i class="bi bi-ui-checks me-1"></i>Campi Personalizzati</a></li>
</ul>

<div class="tab-content">

    <!-- ── TAB: Generali ─────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-general'?'show active':'' ?>" id="tab-general">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="general">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nome Azienda</label>
                    <input type="text" name="company_name" class="form-control" value="<?= h($settings['company_name'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Prefisso Ticket</label>
                    <input type="text" name="ticket_prefix" class="form-control font-monospace" maxlength="5" value="<?= h($settings['ticket_prefix'] ?? 'TKT') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Elementi per pagina</label>
                    <select name="items_per_page" class="form-select">
                        <?php foreach ([10,25,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= ($settings['items_per_page']??'25')==$n?'selected':'' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-envelope me-1"></i>Email / SMTP</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Notifiche Email</label>
                    <select name="email_notifications" class="form-select">
                        <option value="0" <?= ($settings['email_notifications']??'0')==='0'?'selected':'' ?>>Disabilitate</option>
                        <option value="1" <?= ($settings['email_notifications']??'0')==='1'?'selected':'' ?>>Abilitate</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Host SMTP</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= h($settings['smtp_host'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Porta</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= h($settings['smtp_port'] ?? '587') ?>">
                </div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <label class="form-label">Username SMTP</label>
                    <input type="text" name="smtp_user" class="form-control" value="<?= h($settings['smtp_user'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Password SMTP <small class="text-muted">(lascia vuoto per non cambiare)</small></label>
                    <input type="password" name="smtp_pass" class="form-control" autocomplete="off">
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-robot me-1"></i>Automazioni</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">Auto-assegnazione Ticket</label>
                    <select name="auto_assign" class="form-select">
                        <option value="0" <?= ($settings['auto_assign']??'0')==='0'?'selected':'' ?>>Disabilitata</option>
                        <option value="1" <?= ($settings['auto_assign']??'0')==='1'?'selected':'' ?>>Abilitata</option>
                    </select>
                    <small class="text-muted">Auto-assegna nuovi ticket al tecnico con meno carico</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Giorni per Auto-chiusura</label>
                    <input type="number" name="auto_close_days" class="form-control" min="0" value="<?= h($settings['auto_close_days'] ?? '0') ?>">
                    <small class="text-muted">0 = disabilitato. Chiude ticket "Risolti" dopo N giorni.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Chiave Segreta Auto-chiusura (cron)</label>
                    <input type="text" name="auto_close_secret" class="form-control font-monospace" value="<?= h($settings['auto_close_secret'] ?? '') ?>" placeholder="Lascia vuoto per disabilitare la chiamata cron">
                    <small class="text-muted">Cron: <code>/api/auto_close.php?secret=&lt;chiave&gt;</code></small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Aspetto ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-appearance'?'show active':'' ?>" id="tab-appearance">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="appearance">
            <h6 class="mb-3"><i class="bi bi-palette me-1"></i>Colori Tema</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Colore Primario</label>
                    <div class="input-group">
                        <input type="color" name="theme_primary_color" class="form-control form-control-color" value="<?= h($settings['theme_primary_color']) ?>" title="Colore primario (pulsanti, link attivi, badge)">
                        <input type="text" class="form-control font-monospace" value="<?= h($settings['theme_primary_color']) ?>" id="primaryColorText" readonly>
                    </div>
                    <small class="text-muted">Pulsanti, link, badge, highlight</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sidebar — Colore Alto</label>
                    <div class="input-group">
                        <input type="color" name="theme_sidebar_top" class="form-control form-control-color" value="<?= h($settings['theme_sidebar_top']) ?>" title="Colore top sidebar">
                        <input type="text" class="form-control font-monospace" value="<?= h($settings['theme_sidebar_top']) ?>" id="sidebarTopText" readonly>
                    </div>
                    <small class="text-muted">Gradiente superiore barra laterale</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sidebar — Colore Basso</label>
                    <div class="input-group">
                        <input type="color" name="theme_sidebar_bottom" class="form-control form-control-color" value="<?= h($settings['theme_sidebar_bottom']) ?>" title="Colore bottom sidebar">
                        <input type="text" class="form-control font-monospace" value="<?= h($settings['theme_sidebar_bottom']) ?>" id="sidebarBottomText" readonly>
                    </div>
                    <small class="text-muted">Gradiente inferiore barra laterale</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sfondo Pagina</label>
                    <div class="input-group">
                        <input type="color" name="theme_bg_color" class="form-control form-control-color" value="<?= h($settings['theme_bg_color']) ?>" title="Colore sfondo pagina">
                        <input type="text" class="form-control font-monospace" value="<?= h($settings['theme_bg_color']) ?>" id="bgColorText" readonly>
                    </div>
                    <small class="text-muted">Sfondo generale dell'applicazione</small>
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-type me-1"></i>Tipografia e Layout</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Dimensione Testo</label>
                    <select name="theme_font_size" class="form-select">
                        <option value="small"   <?= $settings['theme_font_size']==='small'?'selected':'' ?>>Piccolo (0.8rem)</option>
                        <option value="default" <?= $settings['theme_font_size']==='default'?'selected':'' ?>>Normale (0.9rem)</option>
                        <option value="large"   <?= $settings['theme_font_size']==='large'?'selected':'' ?>>Grande (1rem)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Arrotondamento Card</label>
                    <select name="theme_radius" class="form-select">
                        <option value="none" <?= $settings['theme_radius']==='none'?'selected':'' ?>>Nessuno (quadrato)</option>
                        <option value="sm"   <?= $settings['theme_radius']==='sm'?'selected':'' ?>>Piccolo (8px)</option>
                        <option value="md"   <?= $settings['theme_radius']==='md'?'selected':'' ?>>Medio (12px)</option>
                        <option value="lg"   <?= $settings['theme_radius']==='lg'?'selected':'' ?>>Grande (16px)</option>
                        <option value="xl"   <?= $settings['theme_radius']==='xl'?'selected':'' ?>>Extra Grande (24px)</option>
                    </select>
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-image me-1"></i>Logo</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">URL Logo Azienda</label>
                    <input type="url" name="company_logo_url" class="form-control" value="<?= h($settings['company_logo_url']) ?>" placeholder="https://example.com/logo.png">
                    <small class="text-muted">Mostrato nella sidebar e nei rapportini PDF. Lascia vuoto per usare l'icona predefinita.</small>
                </div>
                <?php if ($settings['company_logo_url']): ?>
                <div class="col-md-4 d-flex align-items-end">
                    <img src="<?= h($settings['company_logo_url']) ?>" alt="Logo" style="max-height:60px;max-width:100%;object-fit:contain;" class="border rounded p-1">
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Aspetto</button>
                <span class="text-muted small">Le modifiche all'aspetto si applicano immediatamente al prossimo caricamento.</span>
            </div>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Azienda ─────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-company'?'show active':'' ?>" id="tab-company">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-4">Queste informazioni vengono usate nell'intestazione dei rapportini PDF e nelle comunicazioni ufficiali.</p>
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="company">
            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Indirizzo</label>
                    <input type="text" name="company_address" class="form-control" value="<?= h($settings['company_address']) ?>" placeholder="Via Roma 1">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Città</label>
                    <input type="text" name="company_city" class="form-control" value="<?= h($settings['company_city']) ?>" placeholder="Milano">
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">P.IVA</label>
                    <input type="text" name="company_vat" class="form-control font-monospace" value="<?= h($settings['company_vat']) ?>" placeholder="IT00000000000">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Telefono</label>
                    <input type="text" name="company_phone" class="form-control" value="<?= h($settings['company_phone']) ?>" placeholder="+39 02 0000000">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Email Aziendale</label>
                    <input type="email" name="company_email" class="form-control" value="<?= h($settings['company_email']) ?>" placeholder="info@azienda.it">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Testo Piè di Pagina PDF</label>
                <textarea name="pdf_footer_text" class="form-control" rows="2" placeholder="Es: Grazie per aver scelto i nostri servizi. Per info: info@azienda.it"><?= h($settings['pdf_footer_text']) ?></textarea>
                <small class="text-muted">Mostrato in fondo ai rapportini PDF.</small>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Informazioni Azienda</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Ticket ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-ticket'?'show active':'' ?>" id="tab-ticket">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="ticket_settings">
            <h6 class="mb-3"><i class="bi bi-ticket-detailed me-1"></i>Impostazioni Ticket</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Priorità Predefinita Nuovi Ticket</label>
                    <select name="ticket_default_priority" class="form-select">
                        <option value="low"    <?= $settings['ticket_default_priority']==='low'?'selected':'' ?>>🟢 Bassa</option>
                        <option value="medium" <?= $settings['ticket_default_priority']==='medium'?'selected':'' ?>>🔵 Media</option>
                        <option value="high"   <?= $settings['ticket_default_priority']==='high'?'selected':'' ?>>🟠 Alta</option>
                        <option value="urgent" <?= $settings['ticket_default_priority']==='urgent'?'selected':'' ?>>🔴 Urgente</option>
                    </select>
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-paperclip me-1"></i>Allegati</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Dimensione Massima Allegati (MB)</label>
                    <input type="number" name="ticket_max_file_mb" class="form-control" min="1" max="100" value="<?= h($settings['ticket_max_file_mb']) ?>">
                    <small class="text-muted">Max dimensione per file allegato</small>
                </div>
                <div class="col-md-9">
                    <label class="form-label fw-semibold">Estensioni File Permesse</label>
                    <input type="text" name="ticket_allowed_ext" class="form-control font-monospace" value="<?= h($settings['ticket_allowed_ext']) ?>" placeholder="jpg,jpeg,png,pdf,doc,docx,zip">
                    <small class="text-muted">Separate da virgola, senza punto. Es: <code>jpg,png,pdf,docx,zip</code></small>
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-people me-1"></i>Permessi</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="ticket_tech_create" id="ticketTechCreate" <?= $settings['ticket_tech_create']==='1'?'checked':'' ?>>
                        <label class="form-check-label fw-semibold" for="ticketTechCreate">Tecnici possono creare ticket</label>
                    </div>
                    <small class="text-muted">Se disabilitato, solo gli admin possono creare nuovi ticket.</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Impostazioni Ticket</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Sicurezza ───────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-security'?'show active':'' ?>" id="tab-security">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-4"><i class="bi bi-info-circle me-1"></i>Le impostazioni di sicurezza vengono applicate alle nuove sessioni e ai nuovi accessi.</p>
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="security">
            <h6 class="mb-3"><i class="bi bi-clock me-1"></i>Sessione</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Timeout Sessione (minuti)</label>
                    <input type="number" name="session_timeout_mins" class="form-control" min="0" value="<?= h($settings['session_timeout_mins']) ?>">
                    <small class="text-muted">0 = nessun timeout (sessione illimitata)</small>
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-key me-1"></i>Password</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Lunghezza Minima Password</label>
                    <input type="number" name="min_password_length" class="form-control" min="4" max="64" value="<?= h($settings['min_password_length']) ?>">
                </div>
            </div>
            <hr>
            <h6 class="mb-3"><i class="bi bi-shield-exclamation me-1"></i>Protezione Login</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tentativi Max Login</label>
                    <input type="number" name="max_login_attempts" class="form-control" min="0" value="<?= h($settings['max_login_attempts']) ?>">
                    <small class="text-muted">0 = nessun limite</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Durata Blocco (minuti)</label>
                    <input type="number" name="login_lockout_mins" class="form-control" min="1" value="<?= h($settings['login_lockout_mins']) ?>">
                    <small class="text-muted">Minuti di blocco dopo i tentativi falliti</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Sicurezza</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Localizzazione ──────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-locale'?'show active':'' ?>" id="tab-locale">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="locale">
            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Fuso Orario</label>
                    <select name="app_timezone" class="form-select">
                        <?php
                        $tzGroups = [
                            'Europa'    => ['Europe/Rome','Europe/London','Europe/Paris','Europe/Berlin','Europe/Madrid','Europe/Amsterdam','Europe/Brussels','Europe/Zurich','Europe/Vienna','Europe/Prague','Europe/Warsaw','Europe/Budapest','Europe/Bucharest','Europe/Athens','Europe/Helsinki','Europe/Stockholm','Europe/Oslo','Europe/Copenhagen','Europe/Lisbon','Europe/Dublin'],
                            'America'   => ['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','America/Toronto','America/Vancouver','America/Sao_Paulo','America/Argentina/Buenos_Aires','America/Mexico_City'],
                            'Asia'      => ['Asia/Tokyo','Asia/Shanghai','Asia/Hong_Kong','Asia/Singapore','Asia/Dubai','Asia/Kolkata','Asia/Bangkok','Asia/Seoul'],
                            'Pacifico'  => ['Pacific/Auckland','Pacific/Sydney','Australia/Melbourne'],
                            'Africa'    => ['Africa/Cairo','Africa/Johannesburg','Africa/Lagos'],
                            'UTC'       => ['UTC'],
                        ];
                        foreach ($tzGroups as $group => $zones):
                        ?>
                        <optgroup label="<?= h($group) ?>">
                            <?php foreach ($zones as $tz): ?>
                            <option value="<?= h($tz) ?>" <?= $settings['app_timezone']===$tz?'selected':'' ?>><?= h($tz) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Formato Data</label>
                    <select name="date_format" class="form-select">
                        <?php
                        $formats = [
                            'd/m/Y H:i' => date('d/m/Y H:i') . ' (d/m/Y H:i)',
                            'd/m/Y'     => date('d/m/Y') . ' (d/m/Y)',
                            'Y-m-d H:i' => date('Y-m-d H:i') . ' (Y-m-d H:i)',
                            'Y-m-d'     => date('Y-m-d') . ' (Y-m-d)',
                            'd.m.Y H:i' => date('d.m.Y H:i') . ' (d.m.Y H:i)',
                            'd-m-Y H:i' => date('d-m-Y H:i') . ' (d-m-Y H:i)',
                            'm/d/Y H:i' => date('m/d/Y H:i') . ' (m/d/Y H:i)',
                        ];
                        foreach ($formats as $fmt => $label): ?>
                        <option value="<?= h($fmt) ?>" <?= $settings['date_format']===$fmt?'selected':'' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Simbolo Valuta</label>
                    <input type="text" name="currency_symbol" class="form-control" maxlength="5" value="<?= h($settings['currency_symbol']) ?>" placeholder="€">
                    <small class="text-muted">Usato nei prezzi ricambi e rapportini</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Localizzazione</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Notifiche ───────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-notif'?'show active':'' ?>" id="tab-notif">
        <div class="card border-0 shadow-sm"><div class="card-body">
        <p class="text-muted small mb-4"><i class="bi bi-info-circle me-1"></i>Configura quali eventi generano notifiche interne nel sistema. Le notifiche email dipendono anche dalla configurazione SMTP nella tab Generali.</p>
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="notif_triggers">
            <h6 class="mb-3"><i class="bi bi-ticket-detailed me-1"></i>Ticket</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="notif_new_ticket" id="notifNewTicket" <?= $settings['notif_new_ticket']==='1'?'checked':'' ?>>
                        <label class="form-check-label" for="notifNewTicket"><strong>Nuovo ticket creato</strong></label>
                    </div>
                    <small class="text-muted d-block mb-3">Notifica gli admin quando viene aperto un nuovo ticket.</small>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="notif_ticket_assign" id="notifAssign" <?= $settings['notif_ticket_assign']==='1'?'checked':'' ?>>
                        <label class="form-check-label" for="notifAssign"><strong>Ticket assegnato</strong></label>
                    </div>
                    <small class="text-muted d-block mb-3">Notifica il tecnico quando un ticket gli viene assegnato.</small>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="notif_ticket_comment" id="notifComment" <?= $settings['notif_ticket_comment']==='1'?'checked':'' ?>>
                        <label class="form-check-label" for="notifComment"><strong>Nuovo commento</strong></label>
                    </div>
                    <small class="text-muted d-block mb-3">Notifica i partecipanti quando viene aggiunto un commento.</small>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="notif_ticket_resolved" id="notifResolved" <?= $settings['notif_ticket_resolved']==='1'?'checked':'' ?>>
                        <label class="form-check-label" for="notifResolved"><strong>Ticket risolto / chiuso</strong></label>
                    </div>
                    <small class="text-muted d-block">Notifica il creatore quando il ticket viene risolto o chiuso.</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Notifiche</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Moduli ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-modules'?'show active':'' ?>" id="tab-modules">
        <div class="card border-0 shadow-sm"><div class="card-header bg-white"><h6 class="mb-0">Abilita / Disabilita Moduli</h6></div><div class="card-body">
        <form method="post">
            <?= csrfField() ?><input type="hidden" name="section" value="modules">
            <div class="row g-3 mb-4">
                <?php foreach ($allModules as $m): ?>
                <div class="col-md-4">
                    <div class="card border h-100"><div class="card-body d-flex align-items-center gap-3">
                        <i class="bi <?= h($m['icon']) ?> fs-2 text-primary"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= h($m['name']) ?></div>
                            <div class="small text-muted"><?= h($m['description'] ?? '') ?></div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="module_<?= h($m['slug']) ?>" <?= $m['enabled']?'checked':'' ?>>
                        </div>
                    </div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Moduli</button>
        </form>
        </div></div>
    </div>

    <!-- ── TAB: Categorie Ticket ────────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-cats'?'show active':'' ?>" id="tab-cats">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Categorie Ticket</h6>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#catAddModal">
                    <i class="bi bi-plus-lg me-1"></i>Aggiungi
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="ticketCatsTable">
                    <thead class="table-light"><tr><th>Nome</th><th>Descrizione</th><th style="width:120px"></th></tr></thead>
                    <tbody>
                    <?php if ($ticketCats): foreach ($ticketCats as $c): ?>
                    <tr id="tc-row-<?= $c['id'] ?>">
                        <td class="fw-semibold"><?= h($c['name']) ?></td>
                        <td class="small text-muted"><?= h($c['description']??'') ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary tc-edit"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= h($c['name']) ?>"
                                    data-desc="<?= h($c['description']??'') ?>"
                                    title="Modifica"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-outline-danger tc-delete"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= h($c['name']) ?>"
                                    title="Elimina"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr id="tc-empty"><td colspan="3" class="text-center text-muted py-3">Nessuna categoria</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── TAB: Categorie Ricambi ───────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-pcats'?'show active':'' ?>" id="tab-pcats">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Categorie Ricambi</h6>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#pcatAddModal">
                    <i class="bi bi-plus-lg me-1"></i>Aggiungi
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="partsCatsTable">
                    <thead class="table-light"><tr><th>Nome</th><th>Descrizione</th><th style="width:120px"></th></tr></thead>
                    <tbody>
                    <?php if ($partsCats): foreach ($partsCats as $c): ?>
                    <tr id="pc-row-<?= $c['id'] ?>">
                        <td class="fw-semibold"><?= h($c['name']) ?></td>
                        <td class="small text-muted"><?= h($c['description']??'') ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary pc-edit"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= h($c['name']) ?>"
                                    data-desc="<?= h($c['description']??'') ?>"
                                    title="Modifica"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-outline-danger pc-delete"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= h($c['name']) ?>"
                                    title="Elimina"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr id="pc-empty"><td colspan="3" class="text-center text-muted py-3">Nessuna categoria</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── TAB: Campi Personalizzati ─────────────────────────────────────── -->
    <div class="tab-pane fade <?= $activeTab==='tab-custom'?'show active':'' ?>" id="tab-custom">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0">Campi Personalizzati Ticket</h6>
                    <small class="text-muted">Aggiungi campi extra ai ticket (testo, numero, data, selezione, ecc.)</small>
                </div>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#cfAddModal">
                    <i class="bi bi-plus-lg me-1"></i>Aggiungi Campo
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="customFieldsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Etichetta</th>
                            <th>Nome Campo</th>
                            <th>Tipo</th>
                            <th>Obbligatorio</th>
                            <th>Ordine</th>
                            <th>Stato</th>
                            <th style="width:130px"></th>
                        </tr>
                    </thead>
                    <tbody id="cfTbody">
                        <tr id="cf-loading"><td colspan="7" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Caricamento...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS: Ticket Categories
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Add Ticket Category -->
<div class="modal fade" id="catAddModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-tag me-2 text-success"></i>Aggiungi Categoria Ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="catAddError" class="alert alert-danger d-none"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
                    <input type="text" id="catAddName" class="form-control" placeholder="Nome categoria" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Descrizione</label>
                    <input type="text" id="catAddDesc" class="form-control" placeholder="Descrizione (opzionale)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Annulla</button>
                <button type="button" class="btn btn-success" id="catAddSaveBtn"><i class="bi bi-check-lg me-1"></i>Aggiungi</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Ticket Category -->
<div class="modal fade" id="catEditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2 text-warning"></i>Modifica Categoria Ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="catEditError" class="alert alert-danger d-none"></div>
                <input type="hidden" id="catEditId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
                    <input type="text" id="catEditName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Descrizione</label>
                    <input type="text" id="catEditDesc" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Annulla</button>
                <button type="button" class="btn btn-warning" id="catEditSaveBtn"><i class="bi bi-save me-1"></i>Salva</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS: Spare Parts Categories
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Add Spare Parts Category -->
<div class="modal fade" id="pcatAddModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-tools me-2 text-success"></i>Aggiungi Categoria Ricambi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="pcatAddError" class="alert alert-danger d-none"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
                    <input type="text" id="pcatAddName" class="form-control" placeholder="Nome categoria" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Descrizione</label>
                    <input type="text" id="pcatAddDesc" class="form-control" placeholder="Descrizione (opzionale)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Annulla</button>
                <button type="button" class="btn btn-success" id="pcatAddSaveBtn"><i class="bi bi-check-lg me-1"></i>Aggiungi</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Spare Parts Category -->
<div class="modal fade" id="pcatEditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2 text-warning"></i>Modifica Categoria Ricambi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="pcatEditError" class="alert alert-danger d-none"></div>
                <input type="hidden" id="pcatEditId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label>
                    <input type="text" id="pcatEditName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Descrizione</label>
                    <input type="text" id="pcatEditDesc" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Annulla</button>
                <button type="button" class="btn btn-warning" id="pcatEditSaveBtn"><i class="bi bi-save me-1"></i>Salva</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS: Custom Fields
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Add Custom Field -->
<div class="modal fade" id="cfAddModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-ui-checks me-2 text-success"></i>Aggiungi Campo Personalizzato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="cfAddError" class="alert alert-danger d-none"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Etichetta <span class="text-danger">*</span></label>
                        <input type="text" id="cfAddLabel" class="form-control" placeholder="Es: Numero Seriale">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tipo Campo</label>
                        <select id="cfAddType" class="form-select">
                            <option value="text">Testo</option>
                            <option value="textarea">Testo lungo</option>
                            <option value="number">Numero</option>
                            <option value="date">Data</option>
                            <option value="select">Selezione</option>
                            <option value="checkbox">Checkbox</option>
                        </select>
                    </div>
                    <div class="col-12 cf-options-row d-none">
                        <label class="form-label fw-semibold">Opzioni <small class="text-muted">(una per riga)</small></label>
                        <textarea id="cfAddOptions" class="form-control font-monospace" rows="4" placeholder="Opzione 1&#10;Opzione 2&#10;Opzione 3"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Ordine di visualizzazione</label>
                        <input type="number" id="cfAddSort" class="form-control" value="0" min="0">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="cfAddRequired">
                            <label class="form-check-label fw-semibold" for="cfAddRequired">Campo obbligatorio</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Annulla</button>
                <button type="button" class="btn btn-success" id="cfAddSaveBtn"><i class="bi bi-check-lg me-1"></i>Aggiungi Campo</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Custom Field -->
<div class="modal fade" id="cfEditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2 text-warning"></i>Modifica Campo Personalizzato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="cfEditError" class="alert alert-danger d-none"></div>
                <input type="hidden" id="cfEditId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Etichetta <span class="text-danger">*</span></label>
                        <input type="text" id="cfEditLabel" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tipo Campo</label>
                        <select id="cfEditType" class="form-select">
                            <option value="text">Testo</option>
                            <option value="textarea">Testo lungo</option>
                            <option value="number">Numero</option>
                            <option value="date">Data</option>
                            <option value="select">Selezione</option>
                            <option value="checkbox">Checkbox</option>
                        </select>
                    </div>
                    <div class="col-12 cf-edit-options-row d-none">
                        <label class="form-label fw-semibold">Opzioni <small class="text-muted">(una per riga)</small></label>
                        <textarea id="cfEditOptions" class="form-control font-monospace" rows="4"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Ordine</label>
                        <input type="number" id="cfEditSort" class="form-control" min="0">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="cfEditRequired">
                            <label class="form-check-label fw-semibold" for="cfEditRequired">Obbligatorio</label>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="cfEditActive">
                            <label class="form-check-label fw-semibold" for="cfEditActive">Attivo</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i>Annulla</button>
                <button type="button" class="btn btn-warning" id="cfEditSaveBtn"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
            </div>
        </div>
    </div>
</div>

<script>
// Sync color pickers with text inputs
(function() {
    var pairs = [
        ['input[name="theme_primary_color"]',  '#primaryColorText'],
        ['input[name="theme_sidebar_top"]',     '#sidebarTopText'],
        ['input[name="theme_sidebar_bottom"]',  '#sidebarBottomText'],
        ['input[name="theme_bg_color"]',        '#bgColorText'],
    ];
    pairs.forEach(function(p) {
        var picker = document.querySelector(p[0]);
        var text   = document.querySelector(p[1]);
        if (!picker || !text) return;
        picker.addEventListener('input', function() { text.value = picker.value; });
    });
})();

// ── Settings AJAX helpers ────────────────────────────────────────────────────
(function() {
    'use strict';

    var apiUrl   = <?= json_encode(APP_URL . '/api/settings.php') ?>;
    var csrfToken = document.querySelector('meta[name="csrf-token"]') ?
                    document.querySelector('meta[name="csrf-token"]').content : '';

    function apiPost(action, data, onOk, onErr) {
        data.action = action;
        data.csrf_token = csrfToken;
        $.ajax({
            url: apiUrl,
            method: 'POST',
            data: data,
            dataType: 'json',
            success: function(r) {
                if (r.success) { onOk(r); }
                else { onErr(r.error || 'Errore.'); }
            },
            error: function() { onErr('Errore di comunicazione con il server.'); }
        });
    }

    function showErr(elId, msg) {
        var el = document.getElementById(elId);
        if (el) { el.textContent = msg; el.classList.remove('d-none'); }
    }
    function hideErr(elId) {
        var el = document.getElementById(elId);
        if (el) { el.classList.add('d-none'); }
    }
    function escHtml(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── TICKET CATEGORIES ──────────────────────────────────────────────────

    // Add
    document.getElementById('catAddSaveBtn').addEventListener('click', function() {
        hideErr('catAddError');
        var name = document.getElementById('catAddName').value.trim();
        var desc = document.getElementById('catAddDesc').value.trim();
        if (!name) { showErr('catAddError', 'Il nome è obbligatorio.'); return; }
        apiPost('add_ticket_cat', {name: name, description: desc}, function(r) {
            var tbody = document.querySelector('#ticketCatsTable tbody');
            var empty = document.getElementById('tc-empty');
            if (empty) empty.remove();
            var row = '<tr id="tc-row-' + r.id + '">' +
                '<td class="fw-semibold">' + escHtml(r.name) + '</td>' +
                '<td class="small text-muted">' + escHtml(r.description) + '</td>' +
                '<td><div class="btn-group btn-group-sm">' +
                '<button class="btn btn-outline-secondary tc-edit" data-id="' + r.id + '" data-name="' + escHtml(r.name) + '" data-desc="' + escHtml(r.description) + '" title="Modifica"><i class="bi bi-pencil"></i></button>' +
                '<button class="btn btn-outline-danger tc-delete" data-id="' + r.id + '" data-name="' + escHtml(r.name) + '" title="Elimina"><i class="bi bi-trash"></i></button>' +
                '</div></td></tr>';
            tbody.insertAdjacentHTML('beforeend', row);
            document.getElementById('catAddName').value = '';
            document.getElementById('catAddDesc').value = '';
            bootstrap.Modal.getInstance(document.getElementById('catAddModal')).hide();
        }, function(err) { showErr('catAddError', err); });
    });

    // Edit (open modal)
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.tc-edit');
        if (!btn) return;
        hideErr('catEditError');
        document.getElementById('catEditId').value   = btn.dataset.id;
        document.getElementById('catEditName').value = btn.dataset.name;
        document.getElementById('catEditDesc').value = btn.dataset.desc;
        (bootstrap.Modal.getOrCreateInstance(document.getElementById('catEditModal'))).show();
    });

    document.getElementById('catEditSaveBtn').addEventListener('click', function() {
        hideErr('catEditError');
        var id   = document.getElementById('catEditId').value;
        var name = document.getElementById('catEditName').value.trim();
        var desc = document.getElementById('catEditDesc').value.trim();
        if (!name) { showErr('catEditError', 'Il nome è obbligatorio.'); return; }
        apiPost('edit_ticket_cat', {id: id, name: name, description: desc}, function(r) {
            var row = document.getElementById('tc-row-' + r.id);
            if (row) {
                row.cells[0].textContent = r.name;
                row.cells[1].textContent = r.description;
                var editBtn = row.querySelector('.tc-edit');
                if (editBtn) { editBtn.dataset.name = r.name; editBtn.dataset.desc = r.description; }
            }
            bootstrap.Modal.getInstance(document.getElementById('catEditModal')).hide();
        }, function(err) { showErr('catEditError', err); });
    });

    // Delete
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.tc-delete');
        if (!btn) return;
        showConfirmModal('Eliminare la categoria "' + btn.dataset.name + '"?', function() {
            apiPost('delete_ticket_cat', {id: btn.dataset.id}, function() {
                var row = document.getElementById('tc-row-' + btn.dataset.id);
                if (row) row.remove();
                if (!document.querySelector('#ticketCatsTable tbody tr')) {
                    document.querySelector('#ticketCatsTable tbody').innerHTML =
                        '<tr id="tc-empty"><td colspan="3" class="text-center text-muted py-3">Nessuna categoria</td></tr>';
                }
            }, function(err) {
                showConfirmModal(err, null, {title:'Errore', hideOk:true});
            });
        }, {title:'Elimina Categoria', btnClass:'btn-danger', btnText:'Elimina'});
    });

    // ── SPARE PARTS CATEGORIES ─────────────────────────────────────────────

    document.getElementById('pcatAddSaveBtn').addEventListener('click', function() {
        hideErr('pcatAddError');
        var name = document.getElementById('pcatAddName').value.trim();
        var desc = document.getElementById('pcatAddDesc').value.trim();
        if (!name) { showErr('pcatAddError', 'Il nome è obbligatorio.'); return; }
        apiPost('add_parts_cat', {name: name, description: desc}, function(r) {
            var tbody = document.querySelector('#partsCatsTable tbody');
            var empty = document.getElementById('pc-empty');
            if (empty) empty.remove();
            var row = '<tr id="pc-row-' + r.id + '">' +
                '<td class="fw-semibold">' + escHtml(r.name) + '</td>' +
                '<td class="small text-muted">' + escHtml(r.description) + '</td>' +
                '<td><div class="btn-group btn-group-sm">' +
                '<button class="btn btn-outline-secondary pc-edit" data-id="' + r.id + '" data-name="' + escHtml(r.name) + '" data-desc="' + escHtml(r.description) + '" title="Modifica"><i class="bi bi-pencil"></i></button>' +
                '<button class="btn btn-outline-danger pc-delete" data-id="' + r.id + '" data-name="' + escHtml(r.name) + '" title="Elimina"><i class="bi bi-trash"></i></button>' +
                '</div></td></tr>';
            tbody.insertAdjacentHTML('beforeend', row);
            document.getElementById('pcatAddName').value = '';
            document.getElementById('pcatAddDesc').value = '';
            bootstrap.Modal.getInstance(document.getElementById('pcatAddModal')).hide();
        }, function(err) { showErr('pcatAddError', err); });
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.pc-edit');
        if (!btn) return;
        hideErr('pcatEditError');
        document.getElementById('pcatEditId').value   = btn.dataset.id;
        document.getElementById('pcatEditName').value = btn.dataset.name;
        document.getElementById('pcatEditDesc').value = btn.dataset.desc;
        (bootstrap.Modal.getOrCreateInstance(document.getElementById('pcatEditModal'))).show();
    });

    document.getElementById('pcatEditSaveBtn').addEventListener('click', function() {
        hideErr('pcatEditError');
        var id   = document.getElementById('pcatEditId').value;
        var name = document.getElementById('pcatEditName').value.trim();
        var desc = document.getElementById('pcatEditDesc').value.trim();
        if (!name) { showErr('pcatEditError', 'Il nome è obbligatorio.'); return; }
        apiPost('edit_parts_cat', {id: id, name: name, description: desc}, function(r) {
            var row = document.getElementById('pc-row-' + r.id);
            if (row) {
                row.cells[0].textContent = r.name;
                row.cells[1].textContent = r.description;
                var editBtn = row.querySelector('.pc-edit');
                if (editBtn) { editBtn.dataset.name = r.name; editBtn.dataset.desc = r.description; }
            }
            bootstrap.Modal.getInstance(document.getElementById('pcatEditModal')).hide();
        }, function(err) { showErr('pcatEditError', err); });
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.pc-delete');
        if (!btn) return;
        showConfirmModal('Eliminare la categoria "' + btn.dataset.name + '"?', function() {
            apiPost('delete_parts_cat', {id: btn.dataset.id}, function() {
                var row = document.getElementById('pc-row-' + btn.dataset.id);
                if (row) row.remove();
                if (!document.querySelector('#partsCatsTable tbody tr')) {
                    document.querySelector('#partsCatsTable tbody').innerHTML =
                        '<tr id="pc-empty"><td colspan="3" class="text-center text-muted py-3">Nessuna categoria</td></tr>';
                }
            }, function(err) {
                showConfirmModal(err, null, {title:'Errore', hideOk:true});
            });
        }, {title:'Elimina Categoria', btnClass:'btn-danger', btnText:'Elimina'});
    });

    // ── CUSTOM FIELDS ──────────────────────────────────────────────────────

    var CF_TYPE_LABELS = {
        text:'Testo', textarea:'Testo lungo', number:'Numero',
        date:'Data', select:'Selezione', checkbox:'Checkbox'
    };

    function renderCfRow(f) {
        var optionsBadge = f.field_type === 'select' ? '<span class="badge bg-info ms-1" title="' + escHtml(f.field_options||'') + '">opts</span>' : '';
        return '<tr id="cf-row-' + f.id + '">' +
            '<td class="fw-semibold">' + escHtml(f.field_label) + (f.is_required=='1'?'<span class="text-danger ms-1" title="Obbligatorio">*</span>':'') + '</td>' +
            '<td class="small font-monospace text-muted">' + escHtml(f.field_name) + '</td>' +
            '<td><span class="badge bg-secondary">' + escHtml(CF_TYPE_LABELS[f.field_type]||f.field_type) + '</span>' + optionsBadge + '</td>' +
            '<td>' + (f.is_required=='1'?'<i class="bi bi-check-circle-fill text-success"></i>':'<i class="bi bi-dash text-muted"></i>') + '</td>' +
            '<td class="small">' + escHtml(f.sort_order) + '</td>' +
            '<td>' + (f.active=='1'?'<span class="badge bg-success">Attivo</span>':'<span class="badge bg-secondary">Inattivo</span>') + '</td>' +
            '<td><div class="btn-group btn-group-sm">' +
            '<button class="btn btn-outline-secondary cf-edit" data-id="' + f.id + '" title="Modifica"><i class="bi bi-pencil"></i></button>' +
            '<button class="btn btn-outline-danger cf-delete" data-id="' + f.id + '" data-label="' + escHtml(f.field_label) + '" title="Elimina"><i class="bi bi-trash"></i></button>' +
            '</div></td></tr>';
    }

    var cfCache = {};

    function loadCustomFields() {
        $.ajax({
            url: apiUrl + '?action=list_custom_fields',
            dataType: 'json',
            success: function(r) {
                var tbody = document.getElementById('cfTbody');
                if (!r.success) { tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Errore caricamento dati.</td></tr>'; return; }
                cfCache = {};
                if (!r.data || r.data.length === 0) {
                    tbody.innerHTML = '<tr id="cf-empty"><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-ui-checks fs-2 d-block mb-2 text-muted"></i>Nessun campo personalizzato. Clicca "Aggiungi Campo" per iniziare.</td></tr>';
                    return;
                }
                var html = '';
                r.data.forEach(function(f) { cfCache[f.id] = f; html += renderCfRow(f); });
                tbody.innerHTML = html;
            },
            error: function() {
                document.getElementById('cfTbody').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Errore di comunicazione.</td></tr>';
            }
        });
    }

    // Load custom fields when tab is activated
    document.querySelector('a[href="#tab-custom"]').addEventListener('shown.bs.tab', function() {
        loadCustomFields();
    });
    // Also load if already active
    if (document.getElementById('tab-custom').classList.contains('show')) {
        loadCustomFields();
    }

    // Toggle options textarea visibility on type change
    document.getElementById('cfAddType').addEventListener('change', function() {
        document.querySelector('#cfAddModal .cf-options-row').classList.toggle('d-none', this.value !== 'select');
    });
    document.getElementById('cfEditType').addEventListener('change', function() {
        document.querySelector('#cfEditModal .cf-edit-options-row').classList.toggle('d-none', this.value !== 'select');
    });

    // Add custom field
    document.getElementById('cfAddSaveBtn').addEventListener('click', function() {
        hideErr('cfAddError');
        var label   = document.getElementById('cfAddLabel').value.trim();
        var type    = document.getElementById('cfAddType').value;
        var options = document.getElementById('cfAddOptions').value.trim();
        var sort    = document.getElementById('cfAddSort').value;
        var req     = document.getElementById('cfAddRequired').checked ? '1' : '0';
        if (!label) { showErr('cfAddError', 'L\'etichetta è obbligatoria.'); return; }
        var data = {field_label: label, field_type: type, field_options: options, sort_order: sort};
        if (req === '1') data.is_required = '1';
        apiPost('add_custom_field', data, function(r) {
            var tbody = document.getElementById('cfTbody');
            var empty = document.getElementById('cf-empty');
            if (empty) empty.remove();
            tbody.insertAdjacentHTML('beforeend', renderCfRow(r.field));
            cfCache[r.field.id] = r.field;
            document.getElementById('cfAddLabel').value   = '';
            document.getElementById('cfAddOptions').value = '';
            document.getElementById('cfAddSort').value    = '0';
            document.getElementById('cfAddRequired').checked = false;
            document.getElementById('cfAddType').value = 'text';
            document.querySelector('#cfAddModal .cf-options-row').classList.add('d-none');
            bootstrap.Modal.getInstance(document.getElementById('cfAddModal')).hide();
        }, function(err) { showErr('cfAddError', err); });
    });

    // Open edit modal
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.cf-edit');
        if (!btn) return;
        var id = btn.dataset.id;
        var f  = cfCache[id];
        if (!f) return;
        hideErr('cfEditError');
        document.getElementById('cfEditId').value    = f.id;
        document.getElementById('cfEditLabel').value = f.field_label;
        document.getElementById('cfEditType').value  = f.field_type;
        document.getElementById('cfEditSort').value  = f.sort_order;
        document.getElementById('cfEditRequired').checked = f.is_required == '1';
        document.getElementById('cfEditActive').checked   = f.active == '1';
        // Options
        var optRow = document.querySelector('#cfEditModal .cf-edit-options-row');
        if (f.field_type === 'select') {
            optRow.classList.remove('d-none');
            var opts = [];
            try { opts = JSON.parse(f.field_options || '[]'); } catch(ex) {}
            document.getElementById('cfEditOptions').value = opts.join('\n');
        } else {
            optRow.classList.add('d-none');
            document.getElementById('cfEditOptions').value = '';
        }
        (bootstrap.Modal.getOrCreateInstance(document.getElementById('cfEditModal'))).show();
    });

    // Save edit
    document.getElementById('cfEditSaveBtn').addEventListener('click', function() {
        hideErr('cfEditError');
        var id      = document.getElementById('cfEditId').value;
        var label   = document.getElementById('cfEditLabel').value.trim();
        var type    = document.getElementById('cfEditType').value;
        var options = document.getElementById('cfEditOptions').value.trim();
        var sort    = document.getElementById('cfEditSort').value;
        var req     = document.getElementById('cfEditRequired').checked;
        var active  = document.getElementById('cfEditActive').checked;
        if (!label) { showErr('cfEditError', 'L\'etichetta è obbligatoria.'); return; }
        var data = {id: id, field_label: label, field_type: type, field_options: options, sort_order: sort};
        if (req)    data.is_required = '1';
        if (active) data.active      = '1';
        apiPost('edit_custom_field', data, function(r) {
            var row = document.getElementById('cf-row-' + r.field.id);
            if (row) {
                var tmp = document.createElement('tbody');
                tmp.innerHTML = renderCfRow(r.field);
                row.replaceWith(tmp.firstElementChild);
            }
            cfCache[r.field.id] = r.field;
            bootstrap.Modal.getInstance(document.getElementById('cfEditModal')).hide();
        }, function(err) { showErr('cfEditError', err); });
    });

    // Delete custom field
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.cf-delete');
        if (!btn) return;
        showConfirmModal('Eliminare il campo "' + btn.dataset.label + '"?', function() {
            apiPost('delete_custom_field', {id: btn.dataset.id}, function() {
                var row = document.getElementById('cf-row-' + btn.dataset.id);
                if (row) { delete cfCache[btn.dataset.id]; row.remove(); }
                if (!document.querySelector('#cfTbody tr')) {
                    document.getElementById('cfTbody').innerHTML =
                        '<tr id="cf-empty"><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-ui-checks fs-2 d-block mb-2 text-muted"></i>Nessun campo personalizzato.</td></tr>';
                }
            }, function(err) {
                showConfirmModal(err, null, {title:'Errore', hideOk:true});
            });
        }, {title:'Elimina Campo', btnClass:'btn-danger', btnText:'Elimina'});
    });

}());
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
