/* global $, swal, showSuccess, showError, showWarning */

/**
 * Custom NFS Shares — main.js
 *
 * Provides:
 *   - nfsToggleWarnings()      Toggle async/no_root_squash risk warnings
 *   - nfsOpenPathBrowser(el)   Directory picker wired to fileTree
 *   - nfsSubmitForm(form)      AJAX form submit with client-side validation
 *   - nfsDone()                Navigate back to list
 *
 * Ported/adapted from custom.smb.shares/js/main.js.
 *
 * CRITICAL — .trigger('change') after every programmatic .val():
 *   Unraid's form-state tracker listens for the native 'change' event to
 *   decide whether Apply should be enabled. A programmatic .val() does NOT
 *   fire that event; .trigger('change') must follow immediately. Omitting it
 *   leaves Apply grayed out even when the path field is populated — the bug
 *   is invisible in unit tests and only appears on a real Unraid system.
 *   (See forum report: "changing a directory under Edit for the Path still
 *   doesn't populate the SAVE button" — comet424, post-v2026.05.18a.)
 *
 * jQuery patterns only: $(function(){}), no arrow functions, input type=submit.
 */

/**
 * Toggle risk-warning banners immediately on option change (AC-NFS-15.2).
 *
 * Called via onchange on #nfs-sync-select and #nfs-squash-select in
 * ShareForm.php. Safe to call before DOM ready (both selects have
 * server-rendered initial values so the banners start in the correct state).
 */
function nfsToggleWarnings() {
    var sync   = $('#nfs-sync-select').val();
    var squash = $('#nfs-squash-select').val();
    $('#nfs-warn-async').toggle(sync === 'async');
    $('#nfs-warn-no-root-squash').toggle(squash === 'no_root_squash');
}

/**
 * Navigate back to the NFS exports list page.
 */
function nfsDone() {
    location.href = '/NFSShares';
}

/**
 * Open a fileTree directory picker attached to the given path input.
 *
 * Mirrors the pattern from SMB ShareForm.php / Docker CreateDocker.php.
 * Calling sequence on folder selection:
 *   1. $input.val(folder).trigger('change')  — updates field + fires Unraid tracker
 *   2. $nameInput.val(name).trigger('change') — auto-fills name on Add (not Edit)
 *
 * Both .trigger('change') calls are CRITICAL (see module header).
 *
 * @param {HTMLInputElement} el  The <input type="text" name="path"> element.
 */
function nfsOpenPathBrowser(el) {
    var $input   = $(el);
    var $wrapper = $input.closest('span');

    // Toggle: if tree is already open for this input, close it.
    if ($wrapper.next('.fileTree').length) {
        $wrapper.next('.fileTree').slideUp('fast', function () { $(this).remove(); });
        return;
    }

    var r  = Math.floor(Math.random() * 10000 + 1);
    var id = 'nfsFileTree' + r;

    $wrapper.after(
        '<div id="' + id + '" class="fileTree nfs-filetree-dropdown"'
        + ' style="display:none;"></div>'
    );
    var $ft = $('#' + id);

    $ft.fileTree({
        root:          '/mnt',
        top:           '/mnt',
        filter:        'HIDE_FILES_FILTER',
        allowBrowsing: true
    }, function () {
        // file callback — not used; we only care about folder selection
    }, function (folder) {
        // ── CRITICAL: .trigger('change') immediately after .val() ────────────
        // Without this, Unraid's form-state tracker does not register the
        // field change and keeps Apply disabled.
        $input.val(folder.replace(/\/\/+/g, '/')).trigger('change');

        // Auto-populate the name field from the folder basename — only when
        // adding a new export (action=add in the form's POST URL) and only
        // when the name field is still empty.  On Edit the name field already
        // has a value and must NOT be overwritten silently.
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
        // Keep tree open — user may want to drill further down.
    });

    // Position the dropdown flush below the Browse button wrapper.
    $ft.css({
        position: 'absolute',
        left:     $wrapper.position().left,
        top:      $wrapper.position().top + $wrapper.outerHeight(),
        width:    400,
        'z-index': 1000
    });

    // Close on click outside the tree, the input, or the Browse button.
    $(document).on('mouseup.nfsft' + r, function (e) {
        if (
            !$ft.is(e.target)
            && $ft.has(e.target).length === 0
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
 * Client-side validation + AJAX submit for the NFS share form (AC-NFS-12.2).
 *
 * Called via onsubmit="return nfsSubmitForm(this)" on #nfsShareForm.
 * Always returns false to suppress the browser's native form submission.
 *
 * Server-side validateShare() is the authoritative validation gate; the
 * checks here are a fast-fail UX guard only.
 *
 * On server-side validation failure the button is re-enabled so the user can
 * correct the error without losing their form state (AC-NFS-12.2).
 *
 * Partial-success (save OK but exportfs -ra failed): the server returns
 * {success:true, warning:"..."} — we show a warning toast and still redirect
 * to the list so the user can see the export was saved.
 *
 * @param {HTMLFormElement} form
 * @returns {boolean} Always false.
 */
function nfsSubmitForm(form) {
    var $form   = $(form);
    var name    = $.trim($form.find('input[name="name"]').val());
    var path    = $.trim($form.find('input[name="path"]').val());
    var clients = $.trim($form.find('textarea[name="clients"]').val());

    // ── Client-side guards (fast-fail; server is authoritative) ─────────────
    if (!name) {
        swal({ title: 'Error', text: 'Export name is required', type: 'error' });
        return false;
    }
    if (!path) {
        swal({ title: 'Error', text: 'Path is required', type: 'error' });
        return false;
    }
    if (path.indexOf('/mnt/') !== 0) {
        swal({ title: 'Error', text: 'Path must start with /mnt/', type: 'error' });
        return false;
    }
    if (!clients) {
        swal({ title: 'Error', text: 'At least one client is required', type: 'error' });
        return false;
    }

    var $btn = $('#nfs-submit-btn');
    var orig = $btn.data('orig-val') || $btn.val();
    $btn.val('Saving...').prop('disabled', true);

    $.post($form.attr('action'), $form.serialize())
        .done(function (response) {
            if (response.success) {
                if (response.warning) {
                    // Partial success: saved but reload failed.
                    showWarning(response.warning);
                } else {
                    showSuccess(response.message || 'Export saved');
                }
                // Redirect to list on success (AC-NFS-12.2).
                setTimeout(function () { location.href = '/NFSShares'; }, 1200);
            } else {
                // Surface server-side validation errors inline WITHOUT losing
                // form state: re-enable the submit button so the user can fix
                // and resubmit (AC-NFS-12.2).
                swal({
                    title: 'Validation error',
                    text:  response.error || 'Unknown error',
                    type:  'error'
                });
                $btn.val(orig).prop('disabled', false);
            }
        })
        .fail(function (xhr) {
            var msg = 'Request failed';
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.error) { msg = r.error; }
            } catch (e) { /* ignore parse error */ }
            swal({ title: 'Error', text: msg, type: 'error' });
            $btn.val(orig).prop('disabled', false);
        });

    return false; // Always prevent native form submission
}

// ── Page-ready initialisation ──────────────────────────────────────────────
$(function () {
    // Store original submit-button text before any onclick handler can mutate
    // it (mirrors the SMB ShareForm.php pattern).
    $('#nfs-submit-btn').each(function () {
        $(this).data('orig-val', $(this).val());
    });

    // Apply initial warning visibility to match server-rendered select values.
    if ($('#nfs-sync-select').length || $('#nfs-squash-select').length) {
        nfsToggleWarnings();
    }
});
