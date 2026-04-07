<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('componenti')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

define('PAGE_TITLE', 'Nuovo Modello Componente');
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Componenti' => APP_URL.'/modules/componenti/index.php', 'Nuovo' => '']);

$db   = getDB();
$user = currentUser();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }

    $tipo        = $_POST['tipo'] ?? '';
    $nome        = trim($_POST['nome'] ?? '');
    $marca       = trim($_POST['marca'] ?? '') ?: null;
    $descrizione = trim($_POST['descrizione'] ?? '') ?: null;
    $active      = isset($_POST['active']) ? 1 : 0;

    if (!in_array($tipo, ['periferica', 'accessorio', 'cavo'])) $errors[] = 'Tipo non valido.';
    if (!$nome) $errors[] = 'Il nome del modello è obbligatorio.';

    if (!$errors) {
        $stmt = $db->prepare("INSERT INTO modelli_componenti (tipo, nome, marca, descrizione, active) VALUES (?,?,?,?,?)");
        $stmt->execute([$tipo, $nome, $marca, $descrizione, $active]);
        $newId = $db->lastInsertId();
        logActivity($user['id'], 'create', 'modello_componente', $newId, "Creato modello: $nome ($tipo)");
        header('Location: ' . APP_URL . '/modules/componenti/index.php?created=1');
        exit;
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-6">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Nuovo Modello Componente</h4>
    <a href="<?= APP_URL ?>/modules/componenti/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna alla lista</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Tipo <span class="text-danger">*</span></label>
        <select name="tipo" class="form-select" required>
            <option value="">-- Seleziona tipo --</option>
            <option value="periferica" <?= ($_POST['tipo'] ?? '') === 'periferica' ? 'selected' : '' ?>>Periferica (ha seriale)</option>
            <option value="accessorio" <?= ($_POST['tipo'] ?? '') === 'accessorio' ? 'selected' : '' ?>>Accessorio (senza seriale)</option>
            <option value="cavo"       <?= ($_POST['tipo'] ?? '') === 'cavo'       ? 'selected' : '' ?>>Cavo (senza seriale)</option>
        </select>
        <div class="form-text">Le periferiche richiedono un numero di serie. Accessori e cavi no.</div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Nome Modello <span class="text-danger">*</span></label>
        <input type="text" name="nome" class="form-control" required value="<?= h($_POST['nome'] ?? '') ?>" placeholder="Es. Stampante Termica, Lettore Codici a Barre...">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Marca</label>
        <input type="text" name="marca" class="form-control" value="<?= h($_POST['marca'] ?? '') ?>" placeholder="Es. Zebra, Honeywell, Epson...">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione</label>
        <textarea name="descrizione" class="form-control" rows="3"><?= h($_POST['descrizione'] ?? '') ?></textarea>
    </div>
    <div class="mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" id="active" <?= (!isset($_POST['active']) || $_POST['active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Modello attivo (disponibile per la selezione nei ticket)</label>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Modello</button>
        <a href="<?= APP_URL ?>/modules/componenti/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
