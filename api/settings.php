<?php
/**
 * api/settings.php
 * AJAX API for Settings: categories (ticket + spare_parts) and custom fields CRUD.
 * All mutating actions require POST + valid CSRF token. Admin role required.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireRole('admin');

$db   = getDB();
$user = currentUser();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── helpers ────────────────────────────────────────────────────────────────

function jsonOk(array $data = []): void {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function csrfCheck(): void {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonErr('Token CSRF non valido.', 403);
    }
}

// ── router ─────────────────────────────────────────────────────────────────

switch ($action) {

    // ── TICKET CATEGORIES ─────────────────────────────────────────────────

    case 'list_ticket_cats':
        $rows = $db->query("SELECT * FROM ticket_categories ORDER BY name")->fetchAll();
        jsonOk(['data' => $rows]);

    case 'add_ticket_cat':
        csrfCheck();
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!$name) jsonErr('Il nome è obbligatorio.');
        $db->prepare("INSERT INTO ticket_categories (name, description) VALUES (?,?)")->execute([$name, $desc]);
        $id = (int)$db->lastInsertId();
        logActivity($user['id'], 'settings_update', 'ticket_categories', $id, "Categoria aggiunta: $name");
        jsonOk(['id' => $id, 'name' => $name, 'description' => $desc]);

    case 'edit_ticket_cat':
        csrfCheck();
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!$id)   jsonErr('ID non valido.');
        if (!$name) jsonErr('Il nome è obbligatorio.');
        $db->prepare("UPDATE ticket_categories SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
        logActivity($user['id'], 'settings_update', 'ticket_categories', $id, "Categoria modificata: $name");
        jsonOk(['id' => $id, 'name' => $name, 'description' => $desc]);

    case 'delete_ticket_cat':
        csrfCheck();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonErr('ID non valido.');
        $db->prepare("DELETE FROM ticket_categories WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'settings_update', 'ticket_categories', $id, "Categoria eliminata ID $id");
        jsonOk();

    // ── SPARE PARTS CATEGORIES ────────────────────────────────────────────

    case 'list_parts_cats':
        $rows = $db->query("SELECT * FROM spare_parts_categories ORDER BY name")->fetchAll();
        jsonOk(['data' => $rows]);

    case 'add_parts_cat':
        csrfCheck();
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!$name) jsonErr('Il nome è obbligatorio.');
        $db->prepare("INSERT INTO spare_parts_categories (name, description) VALUES (?,?)")->execute([$name, $desc]);
        $id = (int)$db->lastInsertId();
        logActivity($user['id'], 'settings_update', 'spare_parts_categories', $id, "Categoria ricambi aggiunta: $name");
        jsonOk(['id' => $id, 'name' => $name, 'description' => $desc]);

    case 'edit_parts_cat':
        csrfCheck();
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!$id)   jsonErr('ID non valido.');
        if (!$name) jsonErr('Il nome è obbligatorio.');
        $db->prepare("UPDATE spare_parts_categories SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
        logActivity($user['id'], 'settings_update', 'spare_parts_categories', $id, "Categoria ricambi modificata: $name");
        jsonOk(['id' => $id, 'name' => $name, 'description' => $desc]);

    case 'delete_parts_cat':
        csrfCheck();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonErr('ID non valido.');
        $db->prepare("DELETE FROM spare_parts_categories WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'settings_update', 'spare_parts_categories', $id, "Categoria ricambi eliminata ID $id");
        jsonOk();

    // ── CUSTOM FIELDS ─────────────────────────────────────────────────────

    case 'list_custom_fields':
        $rows = $db->query("SELECT * FROM ticket_custom_fields ORDER BY sort_order, field_label")->fetchAll();
        jsonOk(['data' => $rows]);

    case 'add_custom_field':
        csrfCheck();
        $label    = trim($_POST['field_label'] ?? '');
        $type     = $_POST['field_type'] ?? 'text';
        $options  = trim($_POST['field_options'] ?? '');
        $required = isset($_POST['is_required']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);
        if (!$label) jsonErr('L\'etichetta è obbligatoria.');
        $validTypes = ['text','number','date','select','checkbox','textarea'];
        if (!in_array($type, $validTypes)) jsonErr('Tipo non valido.');
        // Auto-generate field_name from label
        $fieldName = preg_replace('/[^a-z0-9_]/', '_', strtolower($label));
        $fieldName = preg_replace('/_+/', '_', trim($fieldName, '_'));
        // Ensure uniqueness using a prepared statement
        $base = $fieldName;
        $i = 1;
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM ticket_custom_fields WHERE field_name=?");
        $checkStmt->execute([$fieldName]);
        while ((int)$checkStmt->fetchColumn() > 0) {
            $fieldName = $base . '_' . $i++;
            $checkStmt->execute([$fieldName]);
        }
        $optionsJson = ($type === 'select' && $options) ? json_encode(array_filter(array_map('trim', explode("\n", $options)))) : null;
        $db->prepare("INSERT INTO ticket_custom_fields (field_label, field_name, field_type, field_options, is_required, sort_order) VALUES (?,?,?,?,?,?)")
           ->execute([$label, $fieldName, $type, $optionsJson, $required, $sort]);
        $id = (int)$db->lastInsertId();
        logActivity($user['id'], 'settings_update', 'ticket_custom_fields', $id, "Campo personalizzato aggiunto: $label");
        $row = $db->prepare("SELECT * FROM ticket_custom_fields WHERE id=?");
        $row->execute([$id]);
        jsonOk(['field' => $row->fetch()]);

    case 'edit_custom_field':
        csrfCheck();
        $id       = (int)($_POST['id'] ?? 0);
        $label    = trim($_POST['field_label'] ?? '');
        $type     = $_POST['field_type'] ?? 'text';
        $options  = trim($_POST['field_options'] ?? '');
        $required = isset($_POST['is_required']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['active']) ? 1 : 0;
        if (!$id)    jsonErr('ID non valido.');
        if (!$label) jsonErr('L\'etichetta è obbligatoria.');
        $validTypes = ['text','number','date','select','checkbox','textarea'];
        if (!in_array($type, $validTypes)) jsonErr('Tipo non valido.');
        $optionsJson = ($type === 'select' && $options) ? json_encode(array_filter(array_map('trim', explode("\n", $options)))) : null;
        $db->prepare("UPDATE ticket_custom_fields SET field_label=?, field_type=?, field_options=?, is_required=?, sort_order=?, active=?, updated_at=NOW() WHERE id=?")
           ->execute([$label, $type, $optionsJson, $required, $sort, $active, $id]);
        logActivity($user['id'], 'settings_update', 'ticket_custom_fields', $id, "Campo personalizzato modificato: $label");
        $row = $db->prepare("SELECT * FROM ticket_custom_fields WHERE id=?");
        $row->execute([$id]);
        jsonOk(['field' => $row->fetch()]);

    case 'delete_custom_field':
        csrfCheck();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonErr('ID non valido.');
        $db->prepare("DELETE FROM ticket_custom_fields WHERE id=?")->execute([$id]);
        logActivity($user['id'], 'settings_update', 'ticket_custom_fields', $id, "Campo personalizzato eliminato ID $id");
        jsonOk();

    default:
        jsonErr('Azione non riconosciuta.', 400);
}
