<?php
// refresh_session.php - Refresh session to prevent timeout
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
    echo json_encode(['success' => true, 'message' => 'Session refreshed']);
} else {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
}
?>