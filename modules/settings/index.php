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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'general') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    else {
        $fields = ['company_name','ticket_prefix','items_per_page','email_notifications','smtp_host','smtp_port','smtp_user'];
        foreach ($fields as $k) {
            $v = trim($_POST[$k] ?? '');
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k,$v,$v]);
        }
        if (!empty($_POST['smtp_pass'])) {
            $v = $_POST['smtp_pass'];
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute(['smtp_pass',$v,$v]);
        }
        logActivity($user['id'], 'settings_update', 'settings', 0, 'Impostazioni generali aggiornate');
        $success = 'Impostazioni salvate.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'modules') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    else {
        foreach ($db->query("SELECT id, slug FROM modules")->fetchAll() as $m) {
            $enabled = isset($_POST['module_'.$m['slug']]) ? 1 : 0;
            $db->prepare("UPDATE modules SET enabled=? WHERE id=?")->execute([$enabled, $m['id']]);
        }
        logActivity($user['id'], 'settings_update', 'modules', 0, 'Moduli aggiornati');
        $success = 'Moduli aggiornati.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'add_cat') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    elseif (trim($_POST['cat_name'] ?? '')) {
        $db->prepare("INSERT INTO ticket_categories (name, description) VALUES (?,?)")->execute([trim($_POST['cat_name']), trim($_POST['cat_desc']??'')]);
        $success = 'Categoria aggiunta.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'del_cat') {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $db->prepare("DELETE FROM ticket_categories WHERE id=?")->execute([(int)$_POST['cat_id']]);
        $success = 'Categoria eliminata.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'add_pcat') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido.'; }
    elseif (trim($_POST['pcat_name'] ?? '')) {
        $db->prepare("INSERT INTO spare_parts_categories (name, description) VALUES (?,?)")->execute([trim($_POST['pcat_name']), trim($_POST['pcat_desc']??'')]);
        $success = 'Categoria ricambi aggiunta.';
    }
}

$settingsRaw = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings = [];
foreach ($settingsRaw as $s) { $settings[$s['setting_key']] = $s['setting_value']; }
$allModules  = $db->query("SELECT * FROM modules ORDER BY sort_order")->fetchAll();
$ticketCats  = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
$partsCats   = $db->query("SELECT * FROM spare_parts_categories ORDER BY name")->fetchAll();

include APP_ROOT . '/includes/header.php';
?>

<div class="mb-4"><h4 class="mb-0"><i class="bi bi-gear me-2 text-primary"></i>Impostazioni Sistema</h4></div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-general"><i class="bi bi-sliders me-1"></i>Generali</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-modules"><i class="bi bi-puzzle me-1"></i>Moduli</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-cats"><i class="bi bi-tags me-1"></i>Categorie Ticket</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-pcats"><i class="bi bi-tools me-1"></i>Categorie Ricambi</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-general">
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
                    <input type="password" name="smtp_pass" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva</button>
        </form>
        </div></div>
    </div>

    <div class="tab-pane fade" id="tab-modules">
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

    <div class="tab-pane fade" id="tab-cats">
        <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h6 class="mb-0">Aggiungi Categoria Ticket</h6></div><div class="card-body">
        <form method="post" class="row g-2">
            <?= csrfField() ?><input type="hidden" name="section" value="add_cat">
            <div class="col-md-5"><input type="text" name="cat_name" class="form-control" placeholder="Nome" required></div>
            <div class="col-md-5"><input type="text" name="cat_desc" class="form-control" placeholder="Descrizione"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button></div>
        </form>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Nome</th><th>Descrizione</th><th></th></tr></thead>
                <tbody>
                <?php if ($ticketCats): foreach ($ticketCats as $c): ?>
                <tr>
                    <td><?= h($c['name']) ?></td>
                    <td class="small text-muted"><?= h($c['description']??'') ?></td>
                    <td>
                        <form method="post" class="d-inline">
                            <?= csrfField() ?><input type="hidden" name="section" value="del_cat"><input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                            <button type="submit"
                                class="btn btn-sm btn-outline-danger"
                                data-confirm="Eliminare questa categoria?"
                                data-confirm-class="btn-danger"
                                data-confirm-text="Elimina"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Nessuna categoria</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>

    <div class="tab-pane fade" id="tab-pcats">
        <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h6 class="mb-0">Aggiungi Categoria Ricambi</h6></div><div class="card-body">
        <form method="post" class="row g-2">
            <?= csrfField() ?><input type="hidden" name="section" value="add_pcat">
            <div class="col-md-5"><input type="text" name="pcat_name" class="form-control" placeholder="Nome" required></div>
            <div class="col-md-5"><input type="text" name="pcat_desc" class="form-control" placeholder="Descrizione"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Aggiungi</button></div>
        </form>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Nome</th><th>Descrizione</th></tr></thead>
                <tbody>
                <?php if ($partsCats): foreach ($partsCats as $c): ?>
                <tr><td><?= h($c['name']) ?></td><td class="small text-muted"><?= h($c['description']??'') ?></td></tr>
                <?php endforeach; else: ?>
                <tr><td colspan="2" class="text-center text-muted py-3">Nessuna categoria</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
