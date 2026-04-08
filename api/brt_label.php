<?php
/**
 * api/brt_label.php
 * Serve the stored BRT label PDF for a shipment.
 * GET /api/brt_label.php?id=<spedizione_id>            → first label (legacy/single)
 * GET /api/brt_label.php?id=<spedizione_id>&idx=<n>    → n-th label (1-based)
 * GET /api/brt_label.php?id=<spedizione_id>&a4=1       → redirect to A4 multi-label viewer
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('ID mancante.'); }

$db   = getDB();
$stmt = $db->prepare("SELECT brt_label_stream, brt_labels_json, brt_parcel_id FROM spedizioni WHERE id = ?");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Spedizione non trovata.');
}

// Redirect to A4 viewer if requested
if (!empty($_GET['a4'])) {
    header('Location: ' . APP_URL . '/api/brt_labels_a4.php?id=' . $id);
    exit;
}

// Determine which label to serve
$pdfBase64 = null;
$parcelId  = $row['brt_parcel_id'] ?? $id;

if (!empty($row['brt_labels_json'])) {
    $labels = json_decode($row['brt_labels_json'], true) ?? [];
    $idx    = max(0, (int)($_GET['idx'] ?? 1) - 1); // 1-based to 0-based
    if (isset($labels[$idx]['stream'])) {
        $pdfBase64 = $labels[$idx]['stream'];
        $parcelId  = $labels[$idx]['parcelID'] ?? $parcelId;
    }
}

// Fallback to legacy single-label stream
if (!$pdfBase64 && !empty($row['brt_label_stream'])) {
    $pdfBase64 = $row['brt_label_stream'];
}

if (!$pdfBase64) {
    http_response_code(404);
    exit('Etichetta non disponibile.');
}

$pdf = base64_decode($pdfBase64);
$filename = 'BRT_etichetta_' . $parcelId . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=86400');
echo $pdf;
