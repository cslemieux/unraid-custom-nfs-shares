<?php

/**
 * Custom NFS Shares — AJAX API endpoint
 *
 * NOT a mirror of custom.smb.shares/api.php:
 *   • Omits getUsers / getGroups / searchUsers / getShare (SMB-specific).
 *   • Reload path uses applySharesAndReload() + exportfs -ra + rollback,
 *     not rebuildSambaConfig().
 *
 * CSRF validation is handled globally by Unraid — not re-validated here.
 *
 * Actions implemented:
 *   add, update, delete, toggle         — CRUD (AC-NFS-01.1–01.4)
 *   reload                              — applySharesAndReload directly
 *   status                              — NFS service and export status
 *   createBackup, listBackups,
 *   viewBackup, restoreBackup,
 *   deleteBackup                        — Backup management
 *   exportConfig                        — return shares as JSON
 *   importConfig                        — validate each share, save+apply
 *
 * Partial-success rule:
 *   If saveShares() succeeded but applySharesAndReload() reload failed,
 *   return HTTP 200 {"success":true,"warning":"<exportfs stderr>"}
 *   so the client can show a recoverable warning (task 5.5).
 *
 * Validation contract (task 5.5):
 *   All CRUD handlers call validateShare() first. If !empty($errors) the
 *   handler returns HTTP 200 {"success":false,"error":"<joined errors>"}
 *   WITHOUT calling saveShares() or applySharesAndReload(). No exports
 *   file is written when validation fails.
 *
 */

declare(strict_types=1);

require_once __DIR__ . '/include/lib.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Encode and emit a JSON response, then exit.
 *
 * @param array<string,mixed> $data
 */
function nfsApiRespond(array $data): void
{
    echo json_encode($data);
    exit;
}

/**
 * Build a JSON error response for validation failures (HTTP 200, success=false).
 *
 * @param string[] $errors
 */
function nfsValidationError(array $errors): void
{
    nfsApiRespond(['success' => false, 'error' => implode('; ', $errors)]);
}

/**
 * Translate an applySharesAndReload() result into the HTTP response.
 *
 * Partial-success rule: if data was already persisted but the reload failed,
 * the caller passes $saved=true and we return success=true with a warning
 * rather than success=false, so the UI treats the save as non-destructive.
 *
 * @param array{success: bool, error: string, escalated: bool} $result
 * @param bool        $saved   True when saveShares() has already succeeded.
 * @param string      $successMsg
 */
function nfsApplyResponse(array $result, bool $saved, string $successMsg): void
{
    if ($result['success']) {
        nfsApiRespond(['success' => true, 'message' => $successMsg]);
    }

    // Distinguish double-fault (escalated) from ordinary reload failure.
    if (!empty($result['escalated'])) {
        if ($saved) {
            // Data persisted; rollback happened but second reload also failed.
            nfsApiRespond([
                'success'   => true,
                'warning'   => 'Export saved but NFS reload failed (escalated — manual '
                    . 'intervention may be required): ' . $result['error'],
            ]);
        }
        nfsApiRespond([
            'success'   => false,
            'escalated' => true,
            'error'     => $result['error'],
        ]);
    }

    if ($saved) {
        // Partial success: save OK, reload failed with rollback.
        nfsApiRespond([
            'success' => true,
            'warning' => 'Export saved but NFS reload failed: ' . $result['error'],
        ]);
    }

    nfsApiRespond(['success' => false, 'error' => $result['error']]);
}

// ── action=add ───────────────────────────────────────────────────────────────
if ($action === 'add') {
    /** @var array<string,mixed> $share */
    $share = buildShareFromPost();

    /** @var array<int,array<string,mixed>> $shares */
    $shares = loadShares();

    $errors = validateShare($share, $shares);
    if (!empty($errors)) {
        nfsValidationError($errors);
    }

    $shares[] = $share;
    saveShares($shares);

    $result = applySharesAndReload($shares);
    nfsApplyResponse($result, true, 'Export added successfully');
}

// ── action=update ─────────────────────────────────────────────────────────────
if ($action === 'update') {
    $originalName = (string) ($_POST['original_name'] ?? '');
    if ($originalName === '') {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'original_name is required for update']);
    }

    /** @var array<string,mixed> $share */
    $share = buildShareFromPost();

    /** @var array<int,array<string,mixed>> $shares */
    $shares = loadShares();

    // Pass editingName so validateShare() allows the share to keep its own name.
    $errors = validateShare($share, $shares, $originalName);
    if (!empty($errors)) {
        nfsValidationError($errors);
    }

    $index = findShareIndex($shares, $originalName);
    if ($index === -1) {
        http_response_code(404);
        nfsApiRespond(['success' => false, 'error' => "Export '{$originalName}' not found"]);
    }

    // Carry the persisted auto-fsid forward: buildShareFromPost()
    // rebuilds the record without it, and re-assignment is not guaranteed
    // to pick the same number (fsid gaps from deleted shares) — an fsid
    // change invalidates NFS client file handles (ESTALE).
    if (isset($shares[$index]['fsid'])) {
        $share['fsid'] = $shares[$index]['fsid'];
    }

    $shares[$index] = $share;
    saveShares($shares);

    $result = applySharesAndReload($shares);
    nfsApplyResponse($result, true, 'Export updated successfully');
}

// ── action=delete ─────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $name = (string) ($_POST['name'] ?? $_GET['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'name is required']);
    }

    /** @var array<int,array<string,mixed>> $shares */
    $shares = loadShares();
    $index  = findShareIndex($shares, $name);
    if ($index === -1) {
        http_response_code(404);
        nfsApiRespond(['success' => false, 'error' => "Export '{$name}' not found"]);
    }

    array_splice($shares, $index, 1);
    saveShares($shares);

    $result = applySharesAndReload($shares);
    nfsApplyResponse($result, true, 'Export deleted successfully');
}

// ── action=toggle ─────────────────────────────────────────────────────────────
if ($action === 'toggle') {
    $name = (string) ($_POST['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'name is required']);
    }

    // Caller may pass an explicit target state ('true'/'false') or omit it
    // to flip the current value.
    $explicitStr = $_POST['enabled'] ?? null;
    $explicit    = ($explicitStr !== null) ? ($explicitStr === 'true') : null;

    /** @var array<int,array<string,mixed>> $shares */
    $shares = loadShares();
    $index  = findShareIndex($shares, $name);
    if ($index === -1) {
        http_response_code(404);
        nfsApiRespond(['success' => false, 'error' => "Export '{$name}' not found"]);
    }

    $current                  = isset($shares[$index]['enabled'])
        ? (bool) $shares[$index]['enabled']
        : true;
    $shares[$index]['enabled'] = ($explicit !== null) ? $explicit : !$current;
    $newState                  = (bool) $shares[$index]['enabled'];

    saveShares($shares);

    $result = applySharesAndReload($shares);

    if ($result['success']) {
        nfsApiRespond(['success' => true, 'enabled' => $newState]);
    }

    if (!empty($result['escalated'])) {
        // Toggled and saved; reload escalated — surface as partial success.
        nfsApiRespond([
            'success' => true,
            'enabled' => $newState,
            'warning' => 'Toggle saved but NFS reload failed (escalated): ' . $result['error'],
        ]);
    }

    // Reload failed with rollback — share state was saved so partial success.
    nfsApiRespond([
        'success' => true,
        'enabled' => $newState,
        'warning' => 'Toggle saved but NFS reload failed: ' . $result['error'],
    ]);
}

// ── action=reload ─────────────────────────────────────────────────────────────
if ($action === 'reload') {
    $result = applySharesAndReload(null);

    if ($result['success']) {
        nfsApiRespond(['success' => true, 'message' => 'NFS exports reloaded successfully']);
    }

    if (!empty($result['escalated'])) {
        nfsApiRespond([
            'success'   => false,
            'escalated' => true,
            'error'     => $result['error'],
        ]);
    }

    nfsApiRespond(['success' => false, 'error' => $result['error']]);
}

// ── action=status ─────────────────────────────────────────────────────────────
if ($action === 'status') {
    $service = getNfsServiceStatus();
    $exports = getExportStatus();
    nfsApiRespond([
        'success'      => true,
        'nfs_enabled'  => $service['nfs_enabled'],
        'nfsd_running' => $service['nfsd_running'],
        'rpcinfo_ok'   => $service['rpcinfo_ok'],
        'exports'      => $exports['exports'],
        'showmount'    => $exports['showmount'],
        'rpcinfo'      => $exports['rpcinfo'],
    ]);
}

// ── action=getSettings / saveSettings ────────────────────────────────────────
if ($action === 'getSettings') {
    nfsApiRespond(['success' => true, 'settings' => loadSettings()]);
}

if ($action === 'saveSettings') {
    $current = loadSettings();
    $enable = ($_POST['ENABLE'] ?? $current['ENABLE']) === 'yes' ? 'yes' : 'no';
    $retention = (string)max(1, (int)($_POST['RETENTION'] ?? $current['RETENTION']));
    $placement = ($_POST['MENU_PLACEMENT'] ?? $current['MENU_PLACEMENT']) === 'settings'
        ? 'settings' : 'topbar';
    $ok = saveSettings([
        'ENABLE' => $enable,
        'RETENTION' => $retention,
        'MENU_PLACEMENT' => $placement,
    ]);
    nfsApiRespond($ok
        ? ['success' => true, 'message' => 'Settings saved']
        : ['success' => false, 'error' => 'Failed to write settings']);
}

// ── action=exportConfig ───────────────────────────────────────────────────────
if ($action === 'exportConfig') {
    /** @var array<int,array<string,mixed>> $shares */
    $shares = loadShares();
    nfsApiRespond(['success' => true, 'config' => $shares]);
}

// ── action=importConfig ───────────────────────────────────────────────────────
if ($action === 'importConfig') {
    // jQuery $.post sends application/x-www-form-urlencoded; read from
    // $_POST['config'], not php://input (which holds the raw encoded body).
    $configJson = $_POST['config'] ?? '';
    if ($configJson === '') {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'Missing config parameter']);
    }

    $imported = json_decode($configJson, true);
    if (!is_array($imported)) {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'Invalid configuration: not a valid JSON array']);
    }

    // Validate every share before touching the current config.
    // Collect all errors so the user can fix them all at once.
    $allErrors = [];
    /** @var array<int,array<string,mixed>> $importedShares */
    $importedShares = [];
    foreach ($imported as $i => $raw) {
        if (!is_array($raw)) {
            $allErrors[] = "Item {$i}: not a valid share object";
            continue;
        }
        /** @var array<string,mixed> $raw */
        $errors = validateShare($raw, $importedShares);
        if (!empty($errors)) {
            $allErrors[] = "Item {$i} (" . ($raw['name'] ?? '?') . '): ' . implode(', ', $errors);
        } else {
            $importedShares[] = $raw;
        }
    }

    if (!empty($allErrors)) {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => implode('; ', $allErrors)]);
    }

    // Backup the current config before overwriting (AC-NFS-14.2). Best-effort:
    // a false return means no shares.json existed yet — nothing to protect.
    createBackup();

    saveShares($importedShares);
    $result = applySharesAndReload($importedShares);
    nfsApplyResponse($result, true, 'Configuration imported successfully');
}

// ── Backup actions (task 6.1) ─────────────────────────────────────────────────
// Every filename-taking action validates against BACKUP_FILENAME_PATTERN
// BEFORE any filesystem operation (path-traversal guard).

if ($action === 'createBackup') {
    $filename = createBackup();
    nfsApiRespond($filename !== false
        ? ['success' => true, 'filename' => $filename]
        : ['success' => false, 'error' => 'Backup failed (no shares.json yet?)']);
}

if ($action === 'listBackups') {
    nfsApiRespond(['success' => true, 'backups' => listBackups()]);
}

if ($action === 'viewBackup') {
    $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';
    if ($filename === '' || !preg_match(ConfigRegistry::BACKUP_FILENAME_PATTERN, $filename)) {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'Invalid backup filename']);
    }
    $content = viewBackup($filename);
    nfsApiRespond($content !== false
        ? ['success' => true, 'shares' => $content]
        : ['success' => false, 'error' => 'Backup not found or unreadable']);
}

if ($action === 'restoreBackup') {
    $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';
    if ($filename === '' || !preg_match(ConfigRegistry::BACKUP_FILENAME_PATTERN, $filename)) {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'Invalid backup filename']);
    }
    $result = restoreBackup($filename);
    nfsApiRespond($result['success']
        ? ['success' => true, 'message' => 'Backup restored and exports reloaded']
        : ['success' => false, 'error' => $result['error']]);
}

if ($action === 'deleteBackup') {
    $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';
    if ($filename === '' || !preg_match(ConfigRegistry::BACKUP_FILENAME_PATTERN, $filename)) {
        http_response_code(400);
        nfsApiRespond(['success' => false, 'error' => 'Invalid backup filename']);
    }
    nfsApiRespond(deleteBackup($filename)
        ? ['success' => true, 'message' => 'Backup deleted']
        : ['success' => false, 'error' => 'Backup not found']);
}

// ── Unknown action ────────────────────────────────────────────────────────────
http_response_code(400);
nfsApiRespond(['success' => false, 'error' => 'Unknown action: ' . $action]);

// ═════════════════════════════════════════════════════════════════════════════
// Helper: build a share array from $_POST fields
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Assemble a share array from the current POST request.
 *
 * Clients are split from the textarea by newline; extra_options by comma.
 * All string fields are trimmed; control chars are stripped in validateShare().
 *
 * @return array<string,mixed>
 */
function buildShareFromPost(): array
{
    // Split clients textarea on newlines; filter blank lines.
    $clientsRaw = (string) ($_POST['clients'] ?? '');
    $clients    = array_values(array_filter(
        array_map('trim', explode("\n", $clientsRaw)),
        static fn(string $v): bool => $v !== ''
    ));

    // Split extra_options on commas; filter blanks.
    $extraRaw = (string) ($_POST['extra_options'] ?? '');
    $extra    = array_values(array_filter(
        array_map('trim', explode(',', $extraRaw)),
        static fn(string $v): bool => $v !== ''
    ));

    // anonuid / anongid: store as int when provided, null when empty.
    $anonuidStr = trim((string) ($_POST['anonuid'] ?? ''));
    $anongidStr = trim((string) ($_POST['anongid'] ?? ''));
    $anonuid    = $anonuidStr !== '' ? (int) $anonuidStr : null;
    $anongid    = $anongidStr !== '' ? (int) $anongidStr : null;

    $enabledVal = (string) ($_POST['enabled'] ?? 'yes');
    $enabled    = $enabledVal === 'yes';

    return [
        'name'          => trim((string) ($_POST['name']    ?? '')),
        'path'          => trim((string) ($_POST['path']    ?? '')),
        'comment'       => trim((string) ($_POST['comment'] ?? '')),
        'clients'       => $clients,
        'access'        => trim((string) ($_POST['access']  ?? 'rw')),
        'sync'          => trim((string) ($_POST['sync']    ?? 'sync')),
        'subtree'       => trim((string) ($_POST['subtree'] ?? 'no_subtree_check')),
        'squash'        => trim((string) ($_POST['squash']  ?? 'root_squash')),
        'anonuid'       => $anonuid,
        'anongid'       => $anongid,
        'extra_options' => $extra,
        'enabled'       => $enabled,
    ];
}
