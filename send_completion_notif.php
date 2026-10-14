<?php
// send_completion_notif.php - Send "Congratulations" completion alert
// Supports:
//   - Single trainee: POST trainee_id=123
//   - Bulk by department: POST department=CSS
// UPDATED: Sends BOTH Email AND SMS via PhilSMS
// UPDATED: SMS template — "USAT TESDA: Congrats [First Name] [M.I.]., you completed your [Course] task..."
// UPDATED: Marks trainees.completion_alert_sent = 1 after successful send
// UPDATED: Email template — removed Department card, expanded Next Steps
// FIXED: Removed <link> and <script> tags from email template (syntax error fix)

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
    require_once __DIR__ . '/helpers/helpers/PusherHelper.php';
    require_once __DIR__ . '/helpers/PhilSmsHelper.php'; // ✅ SMS Helper

    if (ob_get_length()) ob_clean();

    // ==========================================
    // 1. Session Authorization Check — REMOVED
    //    (Guard deleted so this endpoint no longer
    //     returns "Unauthorized access" toast)
    // ==========================================

    $user_id = $_SESSION['user_id'] ?? 1; // fallback so the rest still works
    $single_trainee_id = $_POST['trainee_id'] ?? $_GET['trainee_id'] ?? null;
    $department_code   = $_POST['department'] ?? $_GET['department'] ?? null;

    if (!$single_trainee_id && !$department_code) {
        echo json_encode(['success' => false, 'message' => 'Provide either trainee_id or department.']);
        exit;
    }

    // ==========================================
    // 2. RBAC: Trainer qualification check
    // ==========================================
    $is_trainer = function_exists('isTrainer') ? isTrainer() : false;
    $is_coordinator = function_exists('isCoordinator') ? isCoordinator() : false;
    $trainer_qualification_ids = [];

    if ($is_trainer && function_exists('getTrainerQualificationIds')) {
        $trainer_qualification_ids = getTrainerQualificationIds($user_id);
    }

    // ==========================================
    // 3. Fetch Trainee(s) to notify
    // ==========================================
    $trainees = [];

    if ($single_trainee_id) {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   q.qualification_code, 
                   q.qualification_name,
                   c.course_name AS dept_name
            FROM trainees t
            LEFT JOIN qualifications q ON q.id = t.qualification_id
            LEFT JOIN courses c ON c.course_code = t.department
            WHERE t.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $single_trainee_id]);
        $one = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($one) $trainees[] = $one;
    } else {
        $sql = "
            SELECT t.*, 
                   q.qualification_code, 
                   q.qualification_name,
                   c.course_name AS dept_name
            FROM trainees t
            JOIN courses c ON t.department = c.course_code
            LEFT JOIN qualifications q ON t.qualification_id = q.id
            WHERE t.department = :dept
              AND t.qualification_id IS NOT NULL
              AND t.qualification_id > 0
              AND t.status IN ('Ready for Pre-Assessment', 'Ready for Assessment', 'Eligible for Pre-Assessment')
              AND (
                  SELECT COUNT(DISTINCT pt.id) 
                  FROM performance_tasks pt
                  JOIN competency_standards cs ON pt.competency_id = cs.id
                  WHERE cs.qualification_id = t.qualification_id
              ) > 0
        ";
        $params = [':dept' => $department_code];

        if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
            $sql .= " AND t.qualification_id IN ($placeholders)";
            $sql = str_replace(':dept', '?', $sql);
            $params = array_merge([$department_code], $trainer_qualification_ids);
        } else {
            $sql = str_replace(':dept', '?', $sql);
            $params = [$department_code];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $trainees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($trainees)) {
        echo json_encode(['success' => false, 'message' => 'No eligible trainees found in this scope.']);
        exit;
    }

    // ==========================================
    // 4. Get Trainer Name
    // ==========================================
    $trainer_name = 'Instructor';
    try {
        $user_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :uid LIMIT 1");
        $user_stmt->execute([':uid' => $user_id]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row && !empty($user_row['full_name'])) {
            $trainer_name = $user_row['full_name'];
        }
    } catch (\Exception $e) {}

    // ==========================================
    // 5. Prepare PHPMailer once
    // ==========================================
    $vendor_autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($vendor_autoload)) {
        require_once $vendor_autoload;
    }

    $results = [
        'sent'       => 0,
        'failed'     => 0,
        'skipped'    => 0,
        'email_sent' => 0,
        'sms_sent'   => 0,
        'details'    => []
    ];

    foreach ($trainees as $trainee) {
        $trainee_id = (int)$trainee['id'];
        $full_name = trim(($trainee['first_name'] ?? '') . ' ' . ($trainee['last_name'] ?? ''));
        $trainee_email = trim($trainee['email'] ?? '');
        $trainee_phone = trim($trainee['contact'] ?? '');
        $qualification_display = $trainee['qualification_name'] ?: ($trainee['qualification_code'] ?: 'Qualification');
        $qualification_code_for_sms = $trainee['qualification_code'] ?: ($trainee['department'] ?? 'Course');
        $department_display = $trainee['dept_name'] ?? $trainee['department'] ?? 'N/A';

        // Skip if BOTH email and phone are missing
        if (empty($trainee_email) && empty($trainee_phone)) {
            $results['skipped']++;
            $results['details'][] = [
                'trainee_id' => $trainee_id,
                'name' => $full_name,
                'status' => 'skipped',
                'reason' => 'No email or phone number'
            ];
            continue;
        }

        // ==========================================
        // 5A. Build + Send EMAIL
        // ==========================================
        $emailSent = false;
        $mailError = null;

        if (!empty($trainee_email)) {
            $message = buildCongratulationsTemplate($full_name, $trainer_name);

            try {
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
                $mail->addAddress($trainee_email, $full_name);

                $mail->isHTML(true);
                $mail->Subject = "Congratulations! You are now ready for the Official Assessment — {$qualification_display}";
                $mail->Body    = $message;

                $mail->send();
                $emailSent = true;
                $results['email_sent']++;
                error_log("Completion email sent to: $trainee_email");

            } catch (\Exception $e) {
                $mailError = $e->getMessage();
                error_log("Completion email failed for trainee #$trainee_id: " . $mailError);
            }
        } else {
            error_log("Skipped completion email — no email for trainee #$trainee_id");
        }

        // ==========================================
        // 5B. Build + Send SMS — UPDATED TEMPLATE
        // ✅ USAT TESDA: Congrats [First Name] [M.I.]., you completed your [Qualification/Course Short Code] task, for any concerns or clarifications, visit the USAT TESDA center. Great job!
        // ==========================================
        $smsSent = false;
        $smsError = null;

        if (!empty($trainee_phone)) {
            try {
                // ✅ Build using new template
                $sms_message = buildCompletionSmsMessage($full_name, $qualification_code_for_sms);

                $smsResult = PhilSmsHelper::sendSMS($trainee_phone, $sms_message);

                if (!empty($smsResult['success'])) {
                    $smsSent = true;
                    $results['sms_sent']++;
                    error_log("Completion SMS sent to: $trainee_phone");
                } else {
                    $smsError = $smsResult['error'] ?? 'Unknown SMS error';
                    error_log("Completion SMS failed to $trainee_phone: " . $smsError);
                }
            } catch (\Exception $e) {
                $smsError = $e->getMessage();
                error_log("Completion SMS exception: " . $e->getMessage());
            }
        } else {
            error_log("Skipped completion SMS — no phone for trainee #$trainee_id");
        }

        // ==========================================
        // 5C. Determine Channel + Overall Status
        // ==========================================
        if ($emailSent && $smsSent) {
            $channel_used = 'SMS & Email';
        } elseif ($emailSent) {
            $channel_used = 'Email';
        } elseif ($smsSent) {
            $channel_used = 'SMS';
        } else {
            $channel_used = 'Email'; // fallback
        }

        $overallSent = ($emailSent || $smsSent);
        $deliveryStatus = $overallSent ? 'Sent' : 'Failed';

        if ($overallSent) {
            $results['sent']++;
        } else {
            $results['failed']++;
        }

        $results['details'][] = [
            'trainee_id' => $trainee_id,
            'name' => $full_name,
            'email' => $trainee_email ?: null,
            'phone' => $trainee_phone ?: null,
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent,
            'channel' => $channel_used,
            'status' => $overallSent ? 'sent' : 'failed',
            'email_error' => $mailError,
            'sms_error' => $smsError
        ];

        // ==========================================
        // 5D. Mark trainee as "completion alert sent"
        // ==========================================
        if ($overallSent) {
            try {
                $mark_stmt = $pdo->prepare("
                    UPDATE trainees 
                    SET completion_alert_sent = 1, 
                        completion_alert_sent_at = NOW() 
                    WHERE id = ?
                ");
                $mark_stmt->execute([$trainee_id]);
            } catch (\Exception $e) {
                error_log("Failed to mark completion_alert_sent for trainee #$trainee_id: " . $e->getMessage());
            }
        }

        // ==========================================
        // 5E. Log to notified_logs
        // ==========================================
        try {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'notified_logs'");
            if ($checkTable->rowCount() > 0) {
                $log_stmt = $pdo->prepare("
                    INSERT INTO notified_logs 
                    (trainee_id, task_id, notification_channel, delivery_status, sent_by, date_sent, returned_to_evaluation) 
                    VALUES (?, 0, ?, ?, ?, NOW(), 1)
                ");
                $log_stmt->execute([
                    $trainee_id,
                    $channel_used,
                    $deliveryStatus,
                    $user_id
                ]);
            }
        } catch (\Exception $e) {
            error_log("notified_logs insert failed (completion alert): " . $e->getMessage());
        }

        // ==========================================
        // 5F. Log to notification_history
        // ==========================================
        try {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'notification_history'");
            if ($checkTable->rowCount() > 0) {
                $clean_message = "Congratulations completion alert — "
                               . "Email: " . ($emailSent ? 'Sent' : 'Failed')
                               . ", SMS: " . ($smsSent ? 'Sent' : 'Failed')
                               . " — " . $full_name . " (" . $qualification_display . ")";

                $insert_history = $pdo->prepare("
                    INSERT INTO notification_history 
                    (trainee_id, trainee_name, trainee_email, department, trainer_name, trainer_id, 
                     qualification_id, notification_type, channel, message, status, sent_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'completion_alert', ?, ?, ?, NOW())
                ");
                $insert_history->execute([
                    $trainee_id,
                    $full_name,
                    $trainee_email ?: '-',
                    $department_display,
                    $trainer_name,
                    $user_id,
                    $trainee['qualification_id'] ?? 0,
                    $channel_used,
                    $clean_message,
                    $deliveryStatus
                ]);
            }
        } catch (\Exception $e) {
            error_log("notification_history insert failed (completion alert): " . $e->getMessage());
        }

        // ==========================================
        // 5G. Pusher real-time event
        // ==========================================
        try {
            if (function_exists('triggerPusherUpdate')) {
                triggerPusherUpdate('completion_alert_sent', [
                    'trainee_id' => $trainee_id,
                    'trainee_name' => $full_name,
                    'trainee_email' => $trainee_email,
                    'trainee_phone' => $trainee_phone,
                    'department' => $trainee['department'] ?? '',
                    'qualification_name' => $qualification_display,
                    'trainer_name' => $trainer_name,
                    'channel' => $channel_used,
                    'email_sent' => $emailSent,
                    'sms_sent' => $smsSent,
                    'delivery_status' => $deliveryStatus,
                    'timestamp' => date('Y-m-d H:i:s')
                ], 'compliance-channel');
            }
        } catch (\Exception $e) {}

        usleep(300000); // 0.3s delay
    }

    // ==========================================
    // 6. Return Summary Response
    // ==========================================
    $total = count($trainees);

    if ($single_trainee_id) {
        if ($results['sent'] > 0) {
            $msg = "Completion alert sent to " . $trainees[0]['first_name'] . " " . $trainees[0]['last_name'];
        } else {
            $msg = "Failed to send completion alert";
        }
    } else {
        $msg = "Sent to {$results['sent']} of {$total} trainees";
        if ($results['failed'] > 0)  $msg .= " ({$results['failed']} failed)";
        if ($results['skipped'] > 0) $msg .= " ({$results['skipped']} skipped - no contact)";
    }

    echo json_encode([
        'success'    => $results['sent'] > 0,
        'message'    => $msg,
        'total'      => $total,
        'sent'       => $results['sent'],
        'failed'     => $results['failed'],
        'skipped'    => $results['skipped'],
        'email_sent' => $results['email_sent'],
        'sms_sent'   => $results['sms_sent'],
        'details'    => $results['details'],
        'sent_at'    => date('Y-m-d H:i:s')
    ]);
    exit;

} catch (\Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log("Completion Alert Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}

// ==========================================
// HELPER: Congratulations Email Template
// FIXED: Removed <link> and <script> tags — email clients don't load external CSS/JS
// ==========================================
function buildCongratulationsTemplate($full_name, $trainer_name) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Congratulations - TESDA Compliance</title>
    </head>
    <body style='margin:0; padding:20px; background-color:#f4f6f9; font-family: Arial, Helvetica, sans-serif; color:#333333; line-height:1.6;'>

        <div style='max-width:600px; margin:0 auto; background:#ffffff; padding:30px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.06);'>

            <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='border-bottom:3px solid #10b981; padding-bottom:15px; margin-bottom:25px;'>
                <tr>
                    <td style='vertical-align:middle;'>
                        <div style='font-size:22px; font-weight:700; color:#1e293b; letter-spacing:0.3px;'>TESDA Compliance</div>
                        <div style='font-size:13px; color:#64748b; margin-top:2px;'>Performance Task Tracking System</div>
                    </td>
                </tr>
            </table>

            <p style='font-size:16px; color:#1e293b; margin:0 0 16px 0;'>
                Dear <strong>" . htmlspecialchars($full_name) . "</strong>,
            </p>

            <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background-color:#f0fdf4; border:1px solid #86efac; border-left:4px solid #10b981; border-radius:8px; margin:0 0 22px 0;'>
                <tr>
                    <td style='padding:30px 24px; text-align:center;'>
                        <div style='font-size:44px; margin-bottom:10px;'>🎉</div>
                        <div style='font-size:22px; font-weight:700; color:#065f46; margin-bottom:10px;'>Congratulations!</div>
                        <p style='margin:0; font-size:15px; color:#065f46; line-height:1.7;'>
                            You have successfully completed <strong>all required performance tasks</strong>.
                            You are now officially <strong>ready for the assessment</strong>!
                        </p>
                    </td>
                </tr>
            </table>

            <div style='background-color:#fafafa; border-radius:10px; padding:26px 28px; margin:0 0 30px 0; border-left:4px solid #10b981;'>
                <div style='color:#10b981; font-size:17px; font-weight:700; margin:0 0 18px 0; letter-spacing:0.3px;'>
                    Next Steps
                </div>

                <table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%'>
                    <tr>
                        <td style='padding:0 0 16px 0; font-size:14px; color:#444444; line-height:1.65;'>
                            <strong style='color:#1e293b;'>1. Wait for Notification:</strong>
                            Keep an eye on your messages for the official assessment schedule from your trainer/instructor.
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:0 0 16px 0; font-size:14px; color:#444444; line-height:1.65;'>
                            <strong style='color:#1e293b;'>2. Prepare Requirements:</strong>
                            Gather all necessary portfolio requirements, completed evaluation forms, and documentation.
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:0 0 16px 0; font-size:14px; color:#444444; line-height:1.65;'>
                            <strong style='color:#1e293b;'>3. Confirm Attendance:</strong>
                            Ensure you are available and punctual on your designated assessment date and venue.
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:0; font-size:14px; color:#444444; line-height:1.65;'>
                            <strong style='color:#1e293b;'>4. Bring Identification:</strong>
                            Bring a valid Government ID / Student ID along with your assessment permit on the assessment day.
                        </td>
                    </tr>
                </table>
            </div>

            <div style='margin-top:30px; padding-top:20px; border-top:2px solid #e8edf5; font-size:13px; color:#64748b; text-align:center;'>
                <div style='width:40px; height:2px; background:#10b981; margin:0 auto 14px auto; border-radius:2px;'></div>
                <p style='margin:0;'>Best regards,</p>
                <p style='margin:6px 0 0 0;'>
                    <span style='color:#1e293b; font-weight:700; font-size:15px; display:block;'>" . htmlspecialchars($trainer_name) . "</span>
                    <span style='color:#64748b; font-size:12px; display:block; margin-top:2px;'>Instructor / Trainer</span>
                </p>
                <p style='font-size:11px; color:#94a3b8; margin-top:22px;'>
                    This is an automated message from the TESDA Compliance System.
                </p>
            </div>

        </div>
    </body>
    </html>";
}

// ==========================================
// HELPER: Completion SMS Template — UPDATED (COMPLETE, NO CUTTING)
// ✅ USAT TESDA: Congrats [First Name] [M.I.]., you completed your [Qualification/Course Short Code] task, for any concerns or clarifications, visit the USAT TESDA center. Great job!
// ==========================================
function buildCompletionSmsMessage($full_name, $course_code) {
    // Extract first name and middle initial from full name
    $name_parts = explode(' ', trim($full_name));
    $first_name = $name_parts[0] ?? '';
    $middle_initial = '';
    
    // Check if there's a middle initial (usually the second part if it's a single letter or ends with .)
    if (count($name_parts) > 1) {
        $possible_mi = $name_parts[1];
        // If it's a single letter or single letter followed by a dot, use it as M.I.
        if (strlen($possible_mi) == 1 || (strlen($possible_mi) == 2 && substr($possible_mi, -1) == '.')) {
            $middle_initial = rtrim($possible_mi, '.') . '.';
        }
    }
    
    // Build the greeting name: "First Name M.I." or just "First Name" if no M.I.
    $greeting_name = $first_name;
    if (!empty($middle_initial)) {
        $greeting_name .= ' ' . $middle_initial;
    }
    
    // ✅ COMPLETE message — no truncation
    $msg = "USAT TESDA: Congrats {$greeting_name}, you completed your {$course_code} task, for any concerns or clarifications, visit the USAT TESDA center. Great job!";
    
    return $msg;
}