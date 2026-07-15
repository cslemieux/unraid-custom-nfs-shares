<?php

/**
 * Custom NFS Shares — Share Form
 *
 * Shared form renderer for NFSSharesAdd.page and NFSSharesUpdate.page.
 * Ported from custom.smb.shares/include/ShareForm.php; NFS-specific fields
 * replace all SMB/Samba concepts.
 *
 * Fields: name, path (+Browse), clients (textarea), access, sync, subtree,
 * squash, anonuid, anongid, extra_options, comment, enabled.
 *
 * New-export defaults: rw / sync / no_subtree_check / root_squash (AC-NFS-15.1)
 * Warning markup for async (data-integrity) and no_root_squash (security)
 * is rendered hidden and revealed by JS on option change (AC-NFS-15.2).
 *
 * @phpstan-type ShareData array{
 *   name?: string,
 *   path?: string,
 *   clients?: string[],
 *   access?: string,
 *   sync?: string,
 *   subtree?: string,
 *   squash?: string,
 *   anonuid?: int|string|null,
 *   anongid?: int|string|null,
 *   extra_options?: string[],
 *   comment?: string,
 *   enabled?: bool,
 * }
 */

// NO declare(strict_types=1) here — this file is consumed by the Add/Update
// .page files via an eval of parse_file(), where the declare is no longer the
// first statement and PHP throws "strict_types declaration must be the very
// first statement" as a Fatal error on real Unraid (invisible in the test
// harness, which require's this file directly). Mirrors the SMB plugin's
// ShareForm.php, which omits the declare for the same reason.
//
// ALSO: never put a literal PHP close-tag sequence inside comments/strings in
// this file. Unraid renders .page output through Michelf MarkdownExtra, whose
// PHP-block protection lazily matches to the FIRST close-tag it sees
// (webGui/include/Markdown.php ~line 447); a close-tag inside a comment
// truncates the protected region and the rest of this block leaks into
// Markdown as literal text instead of executing.
// Both found during on-device verification 2026-07-15 on Unraid 7.3.1.

/** @var string $docroot Provided by Unraid's page renderer */
global $var, $docroot;

require_once "$docroot/plugins/custom.nfs.shares/include/lib.php";

// ── Resolve mode: edit (name param) or add (no param) ────────────────────────
$shareName = $_GET['name'] ?? '';

/** @var array<int,array<string,mixed>> $shares */
$shares     = loadShares();

/** @var array<string,mixed> $share */
$share      = [];
$shareIndex = -1;
$isNew      = true;

if ($shareName !== '') {
    foreach ($shares as $idx => $s) {
        if (($s['name'] ?? '') === $shareName) {
            $share      = $s;
            $shareIndex = $idx;
            $isNew      = false;
            break;
        }
    }
}

// ── Field values with defaults (AC-NFS-15.1: rw/sync/no_subtree_check/root_squash) ──
$fEnabled  = isset($share['enabled']) ? (bool) $share['enabled'] : true;
$fName     = (string) ($share['name']    ?? '');
$fPath     = (string) ($share['path']    ?? '');
$fComment  = (string) ($share['comment'] ?? '');
$fAccess   = (string) ($share['access']  ?? 'rw');
$fSync     = (string) ($share['sync']    ?? 'sync');
$fSubtree  = (string) ($share['subtree'] ?? 'no_subtree_check');
$fSquash   = (string) ($share['squash']  ?? 'root_squash');
$fAnonuid  = isset($share['anonuid']) ? (string) $share['anonuid'] : '';
$fAnongid  = isset($share['anongid']) ? (string) $share['anongid'] : '';

/** @var string[] $rawClients */
$rawClients = is_array($share['clients'] ?? null) ? $share['clients'] : [];
$fClients   = implode("\n", $rawClients);

/** @var string[] $rawExtra */
$rawExtra   = is_array($share['extra_options'] ?? null) ? $share['extra_options'] : [];
$fExtra     = implode(',', $rawExtra);

// AC-NFS-15.2: initial warning visibility determined server-side
$showAsyncWarn      = $fSync   === 'async'        ? 'block' : 'none';
$showSquashWarn     = $fSquash === 'no_root_squash' ? 'block' : 'none';

$formAction    = $isNew
    ? '/plugins/custom.nfs.shares/api.php?action=add'
    : '/plugins/custom.nfs.shares/api.php?action=update';
$submitLabel   = $isNew ? _('Add Export') : _('Apply');
$submitWorking = $isNew ? _('Adding...')  : _('Applying...');
?>
<link rel="stylesheet" href="/plugins/custom.nfs.shares/css/feedback.css">
<link rel="stylesheet" href="/plugins/custom.nfs.shares/css/nfs.css">
<link rel="stylesheet" href="/webGui/styles/jquery.filetree.css">
<script src="/plugins/custom.nfs.shares/js/feedback.js"></script>
<script src="/webGui/javascript/jquery.filetree.js"></script>

<form markdown="1" method="POST" action="<?= htmlspecialchars($formAction) ?>"
      id="nfsShareForm" onsubmit="return nfsSubmitForm(this)">

<?php if (!$isNew) : ?>
<input type="hidden" name="original_name" value="<?= htmlspecialchars($fName) ?>">
<?php endif; ?>

<div class="title">
  <span class="left inline-flex flex-row items-center gap-1">
    <i class="fa fa-share-alt title"></i>_(NFS Export Settings)_
  </span>
  <span class="right"></span>
</div>
<div markdown="1" class="shade">

_(Enabled)_:
: <select name="enabled">
  <?= mk_option($fEnabled ? 'yes' : 'no', 'yes', _('Yes')) ?>
  <?= mk_option($fEnabled ? 'yes' : 'no', 'no', _('No'))  ?>
  </select>

> _(When disabled, this export is excluded from the NFS configuration and will not be served.)_

_(Export Name)_:
: <input type="text" name="name"
         value="<?= htmlspecialchars($fName) ?>"
         maxlength="40" required>

> _(A label up to 40 characters. Must be unique (case-insensitive). Visible in the plugin UI only — not sent over the network.)_

_(Path)_:
: <span class="inline-flex flex-row items-center gap-1"
        style="display:inline-flex;align-items:center;gap:4px;">
    <input type="text" name="path" id="nfs-path-input"
           value="<?= htmlspecialchars($fPath) ?>"
           required
           placeholder="_(Enter path or click Browse...)_"
           style="flex:1;">
    <input type="button" value="_(Browse)_"
           onclick="nfsOpenPathBrowser(document.getElementById('nfs-path-input'))">
  </span>

> _(Must be an existing directory under /mnt/ — e.g. /mnt/user/mydata. The path is validated with realpath() to prevent symlink escapes.)_

_(Comment)_:
: <input type="text" name="comment"
         value="<?= htmlspecialchars($fComment) ?>">

> _(Optional description for this export. Stored in shares.json; not written to the exports file.)_

</div>

<div class="title">
  <span class="left inline-flex flex-row items-center gap-1">
    <i class="fa fa-cog title"></i>_(NFS Export Options)_
  </span>
  <span class="right"></span>
</div>
<div markdown="1" class="shade">

_(Clients)_:
: <textarea name="clients" rows="4"
            style="width:100%;font-family:monospace;"
            placeholder="_(One client per line — e.g. 192.168.1.0/24  *.local  @trusted)_"><?= htmlspecialchars($fClients) ?></textarea>

> _(At least one client is required. Each line: hostname, IPv4 address, CIDR range, @netgroup, or wildcard (*).)_

_(Access)_:
: <select name="access">
  <?= mk_option($fAccess, 'rw', _('Read/Write (rw)')) ?>
  <?= mk_option($fAccess, 'ro', _('Read-only (ro)'))  ?>
  </select>

> _(Controls whether clients may write to this export.)_

_(Sync mode)_:
: <select name="sync" id="nfs-sync-select" onchange="nfsToggleWarnings()">
  <?= mk_option($fSync, 'sync', _('Synchronous (sync) — recommended')) ?>
  <?= mk_option($fSync, 'async', _('Asynchronous (async) — faster, less safe')) ?>
  </select>

<div id="nfs-warn-async" class="nfs-warning nfs-warning-orange"
     style="display:<?= $showAsyncWarn ?>;">
  <i class="fa fa-exclamation-triangle orange-text"></i>
  <strong class="orange-text">_(Data-integrity warning)_</strong><br>
  _(async mode acknowledges write requests before data is flushed to stable storage. A server crash may cause data loss or corruption on the client.)_
</div>

> _(sync: replies only after data is committed to disk. async: replies immediately — higher throughput but data at risk on crash.)_

_(Subtree check)_:
: <select name="subtree">
  <?= mk_option($fSubtree, 'no_subtree_check', _('No subtree check (recommended)')) ?>
  <?= mk_option($fSubtree, 'subtree_check', _('Subtree check'))                  ?>
  </select>

> _(no_subtree_check improves reliability when clients hold open file handles across renames inside an export.)_

_(Squash)_:
: <select name="squash" id="nfs-squash-select" onchange="nfsToggleWarnings()">
  <?= mk_option($fSquash, 'root_squash', _('Root squash (recommended)'))    ?>
  <?= mk_option($fSquash, 'no_root_squash', _('No root squash — less secure')) ?>
  <?= mk_option($fSquash, 'all_squash', _('All squash'))                   ?>
  </select>

<div id="nfs-warn-no-root-squash" class="nfs-warning nfs-warning-red"
     style="display:<?= $showSquashWarn ?>;">
  <i class="fa fa-exclamation-triangle red-text"></i>
  <strong class="red-text">_(Security warning)_</strong><br>
  _(no_root_squash grants remote root users full root access on this server. Only use on fully-trusted, isolated networks.)_
</div>

> _(root_squash maps remote uid 0 to the anonymous user. no_root_squash preserves root identity — dangerous on untrusted networks.)_

_(Anonymous UID)_:
: <input type="text" name="anonuid"
         value="<?= htmlspecialchars($fAnonuid) ?>"
         placeholder="_(e.g. 65534 — leave empty for server default)_"
         style="width:220px;">

> _(UID mapped to anonymous users (used with all_squash or root_squash). Leave empty to use the server default (usually nobody=65534).)_

_(Anonymous GID)_:
: <input type="text" name="anongid"
         value="<?= htmlspecialchars($fAnongid) ?>"
         placeholder="_(e.g. 65534 — leave empty for server default)_"
         style="width:220px;">

> _(GID mapped to anonymous users. Leave empty to use the server default.)_

_(Extra options)_:
: <input type="text" name="extra_options"
         value="<?= htmlspecialchars($fExtra) ?>"
         placeholder="_(comma-separated, e.g. fsid=0,crossmnt)_"
         style="width:100%;">

> _(Additional comma-separated exports(5) options appended verbatim after the standard options. Each token is validated server-side.)_

</div>

&nbsp;
: <span class="inline-block">
    <input type="submit" id="nfs-submit-btn"
           value="<?= htmlspecialchars($submitLabel) ?>">
    <input type="button" value="_(Done)_" onclick="nfsDone()">
  </span>

</form>

<script>
/* ── NFS Share Form JS ─────────────────────────────────────────────────────── */
$(function () {
    // Store original submit-button text before any handler mutates it.
    $('#nfs-submit-btn').data('orig-val', $('#nfs-submit-btn').val());
    // Apply initial warning state matching server-rendered select values.
    nfsToggleWarnings();
});

/**
 * Toggle risk warnings immediately when option changes (AC-NFS-15.2).
 * Called via onchange on #nfs-sync-select and #nfs-squash-select.
 */
function nfsToggleWarnings() {
    var sync   = $('#nfs-sync-select').val();
    var squash = $('#nfs-squash-select').val();
    $('#nfs-warn-async').toggle(sync === 'async');
    $('#nfs-warn-no-root-squash').toggle(squash === 'no_root_squash');
}

/** Navigate back to the NFS shares list. */
function nfsDone() {
    location.href = '/NFSShares';
}

/**
 * Directory picker — mirrors CreateDocker.php / SMB ShareForm pattern.
 *
 * CRITICAL: every programmatic .val() call is immediately followed by
 * .trigger('change') so Unraid's form-state tracker notices the update and
 * enables Apply (task 5.4 requirement; omitting the trigger leaves Apply
 * grayed out even when a field is populated).
 *
 * @param {HTMLInputElement} el  The path input element.
 */
function nfsOpenPathBrowser(el) {
    var $input   = $(el);
    var $wrapper = $input.closest('span');

    // Toggle: if tree already open, close it.
    if ($wrapper.next('.fileTree').length) {
        $wrapper.next('.fileTree').slideUp('fast', function () { $(this).remove(); });
        return;
    }

    var r  = Math.floor(Math.random() * 10000 + 1);
    var id = 'nfsFileTree' + r;
    $wrapper.after(
        '<div id="' + id + '" class="fileTree" style="display:none;position:absolute;'
        + 'z-index:1000;background:var(--background-color);border:1px solid var(--border-color);'
        + 'box-shadow:0 4px 12px rgba(0,0,0,0.4);max-height:300px;overflow:auto;'
        + 'border-radius:4px;padding:4px 0;width:400px;"></div>'
    );
    var $ft = $('#' + id);

    $ft.fileTree({
        root:          '/mnt',
        top:           '/mnt',
        filter:        'HIDE_FILES_FILTER',
        allowBrowsing: true
    }, function () {
        // file callback — ignored; we only care about folder selection
    }, function (folder) {
        // CRITICAL: trigger('change') after .val() so Unraid's form tracker fires.
        $input.val(folder.replace(/\/\/+/g, '/')).trigger('change');

        // Auto-populate name from folder only when adding (not editing).
        var $nameInput = $('input[name="name"]');
        var formAction = $input.closest('form').attr('action') || '';
        var isAdd      = formAction.indexOf('action=add') !== -1;
        if (isAdd && !$.trim($nameInput.val())) {
            var parts = folder.split('/').filter(Boolean);
            var last  = parts.length ? parts[parts.length - 1] : '';
            if (last) {
                // CRITICAL: trigger change after auto-population.
                $nameInput.val(last).trigger('change');
            }
        }
    });

    // Position below the wrapper span.
    $ft.css({
        position: 'absolute',
        left:     $wrapper.position().left,
        top:      $wrapper.position().top + $wrapper.outerHeight()
    });

    // Close on outside click.
    $(document).on('mouseup.nfsft' + r, function (e) {
        if (
            !$ft.is(e.target) && $ft.has(e.target).length === 0
            && !$input.is(e.target)
            && !$wrapper.find('input[type=button]').is(e.target)
        ) {
            $ft.slideUp('fast', function () { $(this).remove(); });
            $(document).off('mouseup.nfsft' + r);
        }
    });

    $ft.slideDown('fast');
}

/**
 * Client-side validation + AJAX submit (AC-NFS-12.2).
 * Always returns false to suppress the browser's native submit.
 *
 * @param {HTMLFormElement} form
 * @returns {boolean}
 */
function nfsSubmitForm(form) {
    var $form   = $(form);
    var name    = $.trim($form.find('input[name="name"]').val());
    var path    = $.trim($form.find('input[name="path"]').val());
    var clients = $.trim($form.find('textarea[name="clients"]').val());

    // Client-side guards — server-side validateShare() is the authoritative gate.
    if (!name) {
        swal({ title: '_(Error)_', text: '_(Export name is required)_', type: 'error' });
        return false;
    }
    if (!path) {
        swal({ title: '_(Error)_', text: '_(Path is required)_', type: 'error' });
        return false;
    }
    if (path.indexOf('/mnt/') !== 0) {
        swal({ title: '_(Error)_', text: '_(Path must start with /mnt/)_', type: 'error' });
        return false;
    }
    if (!clients) {
        swal({ title: '_(Error)_', text: '_(At least one client is required)_', type: 'error' });
        return false;
    }

    var $btn = $('#nfs-submit-btn');
    var orig = $btn.data('orig-val') || $btn.val();
    $btn.val('_(Saving...)_').prop('disabled', true);

    $.post($form.attr('action'), $form.serialize())
        .done(function (response) {
            if (response.success) {
                if (response.warning) {
                    showWarning(response.warning);
                } else {
                    showSuccess(response.message || '_(Export saved)_');
                }
                // Redirect to list on success (AC-NFS-12.2).
                setTimeout(function () { location.href = '/NFSShares'; }, 1200);
            } else {
                // Surface server-side errors inline WITHOUT losing form state.
                swal({
                    title: '_(Validation error)_',
                    text:  response.error || '_(Unknown error)_',
                    type:  'error'
                });
                // Re-enable submit so user can correct and retry.
                $btn.val(orig).prop('disabled', false);
            }
        })
        .fail(function (xhr) {
            var msg = '_(Request failed)_';
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.error) { msg = resp.error; }
            } catch (e) { /* ignore parse error */ }
            swal({ title: '_(Error)_', text: msg, type: 'error' });
            $btn.val(orig).prop('disabled', false);
        });

    return false; // Always prevent native form submission
}
</script>
