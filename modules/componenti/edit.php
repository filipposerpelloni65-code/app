<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireRole('admin');
if (!isModuleEnabled('componenti')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/componenti/index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM modelli_componenti WHERE id=?");
$stmt->execute([$id]);
$modello = $stmt->fetch();
if (!$modello) { header('Location: ' . APP_URL . '/modules/componenti/index.php'); exit; }

define('PAGE_TITLE', 'Modifica Modello: ' . $modello['nome']);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Componenti' => APP_URL.'/modules/componenti/index.php', 'Modifica' => '']);

$errors = [];

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
        $db->prepare("UPDATE modelli_componenti SET tipo=?, nome=?, marca=?, descrizione=?, active=?, updated_at=NOW() WHERE id=?")
           ->execute([$tipo, $nome, $marca, $descrizione, $active, $id]);
        logActivity($user['id'], 'update', 'modello_componente', $id, "Modificato modello: $nome");
        header('Location: ' . APP_URL . '/modules/componenti/index.php?updated=1');
        exit;
    }
    // Re-populate from POST on validation error
    $modello = array_merge($modello, ['tipo' => $tipo, 'nome' => $nome, 'marca' => $marca ?? '', 'descrizione' => $descrizione ?? '', 'active' => $active]);
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-6">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2 text-secondary"></i>Modifica Modello</h4>
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
            <option value="periferica" <?= $modello['tipo'] === 'periferica' ? 'selected' : '' ?>>Periferica (ha seriale)</option>
            <option value="accessorio" <?= $modello['tipo'] === 'accessorio' ? 'selected' : '' ?>>Accessorio (senza seriale)</option>
            <option value="cavo"       <?= $modello['tipo'] === 'cavo'       ? 'selected' : '' ?>>Cavo (senza seriale)</option>
        </select>
        <div class="form-text">Le periferiche richiedono un numero di serie. Accessori e cavi no.</div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Nome Modello <span class="text-danger">*</span></label>
        <input type="text" name="nome" class="form-control" required value="<?= h($modello['nome']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Marca</label>
        <input type="text" name="marca" class="form-control" value="<?= h($modello['marca'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Descrizione</label>
        <textarea name="descrizione" class="form-control" rows="3"><?= h($modello['descrizione'] ?? '') ?></textarea>
    </div>
    <div class="mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" id="active" <?= $modello['active'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="active">Modello attivo</label>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Aggiorna</button>
        <a href="<?= APP_URL ?>/modules/componenti/index.php" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
