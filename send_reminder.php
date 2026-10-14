<?php
// send_reminder.php - Send Reminder (Email + SMS) — FAST VERSION
// OPTIMIZED: fastcgi_finish_request() + short SMTP timeout + background processing
// OPTIMIZED: SMTP options to skip SSL verification for faster connection
// FIXED: Socket.io trigger (replaces Pusher) — para tumugma sa evaluation.php
// UPDATED: SMS template
// UPDATED: Sends BOTH Email and SMS via PhilSMS
// FIXED: Proper ENUM values for notified_logs

ob_start();
ini_set('display_errors', 0);
error_reporting(0);

// ============================================================
// SESSION — must match login.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/helpers/helpers/PusherHelper.php'; // keep for backward compat
    require_once __DIR__ . '/helpers/PhilSmsHelper.php';

    if (ob_get_length()) ob_clean();

    error_log("[SEND_REMINDER] ===== FAST VERSION (Socket.io) =====");

    // ==========================================
    // 1. Get IDs
    // ==========================================
    $trainer_id = $_SESSION['user_id'] ?? 1;

    $trainee_id = $_POST['trainee_id'] ?? $_GET['trainee_id'] ?? null;
    if (!$trainee_id) {
        echo json_encode(['success' => false, 'message' => 'No valid Trainee ID selected.']);
        exit;
    }

    // ==========================================
    // 2. Fetch Trainee Data
    // ==========================================
    $stmt = $pdo->prepare("SELECT * FROM trainees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $trainee_id]);
    $trainee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainee) {
        echo json_encode(['success' => false, 'message' => "Trainee record not found."]);
        exit;
    }

    $trainee_email = trim($trainee['email'] ?? '');
    $trainee_phone = trim($trainee['contact'] ?? '');

    if (empty($trainee_email) && empty($trainee_phone)) {
        echo json_encode(['success' => false, 'message' => 'Trainee has no email or phone number on record.']);
        exit;
    }

    $full_name = trim(($trainee['first_name'] ?? '') . ' ' . ($trainee['last_name'] ?? ''));

    // ==========================================
    // 3. Dynamic Department & Qualification Detection
    // ==========================================
    $dept_raw = strtoupper(trim($trainee['department'] ?? ''));
    $qualification_id = $trainee['qualification_id'] ?? 0;
    $qualification_name = '';
    $qualification_code = '';

    if ($qualification_id > 0) {
        $qual_stmt = $pdo->prepare("SELECT qualification_name, qualification_code FROM qualifications WHERE id = ?");
        $qual_stmt->execute([$qualification_id]);
        $qual_result = $qual_stmt->fetch(PDO::FETCH_ASSOC);
        if ($qual_result) {
            $qualification_name = $qual_result['qualification_name'];
            $qualification_code = $qual_result['qualification_code'] ?? '';
        }
    }

    if (strpos($dept_raw, 'CSS') !== false) {
        $department_code       = 'CSS';
        $department_full_name  = 'Computer Systems Servicing (CSS)';
        $qualification_display = $qualification_name ?: 'Computer Systems Servicing NC II';
    } elseif (strpos($dept_raw, 'ATS') !== false) {
        $department_code       = 'ATS';
        $department_full_name  = 'Automotive Servicing (ATS)';
        $qualification_display = $qualification_name ?: 'Automotive Servicing NC II';
    } else {
        $department_code       = 'HRS';
        $department_full_name  = 'Hotel & Restaurant Services (HRS)';
        $qualification_display = $qualification_name ?: 'Food and Beverage Services NC II';
    }

    $course_code_for_sms = !empty($qualification_code) ? $qualification_code : $department_code;

    // ==========================================
    // 4. Get Trainer Name
    // ==========================================
    $trainer_name = 'ROY FERNANDEZ';
    try {
        $user_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :uid LIMIT 1");
        $user_stmt->execute([':uid' => $trainer_id]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row && !empty($user_row['full_name'])) {
            $trainer_name = $user_row['full_name'];
        }
    } catch (\Exception $e) {}

    // ==========================================
    // 5. Fetch Pending CORE Tasks Only
    // ==========================================
    $pending_tasks = [];
    $not_complied_task_names = [];
    $first_task_id = 0;
    $first_competency_name = '';

    try {
        $all_task_stmt = $pdo->prepare("
            SELECT 
                et.id as tracker_id,
                et.task_id,
                et.performance_task as task_name,
                et.status,
                et.remarks,
                cs.id as competency_id,
                cs.competency_name,
                cs.unit_of_competency as competency_type
            FROM evaluation_tracker et
            JOIN performance_tasks pt ON et.task_id = pt.id
            JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE et.trainee_id = :id 
              AND LOWER(cs.unit_of_competency) LIKE '%core%'
              AND (et.status != 'complied' OR et.status IS NULL)
              AND (et.remarks != 'Done' OR et.remarks IS NULL)
            ORDER BY cs.id, pt.id
        ");
        $all_task_stmt->execute([':id' => $trainee_id]);
        $pending_tasks = $all_task_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($pending_tasks)) {
            $first_task = $pending_tasks[0];
            $first_task_id = $first_task['task_id'] ?? 0;
            $first_competency_name = $first_task['competency_name'] ?? 'Core Compliance';
        }

        foreach ($pending_tasks as $task) {
            $task_name = $task['task_name'] ?? 'Unnamed Task';
            if (!in_array($task_name, $not_complied_task_names)) {
                $not_complied_task_names[] = $task_name;
            }
        }
    } catch (\Exception $e) {
        error_log("Error fetching pending tasks: " . $e->getMessage());
    }

    if (empty($pending_tasks) && $qualification_id > 0) {
        try {
            $fallback_stmt = $pdo->prepare("
                SELECT 
                    pt.id as task_id,
                    pt.task_name,
                    cs.id as competency_id,
                    cs.competency_name,
                    cs.unit_of_competency as competency_type
                FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE cs.qualification_id = ?
                  AND LOWER(cs.unit_of_competency) LIKE '%core%'
            ");
            $fallback_stmt->execute([$qualification_id]);
            $fallback_tasks = $fallback_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($fallback_tasks)) {
                $first_task_id = $fallback_tasks[0]['task_id'];
                $first_competency_name = $fallback_tasks[0]['competency_name'] ?? 'Core Compliance';

                foreach ($fallback_tasks as $ft) {
                    $pending_tasks[] = [
                        'tracker_id' => 0,
                        'task_id' => $ft['task_id'],
                        'task_name' => $ft['task_name'] ?? 'Complete assigned core task',
                        'competency_name' => $ft['competency_name'] ?? 'Core Competency',
                        'competency_type' => 'CORE'
                    ];
                    $not_complied_task_names[] = $ft['task_name'] ?? 'Complete assigned core task';
                }
            }
        } catch (\Exception $e) {
            error_log("Error fetching fallback tasks: " . $e->getMessage());
        }
    }

    if (empty($pending_tasks)) {
        $first_task_id = 0;
        $first_competency_name = 'Core Compliance';
        $pending_tasks = [[
            'tracker_id' => 0,
            'task_id' => 0,
            'task_name' => 'Complete all assigned core performance tasks',
            'competency_name' => 'Core Compliance',
            'competency_type' => 'CORE'
        ]];
        $not_complied_task_names = ['Complete all assigned core performance tasks'];
    }

    // ==========================================
    // 6. Build SMS Message
    // ==========================================
    if (!function_exists('buildSmsMessage')) {
        function buildSmsMessage($full_name, $course_code, $pending_count = 0) {
            $name_parts = explode(' ', trim($full_name));
            $first_name = $name_parts[0] ?? '';
            $middle_initial = '';

            if (count($name_parts) > 1) {
                $possible_mi = $name_parts[1];
                if (strlen($possible_mi) == 1 || (strlen($possible_mi) == 2 && substr($possible_mi, -1) == '.')) {
                    $middle_initial = rtrim($possible_mi, '.') . '.';
                }
            }

            $greeting_name = $first_name;
            if (!empty($middle_initial)) {
                $greeting_name .= ' ' . $middle_initial;
            }

            return "USAT TESDA: Hi {$greeting_name}, you have a non-compliant task for {$course_code}, please submit to TESDA training center at USAT college Sagay City Inc. within 2-3 days to stay compliant. Thank you!";
        }
    }

    // ==========================================
    // 7. Build Email Template
    // ==========================================
    if (!function_exists('buildEmailTemplate')) {
        function buildEmailTemplate($full_name, $department_code, $qualification_display, $pending_tasks, $trainer_name) {
            $grouped = [];
            foreach ($pending_tasks as $task) {
                $comp_name = trim($task['competency_name'] ?? 'Core Compliance');
                if ($comp_name === '') $comp_name = 'Core Compliance';
                if (!isset($grouped[$comp_name])) $grouped[$comp_name] = [];
                $grouped[$comp_name][] = $task;
            }

            $total_tasks = count($pending_tasks);

            $task_blocks_html = '';
            foreach ($grouped as $comp_name => $tasks) {
                $comp_name_safe = htmlspecialchars($comp_name);
                foreach ($tasks as $task) {
                    $task_name_safe = htmlspecialchars($task['task_name'] ?? 'Unnamed Task');
                    $task_blocks_html .= "
                    <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='margin-bottom:14px;'>
                        <tr>
                            <td style='border:1px solid #eef2f7; border-radius:10px; padding:16px 18px; background-color:#ffffff;'>
                                <div style='font-size:10px; letter-spacing:1px; color:#8b5cf6; font-weight:700; text-transform:uppercase; margin-bottom:4px;'>CORE</div>
                                <div style='font-size:15px; color:#0f172a; font-weight:700; margin-bottom:6px; line-height:1.35;'>{$comp_name_safe}</div>
                                <div style='font-size:13px; color:#475569; line-height:1.55; margin-bottom:10px;'>{$task_name_safe}</div>
                                <span style='color:#dc3545 !important; font-weight:bold; font-size:12px;'>Not Complied</span>
                            </td>
                        </tr>
                    </table>";
                }
            }

            return "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>TESDA Compliance Task Reminder</title>
            </head>
            <body style='margin:0; padding:20px; background-color:#f4f6f9; font-family: Arial, Helvetica, sans-serif; color:#333333; line-height:1.6;'>

                <div style='max-width:600px; margin:0 auto; background:#ffffff; padding:30px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.06);'>

                    <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='border-bottom:3px solid #8b5cf6; padding-bottom:15px; margin-bottom:25px;'>
                        <tr>
                            <td style='vertical-align:middle;'>
                                <div style='font-size:22px; font-weight:700; color:#1e293b; letter-spacing:0.3px;'>TESDA Compliance</div>
                                <div style='font-size:13px; color:#64748b; margin-top:2px;'>Performance Task Tracking System</div>
                            </td>
                            <td style='vertical-align:middle; text-align:right;'>
                                <span style='display:inline-block; background:#8b5cf6; color:#ffffff; padding:5px 16px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; white-space:nowrap;'>Task Reminder</span>
                            </td>
                        </tr>
                    </table>

                    <p style='font-size:16px; color:#1e293b; margin:0 0 16px 0;'>
                        Dear <strong>" . htmlspecialchars($full_name) . "</strong>,
                    </p>

                    <p style='font-size:14px; color:#475569; margin:0 0 20px 0; line-height:1.7;'>
                        This is a formal reminder regarding your pending core performance tasks. Below is the detailed breakdown of competencies and tasks you have <strong>not yet complied</strong> with:
                    </p>

                    <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background-color:#fff5f5; border:1px solid #fecaca; border-left:4px solid #dc3545; border-radius:8px; margin:0 0 22px 0;'>
                        <tr>
                            <td style='padding:14px 18px;'>
                                <p style='margin:0; font-size:13px; color:#991b1b; line-height:1.6; font-weight:600;'>
                                    IMPORTANT: You are required to comply with these pending tasks within 2 to 3 days upon receipt of this notice. Failure to do so will result in a mark of <u>FAILED</u> for the unaccomplished tasks.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background-color:#f8fafc; border-left:4px solid #8b5cf6; border-radius:8px; margin:0 0 22px 0; border:1px solid #e8edf5;'>
                        <tr>
                            <td style='padding:16px 20px;'>
                                <p style='margin:4px 0; font-size:14px; color:#1e293b;'>
                                    <span style='font-weight:600; color:#475569; display:inline-block; min-width:110px;'>Department:</span>
                                    <span style='color:#0f172a; font-weight:500;'>" . htmlspecialchars($department_code) . "</span>
                                </p>
                                <p style='margin:4px 0; font-size:14px; color:#1e293b;'>
                                    <span style='font-weight:600; color:#475569; display:inline-block; min-width:110px;'>Qualification:</span>
                                    <span style='color:#0f172a; font-weight:500;'>" . htmlspecialchars($qualification_display) . "</span>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style='font-size:16px; color:#dc3545; font-weight:700; margin:0 0 15px 0; border-bottom:2px solid #fee2e2; padding-bottom:10px;'>
                        Non-Compliant Core Tasks
                        <span style='background:#dc3545; color:#ffffff; font-size:11px; padding:2px 10px; border-radius:20px; font-weight:700; margin-left:8px;'>{$total_tasks}</span>
                    </div>

                    {$task_blocks_html}

                    <div style='margin-top:30px; padding-top:18px; border-top:2px solid #e8edf5; font-size:13px; color:#64748b; text-align:center;'>
                        <div style='width:40px; height:2px; background:#8b5cf6; margin:0 auto 12px auto; border-radius:2px;'></div>
                        <p style='margin:0;'>
                            Best regards,<br>
                            <span style='color:#1e293b; font-weight:700; font-size:15px; display:block; margin-top:4px;'>" . htmlspecialchars($trainer_name) . "</span>
                            <span style='color:#64748b; font-size:12px; display:block; margin-top:2px;'>Instructor / Trainer — " . htmlspecialchars($qualification_display) . "</span>
                        </p>
                        <p style='font-size:11px; color:#94a3b8; margin-top:10px;'>
                            This is an automated message from the TESDA Compliance System.
                        </p>
                    </div>

                </div>
            </body>
            </html>";
        }
    }

    // Pre-build heavy content BEFORE flushing
    $sms_message = '';
    if (!empty($trainee_phone)) {
        $sms_message = buildSmsMessage($full_name, $course_code_for_sms, count($pending_tasks));
    }

    $message = buildEmailTemplate($full_name, $department_code, $qualification_display, $pending_tasks, $trainer_name);

    // ==========================================
    // ⚡ 8. RETURN RESPONSE IMMEDIATELY TO USER
    // ==========================================
    echo json_encode([
        'success'           => true,
        'message'           => "Sending reminder to {$full_name}...",
        'status'            => 'processing',
        'trainee_id'        => (int)$trainee['id'],
        'trainee_name'      => $full_name,
        'trainee_email'     => $trainee_email,
        'trainee_phone'     => $trainee_phone,
        'department'        => $department_code,
        'qualification_id'  => (int)$qualification_id,
        'qualification_name'=> $qualification_display,
        'pending_tasks'     => count($pending_tasks),
        'queued'            => true,
        'sent_at'           => date('Y-m-d H:i:s'),
        'realtime'          => true,
        'is_notified'       => true
    ]);

    // ✅ Flush response to browser IMMEDIATELY
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    // ==========================================
    // 9. BACKGROUND PROCESSING STARTS HERE
    // ==========================================
    ignore_user_abort(true);
    set_time_limit(120);

    error_log("[SEND_REMINDER][BG] Background processing started for trainee #$trainee_id");

    // ==========================================
    // 10. Send Email (OPTIMIZED SMTP SETTINGS)
    // ✅ Skip SSL verification for faster connection
    // ✅ Reduced timeout from 8 to 5 seconds
    // ==========================================
    $emailSent = false;
    $mailError = null;

    if (!empty($trainee_email)) {
        try {
            $vendor_autoload = __DIR__ . '/vendor/autoload.php';
            if (file_exists($vendor_autoload)) {
                require_once $vendor_autoload;
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = EnvLoader::get('SMTP_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = EnvLoader::get('SMTP_USERNAME', '');
            $mail->Password   = EnvLoader::get('SMTP_PASSWORD', '');
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)EnvLoader::get('SMTP_PORT', 587);

            // ✅ OPTIMIZED: Faster SMTP settings
            $mail->Timeout       = 5;
            $mail->SMTPKeepAlive = false;
            $mail->SMTPDebug     = 0;
            $mail->CharSet       = 'UTF-8';
            $mail->Encoding      = 'base64';

            // ✅ Skip SSL verification for faster connection (safe for Gmail)
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];

            $mail->setFrom(
                EnvLoader::get('SMTP_FROM_EMAIL', ''),
                EnvLoader::get('SMTP_FROM_NAME', 'TESDA Compliance System')
            );
            $mail->addAddress($trainee_email, $full_name);

            $mail->isHTML(true);
            $mail->Subject = "Urgent: Pending Core Compliance Tasks (2-3 Days Deadline) - {$qualification_display}";
            $mail->Body    = $message;

            $mail->send();
            $emailSent = true;
            error_log("[SEND_REMINDER][BG] ✅ Email sent to: $trainee_email");

        } catch (\Exception $e) {
            error_log("[SEND_REMINDER][BG] ❌ Email failed: " . $e->getMessage());
            $mailError = $e->getMessage();
        }
    } else {
        error_log("[SEND_REMINDER][BG] Skipped email — no email address");
    }

    // ==========================================
    // 11. Send SMS via PhilSMS
    // ==========================================
    $smsSent = false;
    $smsError = null;
    $smsResponse = null;

    if (!empty($trainee_phone) && !empty($sms_message)) {
        try {
            error_log("[SEND_REMINDER][BG] Attempting SMS to {$trainee_phone}");

            $smsResult = PhilSmsHelper::sendSMS($trainee_phone, $sms_message);

            if (!empty($smsResult['success'])) {
                $smsSent = true;
                $smsResponse = $smsResult['data'] ?? null;
                error_log("[SEND_REMINDER][BG] SMS sent to: $trainee_phone");
            } else {
                $smsError = $smsResult['error'] ?? 'Unknown SMS error';
                error_log("[SEND_REMINDER][BG] SMS failed: " . $smsError);
            }
        } catch (\Exception $e) {
            $smsError = $e->getMessage();
            error_log("[SEND_REMINDER][BG] SMS exception: " . $e->getMessage());
        }
    } else {
        error_log("[SEND_REMINDER][BG] Skipped SMS — no phone number");
    }

    // ==========================================
    // 12. Determine Notification Channel
    // ==========================================
    if ($emailSent && $smsSent) {
        $channel = 'SMS & Email';
    } elseif ($emailSent) {
        $channel = 'Email';
    } elseif ($smsSent) {
        $channel = 'SMS';
    } else {
        $channel = 'Email';
    }

    $overallSent = ($emailSent || $smsSent);
    $deliveryStatus = $overallSent ? 'Sent' : 'Failed';

    // ==========================================
    // 13. Insert into NOTIFIED_LOGS
    // ==========================================
    $log_count = 0;
    $inserted_log_id = null;

    try {
        $check_dup = $pdo->prepare("
            SELECT id FROM notified_logs 
            WHERE trainee_id = ? 
              AND DATE(date_sent) = CURDATE() 
              AND delivery_status = 'Sent'
              AND (returned_to_evaluation IS NULL OR returned_to_evaluation = 0)
            LIMIT 1
        ");
        $check_dup->execute([$trainee_id]);
        $existing_log = $check_dup->fetch(PDO::FETCH_ASSOC);

        if ($existing_log) {
            $log_count = 0;
            $inserted_log_id = $existing_log['id'];

            $force_hide = $pdo->prepare("
                UPDATE notified_logs 
                SET returned_to_evaluation = 0, returned_at = NULL 
                WHERE trainee_id = ? 
                  AND delivery_status = 'Sent'
            ");
            $force_hide->execute([$trainee_id]);

            error_log("[SEND_REMINDER][BG] Existing log (ID: {$existing_log['id']}). Hide flag refreshed.");
        } else {
            $task_id_to_log = $first_task_id > 0 ? $first_task_id : 0;

            $log_stmt = $pdo->prepare("
                INSERT INTO notified_logs 
                (trainee_id, task_id, notification_channel, delivery_status, sent_by, date_sent, returned_to_evaluation) 
                VALUES (?, ?, ?, ?, ?, NOW(), 0)
            ");
            $log_stmt->execute([
                $trainee_id,
            $task_id_to_log,
                $channel,
                $deliveryStatus,
                $trainer_id
            ]);
            $inserted_log_id = $pdo->lastInsertId();
            $log_count = 1;
            error_log("[SEND_REMINDER][BG] Inserted notified_logs ID: $inserted_log_id");
        }

    } catch (\Exception $db_err) {
        error_log("[SEND_REMINDER][BG] notified_logs error: " . $db_err->getMessage());
    }

    // ==========================================
    // 14. Insert into NOTIFICATION_HISTORY
    // ==========================================
    $history_id = 0;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'notification_history'");
        if ($checkTable->rowCount() > 0) {
            $clean_message = strip_tags($message);
            $clean_message = substr($clean_message, 0, 500);

            $insert_history = $pdo->prepare("
                INSERT INTO notification_history 
                (trainee_id, trainee_name, trainee_email, department, trainer_name, trainer_id, 
                 qualification_id, notification_type, channel, message, status, sent_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'regular_reminder', ?, ?, ?, NOW())
            ");
            $insert_history->execute([
                $trainee_id,
                $full_name,
                $trainee_email ?: '-',
                $department_full_name,
                $trainer_name,
                $trainer_id,
                $qualification_id,
                $channel,
                $clean_message,
                $deliveryStatus
            ]);
            $history_id = $pdo->lastInsertId();
            error_log("[SEND_REMINDER][BG] notification_history ID: $history_id");
        }
    } catch (\Exception $db_err) {
        error_log("[SEND_REMINDER][BG] notification_history error: " . $db_err->getMessage());
    }

    // ==========================================
    // 15. UPDATE evaluation_eligibility
    // ==========================================
    try {
        $check_eligibility = $pdo->prepare("SELECT id FROM evaluation_eligibility WHERE trainee_id = ?");
        $check_eligibility->execute([$trainee_id]);
        if ($check_eligibility->rowCount() > 0) {
            $update_elig = $pdo->prepare("UPDATE evaluation_eligibility SET is_notified = 1, notified_at = NOW() WHERE trainee_id = ?");
            $update_elig->execute([$trainee_id]);
        } else {
            $insert_elig = $pdo->prepare("INSERT INTO evaluation_eligibility (trainee_id, is_notified, notified_at) VALUES (?, 1, NOW())");
            $insert_elig->execute([$trainee_id]);
        }
    } catch (\Exception $db_err) {
        error_log("[SEND_REMINDER][BG] evaluation_eligibility error: " . $db_err->getMessage());
    }

    // ==========================================
    // 16. REAL-TIME SOCKET.IO TRIGGER
    // ✅ CHANGED: Pusher → Socket.io HTTP
    // ✅ Sends 'reminder_sent' event to local Node.js server
    // ==========================================
    try {
        $payload = [
            'trainee_id'        => (int)$trainee['id'],
            'trainee_name'      => $full_name,
            'trainee_email'     => $trainee_email,
            'trainee_phone'     => $trainee_phone,
            'department'        => $department_code,
            'department_full'   => $department_full_name,
            'qualification_id'  => (int)$qualification_id,
            'qualification_name'=> $qualification_display,
            'notification_type' => 'regular_reminder',
            'channel'           => $channel,
            'delivery_status'   => $deliveryStatus,
            'email_sent'        => $emailSent,
            'sms_sent'          => $smsSent,
            'email_error'       => $mailError,
            'sms_error'         => $smsError,
            'pending_tasks'     => count($pending_tasks),
            'pending_task_names'=> $not_complied_task_names,
            'trainer_name'      => $trainer_name,
            'trainer_id'        => (int)$trainer_id,
            'log_count'         => $log_count,
            'log_id'            => $inserted_log_id,
            'history_id'        => $history_id,
            'timestamp'         => date('Y-m-d H:i:s'),
            'is_realtime'       => true,
            'action'            => 'reminder_sent',
            'is_new'            => $log_count > 0
        ];

        // ✅ POST to local Node.js Socket.io server
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'http://localhost:3000/api/trigger',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'event_type' => 'reminder_sent',
                'payload'    => $payload,
                'timestamp'  => date('Y-m-d H:i:s'),
                'source'     => $trainer_id
            ]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            error_log("[SEND_REMINDER][BG] ✅ Socket.io trigger OK: reminder_sent");
        } else {
            error_log("[SEND_REMINDER][BG] ❌ Socket.io trigger HTTP {$httpCode}: " . substr($response, 0, 200) . " | cURL: {$curlErr}");
        }
    } catch (\Exception $e) {
        error_log("[SEND_REMINDER][BG] Socket.io exception: " . $e->getMessage());
    }

    error_log("[SEND_REMINDER][BG] ===== DONE (email=" . ($emailSent ? '1' : '0') . ", sms=" . ($smsSent ? '1' : '0') . ") =====");
    exit;

} catch (\Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log("Send Reminder Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
    exit;
}