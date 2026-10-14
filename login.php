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
// 🚫 NO-CACHE HEADERS
// Prevents browser from caching this page
// ============================================================
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

// ============================================================
// 👤 GUEST GUARD — Skip login if user is already logged in
// Redirects to dashboard if a valid session exists
// ============================================================
if (!empty($_SESSION['user_id'])) {
    try {
        $__row = Database::fetchOne(
            "SELECT active_session_id FROM users WHERE id = ? LIMIT 1",
            [$_SESSION['user_id']]
        );
        $__dbToken    = $__row['active_session_id'] ?? '';
        $__localToken = $_SESSION['active_session_id'] ?? '';

        if ($__dbToken !== '' && $__localToken !== '' &&
            hash_equals($__dbToken, $__localToken)) {
            header("Location: dashboard.php");
            exit();
        } else {
            $_SESSION = [];
        }
    } catch (\Throwable $e) {
        error_log("login guest guard error: " . $e->getMessage());
        $_SESSION = [];
    }
}

// ============================================================
// 🔄 PRG HANDLER — Redirect to dashboard after POST login
// Prevents form resubmission when user refreshes
// ============================================================
if (isset($_GET['redirect']) && $_GET['redirect'] == '1') {
    if (!empty($_SESSION['user_id'])) {
        header('Location: dashboard.php');
        exit();
    }
}

// ============================================================
// 🔒 4-TIER PROGRESSIVE LOCKOUT SETTINGS
// Tier 1: 3 attempts → 5 min lock
// Tier 2: 6 attempts → 10 min lock
// Tier 3: 9 attempts → 15 min lock
// Tier 4: 12 attempts → PERMANENT lock
// ============================================================
const LOCKOUT_TIER_1_ATTEMPTS = 3;
const LOCKOUT_TIER_1_MINUTES  = 5;
const LOCKOUT_TIER_2_ATTEMPTS = 6;
const LOCKOUT_TIER_2_MINUTES  = 10;
const LOCKOUT_TIER_3_ATTEMPTS = 9;
const LOCKOUT_TIER_3_MINUTES  = 15;
const LOCKOUT_TIER_4_ATTEMPTS = 12;

// ============================================================
// 💬 MESSAGE FLAGS
// Handles session timeout messages
// ============================================================
$timeout_message = '';

if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $timeout_message = 'Your session has expired due to inactivity. Please login again.';
} elseif (isset($_SESSION['timeout_message'])) {
    $timeout_message = $_SESSION['timeout_message'];
    unset($_SESSION['timeout_message']);
}

$error = '';

// ============================================================
// 🔒 LOCKOUT UI STATE
// Values used to show lockout banner + countdown
// ============================================================
$is_locked_out = false;
$lockout_remaining_seconds = 0;
$is_permanently_locked = false;

// ============================================================
// ⏱️ format_lockout_remaining()
// Converts seconds to human-readable time (e.g., "5 minutes")
// ============================================================
function format_lockout_remaining(int $seconds): string
{
    if ($seconds <= 0) return '0 seconds';
    $minutes = (int) ceil($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
    $hours = (int) floor($minutes / 60);
    $remMin = $minutes % 60;
    if ($remMin === 0) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ' . $remMin . ' minute' . ($remMin === 1 ? '' : 's');
}

// ============================================================
// 🔑 generate_active_session_id()
// Creates a random 64-char session token (anti-hijack)
// ============================================================
function generate_active_session_id(): string
{
    return bin2hex(random_bytes(32));
}

// ============================================================
// 🔍 PRE-CHECK: Check if the submitted username is already locked
// Used to display the lockout UI before showing login form
// ============================================================
$prefill_username = trim($_POST['username'] ?? '');

if ($prefill_username !== '') {
    try {
        $checkUser = Database::fetchOne(
            "SELECT id, lockout_until, is_locked FROM users WHERE username = ? LIMIT 1",
            [$prefill_username]
        );

        if ($checkUser) {
            // 🚫 Permanent lock check
            if (!empty($checkUser['is_locked']) && $checkUser['is_locked'] == 1) {
                $is_permanently_locked = true;
                $is_locked_out = true;
            }
            // ⏱️ Temporary lockout check
            elseif (!empty($checkUser['lockout_until'])) {
                $lockoutTs = strtotime($checkUser['lockout_until']);
                $now = time();
                if ($lockoutTs > $now) {
                    $is_locked_out = true;
                    $lockout_remaining_seconds = $lockoutTs - $now;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("Lockout pre-check error: " . $e->getMessage());
    }
}

// ============================================================
// 🎯 POST HANDLER — Process login form submission
// Full flow: check locks → verify password → set session → audit log
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Empty fields check
    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        try {
            $user = Database::fetchOne(
                "SELECT * FROM users WHERE username = ?",
                [$username]
            );

            if ($user) {
                // ---------- 1) CHECK PERMANENT LOCK ----------
                if (!empty($user['is_locked']) && $user['is_locked'] == 1) {
                    $error = 'Your account has been permanently locked due to too many failed login attempts. Please contact the System Administrator or Coordinator to unlock your account.';
                    $is_locked_out = true;
                    $is_permanently_locked = true;
                } else {
                    // ---------- 2) CHECK TEMPORARY LOCKOUT ----------
                    $now = new DateTime('now');
                    $lockoutUntil = !empty($user['lockout_until']) ? new DateTime($user['lockout_until']) : null;

                    if ($lockoutUntil && $lockoutUntil > $now) {
                        $remaining = $lockoutUntil->getTimestamp() - $now->getTimestamp();
                        $error = 'Account is locked. Please try again in ' . format_lockout_remaining($remaining) . '.';
                        $is_locked_out = true;
                        $lockout_remaining_seconds = $remaining;
                    } else {
                        // Auto-clear expired lockout
                        if ($lockoutUntil && $lockoutUntil <= $now) {
                            try {
                                Database::execute(
                                    "UPDATE users SET lockout_until = NULL WHERE id = ?",
                                    [$user['id']]
                                );
                            } catch (\Throwable $e) {
                                error_log("Lockout clear error: " . $e->getMessage());
                            }
                            $user['lockout_until'] = null;
                        }

                        // ---------- 3) AUTO-EXPIRE STALE ACTIVE SESSION ----------
                        // If previous login session is old, kill it
                        if (!empty($user['active_session_id'])) {
                            $lastSeenTs = !empty($user['last_login_at'])
                                ? strtotime($user['last_login_at'])
                                : false;

                            $staleCutoff = time() - (int)SESSION_TIMEOUT;

                            if ($lastSeenTs === false || $lastSeenTs < $staleCutoff) {
                                try {
                                    Database::execute(
                                        "UPDATE users
                                            SET is_logged_in = 0,
                                                active_session_id = NULL
                                          WHERE id = ? AND active_session_id = ?",
                                        [$user['id'], $user['active_session_id']]
                                    );
                                } catch (\Throwable $e) {
                                    error_log("Stale session auto-expire error: " . $e->getMessage());
                                }
                                $user['is_logged_in']      = 0;
                                $user['active_session_id'] = null;
                            }
                        }

                        // ---------- 4) VERIFY PASSWORD ----------
                        if (password_verify($password, $user['password'])) {

                            $existingSessionId = $user['active_session_id'] ?? null;

                            // Block login if already logged in elsewhere
                            if (!empty($existingSessionId)) {
                                $error = 'You are not allowed to log in. This account is already logged in on another device or browser. Please ask that user to log out first.';
                            } else {

                                // 🎉 LOGIN SUCCESS — reset all counters
                                try {
                                    Database::execute(
                                        "UPDATE users SET login_attempts = 0,
                                                          lockout_until = NULL,
                                                          lockout_tier = 0,
                                                          is_locked = 0,
                                                          is_logged_in = 1,
                                                          active_session_id = ?,
                                                          last_ip_address = NULL,
                                                          last_login_at = NOW()
                                         WHERE id = ?",
                                        [generate_active_session_id(), $user['id']]
                                    );
                                } catch (\Throwable $e) {
                                    error_log("Reset on success error: " . $e->getMessage());
                                    $error = 'Database error occurred. Please try again.';
                                }

                                if ($error === '') {
                                    // 🔑 Get the new session token we just stored
                                    $fresh = Database::fetchOne(
                                        "SELECT active_session_id FROM users WHERE id = ?",
                                        [$user['id']]
                                    );
                                    $activeToken = $fresh['active_session_id'] ?? '';

                                    // 📚 Load trainer's qualifications (for RBAC)
                                    $qualification_ids = [];
                                    if (strtolower($user['role']) === 'trainer') {
                                        try {
                                            $rows = Database::fetchAll(
                                                "SELECT qualification_id FROM user_qualifications WHERE user_id = ?",
                                                [$user['id']]
                                            );
                                            $qualification_ids = array_column($rows, 'qualification_id');
                                        } catch (\Throwable $e) {
                                            error_log("Qualifications fetch error: " . $e->getMessage());
                                        }
                                    }

                                    // 🍪 Store user data in session
                                    $_SESSION['user_id']            = $user['id'];
                                    $_SESSION['username']           = $user['username'];
                                    $_SESSION['full_name']          = $user['full_name'];
                                    $_SESSION['role']               = $user['role'];
                                    $_SESSION['department']         = $user['department'] ?? null;
                                    $_SESSION['qualification_ids']  = $qualification_ids;
                                    $_SESSION['last_activity']      = time();
                                    $_SESSION['login_time']         = time();
                                    $_SESSION['active_session_id']  = $activeToken;

                                    // 🔐 Session security tracking
                                    $_SESSION['fingerprint']         = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
                                    $_SESSION['session_started_at']  = time();
                                    $_SESSION['last_regenerate']     = time();

                                    // 🔄 Regenerate session ID (prevents session fixation)
                                    // NOTE: We do this BEFORE setting $_SESSION values so the session file
                                    //       is fresh, and we use delete_old_session = false to avoid a race
                                    //       with the subsequent redirect that reads the same session.
                                    if (session_status() === PHP_SESSION_ACTIVE) {
                                        session_regenerate_id(false);
                                    }

                                    // ---------- 📝 AUDIT LOG ----------
                                    try {
                                        $user_qualification = '';
                                        $user_department = $user['department'] ?? 'N/A';

                                        // For trainers, get their primary qualification name
                                        if (strtolower($user['role']) === 'trainer') {
                                            $qual_stmt = Database::fetchOne(
                                                "SELECT q.qualification_name
                                                 FROM qualifications q
                                                 INNER JOIN user_qualifications uq ON uq.qualification_id = q.id
                                                 WHERE uq.user_id = ?
                                                 LIMIT 1",
                                                [$user['id']]
                                            );
                                            if ($qual_stmt && !empty($qual_stmt['qualification_name'])) {
                                                $user_qualification = $qual_stmt['qualification_name'];
                                            }
                                        }

                                        // Insert login record into audit_log
                                        Database::execute(
                                            "INSERT INTO audit_log
                                             (user_id, username, user_role, user_department, user_qualification, action, login_time, ip_address, user_agent, is_deleted)
                                             VALUES (?, ?, ?, ?, ?, 'LOGIN', NOW(), ?, ?, 0)",
                                            [
                                                $user['id'],
                                                $user['username'],
                                                $user['role'],
                                                $user_department,
                                                $user_qualification,
                                                $_SERVER['REMOTE_ADDR'] ?? null,
                                                $_SERVER['HTTP_USER_AGENT'] ?? null
                                            ]
                                        );
                                    } catch (\Throwable $e) {
                                        error_log("Failed to insert audit log: " . $e->getMessage());
                                    }
                                    // ---------- END AUDIT LOG ----------

                                    // 🚀 PRG pattern — redirect to GET version
                                    header('Location: login.php?redirect=1');
                                    exit();
                                }
                            }

                        } else {
                            // ---------- ❌ FAILURE: 4-TIER PROGRESSIVE LOCKOUT ----------
                            $attempts = (int)($user['login_attempts'] ?? 0) + 1;
                            $tier     = (int)($user['lockout_tier'] ?? 0);
                            $lockMinutes = 0;
                            $isLocked = 0;
                            $lockUntilStr = null;

                            // 🔴 Tier 4: PERMANENT LOCK (12+ attempts)
                            if ($attempts >= LOCKOUT_TIER_4_ATTEMPTS) {
                                $tier = 4;
                                $isLocked = 1;
                                $error = 'Your account has been permanently locked due to too many failed login attempts. Please contact the System Administrator or Coordinator to unlock your account.';
                                $is_locked_out = true;
                                $is_permanently_locked = true;

                            // 🟠 Tier 3: 15 minutes lock (9+ attempts)
                            } elseif ($attempts >= LOCKOUT_TIER_3_ATTEMPTS) {
                                $tier = 3;
                                $lockMinutes = LOCKOUT_TIER_3_MINUTES;
                                $lockUntil = new DateTime('now');
                                $lockUntil->modify("+{$lockMinutes} minutes");
                                $lockUntilStr = $lockUntil->format('Y-m-d H:i:s');
                                $error = 'Account is locked. Please try again in ' . format_lockout_remaining($lockMinutes * 60) . '.';
                                $is_locked_out = true;
                                $lockout_remaining_seconds = $lockMinutes * 60;

                            // 🟡 Tier 2: 10 minutes lock (6+ attempts)
                            } elseif ($attempts >= LOCKOUT_TIER_2_ATTEMPTS) {
                                $tier = 2;
                                $lockMinutes = LOCKOUT_TIER_2_MINUTES;
                                $lockUntil = new DateTime('now');
                                $lockUntil->modify("+{$lockMinutes} minutes");
                                $lockUntilStr = $lockUntil->format('Y-m-d H:i:s');
                                $error = 'Account is locked. Please try again in ' . format_lockout_remaining($lockMinutes * 60) . '.';
                                $is_locked_out = true;
                                $lockout_remaining_seconds = $lockMinutes * 60;

                            // 🟢 Tier 1: 5 minutes lock (3+ attempts)
                            } elseif ($attempts >= LOCKOUT_TIER_1_ATTEMPTS) {
                                $tier = 1;
                                $lockMinutes = LOCKOUT_TIER_1_MINUTES;
                                $lockUntil = new DateTime('now');
                                $lockUntil->modify("+{$lockMinutes} minutes");
                                $lockUntilStr = $lockUntil->format('Y-m-d H:i:s');
                                $error = 'Account is locked. Please try again in ' . format_lockout_remaining($lockMinutes * 60) . '.';
                                $is_locked_out = true;
                                $lockout_remaining_seconds = $lockMinutes * 60;

                            // ⚪ Attempts 1-2: warning only
                            } else {
                                $tier = 0;
                                $remaining = LOCKOUT_TIER_1_ATTEMPTS - $attempts;
                                $error = 'Invalid username or password. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining before lockout.';
                            }

                            // 💾 Save failed attempt to database
                            try {
                                $client_ip = $_SERVER['REMOTE_ADDR'] ?? null;

                                Database::execute(
                                    "UPDATE users 
                                     SET login_attempts = ?, 
                                         lockout_until = ?, 
                                         lockout_tier = ?, 
                                         is_locked = ?, 
                                         last_ip_address = ? 
                                     WHERE id = ?",
                                    [$attempts, $lockUntilStr, $tier, $isLocked, $client_ip, $user['id']]
                                );
                            } catch (\Throwable $e) {
                                error_log("Lockout update error: " . $e->getMessage());
                            }
                        }
                    }
                }
            } else {
                // Username doesn't exist — generic error (prevents enumeration)
                $error = 'Invalid username or password.';
            }

        } catch (\Throwable $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'Database error occurred. Please try again.';
        }
    }
}

// ============================================================
// 🎨 UI FLAGS
// ============================================================
$skip_welcome = !empty($error);

$is_strong_error = (strpos($error, 'locked') !== false)
    || (strpos($error, 'another device') !== false)
    || (strpos($error, 'currently logged in') !== false)
    || (strpos($error, 'not allowed') !== false)
    || (strpos($error, 'permanently locked') !== false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>ONLINE TESDA PERFORMANCE TASK TRACKING SYSTEM</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        /* ============================================================ */
        /* 🎨 GLOBAL STYLES                                              */
        /* ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; width: 100%; }
        body { min-height: 100vh; width: 100vw; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; background: #f0f2f5; }
        /* Background image + blur */
        body::before { content: ""; position: fixed; inset: 0; background-image: url('assets/images/background.jpg.png'); background-size: cover; background-position: center; background-repeat: no-repeat; filter: blur(4px) brightness(0.75); z-index: 0; }
        body::after { content: ""; position: fixed; inset: 0; background: rgba(255, 255, 255, 0.12); z-index: 0; }

        /* ============================================================ */
        /* 👋 WELCOME SCREEN                                             */
        /* ============================================================ */
        .welcome-overlay { position: fixed; inset: 0; background: rgba(10, 20, 50, 0.55); backdrop-filter: blur(6px); display: flex; justify-content: center; align-items: center; z-index: 100; transition: opacity 0.6s ease, visibility 0.6s ease; padding: 20px; }
        .welcome-overlay.fade-out { opacity: 0; visibility: hidden; pointer-events: none; }
        .welcome-content { text-align: center; color: #fff; max-width: 800px; width: 100%; }
        .welcome-logos-wrapper { display: flex; justify-content: center; margin-bottom: 30px; }
        .welcome-logo.usat-logo { height: 140px; border-radius: 50%; border: 4px solid rgba(255,255,255,.65); background: #fff; padding: 6px; }
        .welcome-title { font-size: 1.5rem; font-weight: 500; letter-spacing: 4px; margin-bottom: 12px; color: #cbd5e1; text-transform: uppercase; }
        .welcome-system-title { font-size: 2rem; font-weight: 800; line-height: 1.3; margin-bottom: 18px; text-transform: uppercase; }
        .welcome-login-hint { font-size: 1rem; color: #cbd5e1; margin-bottom: 28px; }
        .btn-welcome { background: linear-gradient(135deg, #1a2a6c, #2d4a8a); color: #fff; border: none; padding: 16px 40px; font-size: 1rem; font-weight: 600; border-radius: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 12px; }
        .welcome-school-name { font-size: 1.1rem; color: #e2e8f0; margin-top: 24px; font-style: italic; }

        /* 🎬 Proceed button animation */
        .btn-welcome {
            position: relative;
            overflow: hidden;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                        box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: welcomePulse 2.2s ease-in-out infinite;
        }
        .btn-welcome:hover {
            transform: translateY(-4px) scale(1.04);
            box-shadow: 0 15px 35px rgba(26, 42, 108, 0.5),
                        0 0 0 4px rgba(45, 74, 138, 0.2);
            animation-play-state: paused;
        }
        .btn-welcome:active { transform: translateY(-1px) scale(0.98); }
        .btn-welcome::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 60%;
            height: 100%;
            background: linear-gradient(120deg, transparent 0%, rgba(255,255,255,0.35) 50%, transparent 100%);
            animation: welcomeShine 3s ease-in-out infinite;
            pointer-events: none;
        }
        .btn-welcome i { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-welcome:hover i { transform: translateX(5px); }

        @keyframes welcomePulse {
            0%   { box-shadow: 0 0 0 0 rgba(45, 74, 138, 0.55), 0 6px 18px rgba(0, 0, 0, 0.25); }
            50%  { box-shadow: 0 0 0 14px rgba(45, 74, 138, 0), 0 6px 18px rgba(0, 0, 0, 0.25); }
            100% { box-shadow: 0 0 0 0 rgba(45, 74, 138, 0), 0 6px 18px rgba(0, 0, 0, 0.25); }
        }
        @keyframes welcomeShine {
            0%   { left: -100%; }
            50%  { left: 150%; }
            100% { left: 150%; }
        }

        /* ============================================================ */
        /* 🔑 LOGIN CARD                                                 */
        /* ============================================================ */
        .login-wrapper { position: relative; z-index: 1; width: 100%; max-width: 520px; padding: 20px; display: flex; justify-content: center; align-items: center; transition: opacity 0.6s ease, transform 0.6s ease; }
        .login-wrapper.hidden { opacity: 0; pointer-events: none; transform: scale(0.95); visibility: hidden; position: absolute; }
        .login-card { background: rgba(255,255,255,.92); backdrop-filter: blur(12px); border-radius: 20px; padding: 2.5rem 2rem; box-shadow: 0 15px 35px rgba(0,0,0,.12); width: 100%; border: 1px solid rgba(255,255,255,.3); }

        /* Header with TESDA + USAT logos */
        .header-with-logos { display: flex; align-items: center; justify-content: center; gap: 14px; margin-bottom: 2rem; }
        .left-logo, .right-logo { display: flex; flex-direction: column; align-items: center; gap: 2px; }
        .left-logo img, .right-logo img { height: 70px; object-fit: contain; }
        .right-logo img { border-radius: 50%; border: 2px solid rgba(232,237,245,.7); }
        .left-logo .logo-text, .right-logo .logo-text { font-size: .5rem; font-weight: 700; color: #0f2942; text-transform: uppercase; }
        .divider-line { height: 42px; width: 2px; background: rgba(203,213,225,.6); border-radius: 2px; }
        .header-title { color: #0f2942; font-size: .85rem; font-weight: 700; text-transform: uppercase; text-align: center; }

        /* ============================================================ */
        /* ⚠️ ERROR MESSAGES                                            */
        /* ============================================================ */
        .error-message { background: rgba(254,243,232,.9); color: #a0523a; padding: 10px 14px; border-radius: 12px; margin-bottom: 18px; font-size: .78rem; display: flex; align-items: center; gap: 10px; border-left: 4px solid #d4764a; }
        .error-message.timeout { background: rgba(254,249,232,.9); color: #8a7a3a; border-left-color: #d4b84a; }
        .error-message.locked { background: rgba(254,235,235,.9); color: #9a3a3a; border-left-color: #d44a4a; }

        /* ============================================================ */
        /* 🔒 LOCKOUT BANNER (with countdown)                           */
        /* ============================================================ */
        .lockout-banner {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border: 2px solid #fca5a5;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .lockout-banner .lockout-icon {
            width: 48px; height: 48px; border-radius: 50%;
            background: #ef4444; color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; flex-shrink: 0;
            animation: lockPulse 1.5s ease-in-out infinite;
        }
        @keyframes lockPulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .lockout-banner .lockout-text { flex: 1; }
        .lockout-banner .lockout-title { font-size: 1rem; font-weight: 700; color: #991b1b; margin-bottom: 4px; }
        .lockout-banner .lockout-countdown {
            font-size: 1.5rem; font-weight: 800; color: #dc2626;
            font-family: 'Courier New', monospace;
            letter-spacing: 2px; line-height: 1.2;
        }
        .lockout-banner .lockout-subtext { font-size: 0.75rem; color: #991b1b; margin-top: 4px; }
        .lockout-banner.permanent { background: linear-gradient(135deg, #fef2f2, #fecaca); border-color: #dc2626; }
        .lockout-banner.permanent .lockout-countdown { font-size: 1rem; color: #991b1b; letter-spacing: 0; }

        /* ============================================================ */
        /* 📝 FORM INPUTS                                                */
        /* ============================================================ */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; color: #1a2a3a; font-weight: 600; font-size: .78rem; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper .input-icon { position: absolute; left: 16px; color: #6a7a8a; pointer-events: none; }
        .input-wrapper input { width: 100%; padding: 14px 16px 14px 48px; border: 2px solid rgba(200,210,225,.5); border-radius: 14px; font-size: .85rem; background: rgba(250,252,255,.7); color: #1a2a3a; outline: none; }
        .input-wrapper input:focus { border-color: #1a2a6c; box-shadow: 0 0 0 4px rgba(26,42,108,.08); background: #fff; }
        .input-wrapper input:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
        .toggle-password { position: absolute; right: 16px; cursor: pointer; color: #8aa0b8; }

        /* ============================================================ */
        /* 🔓 LOGIN BUTTON                                               */
        /* ============================================================ */
        .btn-login { width: 100%; height: 48px; background: linear-gradient(135deg, #1a2a6c, #2d4a8a); color: #fff; border: none; border-radius: 12px; font-size: .9rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s ease; }
        .btn-login:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(26, 42, 108, 0.3); }
        .btn-login:disabled { opacity: .5; cursor: not-allowed; background: #94a3b8; transform: none; box-shadow: none; }

        /* ============================================================ */
        /* 🔑 FORGOT PASSWORD LINK                                       */
        /* ============================================================ */
        .forgot-password-wrapper { text-align: center; margin-top: 28px; }
        .forgot-password-link { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 500; color: #2d4a8a; text-decoration: none; transition: color 0.2s ease, gap 0.2s ease; }
        .forgot-password-link i { font-size: 0.75rem; }
        .forgot-password-link:hover { color: #1a2a6c; gap: 9px; text-decoration: underline; }

        /* ============================================================ */
        /* 📌 FOOTER                                                     */
        /* ============================================================ */
        .footer { text-align: center; margin-top: 22px; color: #8a9aac; font-size: .6rem; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .footer .line { flex: 1; max-width: 25px; height: 1px; background: linear-gradient(to right, transparent, rgba(192,208,224,.6), transparent); }
    </style>
    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
</head>
<body>

<!-- ============================================================ -->
<!-- 👋 WELCOME SCREEN (shown first, hides on click)               -->
<!-- ============================================================ -->
<div id="welcome-screen" class="welcome-overlay <?= $skip_welcome ? 'fade-out' : '' ?>">
    <div class="welcome-content">
        <div class="welcome-logos-wrapper">
            <img src="/tesda_compliance/assets/images/love.png" alt="USAT Logo" class="welcome-logo usat-logo">
        </div>
        <h1 class="welcome-title">WELCOME TO</h1>
        <h2 class="welcome-system-title">ONLINE TESDA PERFORMANCE<br>TASK COMPLIANCE TRACKING SYSTEM</h2>
        <p class="welcome-login-hint">To keep connected with us please login<br>with your personal info</p>
        <button id="btn-proceed" class="btn-welcome">Proceed to Login <i class="fas fa-arrow-right"></i></button>
        <p class="welcome-school-name">USAT College Sagay City, Inc.</p>
    </div>
</div>

<!-- ============================================================ -->
<!-- 🔑 LOGIN CARD (hidden initially, shows after Proceed click)   -->
<!-- ============================================================ -->
<div id="login-card-container" class="login-wrapper <?= $skip_welcome ? '' : 'hidden' ?>">
    <div class="login-card">
        <div class="header-with-logos">
            <div class="left-logo">
                <img src="/tesda_compliance/assets/images/Tesda-Logo.png" alt="TESDA Logo">
                <span class="logo-text">TESDA</span>
            </div>
            <div class="divider-line"></div>
            <div class="header-center">
                <div class="header-title">ONLINE TESDA PERFORMANCE<br>TASK TRACKING SYSTEM</div>
            </div>
            <div class="divider-line"></div>
            <div class="right-logo">
                <img src="/tesda_compliance/assets/images/love.png" alt="USAT College">
                <span class="logo-text">USAT</span>
            </div>
        </div>

        <!-- ⏱️ Session timeout message -->
        <?php if ($timeout_message): ?>
            <div class="error-message timeout"><i class="fas fa-clock"></i> <?= htmlspecialchars($timeout_message) ?></div>
        <?php endif; ?>

        <!-- ⚠️ Error message (not lockout) -->
        <?php if ($error && !$is_locked_out): ?>
            <div class="error-message <?= $is_strong_error ? 'locked' : '' ?>">
                <i class="fas <?= $is_strong_error ? 'fa-lock' : 'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- 🔒 LOCKOUT BANNER — Shows countdown timer     -->
        <!-- ============================================ -->
        <?php if ($is_locked_out): ?>
            <div class="lockout-banner <?= $is_permanently_locked ? 'permanent' : '' ?>">
                <div class="lockout-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <div class="lockout-text">
                    <?php if ($is_permanently_locked): ?>
                        <div class="lockout-title">Account Permanently Locked</div>
                        <div class="lockout-countdown">Contact Administrator</div>
                        <div class="lockout-subtext">Your account has been locked due to too many failed login attempts. Please contact the System Administrator or Coordinator to unlock your account.</div>
                    <?php else: ?>
                        <div class="lockout-title">Account Temporarily Locked</div>
                        <div class="lockout-countdown" id="countdownTimer">--:--</div>
                        <div class="lockout-subtext">Please wait for the countdown to finish before trying again.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- 📝 LOGIN FORM                                  -->
        <!-- ============================================ -->
        <form method="POST" action="" id="loginForm" autocomplete="off">
            <div class="form-group">
                <label>Username</label>
                <div class="input-wrapper">
                    <input type="text" name="username" id="usernameInput" placeholder="Enter your username"
                           value="<?= htmlspecialchars($prefill_username) ?>"
                           <?= $is_locked_out ? 'disabled' : '' ?> required>
                    <span class="input-icon"><i class="fas fa-user"></i></span>
                </div>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password" placeholder="Enter your password"
                           <?= $is_locked_out ? 'disabled' : '' ?> required>
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <i class="fas fa-eye toggle-password" id="togglePassword" onclick="togglePassword()"></i>
                </div>
            </div>

            <button type="submit" class="btn-login" id="loginBtn" <?= $is_locked_out ? 'disabled' : '' ?>>
                <i class="fas <?= $is_locked_out ? 'fa-lock' : 'fa-arrow-right' ?>"></i>
                <?= $is_locked_out ? 'Login Disabled' : 'Login' ?>
            </button>

            <!-- 🔑 Forgot password link -->
            <div class="forgot-password-wrapper">
                <a href="forgot_password.php" class="forgot-password-link">
                    <i class="fas fa-key"></i>
                    Forgot password?
                </a>
            </div>
        </form>

        <div class="footer">
            <span class="line"></span>
            <p>&copy; <?= date('Y') ?> TESDA. All rights reserved.</p>
            <span class="line"></span>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // 👁️ togglePassword() — Show/hide password input
    // ============================================================
    function togglePassword() {
        const input = document.getElementById('password');
        const icon = document.getElementById('togglePassword');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // ============================================================
    // 👋 WELCOME SCREEN — Hide welcome, show login card
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        const welcomeScreen = document.getElementById('welcome-screen');
        const loginCardContainer = document.getElementById('login-card-container');
        const btnProceed = document.getElementById('btn-proceed');
        if (btnProceed) {
            btnProceed.addEventListener('click', function() {
                welcomeScreen.classList.add('fade-out');
                loginCardContainer.classList.remove('hidden');
                setTimeout(function() {
                    const f = loginCardContainer.querySelector('input[name="username"]');
                    if (f && !f.disabled) f.focus();
                }, 650);
            });
        }
    });

    // ============================================================
    // 🚀 LOGIN FORM SUBMIT
    // Disable button + set tab-active markers
    // ============================================================
    const loginFormEl = document.getElementById('loginForm');
    if (loginFormEl) {
        loginFormEl.addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            if (btn && btn.disabled) {
                e.preventDefault();
                return false;
            }
            if (btn) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
                btn.disabled = true;
            }

            // ============================================================
            // 🔓 AUTO-LOGOUT ON TAB CLOSE — Set sessionStorage markers
            // ============================================================
            try {
                sessionStorage.setItem('tesda_tab_active', 'true');
                sessionStorage.setItem('tesda_just_logged_in', '1');
                sessionStorage.removeItem('tesda_logout_flag');
            } catch (e) {}
        });
    }

    // ============================================================
    // 🧭 FORWARD/BACK BUTTON GUARD — BFCache Safety
    // ============================================================
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            var sep = location.href.indexOf('?') === -1 ? '?' : '&';
            window.location.replace(location.href + sep + '_=' + Date.now());
        }
    });

    // ============================================================
    // ⏱️ LIVE COUNTDOWN TIMER (only when temporarily locked)
    // ============================================================
    <?php if ($is_locked_out && !$is_permanently_locked && $lockout_remaining_seconds > 0): ?>
    (function() {
        var remainingSeconds = <?= (int)$lockout_remaining_seconds ?>;
        var timerEl = document.getElementById('countdownTimer');
        var usernameInput = document.getElementById('usernameInput');
        var passwordInput = document.getElementById('password');
        var loginBtn = document.getElementById('loginBtn');

        function updateTimer() {
            // ⏰ Lockout expired → re-enable form
            if (remainingSeconds <= 0) {
                timerEl.textContent = '00:00';

                var banner = document.querySelector('.lockout-banner');
                if (banner) {
                    banner.style.transition = 'opacity 0.5s ease';
                    banner.style.opacity = '0';
                    setTimeout(function() { banner.remove(); }, 500);
                }

                if (usernameInput) usernameInput.disabled = false;
                if (passwordInput) passwordInput.disabled = false;
                if (loginBtn) {
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Login';
                }
                return;
            }

            // 🕐 Format as MM:SS
            var minutes = Math.floor(remainingSeconds / 60);
            var seconds = remainingSeconds % 60;
            timerEl.textContent =
                String(minutes).padStart(2, '0') + ':' +
                String(seconds).padStart(2, '0');

            remainingSeconds--;
        }

        updateTimer();
        setInterval(updateTimer, 1000);
    })();
    <?php endif; ?>
</script>

<script src="/tesda_compliance/assets/js/seamless-nav.js"></script>

<script data-no-instant>
  // InstantClick.init(); // DISABLED
</script>
<script src="/tesda_compliance/assets/js/jquery.min.js"></script>
<script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
<script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>