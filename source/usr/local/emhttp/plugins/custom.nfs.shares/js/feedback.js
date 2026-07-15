/**
 * Custom NFS Shares — Toast notification module
 *
 * Ported verbatim from custom.smb.shares/js/feedback.js.
 * Provides showSuccess(), showError(), showWarning() with stacking support.
 */

/**
 * Show a stacking toast notification.
 *
 * @param {string} message  Text to display.
 * @param {string} type     'success' | 'error' | 'warning'
 */
function showNotification(message, type)
{
    var topOffset = 20;
    $('.notification').each(function () {
        topOffset += $(this).outerHeight() + 10;
    });

    var $n = $('<div class="notification notification-' + type + '"></div>');
    $n.text(message);
    $n.css('top', topOffset + 'px');
    $('body').append($n);

    setTimeout(function () { $n.addClass('show'); }, 10);

    setTimeout(function () {
        $n.removeClass('show');
        setTimeout(function () {
            $n.remove();
            repositionNotifications();
        }, 300);
    }, 3000);
}

/**
 * Reposition remaining notifications after one is removed.
 */
function repositionNotifications()
{
    var topOffset = 20;
    $('.notification').each(function () {
        $(this).css('top', topOffset + 'px');
        topOffset += $(this).outerHeight() + 10;
    });
}

/**
 * Show a success toast.
 * @param {string} message
 */
function showSuccess(message) { showNotification(message, 'success'); }

/**
 * Show an error toast.
 * @param {string} message
 */
function showError(message) { showNotification(message, 'error'); }

/**
 * Show a warning toast.
 * @param {string} message
 */
function showWarning(message) { showNotification(message, 'warning'); }
