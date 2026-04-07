<?php
/**
 * Secure file download handler for ticket attachments.
 * Only logged-in users can download files attached to tickets they can access.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('File non trovato.'); }

$att = $db->prepare("SELECT ta.*, t.created_by AS ticket_created_by, t.status AS ticket_status FROM ticket_attachments ta JOIN tickets t ON ta.ticket_id=t.id WHERE ta.id=?");
$att->execute([$id]);
$att = $att->fetch();
if (!$att) { http_response_code(404); exit('File non trovato.'); }

// Access control: users can only download attachments from their own tickets
if ($user['role'] === 'user' && $att['ticket_created_by'] != $user['id']) {
    http_response_code(403); exit('Accesso non autorizzato.');
}

$filePath = APP_ROOT . '/' . $att['filepath'];
if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404); exit('File non trovato sul server.');
}

// Prevent directory traversal
$realFile = realpath($filePath);
$realUploads = realpath(APP_ROOT . '/uploads');
if ($realFile === false || strpos($realFile, $realUploads) !== 0) {
    http_response_code(403); exit('Accesso non autorizzato.');
}

$mime = $att['mimetype'] ?: mime_content_type($filePath) ?: 'application/octet-stream';
$filename = basename($att['filename']);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $filename) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
