<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// Unraid plugins don't use namespaces — they're loaded directly by the WebGUI.

// TestModeDetector must be loaded first; it transitively loads ConfigRegistry.
require_once __DIR__ . '/TestModeDetector.php';
require_once __DIR__ . '/ConfigRegistry.php';

// Define CONFIG_BASE for backward-compatibility with code that checks the
// constant directly. New code should call ConfigRegistry::getConfigBase().
if (!defined('CONFIG_BASE')) {
    define('CONFIG_BASE', '/boot/config');
}

// ============================================================
// Logging helpers
// ============================================================

/**
 * Log an error message with the plugin prefix.
 */
function logError(string $message): void
{
    error_log('[custom.nfs.shares] ERROR: ' . $message);
}

/**
 * Log an informational message with the plugin prefix.
 */
function logInfo(string $message): void
{
    error_log('[custom.nfs.shares] INFO: ' . $message);
}

// ============================================================
// Plugin-enabled check
// ============================================================

/**
 * Returns true when the plugin is enabled in settings.cfg (ENABLE=yes).
 *
 * Defaults to enabled when the settings file does not exist or the key is
 * absent — matching the disks_mounted event handler's guard semantics.
 *
 * @param string|null $configBase Optional override for the config base path.
 */
function isPluginEnabled(?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $configFile = $base . '/plugins/custom.nfs.shares/settings.cfg';

    if (!file_exists($configFile)) {
        return true;
    }

    $settings = parse_ini_file($configFile);
    if (!is_array($settings)) {
        return true;
    }

    return (string)($settings['ENABLE'] ?? 'yes') === 'yes';
}

// ============================================================
// Share normalization (REQ-NFS-08 defence-in-depth)
// ============================================================

/**
 * Normalize a single NFS share entry loaded from disk.
 *
 * Trims whitespace from string fields and strips ASCII control characters
 * (0x00–0x1F, 0x7F) from the name so stale dirty data on disk can never
 * reach an inline JS string context or the exports file. Also filters empty
 * strings out of the clients and extra_options arrays.
 *
 * @param array<string, mixed> $share Raw share data as decoded from JSON.
 * @return array<string, mixed> The normalized share.
 */
function normalizeShare(array $share): array
{
    // name: strip control chars then trim whitespace.
    if (isset($share['name']) && is_string($share['name'])) {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/u', '', $share['name']);
        $share['name'] = trim($stripped ?? $share['name']);
    }

    // Scalar string fields: trim whitespace only.
    foreach (['path', 'comment'] as $field) {
        if (isset($share[$field]) && is_string($share[$field])) {
            $share[$field] = trim($share[$field]);
        }
    }

    // clients[]: trim each element, drop empty strings, re-index.
    if (isset($share['clients']) && is_array($share['clients'])) {
        $clients = [];
        foreach ($share['clients'] as $client) {
            if (is_string($client)) {
                $trimmed = trim($client);
                if ($trimmed !== '') {
                    $clients[] = $trimmed;
                }
            }
        }
        $share['clients'] = $clients;
    }

    // extra_options[]: trim each element, drop empty strings, re-index.
    if (isset($share['extra_options']) && is_array($share['extra_options'])) {
        $opts = [];
        foreach ($share['extra_options'] as $opt) {
            if (is_string($opt)) {
                $trimmed = trim($opt);
                if ($trimmed !== '') {
                    $opts[] = $trimmed;
                }
            }
        }
        $share['extra_options'] = $opts;
    }

    // fsid: keep non-negative integers (int or digit-string → int); drop
    // anything else so invalid on-disk data never reaches the exports file.
    if (array_key_exists('fsid', $share)) {
        if (is_int($share['fsid']) && $share['fsid'] >= 0) {
            // keep as-is
        } elseif (is_string($share['fsid']) && ctype_digit($share['fsid'])) {
            $share['fsid'] = (int)$share['fsid'];
        } else {
            unset($share['fsid']);
        }
    }

    return $share;
}

// ============================================================
// Persistence — loadShares / saveShares (task 2.1)
// ============================================================

/**
 * Load NFS share definitions from shares.json.
 *
 * Returns an empty array on any failure (missing file, unreadable, invalid
 * JSON, non-array root) so callers never receive an exception and the plugin
 * degrades gracefully to an empty share list. Shares are normalized on load
 * so dirty on-disk data is cleaned the first time the file is read.
 *
 * @param string|null $configBase Optional override for the config base path.
 * @return array<int, array<string, mixed>> Normalized share array, possibly empty.
 */
function loadShares(?string $configBase = null): array
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.nfs.shares/shares.json';

    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);
    if ($content === false) {
        return [];
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }

    $result = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            /** @var array<string, mixed> $item */
            $result[] = normalizeShare($item);
        }
    }

    return $result;
}

/**
 * Persist NFS share definitions to shares.json atomically (temp + rename).
 *
 * Atomic-rename guarantee (AC-NFS-06.2): writes to a .tmp sibling in the same
 * directory, then rename()s it into place; readers never observe a partially
 * written file.
 *
 * Last-writer-wins concurrency (AC-NFS-06.3): the caller supplies the complete
 * shares array; whichever session saves last wins, so the on-disk document is
 * always internally consistent even if an interleaved concurrent edit is
 * overwritten. Fine-grained per-share locking is out of scope for v1.
 *
 * @param array<int, array<string, mixed>> $shares Complete shares array to persist.
 * @param string|null $configBase Optional override for the config base path.
 * @return bool True on success, false on any I/O or encoding failure.
 */
function saveShares(array $shares, ?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.nfs.shares/shares.json';
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // Normalize before persisting so dirty data never reaches disk, then
    // assign auto-fsids so the persisted record matches what
    // generateNfsExports() will emit — assignFsids is deterministic, so this
    // and the applySharesAndReload() call site agree on the same values.
    $normalized = assignFsids(array_map('normalizeShare', $shares));

    // JSON_THROW_ON_ERROR keeps the encode result a guaranteed string.
    try {
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        logError('Failed to encode shares as JSON: ' . $e->getMessage());
        return false;
    }

    // Temp file in the same directory so rename() is atomic on POSIX.
    $pidValue = getmypid();
    $pidSuffix = $pidValue !== false ? (string)$pidValue : '0';
    $tmp = $file . '.tmp.' . $pidSuffix;

    if (@file_put_contents($tmp, $json) === false) {
        logError("Failed to write temporary shares file: {$tmp}");
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        logError("Failed to atomically rename shares file into place: {$file}");
        return false;
    }

    return true;
}

/**
 * Find the zero-based index of the share with the given name.
 *
 * Comparison is exact and case-sensitive on the normalized name field.
 *
 * @param array<int, array<string, mixed>> $shares The shares array to search.
 * @param string $name Share name to search for.
 * @return int Zero-based index, or -1 if not found.
 */
function findShareIndex(array $shares, string $name): int
{
    foreach ($shares as $index => $share) {
        if (
            isset($share['name'])
            && is_string($share['name'])
            && $share['name'] === $name
        ) {
            return $index;
        }
    }
    return -1;
}

// ============================================================
// Config-injection hardening — stripControlChars (REQ-NFS-08)
// ============================================================

/**
 * Remove newline, carriage-return, tab, and other control characters from a
 * string before it is written to the exports file.
 *
 * A newline embedded in any share field could inject an extra directive into
 * the generated exports file, bypassing validation (AC-NFS-08.1). Stripping
 * here is defence-in-depth on top of the validateShare() allow-list checks.
 *
 * Codepoints removed: U+0000–U+001F (includes HT, LF, CR) and U+007F (DEL).
 */
function stripControlChars(string $input): string
{
    // preg_replace returns null only on pattern error, which cannot happen
    // with this hard-coded pattern; fall back to the original input.
    $result = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
    return $result ?? $input;
}

// ============================================================
// Export-line generation (tasks 2.2, 2.3, 2.4)
// ============================================================

/**
 * Extract a user-supplied fsid= token value from a share's extra_options.
 *
 * Returns the raw value string (fsid accepts integers or UUIDs per
 * exports(5)) or null when no fsid= token is present. Tokens are trimmed so
 * detection is identical on raw and normalized share arrays — this keeps
 * assignFsids() deterministic regardless of which copy it sees.
 *
 * @param array<string, mixed> $share Share record.
 * @return string|null Raw fsid value, or null when absent.
 */
function getUserFsid(array $share): ?string
{
    if (!isset($share['extra_options']) || !is_array($share['extra_options'])) {
        return null;
    }
    foreach ($share['extra_options'] as $opt) {
        if (is_string($opt) && preg_match('/^\s*fsid=(.+?)\s*$/', $opt, $m) === 1) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Assign stable, unique auto-fsids to shares that need one.
 *
 * exportfs rejects FUSE-backed paths (/mnt/user — shfs) without an explicit
 * fsid=, which is THE primary share location on Unraid (found during
 * on-device verification on Unraid 7.3.1). Every managed share
 * gets a persisted integer fsid unless the user supplied their own fsid=
 * token in extra_options. Assigning uniformly (not just for /mnt/user paths)
 * sidesteps path-classification edge cases (bind mounts, symlinks into the
 * FUSE tree) — an explicit unique fsid is valid on any filesystem, and
 * Unraid's own exports carry one on every line.
 *
 * Properties:
 *  - Stable: an existing valid fsid is never reassigned (fsid changes
 *    invalidate NFS client file handles → ESTALE).
 *  - Unique: new values are the smallest unused integer >= FSID_AUTO_BASE,
 *    checked against all persisted fsids AND numeric user-supplied ones.
 *  - Deterministic: same input array always yields same assignments, so the
 *    saveShares() and generateNfsExports() call sites agree.
 *  - Idempotent: a second pass is a no-op.
 *
 * @param array<int, array<string, mixed>> $shares Shares array.
 * @return array<int, array<string, mixed>> Shares with fsid fields populated.
 */
function assignFsids(array $shares): array
{
    // Collect every fsid value already in use.
    $used = [];
    foreach ($shares as $share) {
        if (isset($share['fsid']) && is_int($share['fsid']) && $share['fsid'] >= 0) {
            $used[$share['fsid']] = true;
        }
        $userFsid = getUserFsid($share);
        if ($userFsid !== null && ctype_digit($userFsid)) {
            $used[(int)$userFsid] = true;
        }
    }

    foreach ($shares as $i => $share) {
        // User-supplied fsid in extra_options wins — no auto value alongside.
        if (getUserFsid($share) !== null) {
            continue;
        }
        // Already has a valid persisted fsid — keep it (stability).
        if (isset($share['fsid']) && is_int($share['fsid']) && $share['fsid'] >= 0) {
            continue;
        }
        // Assign the smallest unused integer in the managed range.
        $candidate = ConfigRegistry::FSID_AUTO_BASE;
        while (isset($used[$candidate])) {
            $candidate++;
        }
        $shares[$i]['fsid'] = $candidate;
        $used[$candidate] = true;
    }

    return $shares;
}

/**
 * Build the comma-joined NFS export option string for a single share.
 *
 * Option ordering (fixed): access, sync, subtree, squash,
 * [anonuid=N], [anongid=N], [fsid=N], [extra...].
 *
 * Conservative defaults when a field is absent or empty (AC-NFS-15.1):
 * access=rw, sync=sync, subtree=no_subtree_check, squash=root_squash.
 *
 * Every string field passes through stripControlChars() so a newline or tab
 * embedded in an option cannot inject an extra exports directive (REQ-NFS-08).
 * anonuid/anongid are cast to int so the kernel receives numeric ids.
 * The auto-assigned fsid is emitted unless the user supplied their own
 * fsid= token in extra_options.
 *
 * @param array<string, mixed> $share Normalized share data.
 * @return string e.g. "rw,sync,no_subtree_check,root_squash,fsid=200".
 */
function buildExportOptions(array $share): string
{
    $parts = [];

    $access = stripControlChars((string)($share['access'] ?? 'rw'));
    $parts[] = $access !== '' ? $access : 'rw';

    $sync = stripControlChars((string)($share['sync'] ?? 'sync'));
    $parts[] = $sync !== '' ? $sync : 'sync';

    $subtree = stripControlChars((string)($share['subtree'] ?? 'no_subtree_check'));
    $parts[] = $subtree !== '' ? $subtree : 'no_subtree_check';

    $squash = stripControlChars((string)($share['squash'] ?? 'root_squash'));
    $parts[] = $squash !== '' ? $squash : 'root_squash';

    // Optional anonuid/anongid — omitted when absent, null, or empty-string.
    if (isset($share['anonuid']) && $share['anonuid'] !== '') {
        $parts[] = 'anonuid=' . (int)$share['anonuid'];
    }
    if (isset($share['anongid']) && $share['anongid'] !== '') {
        $parts[] = 'anongid=' . (int)$share['anongid'];
    }

    // Auto-assigned fsid — omitted when the user supplied their own
    // fsid= token in extra_options (that token is emitted with the extras).
    if (getUserFsid($share) === null && isset($share['fsid']) && is_int($share['fsid']) && $share['fsid'] >= 0) {
        $parts[] = 'fsid=' . $share['fsid'];
    }

    // Optional extra option tokens.
    if (isset($share['extra_options']) && is_array($share['extra_options'])) {
        foreach ($share['extra_options'] as $opt) {
            if (is_string($opt)) {
                $stripped = stripControlChars($opt);
                if ($stripped !== '') {
                    $parts[] = $stripped;
                }
            }
        }
    }

    return implode(',', $parts);
}

/**
 * Encode a filesystem path per exports(5) quoting rules.
 *
 * Algorithm:
 *  1. SPACE (0x20): include verbatim; wrap the final encoded path in
 *     double-quotes — "/mnt/user/my share".
 *  2. DOUBLE-QUOTE (0x22) and BACKSLASH (0x5C): always backslash-octal
 *     escape (\042, \134) so they cannot terminate a double-quoted path.
 *  3. Control chars (0x00–0x1F), DEL (0x7F), non-ASCII (0x80–0xFF):
 *     always backslash-octal escape (\NNN, three zero-padded octal digits).
 *  4. All other printable ASCII: verbatim.
 *
 * Why NOT escapeshellarg()/single-quoting: shell quoting is not exports(5)
 * quoting. nfs-utils parses the exports file itself (no shell) and rejects
 * single-quoted paths — the scaffold's original instinct, explicitly
 * retracted during design review (AC-NFS-04.2, design D-2).
 *
 * @param string $path Raw filesystem path (e.g. "/mnt/user/my share").
 * @return string exports(5)-encoded path token (possibly double-quoted).
 */
function encodeExportsPath(string $path): string
{
    $needsDoubleQuote = false;
    $encoded = '';
    $len = strlen($path);

    for ($i = 0; $i < $len; $i++) {
        $byte = ord($path[$i]);

        if ($byte === 0x20) {
            // Space: verbatim in the encoded string, but flag double-quoting.
            $needsDoubleQuote = true;
            $encoded .= ' ';
        } elseif (
            $byte === 0x22       // " (double-quote)
            || $byte === 0x5C    // \ (backslash)
            || $byte < 0x20      // Control chars (incl. tab, LF, CR)
            || $byte >= 0x7F     // DEL and non-ASCII bytes
        ) {
            $encoded .= '\\' . sprintf('%03o', $byte);
        } else {
            $encoded .= $path[$i];
        }
    }

    return $needsDoubleQuote ? '"' . $encoded . '"' : $encoded;
}

/**
 * Build the exports(5) line for a single enabled share.
 *
 * Output format: <encoded_path> client1(opts) client2(opts) ...
 *
 * MVP scope: one shared option-set is applied to every client listed on the
 * export (AC-NFS-02.2); per-client differentiated options are deferred (D-1).
 * Client specs get a defence-in-depth control-char strip (REQ-NFS-08).
 *
 * @param array<string, mixed> $share A normalized, enabled share.
 * @return string A single exports(5) line (no trailing newline).
 */
function buildShareExportLine(array $share): string
{
    $rawPath = isset($share['path']) && is_string($share['path']) ? $share['path'] : '';
    $encodedPath = encodeExportsPath($rawPath);

    $options = buildExportOptions($share);

    $clientTokens = [];
    if (isset($share['clients']) && is_array($share['clients'])) {
        foreach ($share['clients'] as $client) {
            if (is_string($client) && $client !== '') {
                $safeClient = stripControlChars($client);
                if ($safeClient !== '') {
                    // exports(5): client and option-set are joined WITHOUT
                    // whitespace — "client(options)", not "client (options)".
                    $clientTokens[] = $safeClient . '(' . $options . ')';
                }
            }
        }
    }

    return $encodedPath . ' ' . implode(' ', $clientTokens);
}

/**
 * Generate the complete content for the NFS exports drop-in file.
 *
 * Single source of truth for BOTH the persistent copy (on flash) and the
 * runtime copy (/etc/exports.d/): called once per apply, its return value is
 * written to both paths so the copies are always identical (AC-NFS-03.2).
 *
 * Disabled shares are omitted entirely (AC-NFS-03.3); each enabled share
 * produces exactly one exports(5) line. The header warns operators not to
 * edit the file manually.
 *
 * @param array<int, array<string, mixed>> $shares Full shares array (enabled and disabled).
 * @return string Complete exports drop-in content, including header comment.
 */
function generateNfsExports(array $shares): string
{
    // Assign auto-fsids so every generation path — including
    // action=reload with shares loaded from a pre-fsid shares.json — emits
    // an fsid on each line. Deterministic, so it matches what saveShares()
    // persisted for the same input.
    $shares = assignFsids($shares);

    $lines = [
        '# /etc/exports.d/custom-nfs-shares.exports',
        '# Generated by the custom.nfs.shares Unraid plugin — DO NOT EDIT MANUALLY.',
        '# Changes will be overwritten the next time shares are saved.',
        '# Source: /boot/config/plugins/custom.nfs.shares/shares.json',
        '',
    ];

    foreach ($shares as $share) {
        // Skip disabled shares (AC-NFS-03.3); absent flag defaults to enabled.
        if (isset($share['enabled']) && $share['enabled'] === false) {
            continue;
        }

        $lines[] = buildShareExportLine($share);
    }

    // Terminate with a newline (POSIX convention; some exportfs versions warn
    // about files lacking a terminating newline).
    return implode("\n", $lines) . "\n";
}

// ============================================================
// Apply/rollback subsystem (Phase 3, F-0002)
// ============================================================

/**
 * Atomically write exports content to BOTH the runtime drop-in and the
 * persistent copy (task 3.1).
 *
 * Each write uses the temp-file + rename() pattern so readers (exportfs, the
 * disks_mounted handler) never observe a partially written file
 * (AC-NFS-06.2). The same content string goes to both paths, guaranteeing
 * they are identical (AC-NFS-03.2).
 *
 * @param string $content Complete exports file content to install.
 * @throws \RuntimeException When any write or rename fails, so callers can
 *         detect failure without inspecting return values.
 */
function writeExportsFiles(string $content): void
{
    $targets = [
        ConfigRegistry::getExportsDropInPath(),
        ConfigRegistry::getPersistentExportsPath(),
    ];

    $pidValue = getmypid();
    $pidSuffix = $pidValue !== false ? (string)$pidValue : '0';

    foreach ($targets as $target) {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Temp file in the SAME directory as the target so rename() is a
        // single-filesystem atomic operation on POSIX.
        $tmp = $target . '.tmp.' . $pidSuffix;

        if (@file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException("Failed to write temporary exports file: {$tmp}");
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to atomically install exports file: {$target}");
        }
    }
}

/**
 * Bounded pre-apply structural validator for candidate exports content
 * (task 3.2, design CF-01-05/07).
 *
 * This is deliberately NOT a full exports(5) parser — exportfs has no
 * reliable dry-run, so this check re-verifies the structure of already
 * per-field-validated data. Assertions:
 *  (a) non-comment, non-empty line count equals $enabledShareCount;
 *  (b) each such line begins with '/' or '"/' (encoded path token start);
 *  (c) each client(opts) group has balanced parentheses and no whitespace
 *      between the client token and its opening '(';
 *  (d) no line contains a raw tab or other control character
 *      (injection guard, AC-NFS-08.1).
 *
 * Errors it CANNOT catch (semantically invalid client specs, options
 * nfs-utils dislikes, kernel-rejected paths) surface at exportfs -ra runtime
 * and are handled by the rollback net in applySharesAndReload().
 *
 * @param string $content Candidate exports file content.
 * @param int $enabledShareCount Expected number of export lines.
 * @return string|null Null on success; human-readable error string on
 *         failure (callers treat non-null as a bail condition — no write
 *         proceeds).
 */
function validateCandidateExports(string $content, int $enabledShareCount): ?string
{
    $bodyLines = [];
    foreach (explode("\n", $content) as $line) {
        // (d) Injection guard: no raw control characters anywhere.
        // The trailing structure is line-split on \n already, so any
        // remaining control char (tab, CR, NUL, ...) is illegitimate.
        if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/u', $line) === 1) {
            return 'Candidate exports content contains a raw control character';
        }

        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        $bodyLines[] = $trimmed;
    }

    // (a) Line count must equal the number of enabled shares.
    $count = count($bodyLines);
    if ($count !== $enabledShareCount) {
        return "Candidate exports line count ({$count}) does not match enabled share count ({$enabledShareCount})";
    }

    foreach ($bodyLines as $line) {
        // (b) Each line must begin with an encoded path token.
        if (strpos($line, '/') !== 0 && strpos($line, '"/') !== 0) {
            return "Candidate exports line does not begin with a path token: {$line}";
        }

        // (c) Balanced parentheses; no whitespace before an opening '('.
        $depth = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '(') {
                if ($i > 0 && $line[$i - 1] === ' ') {
                    return "Whitespace separates a client from its option list: {$line}";
                }
                $depth++;
                if ($depth > 1) {
                    return "Nested parentheses in exports line: {$line}";
                }
            } elseif ($ch === ')') {
                $depth--;
                if ($depth < 0) {
                    return "Unbalanced parentheses in exports line: {$line}";
                }
            }
        }
        if ($depth !== 0) {
            return "Unbalanced parentheses in exports line: {$line}";
        }
    }

    return null;
}

/**
 * Run `exportfs -ra` and capture its exit status and output (task 3.3).
 *
 * `exportfs -ra` re-exports every directory from /etc/exports and
 * /etc/exports.d/*.exports and syncs the kernel export table (etab)
 * (AC-NFS-05.1). In test mode the command path is redirected to the mock
 * stub provided by TestModeDetector so integration tests can simulate
 * success/failure without a real NFS stack.
 *
 * @return array{exit: int, output: string} Exit code and combined output.
 */
function runExportfsReload(): array
{
    $mockPaths = TestModeDetector::getMockScriptPaths();
    $exportfs = $mockPaths !== null ? $mockPaths['exportfs'] : 'exportfs';

    if ($mockPaths !== null && (!file_exists($exportfs) || !is_executable($exportfs))) {
        return [
            'exit' => 127,
            'output' => "Mock exportfs not found or not executable: {$exportfs}",
        ];
    }

    $output = [];
    $exitCode = 0;
    exec(escapeshellarg($exportfs) . ' -ra 2>&1', $output, $exitCode);

    return [
        'exit' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

/**
 * The full apply/rollback state machine (task 3.4, REQ-NFS-06, F-0002).
 *
 * Ordered steps:
 *  1. Capture last-known-good (LKG): current runtime drop-in content
 *     (empty string if the file is absent).
 *  2. Generate candidate content via generateNfsExports().
 *  3. Structurally validate the candidate — on error, return failure
 *     immediately WITHOUT writing any file.
 *  4. writeExportsFiles(candidate) — atomic install of both copies.
 *  5. runExportfsReload() — on non-zero exit, roll back: reinstall the LKG
 *     content to both copies, then reload again (AC-NFS-06.1).
 *  6. Double-fault (the restorative reload ALSO fails): return a distinct
 *     escalated error reporting both failures. The on-disk drop-in equals
 *     LKG unconditionally (the rollback write always precedes the second
 *     reload) but the kernel etab may be out of sync — the operator must run
 *     `exportfs -ra` or restart NFS manually. NO retry loop is attempted
 *     (design CF-01-04/06).
 *
 * @param array<int, array<string, mixed>>|null $shares Shares to apply; loaded
 *        from disk when null.
 * @return array{success: bool, error: string, escalated: bool} Result.
 */
function applySharesAndReload(?array $shares = null): array
{
    if ($shares === null) {
        $shares = loadShares();
    }

    // Step 1: capture last-known-good runtime content.
    $runtimePath = ConfigRegistry::getExportsDropInPath();
    $lkg = @file_get_contents($runtimePath);
    if ($lkg === false) {
        $lkg = '';
    }

    // Step 2: generate the candidate.
    $candidate = generateNfsExports($shares);

    // Step 3: bounded structural validation — bail before any write.
    $enabledCount = 0;
    foreach ($shares as $share) {
        if (!isset($share['enabled']) || $share['enabled'] !== false) {
            $enabledCount++;
        }
    }
    $validationError = validateCandidateExports($candidate, $enabledCount);
    if ($validationError !== null) {
        logError("Candidate exports validation failed: {$validationError}");
        return [
            'success' => false,
            'error' => "Validation failed: {$validationError}",
            'escalated' => false,
        ];
    }

    // Step 4: atomic install of the candidate to both copies.
    try {
        writeExportsFiles($candidate);
    } catch (\RuntimeException $e) {
        logError('Exports write failed: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Write failed: ' . $e->getMessage(),
            'escalated' => false,
        ];
    }

    // Step 5: apply via exportfs -ra.
    $reload = runExportfsReload();
    if ($reload['exit'] === 0) {
        return ['success' => true, 'error' => '', 'escalated' => false];
    }

    // Reload failed — roll back to LKG (AC-NFS-06.1). The rollback WRITE
    // always precedes the restorative reload, so the on-disk invariant
    // (drop-in == LKG) holds even if the second reload also fails.
    logError("exportfs -ra failed (exit {$reload['exit']}): {$reload['output']} — rolling back");
    try {
        writeExportsFiles($lkg);
    } catch (\RuntimeException $e) {
        // Rollback write itself failed — escalate with maximum detail.
        logError('CRITICAL: rollback write failed: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => 'exportfs -ra failed (' . $reload['output'] . ') AND the rollback write failed ('
                . $e->getMessage() . '). Manual recovery required: inspect '
                . $runtimePath . ' and run exportfs -ra.',
            'escalated' => true,
        ];
    }

    $restore = runExportfsReload();
    if ($restore['exit'] === 0) {
        // Clean rollback: server is back in its prior good state.
        return [
            'success' => false,
            'error' => 'exportfs -ra rejected the new configuration: ' . $reload['output']
                . ' — the previous exports were restored.',
            'escalated' => false,
        ];
    }

    // Step 6: double-fault. On-disk content equals LKG; etab sync is
    // best-effort. Escalate with both failures; no further retries.
    logError("CRITICAL double-fault: restorative exportfs -ra also failed (exit {$restore['exit']}): {$restore['output']}");
    return [
        'success' => false,
        'error' => 'DOUBLE FAULT: exportfs -ra rejected the new configuration ('
            . $reload['output'] . ') and the restorative exportfs -ra also failed ('
            . $restore['output'] . '). The on-disk exports file has been restored to the '
            . 'last-known-good content, but the kernel export table may be out of sync. '
            . 'Run "exportfs -ra" manually or restart the NFS service to re-sync.',
        'escalated' => true,
    ];
}

// ============================================================
// Validation (Phase 4, F-0001)
// ============================================================

/**
 * Validate a share label (name) — F-0001 (task 4.1, AC-NFS-07.4).
 *
 * Checks, in order:
 *  1. Non-empty.
 *  2. Length <= 40 characters.
 *  3. No forbidden characters (SHARE_NAME_PATTERN is a DENYLIST: a truthy
 *     preg_match means the name contains a forbidden character — leading/
 *     trailing whitespace, control chars, path separators, quotes, angle
 *     brackets, etc.).
 *  4. Case-insensitive uniqueness across the existing shares, excluding the
 *     share currently being edited ($editingName) so a share may keep its
 *     own name on update.
 *
 * @param string $name Proposed label.
 * @param array<int, array<string, mixed>> $existingShares Current shares array.
 * @param string|null $editingName Original name when editing (null on add).
 * @return array<int, string> Empty on success; human-readable errors on failure.
 *         Callers MUST check !empty($errors) before persisting.
 */
function validateShareLabel(string $name, array $existingShares, ?string $editingName = null): array
{
    $errors = [];

    if ($name === '') {
        $errors[] = 'Name is required';
        return $errors;
    }

    if (strlen($name) > 40) {
        $errors[] = 'Name must be 40 characters or fewer';
    }

    // DENYLIST semantics: a match means a forbidden character is present.
    if (preg_match(ConfigRegistry::SHARE_NAME_PATTERN, $name) === 1) {
        $errors[] = 'Name contains invalid characters';
    }

    // Case-insensitive uniqueness, excluding the share being edited.
    $lowerName = strtolower($name);
    $lowerEditing = $editingName !== null ? strtolower($editingName) : null;
    foreach ($existingShares as $share) {
        if (!isset($share['name']) || !is_string($share['name'])) {
            continue;
        }
        $existingLower = strtolower($share['name']);
        if ($existingLower === $lowerEditing) {
            continue; // The share's own current name — allowed on edit.
        }
        if ($existingLower === $lowerName) {
            $errors[] = 'A share with this name already exists';
            break;
        }
    }

    return $errors;
}

/**
 * Validate client specs (task 4.2, AC-NFS-07.1 / 07.3).
 *
 * At least one client spec is required; each must match CLIENT_PATTERN
 * (hostname / wildcard hostname / IPv4 / CIDR / @netgroup / *).
 *
 * @param array<int, mixed> $clients Client specs to validate (mixed —
 *        runtime-guarded; non-strings are rejected with an error).
 * @return array<int, string> Empty on success; specific errors on failure.
 */
function validateClients(array $clients): array
{
    $errors = [];

    if (count($clients) === 0) {
        $errors[] = 'At least one client specification is required';
        return $errors;
    }

    foreach ($clients as $client) {
        if (!is_string($client) || $client === '') {
            $errors[] = 'Empty client specification';
            continue;
        }
        if (preg_match(ConfigRegistry::CLIENT_PATTERN, $client) !== 1) {
            $errors[] = "Invalid client specification: {$client}";
        }
    }

    return $errors;
}

/**
 * Validate extra export option tokens (task 4.2).
 *
 * Each token must match OPTION_PATTERN (identifier with optional =value) —
 * excludes whitespace, shell metacharacters, and anything that could smuggle
 * an additional directive into the exports file.
 *
 * @param array<int, mixed> $extraOptions Option tokens to validate (mixed —
 *        runtime-guarded; non-strings are rejected with an error).
 * @return array<int, string> Empty on success; specific errors on failure.
 */
function validateOptions(array $extraOptions): array
{
    $errors = [];

    foreach ($extraOptions as $opt) {
        if (!is_string($opt) || $opt === '') {
            $errors[] = 'Empty export option';
            continue;
        }
        if (preg_match(ConfigRegistry::OPTION_PATTERN, $opt) !== 1) {
            $errors[] = "Invalid export option: {$opt}";
        }
    }

    return $errors;
}

/**
 * Validate anonuid/anongid (task 4.2, AC-NFS-07.2).
 *
 * When present, each must be a non-negative integer.
 *
 * @param int|null $anonuid Anonymous uid, or null when unset.
 * @param int|null $anongid Anonymous gid, or null when unset.
 * @return array<int, string> Empty on success; specific errors on failure.
 */
function validateAnon(?int $anonuid, ?int $anongid): array
{
    $errors = [];

    if ($anonuid !== null && $anonuid < 0) {
        $errors[] = 'anonuid must be a non-negative integer';
    }
    if ($anongid !== null && $anongid < 0) {
        $errors[] = 'anongid must be a non-negative integer';
    }

    return $errors;
}

/**
 * TOCTOU-hardened path validation (task 4.3, AC-NFS-09.1 / 09.2).
 *
 * Resolves the canonical realpath (following symlinks) and confirms the
 * result is an existing directory under the allowed /mnt/ root. Because the
 * check operates on the RESOLVED path, a symlink whose target escapes /mnt/
 * is rejected even though its lexical path looks allowed — mirroring the
 * SMB plugin's TOCTOU hardening.
 *
 * Test-mode awareness: the path is resolved through TestModeDetector so the
 * chroot-style harness prefix is applied, and the /mnt/ containment check
 * uses the harness-aware matcher.
 *
 * @param string $path Path to validate (e.g. /mnt/user/media).
 * @return array<int, string> Empty on success; specific errors on failure.
 */
function validatePath(string $path): array
{
    $errors = [];

    if ($path === '') {
        $errors[] = 'Path is required';
        return $errors;
    }

    $resolved = realpath(TestModeDetector::resolvePath($path));
    if ($resolved === false) {
        $errors[] = 'Path does not exist or is not accessible';
        return $errors;
    }

    if (!is_dir($resolved)) {
        $errors[] = 'Path must be a directory';
        return $errors;
    }

    // Containment on the RESOLVED path guards against symlink escape.
    if (!TestModeDetector::isValidMntPath($resolved)) {
        $errors[] = 'Path must be under /mnt/';
    }

    return $errors;
}

/**
 * Aggregate share validation (task 4.4, AC-NFS-07.x / 08.1 / 09.x).
 *
 * Strips control characters from all string fields FIRST (REQ-NFS-08), then
 * aggregates errors from every field validator plus the enum allow-list
 * checks for access/sync/subtree/squash.
 *
 * GUARANTEE: all CRUD handlers call validateShare() first and return the
 * errors to the UI WITHOUT calling saveShares()/applySharesAndReload() when
 * any error is present — no exports file is written on validation failure.
 *
 * @param array<string, mixed> $share Share data to validate.
 * @param array<int, array<string, mixed>> $existingShares Current shares array.
 * @param string|null $editingName Original name when editing (null on add).
 * @return array<int, string> Empty on success; all applicable errors on failure.
 */
function validateShare(array $share, array $existingShares, ?string $editingName = null): array
{
    // REQ-NFS-08: strip control chars from every string field before any
    // other check, so validators and generators see sanitized values.
    foreach (['name', 'path', 'comment'] as $field) {
        if (isset($share[$field]) && is_string($share[$field])) {
            $share[$field] = stripControlChars($share[$field]);
        }
    }
    if (isset($share['extra_options']) && is_array($share['extra_options'])) {
        $sanitized = [];
        foreach ($share['extra_options'] as $opt) {
            if (is_string($opt)) {
                $sanitized[] = stripControlChars($opt);
            }
        }
        $share['extra_options'] = $sanitized;
    }

    $errors = [];

    // Label (F-0001).
    $name = isset($share['name']) && is_string($share['name']) ? $share['name'] : '';
    $errors = array_merge($errors, validateShareLabel($name, $existingShares, $editingName));

    // Path (TOCTOU-hardened).
    $path = isset($share['path']) && is_string($share['path']) ? $share['path'] : '';
    $errors = array_merge($errors, validatePath($path));

    // Clients.
    $clients = [];
    if (isset($share['clients']) && is_array($share['clients'])) {
        foreach ($share['clients'] as $client) {
            if (is_string($client) && trim($client) !== '') {
                $clients[] = trim($client);
            }
        }
    }
    $errors = array_merge($errors, validateClients($clients));

    // Extra options.
    if (isset($share['extra_options']) && is_array($share['extra_options'])) {
        $opts = [];
        foreach ($share['extra_options'] as $opt) {
            if (is_string($opt) && $opt !== '') {
                $opts[] = $opt;
            }
        }
        $errors = array_merge($errors, validateOptions($opts));
    }

    // Duplicate fsid guard: a user-supplied fsid= token must not
    // collide with another share's effective fsid (its own fsid= token or
    // its persisted auto-assigned value). Duplicate fsids silently break
    // NFS client file-handle resolution. Auto-assigned values cannot
    // collide by construction, so only user-supplied ones are checked.
    $candidateFsid = getUserFsid($share);
    if ($candidateFsid !== null) {
        foreach ($existingShares as $existing) {
            $existingName = isset($existing['name']) && is_string($existing['name']) ? $existing['name'] : '';
            if ($editingName !== null && $existingName === $editingName) {
                continue; // the share being edited may keep its own fsid
            }
            $existingFsid = getUserFsid($existing);
            if ($existingFsid === null && isset($existing['fsid']) && is_int($existing['fsid'])) {
                $existingFsid = (string)$existing['fsid'];
            }
            if ($existingFsid !== null && $existingFsid === $candidateFsid) {
                $errors[] = "fsid={$candidateFsid} is already used by export '{$existingName}'";
                break;
            }
        }
    }

    // anonuid / anongid: reject non-numeric values, then range-check.
    $anonuid = null;
    if (isset($share['anonuid']) && $share['anonuid'] !== '') {
        if (is_int($share['anonuid']) || (is_string($share['anonuid']) && ctype_digit($share['anonuid']))) {
            $anonuid = (int)$share['anonuid'];
        } else {
            $errors[] = 'anonuid must be a non-negative integer';
        }
    }
    $anongid = null;
    if (isset($share['anongid']) && $share['anongid'] !== '') {
        if (is_int($share['anongid']) || (is_string($share['anongid']) && ctype_digit($share['anongid']))) {
            $anongid = (int)$share['anongid'];
        } else {
            $errors[] = 'anongid must be a non-negative integer';
        }
    }
    $errors = array_merge($errors, validateAnon($anonuid, $anongid));

    // Enum allow-lists for the four option fields (absent fields are fine —
    // generation applies conservative defaults per AC-NFS-15.1).
    $enums = [
        'access'  => ['ro', 'rw'],
        'sync'    => ['sync', 'async'],
        'subtree' => ['subtree_check', 'no_subtree_check'],
        'squash'  => ['root_squash', 'no_root_squash', 'all_squash'],
    ];
    foreach ($enums as $field => $allowed) {
        if (isset($share[$field]) && $share[$field] !== '') {
            if (!is_string($share[$field]) || !in_array($share[$field], $allowed, true)) {
                $errors[] = "Invalid {$field} value";
            }
        }
    }

    return $errors;
}

// ============================================================
// Backups, settings & import/export (Phase 6, task 6.1)
// ============================================================

/**
 * Create a timestamped backup of shares.json (task 6.1, AC-NFS-14.2).
 *
 * Filename format: shares-YYYYMMDD-HHmmss.json (matches
 * ConfigRegistry::BACKUP_FILENAME_PATTERN). Called before destructive
 * operations (import, restore, delete). After creating the backup, retention
 * pruning keeps the newest N backups per settings.
 *
 * @param string|null $configBase Optional override for the config base path.
 * @return string|false Backup filename (basename) on success, false when
 *         shares.json does not exist or the copy fails.
 */
function createBackup(?string $configBase = null)
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $sharesFile = $base . '/plugins/custom.nfs.shares/shares.json';

    if (!file_exists($sharesFile)) {
        return false;
    }

    $backupDir = $base . '/plugins/custom.nfs.shares/backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }

    $filename = 'shares-' . date('Ymd-His') . '.json';
    if (!@copy($sharesFile, $backupDir . '/' . $filename)) {
        logError("Failed to create backup: {$filename}");
        return false;
    }

    pruneBackups($backupDir, getBackupRetention($configBase));
    return $filename;
}

/**
 * Prune old backups, keeping only the newest $retention files (task 6.1).
 *
 * Retention is count-based (not time-based). Backups sort chronologically by
 * filename (shares-YYYYMMDD-HHmmss.json), so an ascending sort puts the
 * oldest first for deletion.
 */
function pruneBackups(string $backupDir, int $retention): void
{
    $files = glob($backupDir . '/shares-*.json');
    if ($files === false || count($files) <= $retention) {
        return;
    }

    sort($files); // Filename timestamp format makes this chronological.
    $toRemove = count($files) - $retention;
    for ($i = 0; $i < $toRemove; $i++) {
        @unlink($files[$i]);
    }
}

/**
 * List all backups with metadata, newest first (task 6.1).
 *
 * @param string|null $configBase Optional override for the config base path.
 * @return array<int, array{filename: string, date: string, size: int, shares: int}>
 */
function listBackups(?string $configBase = null): array
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $backupDir = $base . '/plugins/custom.nfs.shares/backups';

    if (!is_dir($backupDir)) {
        return [];
    }

    $files = glob($backupDir . '/shares-*.json');
    if ($files === false) {
        return [];
    }

    $backups = [];
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        $decoded = $content !== false ? json_decode($content, true) : null;
        $mtime = @filemtime($file);
        $size = @filesize($file);
        $backups[] = [
            'filename' => basename($file),
            'date' => date('Y-m-d H:i:s', $mtime !== false ? $mtime : 0),
            'size' => $size !== false ? $size : 0,
            'shares' => is_array($decoded) ? count($decoded) : 0,
        ];
    }

    // Newest first (filename timestamps sort chronologically).
    usort($backups, fn(array $a, array $b): int => strcmp($b['filename'], $a['filename']));
    return $backups;
}

/**
 * Read a backup's share array (task 6.1).
 *
 * The caller MUST have validated $filename against
 * ConfigRegistry::BACKUP_FILENAME_PATTERN (path-traversal guard).
 *
 * @param string|null $configBase Optional override for the config base path.
 * @return array<int, array<string, mixed>>|false Shares array, or false on error.
 */
function viewBackup(string $filename, ?string $configBase = null)
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.nfs.shares/backups/' . $filename;

    if (!file_exists($file)) {
        return false;
    }

    $content = @file_get_contents($file);
    if ($content === false) {
        return false;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : false;
}

/**
 * Restore shares from a backup and re-apply exports (task 6.1).
 *
 * Validates the backup contains a parseable JSON array BEFORE overwriting the
 * live shares.json (a corrupt backup cannot destroy the current config —
 * mirrors the SMB plugin's v2026.05.18 restore hardening). After restoring,
 * applySharesAndReload() publishes the restored exports (the SMB "restore
 * didn't reload" bug class).
 *
 * @param string|null $configBase Optional override for the config base path.
 * @return array{success: bool, error: string} Result.
 */
function restoreBackup(string $filename, ?string $configBase = null): array
{
    $restored = viewBackup($filename, $configBase);
    if ($restored === false) {
        return ['success' => false, 'error' => "Backup not found or not valid JSON: {$filename}"];
    }

    if (!saveShares($restored, $configBase)) {
        return ['success' => false, 'error' => 'Failed to write restored shares'];
    }

    $apply = applySharesAndReload(loadShares($configBase));
    if (!$apply['success']) {
        return ['success' => false, 'error' => 'Restored but reload failed: ' . $apply['error']];
    }

    return ['success' => true, 'error' => ''];
}

/**
 * Delete a backup file (task 6.1).
 *
 * The caller MUST have validated $filename against BACKUP_FILENAME_PATTERN.
 *
 * @param string|null $configBase Optional override for the config base path.
 */
function deleteBackup(string $filename, ?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.nfs.shares/backups/' . $filename;
    return file_exists($file) && @unlink($file);
}

/**
 * Load plugin settings with defaults applied (task 6.1).
 *
 * settings.cfg is INI format with keys ENABLE, RETENTION, MENU_PLACEMENT.
 * Centralizes the defaults so the Settings page, api.php, and tests agree.
 *
 * @param string|null $configBase Optional override for the config base path.
 * @return array{ENABLE: string, RETENTION: string, MENU_PLACEMENT: string}
 */
function loadSettings(?string $configBase = null): array
{
    $defaults = [
        'ENABLE' => 'yes',
        'RETENTION' => '10',
        'MENU_PLACEMENT' => 'topbar',
    ];

    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.nfs.shares/settings.cfg';
    if (!file_exists($file)) {
        return $defaults;
    }

    $parsed = parse_ini_file($file);
    if (!is_array($parsed)) {
        return $defaults;
    }

    return [
        'ENABLE' => is_string($parsed['ENABLE'] ?? null) ? $parsed['ENABLE'] : $defaults['ENABLE'],
        'RETENTION' => is_string($parsed['RETENTION'] ?? null) ? $parsed['RETENTION'] : $defaults['RETENTION'],
        'MENU_PLACEMENT' => is_string($parsed['MENU_PLACEMENT'] ?? null)
            ? $parsed['MENU_PLACEMENT'] : $defaults['MENU_PLACEMENT'],
    ];
}

/**
 * Persist plugin settings atomically (task 6.1).
 *
 * @param array{ENABLE: string, RETENTION: string, MENU_PLACEMENT: string} $settings
 * @param string|null $configBase Optional override for the config base path.
 */
function saveSettings(array $settings, ?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.nfs.shares/settings.cfg';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $content = 'ENABLE="' . $settings['ENABLE'] . '"' . "\n"
        . 'RETENTION="' . $settings['RETENTION'] . '"' . "\n"
        . 'MENU_PLACEMENT="' . $settings['MENU_PLACEMENT'] . '"' . "\n";

    $pidValue = getmypid();
    $tmp = $file . '.tmp.' . ($pidValue !== false ? (string)$pidValue : '0');
    if (@file_put_contents($tmp, $content) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Backup retention count from settings (task 6.1). Default 10.
 *
 * @param string|null $configBase Optional override for the config base path.
 */
function getBackupRetention(?string $configBase = null): int
{
    $settings = loadSettings($configBase);
    $retention = (int)$settings['RETENTION'];
    return $retention > 0 ? $retention : 10;
}

// ============================================================
// NFS service detection & status (Phase 6, task 6.2)
// ============================================================

/**
 * Run a status command, capturing exit code and output. Uses the test-mode
 * mock when TestModeDetector provides one; missing mocks report exit 127
 * (mirrors runExportfsReload's guard).
 *
 * @return array{exit: int, output: string}
 */
function runStatusCommand(string $mockKey, string $realCommand, string $args): array
{
    $mockPaths = TestModeDetector::getMockScriptPaths();
    $cmd = $realCommand;
    if ($mockPaths !== null) {
        $cmd = $mockPaths[$mockKey] ?? $realCommand;
        if (!file_exists($cmd) || !is_executable($cmd)) {
            return ['exit' => 127, 'output' => "Mock {$mockKey} not available: {$cmd}"];
        }
    }

    $output = [];
    $exitCode = 0;
    exec(escapeshellarg($cmd) . ' ' . $args . ' 2>&1', $output, $exitCode);
    return ['exit' => $exitCode, 'output' => implode("\n", $output)];
}

/**
 * Slackware-style NFS service detection (task 6.2, AC-NFS-10.2).
 *
 * Three checks in sequence:
 *  (a) /etc/rc.d/rc.nfsd exists and is executable (init script present);
 *  (b) `rc.nfsd status` exits 0 (daemon running);
 *  (c) `rpcinfo -p localhost` exits 0 (RPC portmapper responding).
 *
 * NEVER starts, stops, or modifies the NFS service — detection only.
 * The user-facing enable switch is Settings > NFS > Enable NFS.
 *
 * @return array{nfs_enabled: bool, nfsd_running: bool, rpcinfo_ok: bool}
 */
function getNfsServiceStatus(): array
{
    $mockPaths = TestModeDetector::getMockScriptPaths();
    $rcNfsd = $mockPaths !== null ? $mockPaths['rcNfsd'] : '/etc/rc.d/rc.nfsd';

    $initPresent = file_exists($rcNfsd) && is_executable($rcNfsd);

    $running = false;
    if ($initPresent) {
        $status = runStatusCommand('rcNfsd', '/etc/rc.d/rc.nfsd', 'status');
        $running = $status['exit'] === 0;
    }

    $rpc = runStatusCommand('rpcinfo', 'rpcinfo', '-p localhost');

    return [
        'nfs_enabled' => $initPresent,
        'nfsd_running' => $running,
        'rpcinfo_ok' => $rpc['exit'] === 0,
    ];
}

/**
 * Export status panel data (task 6.2, AC-NFS-11.1).
 *
 * Surfaces the three views needed to debug "UI says exported but client
 * cannot mount": what the kernel believes is exported (exportfs -v), mountd's
 * export list (showmount -e), and registered RPC services (rpcinfo -p).
 *
 * @return array{exports: string, showmount: string, rpcinfo: string}
 */
function getExportStatus(): array
{
    $exports = runStatusCommand('exportfs', 'exportfs', '-v');
    $showmount = runStatusCommand('showmount', 'showmount', '-e localhost');
    $rpcinfo = runStatusCommand('rpcinfo', 'rpcinfo', '-p localhost');

    return [
        'exports' => $exports['output'],
        'showmount' => $showmount['output'],
        'rpcinfo' => $rpcinfo['output'],
    ];
}
