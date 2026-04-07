<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('periferiche')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }
if (!isTechnician()) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

$db   = getDB();
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM periferiche_guaste WHERE id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { header('Location: ' . APP_URL . '/modules/periferiche/index.php'); exit; }

// Already finalized: no state changes
if (in_array($p['stato'], ['restituita','rottamata'])) {
    header('Location: ' . APP_URL . '/modules/periferiche/view.php?id=' . $id);
    exit;
}

define('PAGE_TITLE', 'Diagnosi / Stato — ' . $p['codice']);
define('BREADCRUMB', ['Dashboard' => APP_URL.'/dashboard.php', 'Periferiche Guaste' => APP_URL.'/modules/periferiche/index.php', h($p['codice']) => APP_URL.'/modules/periferiche/view.php?id='.$id, 'Diagnosi' => '']);

// Transizioni di stato consentite per ciascuno stato corrente
$transizioniConsentite = [
    'in_giacenza'    => ['in_diagnosi'],
    'in_diagnosi'    => ['in_riparazione','non_riparabile'],
    'in_riparazione' => ['riparata'],
    'riparata'       => ['restituita'],
    'non_riparabile' => ['rottamata'],
];
$statiPossibili = $transizioniConsentite[$p['stato']] ?? [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { $errors[] = 'Token non valido. Riprova.'; }

    $nuovo_stato  = $_POST['stato'] ?? '';
    $note_diagnosi = trim($_POST['note_diagnosi'] ?? '');
    $note_interne  = trim($_POST['note_interne'] ?? '');
    $rapportino_id = (int)($_POST['rapportino_id'] ?? 0) ?: null;

    if (!in_array($nuovo_stato, $statiPossibili)) {
        $errors[] = 'Transizione di stato non consentita.';
    }

    if (!$errors) {
        $fields = "stato=?, note_diagnosi=?, note_interne=?, updated_at=NOW()";
        $vals   = [$nuovo_stato, $note_diagnosi, $note_interne];

        if ($rapportino_id) {
            $fields .= ", rapportino_id=?";
            $vals[]  = $rapportino_id;
            // Also update rapportini to link back periferica_id
            $db->prepare("UPDATE rapportini SET periferica_id=?, updated_at=NOW() WHERE id=?")->execute([$id, $rapportino_id]);
        }

        $vals[] = $id;
        $db->prepare("UPDATE periferiche_guaste SET $fields WHERE id=?")->execute($vals);

        logActivity($user['id'], 'stato_change', 'periferica', $id, "Stato cambiato in: $nuovo_stato");
        header('Location: ' . APP_URL . '/modules/periferiche/view.php?id=' . $id . '&updated=1');
        exit;
    }
}

// Load rapportini linked to same ticket or without periferica_id (for dropdown)
$rapportiniDisponibili = [];
if (in_array($p['stato'], ['in_riparazione','riparata']) || in_array('riparata', $statiPossibili)) {
    $rapSql = "SELECT id, title, status, intervention_date FROM rapportini WHERE periferica_id IS NULL OR periferica_id=? ORDER BY id DESC LIMIT 50";
    $rapStmt = $db->prepare($rapSql);
    $rapStmt->execute([$id]);
    $rapportiniDisponibili = $rapStmt->fetchAll();
}

include APP_ROOT . '/includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2 text-warning"></i>Diagnosi / Aggiornamento Stato</h4>
    <a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Torna al dettaglio</a>
</div>

<!-- Stato corrente -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="text-muted small">Periferica</div>
                <div class="fw-bold font-monospace"><?= h($p['codice']) ?></div>
                <div class="small"><?= h($p['tipo']) ?><?= $p['marca'] ? ' — '.h($p['marca']) : '' ?><?= $p['modello'] ? ' '.h($p['modello']) : '' ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Stato Corrente</div>
                <?= getPerifericaStatoBadge($p['stato']) ?>
            </div>
            <?php if ($p['seriale']): ?>
            <div class="col-md-4">
                <div class="text-muted small">Seriale</div>
                <div class="font-monospace small"><?= h($p['seriale']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($statiPossibili): ?>
<div class="card border-0 shadow-sm">
<div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-arrow-right-circle me-1 text-warning"></i>Aggiorna Stato</h6></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>

    <div class="mb-4">
        <label class="form-label fw-semibold">Nuovo Stato <span class="text-danger">*</span></label>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($statiPossibili as $s):
                $colors = [
                    'in_diagnosi'    => 'info',
                    'in_riparazione' => 'warning',
                    'riparata'       => 'success',
                    'restituita'     => 'primary',
                    'non_riparabile' => 'danger',
                    'rottamata'      => 'dark',
                ];
                $c = $colors[$s] ?? 'secondary';
            ?>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="stato" id="stato_<?= $s ?>" value="<?= $s ?>" required <?= (count($statiPossibili)===1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="stato_<?= $s ?>"><?= getPerifericaStatoBadge($s) ?></label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Note Diagnosi / Aggiornamento</label>
        <textarea name="note_diagnosi" class="form-control" rows="5" placeholder="Descrizione diagnosi, lavori eseguiti, esito..."><?= h($p['note_diagnosi'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Note Interne <small class="text-muted fw-normal">(solo operatori)</small></label>
        <textarea name="note_interne" class="form-control" rows="2"><?= h($p['note_interne'] ?? '') ?></textarea>
    </div>

    <?php if ($rapportiniDisponibili): ?>
    <div class="mb-3">
        <label class="form-label fw-semibold">Collega Rapportino di Riparazione</label>
        <select name="rapportino_id" class="form-select">
            <option value="">-- Nessun rapportino --</option>
            <?php foreach ($rapportiniDisponibili as $r): ?>
            <option value="<?= $r['id'] ?>" <?= ($p['rapportino_id'] == $r['id']) ? 'selected' : '' ?>>RAP-<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?> — <?= h($r['title']) ?> (<?= getRapportinoStatusBadge($r['status']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Oppure <a href="<?= APP_URL ?>/modules/rapportini/create.php?periferica_id=<?= $id ?><?= $p['ticket_id'] ? '&ticket_id='.$p['ticket_id'] : '' ?>">crea un nuovo rapportino</a> per questa periferica.</div>
    </div>
    <?php else: ?>
    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        Una volta completata la riparazione, <a href="<?= APP_URL ?>/modules/rapportini/create.php?periferica_id=<?= $id ?><?= $p['ticket_id'] ? '&ticket_id='.$p['ticket_id'] : '' ?>">crea un rapportino</a> e collegalo a questa periferica.
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i>Aggiorna Stato</button>
        <a href="<?= APP_URL ?>/modules/periferiche/view.php?id=<?= $id ?>" class="btn btn-light">Annulla</a>
    </div>
</form>
</div>
</div>
<?php else: ?>
<div class="alert alert-secondary"><i class="bi bi-info-circle me-2"></i>Nessuna transizione di stato disponibile per lo stato corrente.</div>
<?php endif; ?>

</div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
