<?php
// heartbeat.php — Updates last_activity ONLY when the user is truly active

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

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Only accept AJAX requests
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$isAjax) {
    http_response_code(400);
    echo json_encode(['success' => false, 'reason' => 'invalid_request']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'reason' => 'not_logged_in']);
    exit;
}

// Optional: verify session token matches DB
try {
    if (class_exists('Database')) {
        $row = Database::fetchOne(
            "SELECT active_session_id FROM users WHERE id = ? LIMIT 1",
            [$_SESSION['user_id']]
        );
        $dbToken    = $row['active_session_id'] ?? '';
        $localToken = $_SESSION['active_session_id'] ?? '';

        if ($dbToken === '' || $localToken === '' || !hash_equals($dbToken, $localToken)) {
            $_SESSION = [];
            session_destroy();
            http_response_code(401);
            echo json_encode(['success' => false, 'reason' => 'invalid_session']);
            exit;
        }
    }
} catch (\Throwable $e) {
    error_log("heartbeat token verify error: " . $e->getMessage());
}

// ============================================================
// Only refresh last_activity when the JS layer sent ?active=1
// ------------------------------------------------------------
// The JS layer sets ?active=1 ONLY on genuine user events
// (mousemove, mousedown, keydown, scroll, touchstart, click, wheel).
// Idle polling / notifications / any other caller will not pass
// ?active=1, and therefore will NOT extend the session.
// ============================================================
$userIsActive = isset($_GET['active']) && $_GET['active'] === '1';

if ($userIsActive) {
    $_SESSION['last_activity'] = time();
}

// Release session lock immediately so other requests aren't blocked
session_write_close();

echo json_encode(['success' => true, 'ts' => time(), 'active' => $userIsActive]);