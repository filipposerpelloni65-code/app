<?php
/**
 * api/brt_label.php
 * Serve the stored BRT label PDF for a shipment.
 * GET /api/brt_label.php?id=<spedizione_id>
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('ID mancante.'); }

$db   = getDB();
$stmt = $db->prepare("SELECT brt_label_stream, brt_parcel_id FROM spedizioni WHERE id = ?");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row || empty($row['brt_label_stream'])) {
    http_response_code(404);
    exit('Etichetta non disponibile.');
}

$pdf = base64_decode($row['brt_label_stream']);
$filename = 'BRT_etichetta_' . ($row['brt_parcel_id'] ?? $id) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, max-age=86400');
echo $pdf;
