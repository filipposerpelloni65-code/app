<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();

define('PAGE_TITLE', 'Profilo Utente');
define('BREADCRUMB', ['Dashboard' => APP_URL . '/dashboard.php', 'Profilo' => '']);

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$errors  = [];
$success = '';

/* ── Update profile info ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'profile') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Riprova.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        if (!$fullName) { $errors[] = 'Il nome non può essere vuoto.'; }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Indirizzo email non valido.'; }

        if (!$errors) {
            // Check email uniqueness
            $chk = $db->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $chk->execute([$email, $uid]);
            if ($chk->fetch()) {
                $errors[] = 'Questa email è già utilizzata da un altro utente.';
            } else {
                $db->prepare("UPDATE users SET full_name=?, email=? WHERE id=?")
                   ->execute([$fullName, $email ?: null, $uid]);
                logActivity($uid, 'profile_update', 'user', $uid, 'Profilo aggiornato');
                $success = 'Profilo aggiornato con successo.';
                // Refresh session data
                $userRow = $db->prepare("SELECT * FROM users WHERE id=?");
                $userRow->execute([$uid]);
                $_SESSION['user'] = $userRow->fetch();
                $user = $_SESSION['user'];
            }
        }
    }
}

/* ── Change password ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['section'] ?? '') === 'password') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Riprova.';
    } else {
        $oldPass  = $_POST['old_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        if (!$oldPass)  { $errors[] = 'Inserisci la password attuale.'; }
        if (strlen($newPass) < 8) { $errors[] = 'La nuova password deve essere di almeno 8 caratteri.'; }
        if ($newPass !== $confPass) { $errors[] = 'Le password non coincidono.'; }

        if (!$errors) {
            $row = $db->prepare("SELECT password_hash FROM users WHERE id=?");
            $row->execute([$uid]);
            $stored = $row->fetchColumn();
            if (!password_verify($oldPass, $stored)) {
                $errors[] = 'La password attuale non è corretta.';
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $uid]);
                logActivity($uid, 'password_change', 'user', $uid, 'Password modificata');
                $success = 'Password aggiornata con successo.';
            }
        }
    }
}

/* ── Recent activity ────────────────────────────────────────────────────── */
$actStmt = $db->prepare("SELECT * FROM activity_log WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$actStmt->execute([$uid]);
$recentActivity = $actStmt->fetchAll();

/* ── User ticket stats ──────────────────────────────────────────────────── */
$stTotal   = $db->prepare("SELECT COUNT(*) FROM tickets WHERE created_by=?");
$stTotal->execute([$uid]);
$stOpen    = $db->prepare("SELECT COUNT(*) FROM tickets WHERE created_by=? AND status='open'");
$stOpen->execute([$uid]);
$stResolved = $db->prepare("SELECT COUNT(*) FROM tickets WHERE created_by=? AND status IN ('resolved','closed')");
$stResolved->execute([$uid]);
$stAssigned = $db->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to=? AND status NOT IN ('resolved','closed')");
$stAssigned->execute([$uid]);
$stComments = $db->prepare("SELECT COUNT(*) FROM ticket_comments WHERE user_id=?");
$stComments->execute([$uid]);
$myStats = [
    'total'    => (int)$stTotal->fetchColumn(),
    'open'     => (int)$stOpen->fetchColumn(),
    'resolved' => (int)$stResolved->fetchColumn(),
    'assigned' => (int)$stAssigned->fetchColumn(),
    'comments' => (int)$stComments->fetchColumn(),
];

$actionLabels = [
    'create'          => 'Creazione',
    'update'          => 'Modifica',
    'delete'          => 'Eliminazione',
    'comment'         => 'Commento',
    'status_change'   => 'Cambio stato',
    'add_uscita'      => 'Uscita tecnico',
    'profile_update'  => 'Profilo aggiornato',
    'password_change' => 'Password cambiata',
    'login'           => 'Accesso',
    'settings_update' => 'Impostazioni',
];

$entityLabels = [
    'ticket'   => 'Ticket',
    'user'     => 'Utente',
    'settings' => 'Impostazioni',
];

include APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">
    <!-- Left: profile info + stats -->
    <div class="col-lg-4">
        <!-- Avatar card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body text-center py-4">
                <div class="user-avatar mx-auto mb-3" style="width:72px;height:72px;font-size:2rem">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <h5 class="mb-1"><?= h($user['full_name']) ?></h5>
                <p class="text-muted mb-2 small"><?= h($user['email'] ?? '') ?></p>
                <?php
                $roleColors = ['admin' => 'danger', 'technician' => 'warning text-dark', 'user' => 'primary'];
                $roleLabels = ['admin' => 'Amministratore', 'technician' => 'Tecnico', 'user' => 'Utente'];
                ?>
                <span class="badge bg-<?= $roleColors[$user['role']] ?? 'secondary' ?>">
                    <?= h($roleLabels[$user['role']] ?? ucfirst($user['role'])) ?>
                </span>
                <div class="mt-3 pt-3 border-top text-muted small">
                    <i class="bi bi-calendar3 me-1"></i>Iscritto il <?= formatDate($user['created_at'], 'd/m/Y') ?>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Le mie statistiche</h6></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between py-2">
                        <span class="small">Ticket aperti</span>
                        <span class="badge bg-info"><?= $myStats['open'] ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between py-2">
                        <span class="small">Ticket risolti</span>
                        <span class="badge bg-success"><?= $myStats['resolved'] ?></span>
                    </li>
                    <?php if ($user['role'] !== 'user'): ?>
                    <li class="list-group-item d-flex justify-content-between py-2">
                        <span class="small">Ticket assegnati (attivi)</span>
                        <span class="badge bg-warning text-dark"><?= $myStats['assigned'] ?></span>
                    </li>
                    <?php endif; ?>
                    <li class="list-group-item d-flex justify-content-between py-2">
                        <span class="small">Commenti inviati</span>
                        <span class="badge bg-secondary"><?= $myStats['comments'] ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right: forms + activity -->
    <div class="col-lg-8">
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show auto-dismiss"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <!-- Profile info form -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-person-circle me-2 text-primary"></i>Dati Personali</h6></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="section" value="profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nome e Cognome <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= h($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= h($user['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control bg-light" value="<?= h($user['username']) ?>" disabled>
                            <div class="form-text">Lo username non può essere modificato.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ruolo</label>
                            <input type="text" class="form-control bg-light" value="<?= h($roleLabels[$user['role']] ?? ucfirst($user['role'])) ?>" disabled>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salva Modifiche</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Password change form -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-shield-lock me-2 text-warning"></i>Cambia Password</h6></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="section" value="password">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Password Attuale <span class="text-danger">*</span></label>
                            <input type="password" name="old_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nuova Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" id="newPassInput" required minlength="8" autocomplete="new-password">
                            <div class="form-text">Minimo 8 caratteri.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Conferma Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" id="confPassInput" required autocomplete="new-password">
                        </div>
                    </div>
                    <!-- Password strength bar -->
                    <div class="mt-2 d-none" id="pwStrengthWrap">
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <div class="progress flex-grow-1" style="height:6px">
                                <div class="progress-bar" id="pwStrengthBar" role="progressbar" style="width:0%;transition:width .3s ease"></div>
                            </div>
                            <small id="pwStrengthLabel" class="text-muted" style="min-width:70px"></small>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Aggiorna Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Recent activity -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-clock-history me-2 text-secondary"></i>Attività Recente</h6></div>
            <?php if ($recentActivity): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($recentActivity as $act): ?>
                <div class="list-group-item py-2">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-dot fs-3 text-primary lh-1 mt-n1"></i>
                        <div class="flex-grow-1">
                            <div class="small fw-semibold">
                                <?= h($actionLabels[$act['action']] ?? $act['action']) ?>
                                <?php if ($act['entity_type']): ?>
                                <span class="text-muted fw-normal">· <?= h($entityLabels[$act['entity_type']] ?? $act['entity_type']) ?>
                                <?php if ($act['entity_id']): ?>#<?= (int)$act['entity_id'] ?><?php endif; ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($act['details']): ?><div class="text-muted small"><?= h($act['details']) ?></div><?php endif; ?>
                        </div>
                        <small class="text-muted text-nowrap"><?= formatDate($act['created_at'], 'd/m/Y H:i') ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="card-body text-center text-muted py-4">
                <i class="bi bi-clock-history fs-2 d-block mb-2 opacity-50"></i>Nessuna attività registrata.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Password strength meter
var newPass = document.getElementById('newPassInput');
var bar     = document.getElementById('pwStrengthBar');
var label   = document.getElementById('pwStrengthLabel');
var wrap    = document.getElementById('pwStrengthWrap');

if (newPass) {
    newPass.addEventListener('input', function() {
        var v = this.value;
        wrap.classList.toggle('d-none', v.length === 0);
        var score = 0;
        if (v.length >= 8)  score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[0-9]/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        var pct    = (score / 4) * 100;
        var colors = ['bg-danger','bg-warning','bg-info','bg-success'];
        var labels = ['Debole','Discreta','Buona','Ottima'];
        bar.style.width = pct + '%';
        bar.className   = 'progress-bar ' + (colors[score - 1] || 'bg-danger');
        label.textContent = labels[score - 1] || '';
    });
}
// Confirm password real-time match
var confPass = document.getElementById('confPassInput');
if (confPass && newPass) {
    confPass.addEventListener('input', function() {
        this.setCustomValidity(this.value !== newPass.value ? 'Le password non coincidono' : '');
    });
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
