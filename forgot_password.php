<?php
// forgot_password.php — Single-file password reset flow
//   Step 1: Choose Email or SMS + enter identifier → send 6-digit code
//   Step 2: Show masked email/phone + verify code
//   Step 3: Set new password
//   Step 4: Success screen

// ============================================================
// SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => false, 'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_name('APP_SESSION_ID');
    session_start();
}
require_once __DIR__ . '/config/config.php';

// ============================================================
// HELPERS
// ============================================================
if (!function_exists('fp_generate_code')) {
    function fp_generate_code(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('fp_mask_email')) {
    function fp_mask_email($email) {
        if (!is_string($email) || strpos($email, '@') === false) return $email;
        list($name, $domain) = explode('@', $email, 2);
        $len = strlen($name);
        if ($len <= 2) {
            $masked = str_repeat('*', max(1, $len));
        } else {
            $masked = $name[0] . str_repeat('*', max(1, $len - 2)) . substr($name, -1);
        }
        return $masked . '@' . $domain;
    }
}

if (!function_exists('fp_mask_phone')) {
    function fp_mask_phone($phone) {
        $digits = preg_replace('/\D/', '', (string)$phone);
        $len = strlen($digits);
        if ($len <= 4) return str_repeat('*', max(1, $len));
        return str_repeat('*', $len - 4) . substr($digits, -4);
    }
}

if (!function_exists('fp_email_template')) {
    function fp_email_template($name, $code) {
        $safeName = htmlspecialchars($name);
        $safeCode = htmlspecialchars($code);
        return "
        <!DOCTYPE html><html><head><meta charset='UTF-8'></head>
        <body style='margin:0;padding:20px;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.6;'>
            <div style='max-width:600px;margin:0 auto;background:#fff;padding:30px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.06);'>
                <table role='presentation' width='100%' style='border-bottom:3px solid #3b5bdb;padding-bottom:15px;margin-bottom:25px;'>
                    <tr><td>
                        <div style='font-size:22px;font-weight:700;color:#1e293b;'>TESDA Compliance</div>
                        <div style='font-size:13px;color:#64748b;margin-top:2px;'>Performance Task Tracking System</div>
                    </td></tr>
                </table>
                <p style='font-size:16px;color:#1e293b;margin:0 0 16px;'>Dear <strong>{$safeName}</strong>,</p>
                <p style='font-size:14px;color:#444;line-height:1.7;'>
                    We received a request to reset your password. Use the 6-digit code below to continue.
                    This code is valid for <strong>10 minutes</strong>.
                </p>
                <div style='text-align:center;margin:30px 0;'>
                    <div style='display:inline-block;background:#eef2ff;color:#1e3a8a;font-size:36px;font-weight:800;letter-spacing:10px;padding:18px 28px;border-radius:12px;border:2px dashed #3b5bdb;'>
                        {$safeCode}
                    </div>
                </div>
                <p style='font-size:13px;color:#94a3b8;margin-top:24px;'>
                    If you did not request this change, you can safely ignore this email.
                </p>
                <p style='font-size:11px;color:#94a3b8;margin-top:24px;text-align:center;'>
                    This is an automated message from the TESDA Compliance System.
                </p>
            </div>
        </body></html>";
    }
}

/**
 * Robust email lookup — direct PDO, compares every user in PHP.
 * Returns the user row or null.
 */
if (!function_exists('fp_find_user_by_email')) {
    function fp_find_user_by_email($pdo, $identifier) {
        $needle = strtolower(trim((string)$identifier));
        if ($needle === '') return null;

        try {
            $stmt = $pdo->query("SELECT id, username, full_name, email, contact_number AS phone FROM users");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $db_email = strtolower(trim((string)$row['email']));
                if ($db_email !== '' && $db_email === $needle) {
                    return $row;
                }
            }

            // Fallback: strip all whitespace (unicode-aware)
            $needle_ws = preg_replace('/\s+/u', '', $needle);
            foreach ($rows as $row) {
                $db_email_ws = preg_replace('/\s+/u', '', strtolower((string)$row['email']));
                if ($db_email_ws !== '' && $db_email_ws === $needle_ws) {
                    return $row;
                }
            }
        } catch (\Throwable $e) {
            error_log("FP find_user_by_email error: " . $e->getMessage());
        }
        return null;
    }
}

/**
 * Robust phone lookup — digit-only comparison in PHP.
 */
if (!function_exists('fp_find_user_by_phone')) {
    function fp_find_user_by_phone($pdo, $identifier) {
        $needle = preg_replace('/\D/', '', (string)$identifier);
        if ($needle === '') return null;

        try {
            $stmt = $pdo->query("SELECT id, username, full_name, email, contact_number AS phone FROM users");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $db_digits = preg_replace('/\D/', '', (string)$row['phone']);
                if ($db_digits !== '' && $db_digits === $needle) {
                    return $row;
                }
            }
        } catch (\Throwable $e) {
            error_log("FP find_user_by_phone error: " . $e->getMessage());
        }
        return null;
    }
}

// ============================================================
// STATE
// ============================================================
$error   = '';
$success = '';

$rp_user_id       = (int)($_SESSION['rp_user_id']      ?? 0);
$rp_user_email    = (string)($_SESSION['rp_user_email'] ?? '');
$rp_user_phone    = (string)($_SESSION['rp_user_phone'] ?? '');
$rp_user_name     = (string)($_SESSION['rp_user_name']  ?? '');
$rp_method        = (string)($_SESSION['rp_method']     ?? '');
$rp_code          = (string)($_SESSION['rp_code']       ?? '');
$rp_code_expires  = (int)($_SESSION['rp_code_expires']  ?? 0);
$rp_verified      = (bool)($_SESSION['rp_verified']     ?? false);

if ($rp_verified) {
    $step = 3;
} elseif ($rp_user_id > 0 && $rp_code !== '') {
    $step = 2;
} else {
    $step = 1;
}

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // --------------------------------------------------------
    // ACTION 1: Send 6-digit code
    // --------------------------------------------------------
    if ($action === 'send_code') {

        $identifier = trim($_POST['identifier'] ?? '');
        $method     = $_POST['method'] ?? 'email';

        if ($identifier === '') {
            $error = ($method === 'sms')
                ? 'Please enter your registered phone number.'
                : 'Please enter your registered email address.';
            $step = 1;
        } else {
            try {
                if ($method === 'sms') {
                    $user = fp_find_user_by_phone($pdo, $identifier);
                } else {
                    $user = fp_find_user_by_email($pdo, $identifier);
                }

                if (!$user) {
                    $error = ($method === 'sms')
                        ? 'This phone number is not registered in the system. Please use the number you registered with.'
                        : 'This email address is not registered in the system. Please use the email you registered with.';
                    $step = 1;
                    error_log("FP: no user found for {$method} identifier=[" . $identifier . "]");
                } else {
                    $code = fp_generate_code();

                    $_SESSION['rp_user_id']      = (int)$user['id'];
                    $_SESSION['rp_user_email']   = (string)($user['email'] ?? '');
                    $_SESSION['rp_user_phone']   = (string)($user['phone'] ?? '');
                    $_SESSION['rp_user_name']    = (string)($user['full_name'] ?? $user['username'] ?? '');
                    $_SESSION['rp_method']       = $method;
                    $_SESSION['rp_code']         = $code;
                    $_SESSION['rp_code_expires'] = time() + 600;
                    $_SESSION['rp_verified']     = false;

                    $sent_ok = false;
                    $dispatch_error = '';

                    if ($method === 'email') {
                        try {
                            $vendor_autoload = __DIR__ . '/vendor/autoload.php';
                            if (file_exists($vendor_autoload)) {
                                require_once $vendor_autoload;
                            }

                            if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                                $mail->isSMTP();
                                $mail->Host       = EnvLoader::get('SMTP_HOST', 'smtp.gmail.com');
                                $mail->SMTPAuth   = true;
                                $mail->Username   = EnvLoader::get('SMTP_USERNAME', '');
                                $mail->Password   = EnvLoader::get('SMTP_PASSWORD', '');
                                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                                $mail->Port       = (int)EnvLoader::get('SMTP_PORT', 587);

                                $mail->setFrom(
                                    EnvLoader::get('SMTP_FROM_EMAIL', ''),
                                    EnvLoader::get('SMTP_FROM_NAME', 'TESDA Compliance System')
                                );
                                $mail->addAddress($user['email'], $user['full_name'] ?? $user['username']);
                                $mail->isHTML(true);
                                $mail->Subject = 'Your Password Reset Code — TESDA Compliance';
                                $mail->Body    = fp_email_template($user['full_name'] ?? $user['username'], $code);
                                $mail->send();
                                $sent_ok = true;
                            } else {
                                $dispatch_error = 'PHPMailer not available.';
                            }
                        } catch (\Throwable $e) {
                            $dispatch_error = $e->getMessage();
                            error_log("FP email error: " . $e->getMessage());
                        }
                    } else {
                        try {
                            if (file_exists(__DIR__ . '/helpers/PhilSmsHelper.php')) {
                                require_once __DIR__ . '/helpers/PhilSmsHelper.php';
                            }
                            if (class_exists('PhilSmsHelper')) {
                                $smsBody = "TESDA: Your password reset code is {$code}. Valid for 10 minutes. Do not share this code.";
                                $smsResult = PhilSmsHelper::sendSMS($user['phone'], $smsBody);
                                if (!empty($smsResult['success'])) {
                                    $sent_ok = true;
                                } else {
                                    $dispatch_error = $smsResult['error'] ?? 'SMS send failed.';
                                }
                            } else {
                                $dispatch_error = 'PhilSmsHelper not available.';
                            }
                        } catch (\Throwable $e) {
                            $dispatch_error = $e->getMessage();
                            error_log("FP SMS error: " . $e->getMessage());
                        }
                    }

                    if ($sent_ok) {
                        $step = 2;
                        $success = ($method === 'sms')
                            ? 'A 6-digit code has been sent to your phone number.'
                            : 'A 6-digit code has been sent to your email address.';
                    } else {
                        unset(
                            $_SESSION['rp_user_id'], $_SESSION['rp_user_email'],
                            $_SESSION['rp_user_phone'], $_SESSION['rp_user_name'],
                            $_SESSION['rp_method'], $_SESSION['rp_code'],
                            $_SESSION['rp_code_expires'], $_SESSION['rp_verified']
                        );
                        $step = 1;
                        error_log("FP dispatch failed: " . $dispatch_error);
                        $error = 'Unable to send the code right now. Please try again.';
                    }
                }
            } catch (\Throwable $e) {
                error_log("FP send_code error: " . $e->getMessage());
                $error = 'An error occurred. Please try again.';
                $step = 1;
            }
        }
    }

    // --------------------------------------------------------
    // ACTION 2: Verify code
    // --------------------------------------------------------
    elseif ($action === 'verify_code') {

        $entered = trim($_POST['code'] ?? '');

        if ($rp_user_id === 0 || $rp_code === '') {
            $error = 'Session expired. Please start again.';
            $step = 1;
            unset($_SESSION['rp_user_id'], $_SESSION['rp_code'], $_SESSION['rp_code_expires']);
        } elseif (time() > $rp_code_expires) {
            $error = 'Your code has expired. Please request a new one.';
            $step = 1;
            unset($_SESSION['rp_user_id'], $_SESSION['rp_code'], $_SESSION['rp_code_expires']);
        } elseif ($entered === '' || $entered !== $rp_code) {
            $error = 'Incorrect code. Please try again.';
            $step = 2;
        } else {
            $_SESSION['rp_verified'] = true;
            $step = 3;
            $success = 'Code verified! Please set your new password.';
        }
    }

    // --------------------------------------------------------
    // ACTION 3: Set new password
    // --------------------------------------------------------
    elseif ($action === 'set_password') {

        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($rp_verified) || $rp_user_id === 0) {
            $error = 'Session expired. Please start again.';
            $step = 1;
        } elseif (strlen($new) < 6) {
            $error = 'Password must be at least 6 characters.';
            $step = 3;
        } elseif ($new !== $confirm) {
            $error = 'Passwords do not match.';
            $step = 3;
        } else {
            try {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, active_session_id = NULL, is_logged_in = 0 WHERE id = ?");
                $stmt->execute([$hash, $rp_user_id]);

                unset(
                    $_SESSION['rp_user_id'], $_SESSION['rp_user_email'],
                    $_SESSION['rp_user_phone'], $_SESSION['rp_user_name'],
                    $_SESSION['rp_method'], $_SESSION['rp_code'],
                    $_SESSION['rp_code_expires'], $_SESSION['rp_verified']
                );

                $step = 4;
                $success = 'Password updated successfully! You can now log in.';
            } catch (\Throwable $e) {
                error_log("FP set_password error: " . $e->getMessage());
                $error = 'Failed to update password. Please try again.';
                $step = 3;
            }
        }
    }

    // --------------------------------------------------------
    // ACTION 4: Restart
    // --------------------------------------------------------
    elseif ($action === 'restart') {
        unset(
            $_SESSION['rp_user_id'], $_SESSION['rp_user_email'],
            $_SESSION['rp_user_phone'], $_SESSION['rp_user_name'],
            $_SESSION['rp_method'], $_SESSION['rp_code'],
            $_SESSION['rp_code_expires'], $_SESSION['rp_verified']
        );
        $step = 1;
    }

    $rp_user_id       = (int)($_SESSION['rp_user_id']      ?? 0);
    $rp_user_email    = (string)($_SESSION['rp_user_email'] ?? '');
    $rp_user_phone    = (string)($_SESSION['rp_user_phone'] ?? '');
    $rp_user_name     = (string)($_SESSION['rp_user_name']  ?? '');
    $rp_method        = (string)($_SESSION['rp_method']     ?? '');
    $rp_code          = (string)($_SESSION['rp_code']       ?? '');
    $rp_code_expires  = (int)($_SESSION['rp_code_expires']  ?? 0);
    $rp_verified      = (bool)($_SESSION['rp_verified']     ?? false);

    if (!in_array($step, [1, 2, 3, 4])) {
        if ($rp_verified) $step = 3;
        elseif ($rp_user_id > 0 && $rp_code !== '') $step = 2;
        else $step = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — TESDA Compliance</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body {
            min-height:100vh; background:#f0f2f5;
            display:flex; align-items:center; justify-content:center; padding:20px;
        }
        .fp-card {
            background:#fff; border-radius:20px; padding:42px 38px;
            width:100%; max-width:460px;
            box-shadow:0 15px 45px rgba(0,0,0,0.08);
        }
        .fp-title { font-size:1.7rem; font-weight:700; color:#1e293b; text-align:center; margin-bottom:8px; }
        .fp-sub   { font-size:0.85rem; color:#94a3b8; text-align:center; margin-bottom:28px; line-height:1.5; }

        .fp-alert {
            font-size:0.82rem; padding:12px 16px; border-radius:10px;
            margin-bottom:20px; border-left:4px solid;
            display:flex; align-items:flex-start; gap:8px;
            word-break:break-word;
        }
        .fp-alert.error   { background:#fee2e2; color:#991b1b; border-left-color:#dc2626; }
        .fp-alert.success { background:#dcfce7; color:#166534; border-left-color:#16a34a; }

        .fp-option {
            display:flex; align-items:center; gap:14px;
            padding:16px 18px; border:2px solid #e2e8f0; border-radius:14px;
            margin-bottom:14px; cursor:pointer; transition:all 0.2s ease;
            background:#fff; position:relative;
        }
        .fp-option:hover { border-color:#c7d2fe; background:#f8fafc; }
        .fp-option.selected { border-color:#3b5bdb; box-shadow:0 0 0 4px rgba(59,91,219,0.08); }
        .fp-option input[type="radio"] { position:absolute; opacity:0; pointer-events:none; }
        .fp-option-icon {
            width:42px; height:42px; border-radius:50%;
            background:#eef2ff; color:#3b5bdb;
            display:flex; align-items:center; justify-content:center;
            font-size:1.05rem; flex-shrink:0;
        }
        .fp-option.selected .fp-option-icon { background:#e0e7ff; }
        .fp-option-content { flex:1; min-width:0; }
        .fp-option-title { font-size:0.92rem; font-weight:700; color:#1e293b; margin-bottom:2px; }
        .fp-option-text  { font-size:0.75rem; color:#94a3b8; line-height:1.4; }
        .fp-option-check {
            width:22px; height:22px; border-radius:50%;
            border:2px solid #cbd5e1;
            display:flex; align-items:center; justify-content:center;
            flex-shrink:0; transition:all 0.2s ease;
            font-size:0.7rem; color:transparent;
        }
        .fp-option.selected .fp-option-check {
            background:#3b5bdb; border-color:#3b5bdb; color:#fff;
        }

        .fp-field { margin-bottom:16px; }
        .fp-field label { display:block; font-size:0.78rem; font-weight:600; color:#334155; margin-bottom:6px; }
        .fp-input { position:relative; }
        .fp-input input {
            width:100%; padding:13px 16px 13px 44px;
            border:2px solid #e2e8f0; border-radius:12px;
            font-size:0.88rem; font-family:'Poppins',sans-serif;
            background:#f8fafc; color:#1e293b; outline:none;
            transition:all 0.2s ease;
        }
        .fp-input input:focus { border-color:#3b5bdb; background:#fff; box-shadow:0 0 0 4px rgba(59,91,219,0.08); }
        .fp-input.with-toggle input { padding-right:44px; }
        .fp-input .fp-input-icon {
            position:absolute; left:16px; top:50%; transform:translateY(-50%);
            color:#94a3b8; font-size:0.88rem; pointer-events:none;
        }
        .fp-toggle {
            position:absolute; right:14px; top:50%; transform:translateY(-50%);
            cursor:pointer; color:#94a3b8;
        }

        .fp-code-input {
            text-align:center;
            font-size:1.6rem !important;
            letter-spacing:12px;
            font-weight:700;
            padding-left:16px !important;
            font-family:'Courier New',monospace;
        }

        .fp-info-box {
            background:#eef2ff; border:1px solid #c7d2fe; border-radius:12px;
            padding:14px 18px; margin-bottom:20px;
            display:flex; align-items:center; gap:12px;
        }
        .fp-info-box .fp-info-icon {
            width:38px; height:38px; border-radius:50%;
            background:#3b5bdb; color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:0.95rem; flex-shrink:0;
        }
        .fp-info-box .fp-info-label {
            font-size:0.72rem; color:#64748b; text-transform:uppercase;
            font-weight:600; letter-spacing:0.4px;
        }
        .fp-info-box .fp-info-value {
            font-size:0.95rem; font-weight:700; color:#1e293b;
            word-break:break-all;
        }

        .fp-submit {
            width:100%; height:50px; background:#3b5bdb; color:#fff;
            border:none; border-radius:12px; font-weight:600; font-size:0.92rem;
            font-family:'Poppins',sans-serif; cursor:pointer;
            transition:all 0.25s ease;
            margin-top:4px;
            display:inline-flex; align-items:center; justify-content:center; gap:8px;
            text-decoration:none;
        }
        .fp-submit:hover:not(:disabled) {
            background:#2f4bc4; transform:translateY(-2px);
            box-shadow:0 8px 20px rgba(59,91,219,0.28);
        }
        .fp-submit:disabled { opacity:0.55; cursor:not-allowed; }

        .fp-back {
            display:inline-flex; align-items:center; gap:6px;
            color:#64748b; font-size:0.8rem; text-decoration:none;
            margin-top:22px;
            background:none; border:none; cursor:pointer; padding:0;
            font-family:'Poppins',sans-serif;
        }
        .fp-back:hover { color:#3b5bdb; }
        .fp-center { text-align:center; }

        @media (max-width:480px) {
            .fp-card { padding:32px 22px; }
            .fp-title { font-size:1.4rem; }
            .fp-code-input { font-size:1.3rem !important; letter-spacing:8px; }
        }
    </style>
</head>
<body>

<div class="fp-card">

<?php if ($step === 1): ?>
    <h1 class="fp-title">Forgot Password</h1>
    <p class="fp-sub">Choose how you want to receive your 6-digit reset code</p>

    <?php if ($error): ?>
        <div class="fp-alert error"><i class="fas fa-exclamation-circle"></i> <span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="fpSendForm">
        <input type="hidden" name="action" value="send_code">
        <input type="hidden" name="method" id="fpMethod" value="email">

        <label class="fp-option selected" id="optEmail">
            <input type="radio" name="_method_radio" value="email" checked>
            <div class="fp-option-icon"><i class="fas fa-envelope"></i></div>
            <div class="fp-option-content">
                <div class="fp-option-title">Reset via Email</div>
                <div class="fp-option-text">A 6-digit code will be sent to your registered email</div>
            </div>
            <div class="fp-option-check"><i class="fas fa-check"></i></div>
        </label>

        <label class="fp-option" id="optSms">
            <input type="radio" name="_method_radio" value="sms">
            <div class="fp-option-icon"><i class="fas fa-phone"></i></div>
            <div class="fp-option-content">
                <div class="fp-option-title">Reset via SMS</div>
                <div class="fp-option-text">A 6-digit code will be sent to your registered phone</div>
            </div>
            <div class="fp-option-check"><i class="fas fa-check"></i></div>
        </label>

        <div class="fp-field">
            <label id="fpIdLabel">Email Address</label>
            <div class="fp-input">
                <span class="fp-input-icon" id="fpIdIcon"><i class="fas fa-envelope"></i></span>
                <input type="text" name="identifier" id="fpIdentifier" placeholder="Enter your registered email" required>
            </div>
        </div>

        <button type="submit" class="fp-submit" id="fpSendBtn">
            <i class="fas fa-paper-plane"></i> Send Code
        </button>
    </form>

<?php elseif ($step === 2): ?>
    <h1 class="fp-title">Enter Reset Code</h1>
    <p class="fp-sub">A 6-digit code has been sent to the contact below.</p>

    <div class="fp-info-box">
        <div class="fp-info-icon">
            <i class="fas <?= $rp_method === 'sms' ? 'fa-phone' : 'fa-envelope' ?>"></i>
        </div>
        <div>
            <div class="fp-info-label">
                <?= $rp_method === 'sms' ? 'Sent to Phone' : 'Sent to Email' ?>
            </div>
            <div class="fp-info-value">
                <?= htmlspecialchars(
                    $rp_method === 'sms'
                        ? fp_mask_phone($rp_user_phone)
                        : fp_mask_email($rp_user_email)
                ) ?>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="fp-alert error"><i class="fas fa-exclamation-circle"></i> <span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="fp-alert success"><i class="fas fa-check-circle"></i> <span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="fpVerifyForm">
        <input type="hidden" name="action" value="verify_code">
        <div class="fp-field">
            <label>6-Digit Code</label>
            <div class="fp-input">
                <input type="text"
                       name="code"
                       id="fpCodeInput"
                       class="fp-code-input"
                       inputmode="numeric"
                       maxlength="6"
                       pattern="[0-9]{6}"
                       placeholder="000000"
                       autocomplete="one-time-code"
                       required>
            </div>
        </div>

        <button type="submit" class="fp-submit" id="fpVerifyBtn">
            <i class="fas fa-check"></i> Verify Code
        </button>
    </form>

    <div class="fp-center" style="margin-top:14px;">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="restart">
            <button type="submit" class="fp-back">
                <i class="fas fa-redo"></i> Resend / Change Method
            </button>
        </form>
    </div>

<?php elseif ($step === 3): ?>
    <h1 class="fp-title">Set New Password</h1>
    <p class="fp-sub">Enter and confirm your new password below</p>

    <?php if ($error): ?>
        <div class="fp-alert error"><i class="fas fa-exclamation-circle"></i> <span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="fp-alert success"><i class="fas fa-check-circle"></i> <span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="fpSetForm">
        <input type="hidden" name="action" value="set_password">

        <div class="fp-field">
            <label>New Password</label>
            <div class="fp-input with-toggle">
                <input type="password" name="new_password" id="fpNp" required minlength="6" placeholder="At least 6 characters">
                <i class="fas fa-eye fp-toggle" onclick="fpTogglePw('fpNp', this)"></i>
            </div>
        </div>

        <div class="fp-field">
            <label>Confirm New Password</label>
            <div class="fp-input with-toggle">
                <input type="password" name="confirm_password" id="fpCp" required minlength="6" placeholder="Re-enter new password">
                <i class="fas fa-eye fp-toggle" onclick="fpTogglePw('fpCp', this)"></i>
            </div>
        </div>

        <button type="submit" class="fp-submit" id="fpSetBtn">
            <i class="fas fa-key"></i> Update Password
        </button>
    </form>

<?php else: ?>
    <h1 class="fp-title">Password Updated</h1>
    <p class="fp-sub">Your password has been changed successfully.</p>

    <?php if ($success): ?>
        <div class="fp-alert success">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>

    <a href="login.php" class="fp-submit">
        <i class="fas fa-sign-in-alt"></i> Go to Login
    </a>
<?php endif; ?>

<?php if ($step !== 4): ?>
    <div class="fp-center">
        <a href="login.php" class="fp-back"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
<?php endif; ?>

</div>

<script>
(function() {
    'use strict';

    var optEmail = document.getElementById('optEmail');
    var optSms   = document.getElementById('optSms');
    var fpMethod = document.getElementById('fpMethod');
    var fpIdLbl  = document.getElementById('fpIdLabel');
    var fpIdIcon = document.getElementById('fpIdIcon');
    var fpIdInp  = document.getElementById('fpIdentifier');
    var sendForm = document.getElementById('fpSendForm');
    var sendBtn  = document.getElementById('fpSendBtn');

    function selectMethod(method) {
        if (!fpMethod) return;
        fpMethod.value = method;
        if (method === 'sms') {
            optSms.classList.add('selected');
            optEmail.classList.remove('selected');
            fpIdLbl.textContent = 'Phone Number';
            fpIdIcon.innerHTML  = '<i class="fas fa-phone"></i>';
            fpIdInp.placeholder = 'Enter your registered phone number';
            fpIdInp.value       = '';
        } else {
            optEmail.classList.add('selected');
            optSms.classList.remove('selected');
            fpIdLbl.textContent = 'Email Address';
            fpIdIcon.innerHTML  = '<i class="fas fa-envelope"></i>';
            fpIdInp.placeholder = 'Enter your registered email';
            fpIdInp.value       = '';
        }
        fpIdInp.focus();
    }

    if (optEmail) optEmail.addEventListener('click', function() { selectMethod('email'); });
    if (optSms)   optSms.addEventListener('click',   function() { selectMethod('sms');   });

    if (sendForm) {
        sendForm.addEventListener('submit', function(e) {
            if (fpIdInp.value.trim() === '') {
                e.preventDefault();
                fpIdInp.focus();
                return false;
            }
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        });
    }

    var codeInput = document.getElementById('fpCodeInput');
    if (codeInput) {
        codeInput.focus();
        codeInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
        var vForm = document.getElementById('fpVerifyForm');
        var vBtn  = document.getElementById('fpVerifyBtn');
        if (vForm) {
            vForm.addEventListener('submit', function(e) {
                if (codeInput.value.length !== 6) {
                    e.preventDefault();
                    return false;
                }
                vBtn.disabled = true;
                vBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            });
        }
    }

    var setForm = document.getElementById('fpSetForm');
    var setBtn  = document.getElementById('fpSetBtn');
    if (setForm) {
        setForm.addEventListener('submit', function() {
            setBtn.disabled = true;
            setBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        });
    }
})();

function fpTogglePw(id, icon) {
    var input = document.getElementById(id);
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
</script>
</body>
</html>