<?php
/**
 * modules/spedizioni/bordero_print.php
 * Visualizza e stampa il bordero (distinta di carico per il corriere).
 *
 * GET ?id=<bordero_id>          → bordero archiviato
 * GET ?ids=1,2,3                → bordero al volo da IDs spedizioni
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/modules.php';

requireLogin();
if (!isModuleEnabled('spedizioni')) { header('Location: ' . APP_URL . '/dashboard.php'); exit; }

$db   = getDB();
$user = currentUser();

$borderoId   = (int)($_GET['id'] ?? 0);
$borderoNote = '';
$borderoDate = date('d/m/Y');
$bordero     = null;
$spedizioni  = [];

if ($borderoId) {
    // Load from archive
    $stmt = $db->prepare("SELECT b.*, u.full_name AS creator_name FROM borderi b LEFT JOIN users u ON b.created_by = u.id WHERE b.id = ?");
    $stmt->execute([$borderoId]);
    $bordero = $stmt->fetch();

    if (!$bordero) {
        http_response_code(404);
        echo '<p>Bordero non trovato.</p>';
        exit;
    }

    $borderoNote = $bordero['note'] ?? '';
    $borderoDate = formatDate($bordero['data_bordero'], 'd/m/Y');
    $ids         = json_decode($bordero['spedizioni_ids'], true) ?? [];
} else {
    // Ad-hoc from query string
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
    if (!$ids) {
        http_response_code(400);
        echo '<p>IDs non validi.</p>';
        exit;
    }
}

if ($ids) {
    $inList = implode(',', array_map('intval', $ids));
    $spedizioni = $db->query("
        SELECT s.*,
            d.name    AS dealer_name,
            d.code    AS dealer_code,
            d.address AS dealer_address,
            d.city    AS dealer_city,
            d.region  AS dealer_region,
            dl.name              AS location_name,
            dl.codice_aams       AS location_codice_aams,
            dl.id_punto_vendita  AS location_id_pv,
            sp.name AS part_name,
            t.title AS ticket_title
        FROM spedizioni s
        LEFT JOIN dealers d ON s.dealer_id = d.id
        LEFT JOIN dealer_locations dl ON s.location_id = dl.id
        LEFT JOIN spare_parts_requests spr ON s.spare_parts_request_id = spr.id
        LEFT JOIN spare_parts sp ON spr.part_id = sp.id
        LEFT JOIN tickets t ON s.ticket_id = t.id
        WHERE s.id IN ($inList)
        ORDER BY s.id
    ")->fetchAll();
}

$totalColli = array_sum(array_column($spedizioni, 'num_colli'));
$totalPeso  = array_sum(array_column($spedizioni, 'peso_kg'));

$companyName    = getSetting('company_name', APP_NAME);
$companyAddress = getSetting('company_address', '');
$companyCity    = getSetting('company_city', '');
$companyVat     = getSetting('company_vat', '');
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bordero <?= $borderoId ? '#'.$borderoId : 'al volo' ?> — <?= $borderoDate ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
    body { font-family: Arial, sans-serif; font-size: 0.85rem; background: #f1f5f9; }

    .no-print { }

    @media print {
        body { background: #fff; }
        .no-print { display: none !important; }
        .page-break { page-break-before: always; }
    }
    @page { size: A4; margin: 15mm 10mm; }

    .bordero-page { background: #fff; max-width: 210mm; margin: 0 auto; padding: 15mm 10mm; }
    .header-logo { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
    .header-sub  { font-size: 0.8rem; color: #64748b; }
    .doc-title   { font-size: 1.2rem; font-weight: 700; border-bottom: 2px solid #1e293b; padding-bottom: 6px; margin-bottom: 12px; }
    table.sped-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
    table.sped-table th {
        background: #1e293b; color: #fff; padding: 5px 7px; text-align: left;
        font-size: 0.7rem; font-weight: 600; white-space: nowrap;
    }
    table.sped-table td { padding: 4px 7px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    table.sped-table tr:nth-child(even) td { background: #f8fafc; }
    .totals-row td { font-weight: 700; background: #e2e8f0 !important; border-top: 2px solid #94a3b8; }
    .mono { font-family: 'Courier New', monospace; font-size: 0.7rem; }
    .text-blue { color: #1d4ed8; }
    .sign-box { border: 1px solid #94a3b8; min-height: 70px; padding: 8px; border-radius: 4px; }

    /* Screen toolbar */
    .toolbar { background: #1e293b; color: #fff; padding: 10px 16px; display: flex; align-items: center; gap: 12px; }
    .toolbar h6 { flex: 1; margin: 0; font-size: 14px; }
    .toolbar button, .toolbar a { background: #3b82f6; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; }
    .toolbar .btn-sec { background: #4b5563; }
</style>
</head>
<body>

<!-- Toolbar (hidden on print) -->
<div class="toolbar no-print">
    <h6><i class="bi bi-file-earmark-text"></i>
        Bordero <?= $borderoId ? '#'.$borderoId : 'al volo' ?> — <?= $borderoDate ?>
    </h6>
    <button onclick="window.print()">🖨 Stampa / Salva PDF</button>
    <?php if (!empty($ids)): ?>
    <a href="<?= APP_URL ?>/api/brt_labels_a4.php?<?= $borderoId ? 'bordero_id='.$borderoId : 'ids='.implode(',',array_map('intval',$ids)) ?>" target="_blank" class="btn-sec">🏷 Etichette A4</a>
    <?php endif; ?>
    <a href="javascript:history.back()" class="btn-sec">← Indietro</a>
</div>

<div class="bordero-page">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-7">
            <div class="header-logo"><?= h($companyName) ?></div>
            <?php if ($companyAddress): ?><div class="header-sub"><?= h($companyAddress) ?><?php if ($companyCity): ?>, <?= h($companyCity) ?><?php endif; ?></div><?php endif; ?>
            <?php if ($companyVat): ?><div class="header-sub">P.IVA: <?= h($companyVat) ?></div><?php endif; ?>
        </div>
        <div class="col-5 text-end">
            <div class="doc-title" style="border-color:#3b82f6;color:#1e3a8a">
                BORDERO DI CARICO BRT
            </div>
            <div class="small text-muted">Data: <strong><?= $borderoDate ?></strong></div>
            <?php if ($borderoId): ?><div class="small text-muted">Bordero n°: <strong><?= $borderoId ?></strong></div><?php endif; ?>
            <?php if ($borderoNote): ?><div class="small text-muted">Note: <?= h($borderoNote) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- Summary -->
    <div class="row mb-3 g-2">
        <div class="col-3">
            <div class="card border p-2 text-center">
                <div style="font-size:1.4rem;font-weight:700;color:#3b82f6"><?= count($spedizioni) ?></div>
                <div class="text-muted" style="font-size:.7rem">SPEDIZIONI</div>
            </div>
        </div>
        <div class="col-3">
            <div class="card border p-2 text-center">
                <div style="font-size:1.4rem;font-weight:700;color:#f59e0b"><?= $totalColli ?></div>
                <div class="text-muted" style="font-size:.7rem">COLLI TOTALI</div>
            </div>
        </div>
        <div class="col-3">
            <div class="card border p-2 text-center">
                <div style="font-size:1.4rem;font-weight:700;color:#10b981"><?= number_format($totalPeso, 2) ?> kg</div>
                <div class="text-muted" style="font-size:.7rem">PESO TOTALE</div>
            </div>
        </div>
        <div class="col-3">
            <div class="card border p-2 text-center">
                <div style="font-size:1.4rem;font-weight:700;color:#8b5cf6"><?= count(array_filter($spedizioni, fn($s) => !empty($s['brt_parcel_id']))) ?></div>
                <div class="text-muted" style="font-size:.7rem">TRASMESSE BRT</div>
            </div>
        </div>
    </div>

    <!-- Shipments table -->
    <table class="sped-table mb-4">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th>Destinatario</th>
                <th>Cod. Cliente</th>
                <th>Indirizzo</th>
                <th>Tracking / Parcel ID BRT</th>
                <th>N. Rif.</th>
                <th style="width:44px;text-align:center">Colli</th>
                <th style="width:60px;text-align:right">Peso</th>
                <th>Riferimento Interno</th>
                <th>Trasmessa</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($spedizioni as $i => $s): ?>
        <?php
        $consignee  = '';
        $address    = '';
        $cityInfo   = '';
        if (!empty($s['brt_consignee_json'])) {
            $bcd       = json_decode($s['brt_consignee_json'], true) ?? [];
            $consignee = $bcd['consigneeCompanyName'] ?? '';
            $address   = $bcd['consigneeAddress'] ?? '';
            $cityInfo  = trim(($bcd['consigneeZIPCode'] ?? '') . ' ' . ($bcd['consigneeCity'] ?? '') . ' ' . ($bcd['consigneeProvinceAbbreviation'] ?? ''));
        }
        if (!$consignee) {
            $consignee = $s['dealer_name'] ?? ($s['location_name'] ?? '—');
        }
        if (!$cityInfo && $s['dealer_city']) {
            $cityInfo = $s['dealer_city'];
        }

        // Codice cliente (dealer code + location codes)
        $codiceCliente = $s['dealer_code'] ?? '';
        $aams = $s['location_codice_aams'] ?? '';
        $idpv = $s['location_id_pv'] ?? '';

        // BRT identifiers
        $parcelId   = $s['brt_parcel_id'] ?? '';
        $numericRef = $s['brt_numeric_ref'] ?? '';
        $tracking   = $s['tracking_number'] ?? '';

        // Best display tracking: prefer tracking_number, else parcel_id
        $trackDisplay = $tracking ?: $parcelId;

        $ref = $s['ticket_id'] ? (getTicketPrefix().'-'.str_pad($s['ticket_id'],4,'0',STR_PAD_LEFT)) : '';
        if ($s['part_name']) { $ref .= ($ref ? ' / ' : '') . $s['part_name']; }

        $transmittedAt = $s['transmitted_at'] ? formatDate($s['transmitted_at'], 'd/m/Y H:i') : '';
        ?>
        <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td>
                <strong><?= h($consignee) ?></strong>
                <?php if ($s['location_name'] && $s['location_name'] !== $consignee): ?>
                <br><span class="text-muted"><?= h($s['location_name']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($codiceCliente): ?><span class="mono text-blue"><?= h($codiceCliente) ?></span><?php endif; ?>
                <?php if ($aams): ?><br><span class="text-muted" style="font-size:.65rem">AAMS: <?= h($aams) ?></span><?php endif; ?>
                <?php if ($idpv): ?><br><span class="text-muted" style="font-size:.65rem">PV: <?= h($idpv) ?></span><?php endif; ?>
                <?php if (!$codiceCliente && !$aams && !$idpv): ?>—<?php endif; ?>
            </td>
            <td class="text-muted">
                <?= h($address) ?><?php if ($address && $cityInfo): ?><br><?php endif; ?><?= h($cityInfo) ?>
            </td>
            <td>
                <?php if ($trackDisplay): ?>
                <span class="mono"><?= h($trackDisplay) ?></span>
                <?php if ($tracking && $parcelId && $tracking !== $parcelId): ?>
                <br><span class="text-muted" style="font-size:.65rem">P.ID: <?= h($parcelId) ?></span>
                <?php endif; ?>
                <?php if ($numericRef): ?>
                <br><span class="text-muted" style="font-size:.65rem">Rif: <?= h($numericRef) ?></span>
                <?php endif; ?>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="mono" style="font-size:.7rem"><?= h($numericRef ?: '—') ?></td>
            <td style="text-align:center;font-weight:700"><?= (int)($s['num_colli'] ?? 1) ?></td>
            <td style="text-align:right"><?= number_format((float)($s['peso_kg'] ?? 1), 2) ?> kg</td>
            <td class="text-muted" style="font-size:.7rem"><?= h($ref ?: '—') ?></td>
            <td class="text-muted" style="font-size:.7rem;white-space:nowrap"><?= h($transmittedAt ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="totals-row">
            <td colspan="6" class="text-end">TOTALI:</td>
            <td style="text-align:center"><?= $totalColli ?></td>
            <td style="text-align:right"><?= number_format($totalPeso, 2) ?> kg</td>
            <td colspan="2"></td>
        </tr>
        </tbody>
    </table>

    <!-- Signatures -->
    <div class="row g-4 mt-2">
        <div class="col-6">
            <div class="small fw-semibold mb-2">FIRMA MITTENTE</div>
            <div class="sign-box"></div>
            <div class="small text-muted mt-1">Data: <?= $borderoDate ?></div>
        </div>
        <div class="col-6">
            <div class="small fw-semibold mb-2">FIRMA CORRIERE BRT</div>
            <div class="sign-box"></div>
            <div class="small text-muted mt-1">Timbro e firma autista</div>
        </div>
    </div>

    <div class="mt-4 text-center text-muted" style="font-size:.7rem; border-top:1px solid #e2e8f0; padding-top:8px;">
        Documento generato il <?= date('d/m/Y H:i') ?> — <?= h($companyName) ?>
        <?php if ($borderoId): ?> — Bordero n° <?= $borderoId ?><?php endif; ?>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</body>
</html>
