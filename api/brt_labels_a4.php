<?php
/**
 * api/brt_labels_a4.php
 * Renders BRT labels in A4 format using PDF.js for pixel-perfect rendering.
 * Layout: 2 columns × 2 rows = 4 labels per A4 page (optimal for BRT A6 labels).
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

// Collect label streams from DB (with enriched metadata)
$labelData = [];

$_fetchRows = function(string $inList) use ($db): array {
    return $db->query("
        SELECT s.id, s.brt_labels_json, s.brt_label_stream, s.brt_parcel_id,
               s.tracking_number, s.num_colli, s.peso_kg,
               d.name AS dealer_name, d.code AS dealer_code,
               dl.name AS location_name
        FROM spedizioni s
        LEFT JOIN dealers d ON s.dealer_id = d.id
        LEFT JOIN dealer_locations dl ON s.location_id = dl.id
        WHERE s.id IN ($inList)
        ORDER BY s.id
    ")->fetchAll();
};

if (!empty($_GET['bordero_id'])) {
    $borderoId = (int)$_GET['bordero_id'];
    $bordero = $db->prepare("SELECT spedizioni_ids FROM borderi WHERE id=?");
    $bordero->execute([$borderoId]);
    $bRow = $bordero->fetch();
    if ($bRow) {
        $ids = json_decode($bRow['spedizioni_ids'], true) ?? [];
        if ($ids) {
            $inList = implode(',', array_map('intval', $ids));
            foreach ($_fetchRows($inList) as $r) {
                _appendLabels($r, $labelData);
            }
        }
    }
} elseif (!empty($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    if ($ids) {
        $inList = implode(',', $ids);
        foreach ($_fetchRows($inList) as $r) {
            _appendLabels($r, $labelData);
        }
    }
} else {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("
            SELECT s.id, s.brt_labels_json, s.brt_label_stream, s.brt_parcel_id,
                   s.tracking_number, s.num_colli, s.peso_kg,
                   d.name AS dealer_name, d.code AS dealer_code,
                   dl.name AS location_name
            FROM spedizioni s
            LEFT JOIN dealers d ON s.dealer_id = d.id
            LEFT JOIN dealer_locations dl ON s.location_id = dl.id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r) {
            _appendLabels($r, $labelData);
        }
    }
}

function _appendLabels(array $row, array &$out): void
{
    $meta = [
        'sped_id'      => $row['id'],
        'tracking'     => $row['tracking_number'] ?: ($row['brt_parcel_id'] ?: ''),
        'dealer_name'  => $row['dealer_name'] ?? '',
        'dealer_code'  => $row['dealer_code'] ?? '',
        'location_name'=> $row['location_name'] ?? '',
        'num_colli'    => (int)($row['num_colli'] ?? 1),
        'peso_kg'      => (float)($row['peso_kg'] ?? 1),
    ];

    if (!empty($row['brt_labels_json'])) {
        $labels = json_decode($row['brt_labels_json'], true) ?? [];
        foreach ($labels as $lbl) {
            if (!empty($lbl['stream'])) {
                $out[] = array_merge($meta, [
                    'parcelID' => $lbl['parcelID'] ?? $row['brt_parcel_id'] ?? '?',
                    'stream'   => $lbl['stream'],
                ]);
            }
        }
    } elseif (!empty($row['brt_label_stream'])) {
        // Legacy: single label
        $out[] = array_merge($meta, [
            'parcelID' => $row['brt_parcel_id'] ?? '?',
            'stream'   => $row['brt_label_stream'],
        ]);
    }
}

if (!$labelData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;text-align:center"><h3>Nessuna etichetta disponibile</h3><p style="color:#666">Le etichette BRT non sono ancora state generate per le spedizioni selezionate.</p><button onclick="history.back()" style="margin-top:16px;padding:8px 20px;cursor:pointer">← Indietro</button></body></html>';
    exit;
}

// Prepare data for JavaScript (strip stream from meta to keep JSON lean, pass separately)
$labelsForJs = array_map(function($l) {
    return [
        'parcelID'     => $l['parcelID'],
        'sped_id'      => $l['sped_id'],
        'tracking'     => $l['tracking'],
        'dealer_name'  => $l['dealer_name'],
        'dealer_code'  => $l['dealer_code'],
        'location_name'=> $l['location_name'],
        'num_colli'    => $l['num_colli'],
        'peso_kg'      => $l['peso_kg'],
        'pdf'          => $l['stream'],
    ];
}, $labelData);

$totalColli = array_sum(array_column($labelData, 'num_colli'));
$totalPeso  = array_sum(array_column($labelData, 'peso_kg'));
$totalLabels = count($labelData);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Etichette BRT – A4</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background: #cbd5e1;
        font-family: Arial, sans-serif;
        color: #1e293b;
    }

    /* ── Toolbar ── */
    .toolbar {
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #0f172a;
        color: #fff;
        padding: 0 16px;
        height: 54px;
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 200;
        box-shadow: 0 2px 8px rgba(0,0,0,.4);
    }
    .toolbar-title {
        flex: 1;
        font-size: 14px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .toolbar-badge {
        background: #3b82f6;
        border-radius: 12px;
        padding: 2px 10px;
        font-size: 12px;
        font-weight: 700;
    }
    .toolbar-stats {
        font-size: 12px;
        color: #94a3b8;
    }
    .btn-toolbar {
        background: #3b82f6;
        color: #fff;
        border: none;
        padding: 7px 16px;
        border-radius: 7px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background .15s;
    }
    .btn-toolbar:hover { background: #2563eb; }
    .btn-toolbar:disabled { background: #475569; cursor: not-allowed; opacity: .7; }
    .btn-toolbar-sec { background: #334155; }
    .btn-toolbar-sec:hover { background: #1e293b; }

    /* ── Loading overlay ── */
    #loading-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15,23,42,.75);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 300;
        color: #fff;
    }
    .spinner {
        width: 48px; height: 48px;
        border: 5px solid rgba(255,255,255,.2);
        border-top-color: #3b82f6;
        border-radius: 50%;
        animation: spin .8s linear infinite;
        margin-bottom: 16px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    #loading-text { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
    #loading-progress { font-size: 13px; color: #94a3b8; }
    .progress-bar-wrap {
        width: 240px; height: 6px;
        background: rgba(255,255,255,.15);
        border-radius: 3px;
        margin-top: 12px;
        overflow: hidden;
    }
    .progress-bar-fill {
        height: 100%;
        background: #3b82f6;
        border-radius: 3px;
        transition: width .3s;
        width: 0%;
    }

    /* ── Pages container ── */
    .pages-container {
        margin-top: 70px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 24px;
    }

    /* ── A4 page: 210mm × 297mm ── */
    .a4-page {
        width: 210mm;
        height: 297mm;
        background: #fff;
        box-shadow: 0 6px 24px rgba(0,0,0,.3);
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: 1fr 1fr;
        padding: 8mm;
        gap: 4mm;
        position: relative;
        overflow: hidden;
    }
    .page-number {
        position: absolute;
        bottom: 4mm;
        right: 6mm;
        font-size: 9px;
        color: #94a3b8;
    }

    /* ── Label cell ── */
    .label-cell {
        border: 1px dashed #cbd5e1;
        border-radius: 3px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        background: #fff;
        position: relative;
    }
    .label-cell.label-empty {
        background: #f8fafc;
        border-color: #e2e8f0;
        border-style: solid;
    }

    /* Canvas wrapper — fills remaining cell space after footer */
    .canvas-wrap {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background: #fff;
        min-height: 0;
    }
    .canvas-wrap canvas {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        display: block;
    }
    .render-error {
        color: #ef4444;
        font-size: 12px;
        text-align: center;
        padding: 8px;
    }

    /* Footer bar under each label */
    .label-footer {
        background: #f1f5f9;
        border-top: 1px solid #e2e8f0;
        padding: 3px 6px;
        font-size: 9px;
        color: #475569;
        display: flex;
        flex-direction: column;
        gap: 1px;
        flex-shrink: 0;
    }
    .label-footer-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .label-parcel {
        font-weight: 700;
        color: #1e3a8a;
        font-size: 9.5px;
        font-family: monospace;
        letter-spacing: .5px;
    }
    .label-dealer {
        color: #374151;
        font-weight: 600;
        font-size: 9px;
    }
    .label-meta {
        color: #6b7280;
        font-size: 8.5px;
    }

    /* ── Print styles ── */
    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        #loading-overlay { display: none !important; }
        .pages-container {
            margin-top: 0;
            padding: 0;
            gap: 0;
        }
        .a4-page {
            box-shadow: none;
            page-break-after: always;
            break-after: page;
            width: 210mm;
            height: 297mm;
        }
        .a4-page:last-child {
            page-break-after: avoid;
            break-after: avoid;
        }
        .label-cell { border-color: #ccc; border-style: solid; }
        .page-number { display: none; }
    }

    @page { size: A4 portrait; margin: 0; }
</style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-title">
        🏷️ Etichette BRT
        <span class="toolbar-badge"><?= $totalLabels ?> etichett<?= $totalLabels !== 1 ? 'e' : 'a' ?></span>
    </div>
    <div class="toolbar-stats">
        <?= $totalColli ?> colli · <?= number_format($totalPeso, 2) ?> kg
    </div>
    <button id="btnPrint" class="btn-toolbar" onclick="window.print()" disabled>
        🖨️ Stampa / Salva PDF
    </button>
    <a href="javascript:history.back()" class="btn-toolbar btn-toolbar-sec">← Indietro</a>
</div>

<!-- Loading overlay -->
<div id="loading-overlay">
    <div class="spinner"></div>
    <div id="loading-text">Caricamento etichette...</div>
    <div id="loading-progress">Inizializzazione PDF.js…</div>
    <div class="progress-bar-wrap">
        <div class="progress-bar-fill" id="progress-bar"></div>
    </div>
</div>

<!-- Pages populated by JavaScript -->
<div class="pages-container" id="pages"></div>

<!-- PDF.js from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(function () {
    'use strict';

    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    // ── Constants ──────────────────────────────────────────────────────────
    const LABELS_PER_PAGE = 4; // 2 columns × 2 rows on A4 — optimal for BRT A6 labels
    // Cell inner dimensions (A4 208mm - 2*8mm padding - 1*4mm gap) / 2 cols × (297mm - 2*8mm - 4mm) / 2 rows
    // ≈ 95mm × 134.5mm
    const CELL_W_MM  = 95;
    const CELL_H_MM  = 134.5;
    const RENDER_DPI = 150; // dots per inch for render quality

    // ── Label data from PHP ────────────────────────────────────────────────
    const LABELS = <?= json_encode($labelsForJs, JSON_UNESCAPED_UNICODE) ?>;

    // ── Helpers ───────────────────────────────────────────────────────────
    function mmToPx(mm, dpi) { return Math.round(mm / 25.4 * dpi); }
    function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function setProgress(done, total) {
        const pct = total ? Math.round(done / total * 100) : 0;
        document.getElementById('loading-progress').textContent =
            'Rendering etichetta ' + done + ' di ' + total + '…';
        document.getElementById('progress-bar').style.width = pct + '%';
    }

    // ── Render a single BRT label PDF onto a canvas ────────────────────────
    async function renderLabel(base64pdf, canvas) {
        const dataUri = 'data:application/pdf;base64,' + base64pdf;
        const pdf  = await pdfjsLib.getDocument(dataUri).promise;
        const page = await pdf.getPage(1);

        // Compute scale to fit label in cell at RENDER_DPI
        const cellW = mmToPx(CELL_W_MM, RENDER_DPI);
        const cellH = mmToPx(CELL_H_MM, RENDER_DPI);

        const vp1   = page.getViewport({ scale: 1 });
        const scale = Math.min(cellW / vp1.width, cellH / vp1.height);
        const vp    = page.getViewport({ scale });

        canvas.width  = Math.round(vp.width);
        canvas.height = Math.round(vp.height);

        // CSS display size (screen 96 DPI)
        const screenW = mmToPx(CELL_W_MM, 96);
        const screenH = Math.round(canvas.height * screenW / canvas.width);
        canvas.style.width  = screenW + 'px';
        canvas.style.height = screenH + 'px';

        await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    }

    // ── Build page DOM and queue render tasks ─────────────────────────────
    async function init() {
        const container = document.getElementById('pages');
        const renderTasks = []; // { canvas, base64 }

        const chunks = [];
        for (let i = 0; i < LABELS.length; i += LABELS_PER_PAGE) {
            chunks.push(LABELS.slice(i, i + LABELS_PER_PAGE));
        }

        chunks.forEach(function(chunk, pageIdx) {
            const pageEl = document.createElement('div');
            pageEl.className = 'a4-page';

            chunk.forEach(function(lbl) {
                const cell = document.createElement('div');
                cell.className = 'label-cell';

                const wrap = document.createElement('div');
                wrap.className = 'canvas-wrap';

                const canvas = document.createElement('canvas');
                wrap.appendChild(canvas);
                cell.appendChild(wrap);

                // Footer with identification info
                const foot = document.createElement('div');
                foot.className = 'label-footer';

                const row1 = document.createElement('div');
                row1.className = 'label-footer-row';
                row1.innerHTML =
                    '<span class="label-parcel">📦 ' + esc(lbl.parcelID) + '</span>' +
                    '<span class="label-meta">Sped. #' + lbl.sped_id + '</span>';

                const row2 = document.createElement('div');
                row2.className = 'label-footer-row';

                const dealerLabel = lbl.dealer_name
                    ? esc(lbl.dealer_name) + (lbl.dealer_code ? ' (' + esc(lbl.dealer_code) + ')' : '')
                    : '';
                const colli = lbl.num_colli + ' coll' + (lbl.num_colli === 1 ? 'o' : 'i') +
                              ' · ' + parseFloat(lbl.peso_kg).toFixed(2) + ' kg';

                row2.innerHTML =
                    '<span class="label-dealer">' + dealerLabel + '</span>' +
                    '<span class="label-meta">' + esc(colli) + '</span>';

                foot.appendChild(row1);
                if (dealerLabel) foot.appendChild(row2);
                cell.appendChild(foot);

                pageEl.appendChild(cell);

                renderTasks.push({ canvas: canvas, pdf: lbl.pdf });
            });

            // Fill empty cells to complete the 2×2 grid
            for (let e = chunk.length; e < LABELS_PER_PAGE; e++) {
                const empty = document.createElement('div');
                empty.className = 'label-cell label-empty';
                pageEl.appendChild(empty);
            }

            const pgNum = document.createElement('div');
            pgNum.className = 'page-number';
            pgNum.textContent = 'Pag. ' + (pageIdx + 1) + ' / ' + chunks.length;
            pageEl.appendChild(pgNum);

            container.appendChild(pageEl);
        });

        // Render all labels — concurrent batches of 3 to avoid memory issues
        setProgress(0, renderTasks.length);
        let done = 0;
        const BATCH = 3;

        for (let i = 0; i < renderTasks.length; i += BATCH) {
            const batch = renderTasks.slice(i, i + BATCH);
            await Promise.all(batch.map(async function(task) {
                try {
                    await renderLabel(task.pdf, task.canvas);
                } catch (err) {
                    console.error('PDF render error:', err);
                    task.canvas.parentElement.innerHTML =
                        '<div class="render-error">⚠️ Errore rendering etichetta</div>';
                }
                done++;
                setProgress(done, renderTasks.length);
            }));
        }

        // All done — hide overlay and enable print
        document.getElementById('loading-overlay').style.display = 'none';
        document.getElementById('btnPrint').disabled = false;
    }

    init().catch(function(err) {
        console.error('Fatal init error:', err);
        document.getElementById('loading-text').textContent = '⚠️ Errore di caricamento';
        document.getElementById('loading-progress').textContent = String(err);
    });
}());
</script>
</body>
</html>
