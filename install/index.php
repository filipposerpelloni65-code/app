<?php
session_start();

// Redirect if already installed
if (file_exists(dirname(__DIR__) . '/config.ini') && file_exists(__DIR__ . '/.installed')) {
    header('Location: ../dashboard.php');
    exit;
}

$dbError = '';
$adminError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'next_step1') {
        $_SESSION['install_step'] = 2;
        header('Location: index.php?step=2');
        exit;
    }
    if ($_POST['action'] === 'save_step2') {
        $_SESSION['db_host'] = trim($_POST['db_host'] ?? 'localhost');
        $_SESSION['db_port'] = trim($_POST['db_port'] ?? '3306');
        $_SESSION['db_name'] = trim($_POST['db_name'] ?? '');
        $_SESSION['db_user'] = trim($_POST['db_user'] ?? '');
        $_SESSION['db_pass'] = trim($_POST['db_pass'] ?? '');
        try {
            $dsn = 'mysql:host=' . $_SESSION['db_host'] . ';port=' . $_SESSION['db_port'] . ';dbname=' . $_SESSION['db_name'] . ';charset=utf8mb4';
            new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $_SESSION['install_step'] = 3;
            header('Location: index.php?step=3');
            exit;
        } catch (PDOException $e) {
            $dbError = 'Connessione fallita: ' . $e->getMessage();
        }
    }
    if ($_POST['action'] === 'save_step3') {
        $_SESSION['app_name']        = trim($_POST['app_name'] ?? 'HelpDesk');
        $_SESSION['admin_username']  = trim($_POST['admin_username'] ?? 'admin');
        $_SESSION['admin_email']     = trim($_POST['admin_email'] ?? '');
        $_SESSION['admin_password']  = $_POST['admin_password'] ?? '';
        $_SESSION['admin_password2'] = $_POST['admin_password2'] ?? '';
        if (empty($_SESSION['app_name'])) {
            $adminError = 'Il nome applicazione è obbligatorio.';
        } elseif (empty($_SESSION['admin_username'])) {
            $adminError = 'Il nome utente admin è obbligatorio.';
        } elseif (empty($_SESSION['admin_email'])) {
            $adminError = "L'email admin è obbligatoria.";
        } elseif (strlen($_SESSION['admin_password']) < 8) {
            $adminError = 'La password deve avere almeno 8 caratteri.';
        } elseif ($_SESSION['admin_password'] !== $_SESSION['admin_password2']) {
            $adminError = 'Le password non coincidono.';
        } else {
            $_SESSION['install_step'] = 4;
            header('Location: index.php?step=4');
            exit;
        }
    }
}

$step = isset($_SESSION['install_step']) ? (int)$_SESSION['install_step'] : 1;
if (isset($_GET['step'])) {
    $s = (int)$_GET['step'];
    if ($s >= 1 && $s <= 5) $step = $s;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione HelpDesk</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); min-height: 100vh; }
        .wizard-card { max-width: 680px; margin: 0 auto; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .step-item { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; flex-shrink: 0; }
        .step-active { background: #3b82f6; color: white; }
        .step-done { background: #10b981; color: white; }
        .step-pending { background: #e2e8f0; color: #64748b; }
        .wizard-step { display: none; }
        .wizard-step.active { display: block; }
        .step-connector { flex: 1; height: 3px; background: #e2e8f0; margin: 0 6px; align-self: center; }
        .step-connector.done { background: #10b981; }
    </style>
</head>
<body class="py-5">
<div class="container">
    <div class="text-center mb-4">
        <i class="bi bi-headset text-white" style="font-size:3rem;"></i>
        <h2 class="text-white mt-2 fw-bold">Installazione HelpDesk</h2>
    </div>

    <!-- Step Indicators -->
    <div class="d-flex align-items-center justify-content-center mb-4" style="max-width:680px;margin:0 auto;">
        <?php
        $stepLabels = ['Benvenuto','Database','Configurazione','Installazione','Completato'];
        foreach ($stepLabels as $i => $label):
            $n = $i + 1;
            $cls = $n < $step ? 'step-done' : ($n === $step ? 'step-active' : 'step-pending');
        ?>
        <div class="text-center">
            <div class="step-item <?= $cls ?> mx-auto">
                <?= $n < $step ? '<i class="bi bi-check-lg"></i>' : $n ?>
            </div>
            <small class="text-white-50 d-block mt-1" style="font-size:0.7rem;"><?= $label ?></small>
        </div>
        <?php if ($n < count($stepLabels)): ?>
        <div class="step-connector <?= $n < $step ? 'done' : '' ?>" style="margin-bottom:18px;"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="card wizard-card">
        <div class="card-body p-4">

            <!-- STEP 1: Welcome -->
            <div class="wizard-step <?= $step === 1 ? 'active' : '' ?>" id="step-1">
                <h4 class="mb-3"><i class="bi bi-hand-wave me-2 text-primary"></i>Benvenuto nell'installazione</h4>
                <p class="text-muted">Questo wizard ti guiderà nella configurazione del sistema HelpDesk. Avrai bisogno di:</p>
                <ul class="text-muted">
                    <li>Credenziali di accesso al database MySQL</li>
                    <li>Nome e credenziali per l'account amministratore</li>
                </ul>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Assicurati che il database MySQL sia accessibile prima di procedere.
                </div>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Versioni richieste:</strong> PHP 7.4+ e MySQL 5.7+
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="next_step1">
                    <button type="submit" class="btn btn-primary">Avanti <i class="bi bi-arrow-right ms-1"></i></button>
                </form>
            </div>

            <!-- STEP 2: Database -->
            <div class="wizard-step <?= $step === 2 ? 'active' : '' ?>" id="step-2">
                <h4 class="mb-3"><i class="bi bi-database me-2 text-primary"></i>Configurazione Database</h4>
                <?php if ($dbError): ?>
                <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($dbError) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_step2">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Host Database</label>
                            <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_SESSION['db_host'] ?? 'localhost') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Porta</label>
                            <input type="text" name="db_port" class="form-control" value="<?= htmlspecialchars($_SESSION['db_port'] ?? '3306') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Nome Database</label>
                            <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_SESSION['db_name'] ?? '') ?>" required placeholder="helpdesk">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Utente Database</label>
                            <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_SESSION['db_user'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password Database</label>
                            <input type="password" name="db_pass" class="form-control" value="">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Indietro</a>
                        <button type="submit" class="btn btn-primary">Verifica e Avanti <i class="bi bi-arrow-right ms-1"></i></button>
                    </div>
                </form>
            </div>

            <!-- STEP 3: App Config -->
            <div class="wizard-step <?= $step === 3 ? 'active' : '' ?>" id="step-3">
                <h4 class="mb-3"><i class="bi bi-gear me-2 text-primary"></i>Configurazione Applicazione</h4>
                <?php if ($adminError): ?>
                <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($adminError) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_step3">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Nome Applicazione</label>
                            <input type="text" name="app_name" class="form-control" value="<?= htmlspecialchars($_SESSION['app_name'] ?? 'HelpDesk') ?>" required>
                        </div>
                        <div class="col-12"><hr><h6 class="text-muted">Account Amministratore</h6></div>
                        <div class="col-md-6">
                            <label class="form-label">Nome Utente</label>
                            <input type="text" name="admin_username" class="form-control" value="<?= htmlspecialchars($_SESSION['admin_username'] ?? 'admin') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($_SESSION['admin_email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password (min. 8 caratteri)</label>
                            <input type="password" name="admin_password" class="form-control" minlength="8" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Conferma Password</label>
                            <input type="password" name="admin_password2" class="form-control" minlength="8" required>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Indietro</a>
                        <button type="submit" class="btn btn-primary">Avanti <i class="bi bi-arrow-right ms-1"></i></button>
                    </div>
                </form>
            </div>

            <!-- STEP 4: Install -->
            <div class="wizard-step <?= $step === 4 ? 'active' : '' ?>" id="step-4">
                <h4 class="mb-3"><i class="bi bi-download me-2 text-primary"></i>Installazione</h4>
                <p class="text-muted">Clicca "Installa" per creare le tabelle del database, inserire i dati di default e scrivere il file di configurazione.</p>
                <div id="install-progress" class="d-none">
                    <div class="progress mb-3">
                        <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                    </div>
                    <div id="install-log" class="bg-dark text-light p-3 rounded" style="font-family:monospace;font-size:0.85rem;max-height:250px;overflow-y:auto;"></div>
                </div>
                <div id="install-start">
                    <div class="alert alert-info">
                        <strong>Riepilogo:</strong><br>
                        Database: <code><?= htmlspecialchars(($_SESSION['db_name'] ?? '')) ?></code> su <code><?= htmlspecialchars(($_SESSION['db_host'] ?? '')) ?></code><br>
                        App: <code><?= htmlspecialchars(($_SESSION['app_name'] ?? '')) ?></code><br>
                        Admin: <code><?= htmlspecialchars(($_SESSION['admin_username'] ?? '')) ?></code>
                    </div>
                    <button id="btn-install" class="btn btn-success btn-lg">
                        <i class="bi bi-play-circle me-2"></i>Installa
                    </button>
                </div>
            </div>

            <!-- STEP 5: Done -->
            <div class="wizard-step <?= $step === 5 ? 'active' : '' ?>" id="step-5">
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:4rem;"></i>
                    <h3 class="mt-3">Installazione Completata!</h3>
                    <p class="text-muted">HelpDesk è stato installato con successo. Puoi ora effettuare il login con le credenziali che hai impostato.</p>
                    <div class="alert alert-warning d-inline-block">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Per sicurezza, elimina o rinomina la cartella <code>install/</code> dal server.
                    </div>
                    <br>
                    <a href="../login.php" class="btn btn-primary btn-lg mt-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Vai al Login
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    $('#btn-install').on('click', function() {
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Installazione...');
        $('#install-start .alert').hide();
        $('#install-progress').removeClass('d-none');

        $.ajax({
            url: 'install.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'install' },
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    $('#progress-bar').css('width', '100%').removeClass('progress-bar-animated');
                    $('#install-log').append('<div class="text-success">✓ Installazione completata!</div>');
                    setTimeout(function() {
                        window.location.href = 'index.php?step=5';
                    }, 1500);
                } else {
                    $('#progress-bar').addClass('bg-danger').removeClass('progress-bar-animated');
                    $('#install-log').append('<div class="text-danger">✗ Errore: ' + (response.error || 'Sconosciuto') + '</div>');
                    $('#btn-install').prop('disabled', false).html('<i class="bi bi-play-circle me-2"></i>Riprova');
                }
            },
            error: function(xhr) {
                $('#install-log').append('<div class="text-danger">✗ Errore di comunicazione: ' + xhr.responseText + '</div>');
                $('#btn-install').prop('disabled', false).html('<i class="bi bi-play-circle me-2"></i>Riprova');
            }
        });

        // Simulate progress
        var p = 0;
        var interval = setInterval(function() {
            p = Math.min(p + Math.random() * 15, 90);
            $('#progress-bar').css('width', p + '%');
        }, 400);
        setTimeout(function() { clearInterval(interval); }, 6000);
    });
});
</script>
</body>
</html>
