<?php
// Disable navigation_guard.php on logout page
define('NAV_GUARD_LOADED', true);
require_once __DIR__ . '/config/config.php';

// ============================================================
// 1) BEACON / FETCH HANDLER (auto-logout on tab close)
// ============================================================
$isBeaconRequest = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SERVER['HTTP_X_AUTO_LOGOUT']) && $_SERVER['HTTP_X_AUTO_LOGOUT'] === '1') {
        $isBeaconRequest = true;
    } elseif (isset($_SERVER['CONTENT_TYPE']) && (
        strpos($_SERVER['CONTENT_TYPE'], 'text/plain') !== false ||
        strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false
    )) {
        $isBeaconRequest = true;
    }
}

if ($isBeaconRequest) {
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
            error_log("Auto-logout beacon DB error: " . $e->getMessage());
        }
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }

    http_response_code(200);
    echo 'OK';
    exit();
}

// ============================================================
// 2) NORMAL LOGOUT
// ============================================================

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
        error_log("Logout DB update error: " . $e->getMessage());
    }
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_unset();
    session_destroy();
}

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Logging out...</title>
</head>
<body>
<script>
    // ============================================================
    // SESSIONSTORAGE MARKERS (Forward Button Guard)
    // ============================================================
    // Set the logout flag so the forward-button guard in
    // auth_check.php can force-redirect to login.php if the user
    // presses '>' (forward) after logout.
    try {
        sessionStorage.setItem('tesda_logout_flag', '1');
        sessionStorage.removeItem('tesda_tab_active');
        sessionStorage.removeItem('tesda_just_logged_in');
    } catch (e) {}

    // ============================================================
    // HISTORY REPLACEMENT + HARD REDIRECT
    // ============================================================
    (function () {
        // Replace the current history entry (logout.php) with login.php
        try {
            history.replaceState({ page: 'login' }, '', 'login.php');
        } catch (e) {}

        // Overwrite any forward-history entries with login.php
        var guard = 0;
        while (guard++ < 50) {
            try {
                history.pushState({ page: 'login' }, '', 'login.php');
            } catch (e) {
                break;
            }
        }

        // Hard redirect with cache buster
        window.location.replace('login.php?_=' + Date.now());
    })();
</script>
</body>
</html>