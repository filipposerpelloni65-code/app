<?php
/**
 * api/brt_labels_a4.php
 * Renders BRT labels in A4 format (8 labels per page, 2 columns × 4 rows).
 *
 * Usage:
 *   GET /api/brt_labels_a4.php?id=<spedizione_id>
 *   GET /api/brt_labels_a4.php?bordero_id=<bordero_id>
 *   GET /api/brt_labels_a4.php?ids=1,2,3
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$db = getDB();

// Collect label streams from DB
$labelData = []; // array of ['parcelID' => ..., 'stream' => base64, 'sped_id' => ...]

if (!empty($_GET['bordero_id'])) {
    $borderoId = (int)$_GET['bordero_id'];
    $bordero = $db->prepare("SELECT spedizioni_ids FROM borderi WHERE id=?");
    $bordero->execute([$borderoId]);
    $bRow = $bordero->fetch();
    if ($bRow) {
        $ids = json_decode($bRow['spedizioni_ids'], true) ?? [];
        if ($ids) {
            $inList = implode(',', array_map('intval', $ids));
            $rows = $db->query("SELECT id, brt_labels_json, brt_label_stream, brt_parcel_id FROM spedizioni WHERE id IN ($inList) ORDER BY id")->fetchAll();
            foreach ($rows as $r) {
                _appendLabels($r, $labelData);
            }
        }
    }
} elseif (!empty($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    if ($ids) {
        $inList = implode(',', $ids);
        $rows = $db->query("SELECT id, brt_labels_json, brt_label_stream, brt_parcel_id FROM spedizioni WHERE id IN ($inList) ORDER BY id")->fetchAll();
        foreach ($rows as $r) {
            _appendLabels($r, $labelData);
        }
    }
} else {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("SELECT id, brt_labels_json, brt_label_stream, brt_parcel_id FROM spedizioni WHERE id=?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r) {
            _appendLabels($r, $labelData);
        }
    }
}

function _appendLabels(array $row, array &$out): void
{
    if (!empty($row['brt_labels_json'])) {
        $labels = json_decode($row['brt_labels_json'], true) ?? [];
        foreach ($labels as $lbl) {
            if (!empty($lbl['stream'])) {
                $out[] = [
                    'parcelID' => $lbl['parcelID'] ?? $row['brt_parcel_id'] ?? '?',
                    'stream'   => $lbl['stream'],
                    'sped_id'  => $row['id'],
                ];
            }
        }
    } elseif (!empty($row['brt_label_stream'])) {
        // Legacy: single label
        $out[] = [
            'parcelID' => $row['brt_parcel_id'] ?? '?',
            'stream'   => $row['brt_label_stream'],
            'sped_id'  => $row['id'],
        ];
    }
}

if (!$labelData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><p>Nessuna etichetta disponibile.</p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Etichette BRT – A4</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: #e0e0e0;
        font-family: Arial, sans-serif;
    }

    .toolbar {
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #1a1a2e;
        color: #fff;
        padding: 10px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 100;
    }
    .toolbar h6 { font-size: 14px; font-weight: 600; flex: 1; }
    .toolbar button, .toolbar a {
        background: #3b82f6; color: #fff; border: none;
        padding: 6px 14px; border-radius: 6px; cursor: pointer;
        font-size: 13px; text-decoration: none;
    }
    .toolbar button:hover, .toolbar a:hover { background: #2563eb; }
    .toolbar .btn-secondary { background: #4b5563; }
    .toolbar .btn-secondary:hover { background: #374151; }

    .pages-container {
        margin-top: 60px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
    }

    /* A4 page: 210mm × 297mm at 96dpi ≈ 794 × 1123px */
    .a4-page {
        width: 210mm;
        min-height: 297mm;
        background: #fff;
        box-shadow: 0 4px 16px rgba(0,0,0,.25);
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: repeat(4, 1fr);
        padding: 5mm;
        gap: 3mm;
        page-break-after: always;
    }

    .label-cell {
        border: 1px dashed #999;
        overflow: hidden;
        position: relative;
        width: 99mm;
        height: 70.5mm; /* (297 - 10 - 9) / 4 ≈ 69.5mm */
    }

    .label-cell iframe,
    .label-cell embed,
    .label-cell object {
        width: 100%;
        height: 100%;
        border: none;
        display: block;
    }

    .label-num {
        position: absolute;
        bottom: 2px;
        right: 4px;
        font-size: 9px;
        color: #888;
        background: rgba(255,255,255,.8);
        padding: 1px 3px;
        border-radius: 2px;
        pointer-events: none;
    }

    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .pages-container { margin-top: 0; padding: 0; gap: 0; }
        .a4-page {
            box-shadow: none;
            page-break-after: always;
            break-after: page;
            /* Ensure exact A4 size */
            width: 210mm;
            height: 297mm;
        }
        .label-cell { border-color: transparent; }
        .label-num { display: none; }
    }

    @page { size: A4; margin: 0; }
</style>
</head>
<body>
<div class="toolbar">
    <h6>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" style="margin-right:6px;vertical-align:-2px"><path d="M8 1a2.5 2.5 0 0 1 2.5 2.5V4h-5v-.5A2.5 2.5 0 0 1 8 1zm3.5 3v-.5a3.5 3.5 0 1 0-7 0V4H1v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4h-3.5z"/></svg>
        Etichette BRT — <?= count($labelData) ?> etichett<?= count($labelData) !== 1 ? 'e' : 'a' ?> su A4 (8 per foglio)
    </h6>
    <button onclick="window.print()">🖨 Stampa / Salva PDF</button>
    <a href="javascript:history.back()" class="btn-secondary">← Indietro</a>
</div>

<div class="pages-container">
<?php
$labelsPerPage = 8;
$chunks = array_chunk($labelData, $labelsPerPage);
$globalIdx = 1;

foreach ($chunks as $chunk):
?>
<div class="a4-page">
    <?php foreach ($chunk as $lbl):
        $pdfDataUri = 'data:application/pdf;base64,' . $lbl['stream'];
    ?>
    <div class="label-cell">
        <object data="<?= $pdfDataUri ?>" type="application/pdf">
            <embed src="<?= $pdfDataUri ?>" type="application/pdf" />
        </object>
        <span class="label-num"><?= h($lbl['parcelID']) ?> (#<?= (int)$lbl['sped_id'] ?>)</span>
    </div>
    <?php $globalIdx++; endforeach; ?>
    <?php
    // Fill empty cells to complete the grid
    $empty = $labelsPerPage - count($chunk);
    for ($e = 0; $e < $empty; $e++):
    ?>
    <div class="label-cell" style="border-color:transparent;background:#fafafa;"></div>
    <?php endfor; ?>
</div>
<?php endforeach; ?>
</div>

</body>
</html>
