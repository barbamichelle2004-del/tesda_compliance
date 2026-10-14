/* ============================================================
   auto-logout.js — Auto-logout on tab/browser close ONLY
   ------------------------------------------------------------
   FIX: Removed the beforeunload beacon. That was triggering
   logout on normal navigation (clicking links).

   Auto-logout is now handled entirely by the server-side
   last-activity timeout in auth_check.php + heartbeat.php:
   - While the tab is open, heartbeat runs every 15s.
   - When the tab is closed, heartbeat stops.
   - After 45s, the session expires → force logout on the
     next page load.

   This file only checks whether this is a new tab, so no
   server-side auto-logout triggers on the very first load.
   ============================================================ */

(function () {
    'use strict';

    var SESSION_MARKER = 'tesda_tab_active';

    // ============================================================
    // Detect whether this is a NEW TAB (no marker present)
    // ============================================================
    function checkTabSession() {
        var isActive = null;
        try {
            isActive = sessionStorage.getItem(SESSION_MARKER);
        } catch (e) {
            return; // sessionStorage disabled — skip
        }

        if (isActive === 'true') {
            // Same tab, still active. All good.
            return;
        }

        // Check if this is a fresh login (came from login.php).
        var justLoggedIn = null;
        try {
            justLoggedIn = sessionStorage.getItem('tesda_just_logged_in');
        } catch (e) {}

        if (justLoggedIn === '1') {
            // Fresh login — set the marker and do NOT log out.
            try {
                sessionStorage.setItem(SESSION_MARKER, 'true');
                sessionStorage.removeItem('tesda_just_logged_in');
            } catch (e) {}
            return;
        }

        // No marker and not a fresh login.
        // Just set the marker. Do NOT force-logout here.
        // The server-side auth_check.php handles security.
        try {
            sessionStorage.setItem(SESSION_MARKER, 'true');
        } catch (e) {}
    }

    // ============================================================
    // Init
    // ============================================================
    function init() {
        checkTabSession();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();