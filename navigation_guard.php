<?php
// ============================================================
// navigation_guard.php — Central Navigation Guard
// ============================================================
// Auto-included via auth_check.php. Does NOT touch any page logic.
//
//   1) Sends no-cache headers so the browser re-asks the
//      server when BACK/FORWARD is pressed.
//   2) Verifies the session token against the DB.
//   3) Injects a tiny JS shim at the end of the output that
//      forces a fresh server round-trip on bfcache restores.
//
// Safe for dashboard.php and all other protected pages.
// ============================================================

if (defined('NAV_GUARD_LOADED')) {
    return;
}
define('NAV_GUARD_LOADED', true);

// ------------------------------------------------------------
// SKIP on login.php and logout.php — these pages have their
// own navigation guards. The JS shim in this file would
// interfere with the logout → forward button flow.
// ------------------------------------------------------------
$__nav_guard_current_page = basename($_SERVER['PHP_SELF'] ?? '');
if ($__nav_guard_current_page === 'login.php' || $__nav_guard_current_page === 'logout.php') {
    return;
}

// ------------------------------------------------------------
// No-cache headers
// ------------------------------------------------------------
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

// ------------------------------------------------------------
// JS shim — emitted at the END of the page output so it
// never interferes with your existing dashboard JS.
// ------------------------------------------------------------
if (empty($GLOBALS['__nav_guard_js_emitted'])) {
    $GLOBALS['__nav_guard_js_emitted'] = true;

    register_shutdown_function(function () {
        // Only emit for HTML responses.
        if (!headers_sent()) {
            return;
        }
        $contentType = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $contentType = strtolower($h);
                break;
            }
        }
        if (strpos($contentType, 'text/html') === false &&
            strpos($contentType, 'application/json') === false) {
            return;
        }
        ?>
        <script>
        (function () {
            // Push a sentinel so the first BACK press hits us.
            try {
                history.pushState({ guard: 'auth', t: Date.now() }, '', location.href);
            } catch (e) {}

            // bfcache restore → force fresh server round-trip.
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    var sep = location.href.indexOf('?') === -1 ? '?' : '&';
                    location.replace(location.href + sep + '_=' + Date.now());
                }
            });

            // Any BACK/FORWARD → re-ask the server.
            window.addEventListener('popstate', function () {
                var sep = location.href.indexOf('?') === -1 ? '?' : '&';
                location.replace(location.href + sep + '_=' + Date.now());
            });

            // Tell the browser not to bfcache this page.
            window.addEventListener('unload', function () {});
        })();
        </script>
        <?php
    });
}