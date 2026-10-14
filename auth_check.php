<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_name('APP_SESSION_ID');
    session_start();
}

require_once __DIR__ . '/config/config.php';

// ============================================================
// NO-CACHE HEADERS (Forward Button Guard — Server Side)
// ============================================================
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

// ============================================================
// REQUIRE LOGIN
// ============================================================
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ============================================================
// SESSION FINGERPRINT (Anti-Hijacking)
// ============================================================
if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
} else {
    $currentFingerprint = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    if (!hash_equals($_SESSION['fingerprint'], $currentFingerprint)) {
        error_log("Session fingerprint mismatch for user_id=" . ($_SESSION['user_id'] ?? 'guest'));

        try {
            Database::execute(
                "UPDATE users SET is_logged_in = 0, active_session_id = NULL WHERE id = ? AND active_session_id = ?",
                [$_SESSION['user_id'], $_SESSION['active_session_id'] ?? '']
            );
        } catch (\Throwable $e) {
            error_log("Fingerprint logout DB error: " . $e->getMessage());
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: login.php?timeout=1');
        exit;
    }
}

// ============================================================
// SESSION STARTED AT (Absolute Timeout Tracking)
// ============================================================
if (!isset($_SESSION['session_started_at'])) {
    $_SESSION['session_started_at'] = time();
}

// ============================================================
// ABSOLUTE TIMEOUT CHECK (8-hour cap)
// ============================================================
if (!defined('ABSOLUTE_TIMEOUT')) {
    define('ABSOLUTE_TIMEOUT', 28800); // 8 hours
}

$sessionStartedAt = $_SESSION['session_started_at'] ?? 0;
$nowAbs = time();

if ($sessionStartedAt > 0 && ($nowAbs - $sessionStartedAt) > ABSOLUTE_TIMEOUT) {
    try {
        Database::execute(
            "UPDATE users SET is_logged_in = 0, active_session_id = NULL WHERE id = ? AND active_session_id = ?",
            [$_SESSION['user_id'], $_SESSION['active_session_id'] ?? '']
        );
    } catch (\Throwable $e) {
        error_log("Absolute timeout logout DB error: " . $e->getMessage());
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    $isAjaxAbs = (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (
        !empty($_SERVER['HTTP_ACCEPT']) &&
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
    ) || (
        isset($_GET['ajax']) && !empty($_GET['ajax'])
    );

    if ($isAjaxAbs) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'reason' => 'absolute_timeout']);
        exit;
    }

    header('Location: login.php?timeout=1&reason=absolute');
    exit;
}

// ============================================================
// SESSION ID ROTATION (Anti-Fixation)
// ============================================================
if (!defined('SESSION_REGENERATE_INTERVAL')) {
    define('SESSION_REGENERATE_INTERVAL', 900); // 15 minutes
}

if (!isset($_SESSION['last_regenerate'])) {
    $_SESSION['last_regenerate'] = time();
}

$nowRegen = time();
if (($nowRegen - $_SESSION['last_regenerate']) > SESSION_REGENERATE_INTERVAL) {
    if (session_status() === PHP_SESSION_ACTIVE) {

        $__isAjaxForRegen = (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) || (
            !empty($_SERVER['HTTP_ACCEPT']) &&
            strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        ) || (
            isset($_GET['ajax']) && !empty($_GET['ajax'])
        );

        if (!$__isAjaxForRegen) {
            session_regenerate_id(true);
            $_SESSION['last_regenerate'] = $nowRegen;
        }
    }
}

// ============================================================
// LAST-ACTIVITY TIMEOUT (5 minutes)
// ------------------------------------------------------------
// A request counts as "user activity" ONLY if:
//   (a) it is a real HTML page load (not AJAX), or
//   (b) it is a heartbeat.php call that explicitly passed
//       ?active=1 (set by JS only on genuine mouse/keyboard/
//       scroll/touch/click events).
//
// All other background AJAX requests are IGNORED for the idle
// timer, so an idle user will actually time out.
// ============================================================
if (!defined('LAST_ACTIVITY_TIMEOUT')) {
   define('LAST_ACTIVITY_TIMEOUT', 300); // 5 minutes
}

// Detect AJAX request
$__isAjaxRequest = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    !empty($_SERVER['HTTP_ACCEPT']) &&
    strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
) || (
    isset($_GET['ajax']) && !empty($_GET['ajax'])
);

// Detect a heartbeat call that carries ?active=1
$__scriptName        = basename($_SERVER['PHP_SELF'] ?? '');
$__isActiveHeartbeat = ($__scriptName === 'heartbeat.php')
    && isset($_GET['active'])
    && $_GET['active'] === '1';

// Is this request considered real user activity?
$__isRealUserActivity = (!$__isAjaxRequest) || $__isActiveHeartbeat;

// Enforce idle timeout FIRST
$lastActivity = $_SESSION['last_activity'] ?? 0;
$now = time();

if ($lastActivity > 0 && ($now - $lastActivity) > LAST_ACTIVITY_TIMEOUT) {

    if (!empty($_SESSION['user_id'])) {
        try {
            Database::execute(
                "UPDATE users
                    SET is_logged_in = 0,
                        active_session_id = NULL
                  WHERE id = ? AND active_session_id = ?",
                [$_SESSION['user_id'], $_SESSION['active_session_id'] ?? '']
            );
        } catch (\Throwable $e) {
            error_log("Last-activity logout DB error: " . $e->getMessage());
        }
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    if ($__isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'reason' => 'session_expired']);
        exit;
    }

    header('Location: login.php?timeout=1');
    exit;
}

// Refresh the timer ONLY when the request is real user activity
if ($__isRealUserActivity) {
    $_SESSION['last_activity'] = $now;
}

// ============================================================
// VERIFY SESSION TOKEN
// ============================================================
try {
    $__row = Database::fetchOne(
        "SELECT active_session_id FROM users WHERE id = ? LIMIT 1",
        [$_SESSION['user_id']]
    );
    $__dbToken    = $__row['active_session_id'] ?? '';
    $__localToken = $_SESSION['active_session_id'] ?? '';

    if ($__dbToken === '' || $__localToken === '' ||
        !hash_equals($__dbToken, $__localToken)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: login.php');
        exit;
    }
} catch (\Throwable $e) {
    error_log("auth_check token verify error: " . $e->getMessage());
    header('Location: login.php');
    exit;
}

// ============================================================
// CLIENT-SIDE PROTECTION
// ============================================================
if (basename($_SERVER['PHP_SELF']) !== 'login.php' && !$__isAjaxRequest) {
    ?>
    <script>
    (function() {
        'use strict';

        // ============================================================
        // 1. AUTO-LOGOUT ON TAB CLOSE
        // ============================================================
        try {
            var isActive = sessionStorage.getItem('tesda_tab_active');
            var justLoggedIn = sessionStorage.getItem('tesda_just_logged_in');

            if (isActive !== 'true' && justLoggedIn !== '1') {
                try {
                    fetch('logout.php', {
                        method: 'POST',
                        headers: { 'X-Auto-Logout': '1' },
                        credentials: 'same-origin',
                        keepalive: true
                    });
                } catch (e) {}

                window.location.replace('login.php?timeout=1');
                return;
            }

            if (justLoggedIn === '1') {
                sessionStorage.removeItem('tesda_just_logged_in');
                sessionStorage.setItem('tesda_tab_active', 'true');
            }
        } catch (e) {}

        // ============================================================
        // 2. FORWARD BUTTON GUARD (BFCache Detection)
        // ============================================================
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                var sep = location.href.indexOf('?') === -1 ? '?' : '&';
                location.replace(location.href + sep + '_=' + Date.now());
            }
        });

        // ============================================================
        // 3. USER ACTIVITY TRACKER + CLIENT-SIDE IDLE CHECK
        // ------------------------------------------------------------
        // (a) Fires heartbeat only on genuine user events.
        // (b) Auto-logs out after 5 min of no user activity —
        //     no refresh required. Any click/touch after expiry
        //     will immediately redirect to login.php?timeout=1.
        // ============================================================
        (function() {
            var lastSent = 0;
            var SEND_THROTTLE = 30000;
            var IDLE_LIMIT_MS = 300000;
            var lastUserActivity = Date.now();

            var events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'wheel'];

            function sendActivity() {
                var now = Date.now();
                lastUserActivity = now;
                if (now - lastSent < SEND_THROTTLE) return;
                lastSent = now;

                try {
                    fetch('heartbeat.php?active=1', {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).catch(function () {});
                } catch (e) {}
            }

            events.forEach(function(evt) {
                document.addEventListener(evt, sendActivity, { passive: true, capture: true });
            });

            function checkIdle() {
                if (Date.now() - lastUserActivity >= IDLE_LIMIT_MS) {
                    window.location.replace('login.php?timeout=1');
                }
            }

            if (Date.now() - lastUserActivity >= IDLE_LIMIT_MS) {
                window.location.replace('login.php?timeout=1');
                return;
            }

            setInterval(checkIdle, 3000);
        })();

    })();
    </script>
    <?php
}