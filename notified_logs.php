<?php
// ============================================================
// OUTPUT BUFFERING — CRITICAL FIX FOR JSON RESPONSES
// ============================================================
ob_start();

// Error reporting - log only, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/auth_check.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/helpers/PusherHelper.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$db = $pdo;

// Get user role and department for filtering
$user_role = $_SESSION['role'] ?? '';
$user_department = $_SESSION['department'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// ==========================================
// HELPER: SEND CLEAN JSON RESPONSE
// ==========================================
function send_json($data) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }
    echo json_encode($data);
    exit;
}

// ==========================================
// Handle Return to Evaluation Action
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'return_to_evaluation') {
    try {
        $trainee_id = isset($_POST['trainee_id']) ? (int)$_POST['trainee_id'] : 0;
        $notified_log_id = isset($_POST['notified_log_id']) ? (int)$_POST['notified_log_id'] : 0;
        
        if (!$trainee_id || !$notified_log_id) {
            send_json(['success' => false, 'message' => 'Missing required parameters']);
        }
        
        // Update the notified_logs record - mark as returned
        $update_stmt = $db->prepare("
            UPDATE notified_logs 
            SET returned_to_evaluation = 1, 
                returned_at = NOW() 
            WHERE id = ? AND trainee_id = ?
        ");
        $update_stmt->execute([$notified_log_id, $trainee_id]);
        
        // Also update all records for this trainee
        $update_all = $db->prepare("
            UPDATE notified_logs 
            SET returned_to_evaluation = 1, 
                returned_at = NOW() 
            WHERE trainee_id = ?
        ");
        $update_all->execute([$trainee_id]);
        
        // Log the action in evaluation_history if exists
        try {
            $check_table = $db->query("SHOW TABLES LIKE 'evaluation_history'");
            if ($check_table->rowCount() > 0) {
                $log_stmt = $db->prepare("
                    INSERT INTO evaluation_history 
                    (trainee_id, action, performed_by, remarks, created_at) 
                    VALUES (?, 'returned_to_evaluation', ?, 'Trainee returned to evaluation module', NOW())
                ");
                $log_stmt->execute([$trainee_id, $user_id]);
            }
        } catch (Exception $e) {}
        
        // Trigger Pusher for real-time update
        try {
            $trainee_stmt = $db->prepare("SELECT first_name, last_name FROM trainees WHERE id = ?");
            $trainee_stmt->execute([$trainee_id]);
            $trainee = $trainee_stmt->fetch(PDO::FETCH_ASSOC);
            $trainee_name = ($trainee['first_name'] ?? '') . ' ' . ($trainee['last_name'] ?? '');
            
            $payload = [
                'trainee_id' => $trainee_id,
                'trainee_name' => $trainee_name,
                'action' => 'returned_to_evaluation',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            triggerPusherUpdate('trainee_returned', $payload, 'compliance-channel');
        } catch (Exception $e) {}
        
        send_json([
            'success' => true,
            'message' => 'Trainee returned to evaluation module successfully'
        ]);
        
    } catch (PDOException $e) {
        send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ==========================================
// RBAC: Get trainer qualification IDs
// ==========================================
$is_trainer = isTrainer();
$is_coordinator = isCoordinator();
$trainer_qualification_ids = [];

if ($is_trainer) {
    $trainer_qualification_ids = getTrainerQualificationIds($user_id);
    $_SESSION['qualification_ids'] = $trainer_qualification_ids;
}

// ==========================================
// Get filters
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$qual_filter = isset($_GET['qualification']) ? (int)$_GET['qualification'] : 0;

// ==========================================
// Fetch Qualifications for Filter with Department grouping
// ==========================================
$qualifications = $pdo->query("
    SELECT q.id, q.qualification_name, q.qualification_code, c.course_code, c.course_name
    FROM qualifications q
    JOIN courses c ON q.course_id = c.id
    ORDER BY c.course_code, q.qualification_name
")->fetchAll();

// Group qualifications by department
$qualifications_by_dept = [];
foreach ($qualifications as $qual) {
    $dept_code = $qual['course_code'];
    if (!isset($qualifications_by_dept[$dept_code])) {
        $qualifications_by_dept[$dept_code] = [
            'course_name' => $qual['course_name'],
            'qualifications' => []
        ];
    }
    $qualifications_by_dept[$dept_code]['qualifications'][] = $qual;
}

// ==========================================
// Dynamic Query with RBAC - EXCLUDE RETURNED TRAINEES
// ==========================================
$sql = "SELECT 
            COALESCE(q.id, t.qualification_id) AS qualification_id,
            COALESCE(q.qualification_name, tq.qualification_name, 'Unassigned Qualification') AS qualification_title, 
            COALESCE(q.qualification_code, tq.qualification_code, 'N/A') AS qualification_code,
            COALESCE(c.course_code, t.department) AS department_code,
            COALESCE(c.course_name, t.department) AS department_name,
            COALESCE(cs.competency_name, 'General Compliance') AS competency_name, 
            cs.id AS competency_id,
            COALESCE(pt.task_name, 'Reminder sent for pending tasks') AS task_name, 
            CONCAT(t.first_name, ' ', t.last_name) AS trainee_fullname,
            t.first_name,
            t.last_name,
            t.department,
            t.id AS trainee_id,
            nl.id AS notified_log_id,
            nl.notification_channel, 
            nl.delivery_status, 
            u.username AS sent_by_user, 
            nl.date_sent,
            nl.returned_to_evaluation,
            nl.returned_at
        FROM notified_logs nl 
        JOIN trainees t ON nl.trainee_id = t.id 
        LEFT JOIN performance_tasks pt ON nl.task_id = pt.id 
        LEFT JOIN competency_standards cs ON pt.competency_id = cs.id 
        LEFT JOIN qualifications q ON cs.qualification_id = q.id 
        LEFT JOIN qualifications tq ON t.qualification_id = tq.id 
        LEFT JOIN courses c ON COALESCE(q.course_id, tq.course_id) = c.id
        LEFT JOIN users u ON nl.sent_by = u.id
        WHERE 1=1
        AND (nl.returned_to_evaluation IS NULL OR nl.returned_to_evaluation = 0)";

$params = [];

// RBAC: Trainer qualification filter
if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $sql .= " AND COALESCE(q.id, t.qualification_id) IN ($placeholders)";
    $params = array_merge($params, $trainer_qualification_ids);
} elseif ($is_trainer) {
    $sql .= " AND 1=0";
}

if (!empty($search)) {
    $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR cs.competency_name LIKE ? OR nl.notification_channel LIKE ? OR nl.delivery_status LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($qual_filter)) {
    $sql .= " AND COALESCE(q.id, t.qualification_id) = ?";
    $params[] = $qual_filter;
}

$sql .= " ORDER BY COALESCE(c.course_code, t.department), COALESCE(q.qualification_name, tq.qualification_name), nl.date_sent DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notified_logs = $stmt->fetchAll();

// ==========================================
// Dynamic Stat Counts
// ==========================================
$total_count = count($notified_logs);
$sms_count = 0;
$email_count = 0;
$failed_count = 0;

foreach ($notified_logs as $log) {
    $channel = strtolower($log['notification_channel'] ?? '');
    if (strpos($channel, 'sms') !== false) {
        $sms_count++;
    }
    if (strpos($channel, 'email') !== false) {
        $email_count++;
    }
    
    $status = strtolower($log['delivery_status'] ?? '');
    if (in_array($status, ['pending', 'failed'])) {
        $failed_count++;
    }
}

// Department mapping
$dept_colors = [
    'HRS' => '#8b5cf6',
    'CSS' => '#10b981',
    'ATS' => '#f59e0b'
];

$dept_names = [
    'HRS' => 'Hotel and Restaurant Services',
    'CSS' => 'Computer Systems Servicing',
    'ATS' => 'Automotive Mechanics'
];

$dept_icons = [
    'HRS' => 'fa-hotel',
    'CSS' => 'fa-laptop',
    'ATS' => 'fa-car'
];

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notified Logs - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <script src="/tesda_compliance/assets/js/socket.io.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f0f2f5;
            min-width: 1200px;
            overflow-x: auto;
            margin: 0;
            transition: all 0.3s ease;
        }
        
        body.sidebar-collapsed {
            min-width: 992px;
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-width: 920px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            animation: fadeInPage 0.4s ease-out;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
        }

        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header {
            background: white;
            border-radius: 24px;
            padding: 25px 35px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .header:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 45px rgba(0,0,0,0.12);
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .header-left h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            line-height: 1.2;
        }

        .header-left h1 i {
            color: #8b5cf6;
            font-size: 1.8rem;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0;
            margin-top: 2px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .profile-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 20px 6px 6px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            transition: all 0.2s ease;
            cursor: default;
        }

        .profile-badge:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }

        .profile-badge .avatar-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #263868;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .profile-badge:hover .avatar-circle {
            transform: scale(1.05);
        }

        .profile-badge .avatar-circle i {
            color: #ffffff;
            font-size: 0.9rem;
        }

        .profile-badge .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .date-badge {
            background-color: #f8fafc;
            padding: 10px 20px;
            border-radius: 12px;
            color: #0f172a;
            font-weight: 500;
            font-size: 1rem;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-badge i {
            color: #0f172a;
            font-size: 1rem;
        }

        .date-badge:hover {
            background-color: #f1f5f9;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            border: 1px solid #e8edf5;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.12);
        }

        .stat-icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover .stat-icon-wrapper {
            transform: scale(1.05);
        }

        .stat-icon-wrapper i {
            font-size: 1.5rem;
            color: white;
        }

        .stat-content {
            flex: 1;
        }

        .stat-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a1c2c;
            line-height: 1.2;
            margin-bottom: 2px;
        }

        .stat-content p {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
            margin: 0;
        }

        .global-filter-section {
            background: white;
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .global-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .global-filter-header h2 {
            color: #1a1c2c;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .global-filter-header h2 i {
            color: #8b5cf6;
            font-size: 1.3rem;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-select, .filter-input {
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            min-width: 170px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            flex: 1;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .filter-input {
            flex: 2;
            min-width: 220px;
        }

        .btn-reset-filters {
            padding: 14px 28px;
            background: #e2e8f0;
            color: #1a1c2c;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            font-size: 1rem;
            text-decoration: none;
        }

        .btn-reset-filters:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }

        .btn-primary {
            padding: 14px 28px;
            background: #8b5cf6;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            font-size: 1rem;
            text-decoration: none;
        }

        .btn-primary:hover {
            background: #7c3aed;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139,92,246,0.3);
        }

        .filter-group-hierarchy {
            position: relative;
            flex: 1;
            min-width: 220px;
        }

        .filter-group-hierarchy .filter-select {
            width: 100%;
            min-width: unset;
        }

        .filter-group-hierarchy .filter-select optgroup {
            font-weight: 700;
            color: #0f172a;
            background: #f8fafc;
        }

        .filter-group-hierarchy .filter-select optgroup option {
            font-weight: 400;
            padding-left: 20px;
            color: #1e293b;
        }

        .accordion-container {
            background: white;
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .accordion-header-bar {
            padding: 20px 28px;
            border-bottom: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #fafbfc;
        }

        .accordion-header-bar h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .accordion-header-bar h3 i {
            color: #8b5cf6;
        }

        .count-badge {
            background: #eef2ff;
            color: #4338ca;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .dept-card {
            border-bottom: 1px solid #eef2f6;
        }

        .dept-card:last-child {
            border-bottom: none;
        }

        .dept-banner {
            background: #f8fafc;
            padding: 16px 28px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: default;
            border-left: 4px solid #8b5cf6;
        }

        .dept-banner .dept-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .dept-banner .dept-name {
            font-weight: 600;
            font-size: 1rem;
            color: #0f172a;
        }

        .dept-banner .dept-code {
            font-size: 0.75rem;
            color: #64748b;
            background: #e2e8f0;
            padding: 2px 12px;
            border-radius: 20px;
            font-weight: 500;
        }

        .dept-banner .dept-count {
            margin-left: auto;
            font-size: 0.85rem;
            color: #64748b;
            background: #eef2f6;
            padding: 4px 14px;
            border-radius: 30px;
            font-weight: 500;
        }

        .qual-accordion {
            border-bottom: 1px solid #f1f5f9;
        }

        .qual-accordion:last-child {
            border-bottom: none;
        }

        .qual-header {
            padding: 14px 28px 14px 52px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background 0.2s ease;
            user-select: none;
        }

        .qual-header:hover {
            background: #fafbfc;
        }

        .qual-header .qual-icon {
            color: #8b5cf6;
            font-size: 0.85rem;
        }

        .qual-header .qual-title {
            font-weight: 500;
            font-size: 0.9rem;
            color: #1e293b;
            flex: 1;
        }

        .qual-header .qual-code {
            font-size: 0.7rem;
            color: #64748b;
            background: #f1f5f9;
            padding: 2px 12px;
            border-radius: 20px;
            font-weight: 500;
        }

        .qual-header .toggle-arrow {
            margin-left: auto;
            color: #94a3b8;
            font-size: 0.9rem;
            transition: transform 0.3s ease;
            width: 24px;
            text-align: center;
        }

        .qual-header .toggle-arrow.open {
            transform: rotate(180deg);
        }

        .qual-body {
            overflow: hidden;
            max-height: 0;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .qual-body.open {
            max-height: 2000px;
        }

        .qual-body-inner {
            padding: 0 28px 20px 52px;
        }

        .log-table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #eef2f6;
        }

        .log-table-wrapper table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .log-table-wrapper thead {
            background: #f8fafc;
        }

        .log-table-wrapper th {
            text-align: left;
            padding: 12px 16px;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #eef2f6;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .log-table-wrapper td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .log-table-wrapper tr:hover td {
            background: #fafbfc;
        }

        .log-table-wrapper tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-badge.delivered { background: #d1fae5; color: #065f46; }
        .status-badge.sent { background: #dbeafe; color: #1e40af; }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.failed { background: #fee2e2; color: #991b1b; }

        .channel-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .channel-badge.sms { background: #dbeafe; color: #1e40af; }
        .channel-badge.email { background: #fce7f3; color: #9d174d; }
        .channel-badge.both { background: #ede9fe; color: #5b21b6; }

        .dept-tag {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
            color: white;
        }

        .dept-tag.hrs { background: #8b5cf6; }
        .dept-tag.css { background: #10b981; }
        .dept-tag.ats { background: #f59e0b; }

        .btn-return {
            background: #10b981;
            color: white;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-return:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-return:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-return .spinner-small {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .empty-state p {
            font-size: 0.9rem;
        }

        .competency-hierarchy {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 4px;
        }

        .competency-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.7rem;
            color: #475569;
            background: #f8fafc;
            padding: 2px 10px;
            border-radius: 12px;
            border: 1px solid #eef2f6;
            width: fit-content;
        }

        .competency-item i {
            color: #8b5cf6;
            font-size: 0.5rem;
        }

        .competency-item .competency-code {
            font-weight: 600;
            color: #8b5cf6;
            font-size: 0.6rem;
            background: #ede9fe;
            padding: 0 6px;
            border-radius: 8px;
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #5a67d8, #6b46a0); }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            background: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            border-left: 4px solid #10b981;
            animation: slideIn 0.3s ease;
        }

        .toast.error {
            border-left-color: #ef4444;
        }

        .toast .toast-icon {
            font-size: 1.5rem;
            color: #10b981;
        }

        .toast.error .toast-icon {
            color: #ef4444;
        }

        .toast .toast-message {
            font-size: 0.9rem;
            color: #1e293b;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .return-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            animation: returnFadeIn 0.2s ease-out;
        }

        .return-modal-overlay.active {
            display: flex;
        }

        @keyframes returnFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes returnPopIn {
            from { opacity: 0; transform: translateY(10px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .return-modal-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 28px 24px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: returnPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .return-modal-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #fee2e2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #ef4444;
        }

        .return-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .return-modal-text {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 6px;
        }

        .return-modal-text strong {
            color: #1e293b;
            font-weight: 700;
        }

        .return-modal-text:last-of-type {
            margin-bottom: 24px;
        }

        .return-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .return-btn {
            flex: 1;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: 'Poppins', sans-serif;
        }

        .return-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .return-btn-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .return-btn-confirm {
            background: #ef4444;
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3);
        }

        .return-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(239, 68, 68, 0.4);
            background: #dc2626;
        }

        .return-btn-confirm:active,
        .return-btn-cancel:active {
            transform: translateY(0);
        }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .main-content { margin-left: 80px; width: calc(100% - 80px); }
            body { min-width: 992px; }
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-right { width: 100%; flex-wrap: wrap; }
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .filter-row { flex-direction: column; }
            .filter-select, .filter-input { width: 100%; min-width: unset; }
            .filter-group-hierarchy { width: 100%; }
            .global-filter-header { flex-direction: column; gap: 12px; align-items: stretch; }
            .profile-badge { padding: 6px 12px 6px 6px; }
            .profile-badge .profile-name { font-size: 0.85rem; }
            .qual-header { padding: 12px 16px 12px 16px; flex-wrap: wrap; }
            .qual-body-inner { padding: 0 16px 16px 16px; }
            .dept-banner { padding: 12px 16px; flex-wrap: wrap; }
            .btn-return { font-size: 0.6rem; padding: 4px 10px; }
            .return-modal-card { padding: 25px 20px; }
        }
    </style>

<link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="toast-container" id="toastContainer"></div>
        
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-bell"></i> Notified Logs</h1>
                <p>Track and audit all automated and manual Email and SMS notifications sent to trainees.</p>
            </div>
            <div class="header-right">
                <div class="profile-badge">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'User') ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #8b5cf6;">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stat-content">
                    <h3><?= number_format($total_count) ?></h3>
                    <p>Total Notifications Sent</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #3b82f6;">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="stat-content">
                    <h3><?= number_format($sms_count) ?></h3>
                    <p>SMS Alerts</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #f59e0b;">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-content">
                    <h3><?= number_format($email_count) ?></h3>
                    <p>Email Alerts</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #ef4444;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <h3><?= number_format($failed_count) ?></h3>
                    <p>Pending / Failed</p>
                </div>
            </div>
        </div>

        <div class="global-filter-section">
            <div class="global-filter-header">
                <h2><i class="fas fa-filter"></i> Filter Logs</h2>
            </div>
            
            <form method="GET" action="" id="filterForm" class="filter-row">
                <input type="text" class="filter-input" name="search" placeholder="Search by trainee, competency, channel, or status..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
                
                <div class="filter-group-hierarchy">
                    <select class="filter-select" name="qualification" id="qualificationFilter">
                        <option value="">All Qualifications</option>
                        <?php foreach ($qualifications_by_dept as $dept_code => $dept_data): ?>
                            <?php 
                            if ($is_trainer && !empty($trainer_qualification_ids)) {
                                $has_access = false;
                                foreach ($dept_data['qualifications'] as $qual) {
                                    if (in_array($qual['id'], $trainer_qualification_ids)) {
                                        $has_access = true;
                                        break;
                                    }
                                }
                                if (!$has_access) continue;
                            }
                            ?>
                            <optgroup label="🏢 <?= htmlspecialchars($dept_data['course_name']) ?> (<?= $dept_code ?>)">
                                <?php foreach ($dept_data['qualifications'] as $qual): ?>
                                    <?php 
                                    if ($is_trainer && !empty($trainer_qualification_ids)) {
                                        if (!in_array($qual['id'], $trainer_qualification_ids)) {
                                            continue;
                                        }
                                    }
                                    ?>
                                    <option value="<?= $qual['id'] ?>" <?= $qual_filter == $qual['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($qual['qualification_code']) ?> - <?= htmlspecialchars($qual['qualification_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <a href="notified_logs.php" class="btn-reset-filters">
                    <i class="fas fa-undo-alt"></i> Reset
                </a>
            </form>
        </div>

        <div class="accordion-container">
            <div class="accordion-header-bar">
                <h3><i class="fas fa-bell"></i> Notified Logs</h3>
                <span class="count-badge"><?= $total_count ?> Records</span>
            </div>

            <?php if (empty($notified_logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Notified Logs Found</h3>
                    <p>No notifications have been sent yet, or try adjusting your filters.</p>
                </div>
            <?php else: 
                $hierarchy = [];
                foreach ($notified_logs as $log) {
                    $deptKey = $log['department_code'] ?? 'Unknown';
                    $qualKey = $log['qualification_id'] ?? 0;
                    
                    if (!isset($hierarchy[$deptKey])) {
                        $hierarchy[$deptKey] = [
                            'dept_name' => $log['department_name'] ?? $deptKey,
                            'dept_code' => $deptKey,
                            'qualifications' => []
                        ];
                    }
                    if (!isset($hierarchy[$deptKey]['qualifications'][$qualKey])) {
                        $hierarchy[$deptKey]['qualifications'][$qualKey] = [
                            'qual_name' => $log['qualification_title'] ?? 'Unknown',
                            'qual_code' => $log['qualification_code'] ?? '',
                            'logs' => []
                        ];
                    }
                    $hierarchy[$deptKey]['qualifications'][$qualKey]['logs'][] = $log;
                }
            ?>
                <?php foreach ($hierarchy as $deptCode => $deptData): 
                    $color = $dept_colors[$deptCode] ?? '#64748b';
                    $icon = $dept_icons[$deptCode] ?? 'fa-building';
                    $deptTotal = 0;
                    foreach ($deptData['qualifications'] as $qual) {
                        $deptTotal += count($qual['logs']);
                    }
                ?>
                    <div class="dept-card">
                        <div class="dept-banner" style="border-left-color: <?= $color ?>;">
                            <div class="dept-icon" style="background: <?= $color ?>;">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <span class="dept-name"><?= htmlspecialchars($deptData['dept_name']) ?></span>
                            <span class="dept-code"><?= htmlspecialchars($deptCode) ?></span>
                            <span class="dept-count"><?= $deptTotal ?> notification<?= $deptTotal > 1 ? 's' : '' ?></span>
                        </div>

                        <?php foreach ($deptData['qualifications'] as $qualKey => $qualData): 
                            $logCount = count($qualData['logs']);
                            $unique_trainees = array_unique(array_column($qualData['logs'], 'trainee_id'));
                            $unique_count = count($unique_trainees);
                        ?>
                            <div class="qual-accordion">
                                <div class="qual-header" onclick="toggleAccordion(this)">
                                    <i class="fas fa-certificate qual-icon"></i>
                                    <span class="qual-title"><?= htmlspecialchars($qualData['qual_name']) ?></span>
                                    <span class="qual-code"><?= htmlspecialchars($qualData['qual_code']) ?></span>
                                    <span style="font-size:0.7rem;color:#64748b;background:#f1f5f9;padding:2px 12px;border-radius:20px;">
                                        <?= $unique_count ?> trainee<?= $unique_count > 1 ? 's' : '' ?>
                                    </span>
                                    <span class="toggle-arrow"><i class="fas fa-chevron-down"></i></span>
                                </div>

                                <div class="qual-body">
                                    <div class="qual-body-inner">
                                        <?php if (empty($qualData['logs'])): ?>
                                            <div class="empty-state" style="padding:20px;">
                                                <p style="font-size:0.9rem;">No logs for this qualification.</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="log-table-wrapper">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Trainee</th>
                                                            <th>Competency</th>
                                                            <th>Channel</th>
                                                            <th>Status</th>
                                                            <th>Sent By</th>
                                                            <th>Date</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($qualData['logs'] as $log): 
                                                            $channel = strtolower($log['notification_channel'] ?? '');
                                                            if (strpos($channel, 'sms') !== false && strpos($channel, 'email') !== false) {
                                                                $channel_class = 'both';
                                                                $channel_icon = 'fa-envelope-open-text';
                                                                $channel_label = 'SMS & Email';
                                                            } elseif (strpos($channel, 'sms') !== false) {
                                                                $channel_class = 'sms';
                                                                $channel_icon = 'fa-phone';
                                                                $channel_label = 'SMS';
                                                            } else {
                                                                $channel_class = 'email';
                                                                $channel_icon = 'fa-envelope';
                                                                $channel_label = 'Email';
                                                            }
                                                            
                                                            $status = strtolower($log['delivery_status'] ?? '');
                                                            $status_class = 'pending';
                                                            if ($status === 'delivered' || $status === 'sent') {
                                                                $status_class = 'delivered';
                                                            } elseif ($status === 'failed') {
                                                                $status_class = 'failed';
                                                            } elseif ($status === 'pending') {
                                                                $status_class = 'pending';
                                                            } else {
                                                                $status_class = 'sent';
                                                            }
                                                        ?>
                                                            <tr>
                                                                <td>
                                                                    <strong style="font-size:0.9rem;"><?= htmlspecialchars($log['trainee_fullname']) ?></strong>
                                                                </td>
                                                                <td style="max-width:280px;">
                                                                    <div style="font-size:0.85rem;word-break:break-word;line-height:1.4;">
                                                                        <?php if (!empty($log['competency_name'])): ?>
                                                                            <span style="font-weight:600;color:#8b5cf6;"><?= htmlspecialchars($log['competency_name']) ?></span>
                                                                        <?php else: ?>
                                                                            <span style="color:#94a3b8;">No competency</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <span class="channel-badge <?= $channel_class ?>">
                                                                        <i class="fas <?= $channel_icon ?>"></i>
                                                                        <?= $channel_label ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="status-badge <?= $status_class ?>">
                                                                        <?= ucfirst(htmlspecialchars($log['delivery_status'] ?? 'Pending')) ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <i class="fas fa-user-shield" style="color:#94a3b8;font-size:0.65rem;"></i>
                                                                    <?= htmlspecialchars($log['sent_by_user'] ?? 'System') ?>
                                                                </td>
                                                                <td style="font-size:0.75rem;color:#64748b;white-space:nowrap;">
                                                                    <i class="far fa-clock" style="color:#94a3b8;"></i>
                                                                    <?= date('M d, Y h:i A', strtotime($log['date_sent'])) ?>
                                                                </td>
                                                                <td>
                                                                    <button class="btn-return" 
                                                                            onclick="openReturnModal(<?= $log['trainee_id'] ?>, <?= $log['notified_log_id'] ?>, '<?= htmlspecialchars(addslashes($log['trainee_fullname'])) ?>', this)"
                                                                            title="Return trainee to Evaluation Module">
                                                                        <i class="fas fa-undo-alt"></i> Return
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="return-modal-overlay" id="returnModal">
        <div class="return-modal-card">
            <div class="return-modal-icon">
                <i class="fas fa-undo-alt"></i>
            </div>
            <h3 class="return-modal-title">Confirm Return</h3>
            <p class="return-modal-text">Are you sure you want to return <strong id="returnTraineeName"></strong> to the Evaluation Module?</p>
            <p class="return-modal-text">The trainee will be removed from the Notified Logs and made visible again in Evaluation.</p>
            <div class="return-modal-actions">
                <button type="button" class="return-btn return-btn-cancel" id="returnCancel">Cancel</button>
                <button type="button" class="return-btn return-btn-confirm" id="returnConfirm">
                    Yes, Return
                </button>
            </div>
        </div>
    </div>

    <script>
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            toast.innerHTML = `
                <i class="fas ${icon} toast-icon"></i>
                <span class="toast-message">${message}</span>
            `;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        let currentReturnData = {};
        const returnModal = document.getElementById('returnModal');
        const returnCancelBtn = document.getElementById('returnCancel');
        const returnConfirmBtn = document.getElementById('returnConfirm');

        function openReturnModal(traineeId, notifiedLogId, traineeName, buttonElement) {
            currentReturnData = {
                traineeId: traineeId,
                notifiedLogId: notifiedLogId,
                traineeName: traineeName,
                buttonElement: buttonElement
            };

            document.getElementById('returnTraineeName').textContent = traineeName;

            returnModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeReturnModal() {
            returnModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        if (returnCancelBtn) {
            returnCancelBtn.addEventListener('click', closeReturnModal);
        }

        if (returnModal) {
            returnModal.addEventListener('click', function(e) {
                if (e.target === returnModal) closeReturnModal();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && returnModal && returnModal.classList.contains('active')) {
                closeReturnModal();
            }
        });

        function safeAjax(url, options) {
            return fetch(url, options)
                .then(function(response) {
                    if (!response.ok) {
                        return response.text().then(function(text) {
                            throw new Error('HTTP ' + response.status + ': ' + text.substring(0, 300));
                        });
                    }
                    return response.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error('Server returned non-JSON: ' + text.substring(0, 300));
                        }
                    });
                });
        }

        if (returnConfirmBtn) {
            returnConfirmBtn.addEventListener('click', function() {
                closeReturnModal();

                const { traineeId, notifiedLogId, traineeName, buttonElement } = currentReturnData;

                if (!traineeId || !notifiedLogId || !buttonElement) return;

                buttonElement.disabled = true;
                buttonElement.innerHTML = '<span class="spinner-small"></span> Returning...';

                const formData = new URLSearchParams();
                formData.append('action', 'return_to_evaluation');
                formData.append('trainee_id', traineeId);
                formData.append('notified_log_id', notifiedLogId);

                safeAjax('notified_logs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                })
                .then(data => {
                    if (data.success) {
                        showToast(`✅ "${traineeName}" has been returned to the Evaluation Module successfully`, 'success');
                        
                        const row = buttonElement.closest('tr');
                        if (row) {
                            row.style.transition = 'all 0.4s ease';
                            row.style.opacity = '0';
                            row.style.transform = 'translateX(-20px)';
                        }
                        
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showToast('❌ ' + (data.message || 'Failed to return trainee'), 'error');
                        buttonElement.disabled = false;
                        buttonElement.innerHTML = '<i class="fas fa-undo-alt"></i> Return';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('❌ Error returning trainee: ' + error.message, 'error');
                    buttonElement.disabled = false;
                    buttonElement.innerHTML = '<i class="fas fa-undo-alt"></i> Return';
                });
            });
        }

        function updateCounts() {
            const rows = document.querySelectorAll('.log-table-wrapper tbody tr');
            const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
            const totalRecords = visibleRows.length;
            
            const countBadge = document.querySelector('.count-badge');
            if (countBadge) {
                countBadge.textContent = totalRecords + ' Records';
            }
            
            document.querySelectorAll('.dept-card').forEach(card => {
                const deptRows = card.querySelectorAll('.log-table-wrapper tbody tr');
                const visibleDeptRows = Array.from(deptRows).filter(row => row.style.display !== 'none');
                const deptCount = visibleDeptRows.length;
                const deptCountSpan = card.querySelector('.dept-count');
                if (deptCountSpan) {
                    deptCountSpan.textContent = deptCount + ' notification' + (deptCount > 1 ? 's' : '');
                }
            });
            
            document.querySelectorAll('.qual-accordion').forEach(accordion => {
                const qualRows = accordion.querySelectorAll('.log-table-wrapper tbody tr');
                const visibleQualRows = Array.from(qualRows).filter(row => row.style.display !== 'none');
                const qualCount = visibleQualRows.length;
                const qualHeader = accordion.querySelector('.qual-header');
                if (qualHeader) {
                    const countSpan = qualHeader.querySelector('span[style*="background:#f1f5f9"]');
                    if (countSpan) {
                        const uniqueTrainees = new Set();
                        visibleQualRows.forEach(row => {
                            const cells = row.querySelectorAll('td');
                            if (cells.length > 0) {
                                const traineeName = cells[0]?.textContent?.trim() || '';
                                if (traineeName) uniqueTrainees.add(traineeName);
                            }
                        });
                        countSpan.textContent = uniqueTrainees.size + ' trainee' + (uniqueTrainees.size > 1 ? 's' : '');
                    }
                }
            });
        }

        function toggleAccordion(headerElement) {
            const body = headerElement.nextElementSibling;
            const arrow = headerElement.querySelector('.toggle-arrow');
            
            if (body.classList.contains('open')) {
                body.classList.remove('open');
                if (arrow) arrow.classList.remove('open');
            } else {
                body.classList.add('open');
                if (arrow) arrow.classList.add('open');
            }
        }

        let socketInstance = null;

        function initWebSocket() {
            try {
                if (typeof io === 'undefined') {
                    console.log('Socket.IO library not loaded');
                    return;
                }
                
                socketInstance = io('http://localhost:3000', {
                    transports: ['websocket', 'polling'],
                    timeout: 5000,
                    reconnection: true,
                    reconnectionAttempts: 10,
                    reconnectionDelay: 1000
                });
                
                socketInstance.on('connect', function() {
                    console.log('✅ Connected to notification server');
                    socketInstance.emit('join', { channel: 'compliance-channel' });
                });
                
                socketInstance.on('reminder_sent', function(data) {
                    console.log('📬 Real-time reminder received:', data);
                    
                    showToast(
                        `🔔 Reminder sent to ${data.trainee_name || 'trainee'} (${data.pending_tasks || 0} tasks pending)`,
                        'success'
                    );
                    
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                });
                
                socketInstance.on('trainee_returned', function(data) {
                    console.log('📬 Trainee returned event received:', data);
                    showToast(
                        `↩️ ${data.trainee_name || 'Trainee'} returned to Evaluation`,
                        'info'
                    );
                    setTimeout(function() {
                        location.reload();
                    }, 500);
                });
                
                socketInstance.on('task_evaluated', function(data) {
                    console.log('📬 Task evaluated event received:', data);
                    if (data.transferred) {
                        showToast(
                            `✅ ${data.trainee_name || 'Trainee'} moved to Pre-Assessment!`,
                            'success'
                        );
                    }
                });
                
                socketInstance.on('disconnect', function() {
                    console.log('❌ Disconnected from notification server');
                    setTimeout(initWebSocket, 3000);
                });
                
                socketInstance.on('connect_error', function(error) {
                    console.log('⚠️ WebSocket connection error:', error);
                });
                
            } catch (e) {
                console.log('WebSocket initialization error:', e);
                setTimeout(initWebSocket, 5000);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const deptCards = document.querySelectorAll('.dept-card');
            deptCards.forEach(card => {
                const firstQual = card.querySelector('.qual-accordion');
                if (firstQual) {
                    const header = firstQual.querySelector('.qual-header');
                    const body = firstQual.querySelector('.qual-body');
                    const arrow = firstQual.querySelector('.toggle-arrow');
                    if (header && body && arrow) {
                        body.classList.add('open');
                        arrow.classList.add('open');
                    }
                }
            });

            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('sidebarToggle');
            const toggleIcon = document.getElementById('toggleIcon');
            const body = document.body;
            
            if (sidebar && toggleBtn) {
                const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                
                if (sidebarCollapsed) {
                    sidebar.classList.add('collapsed');
                    body.classList.add('sidebar-collapsed');
                    if (toggleIcon) {
                        toggleIcon.classList.remove('fa-chevron-left');
                        toggleIcon.classList.add('fa-chevron-right');
                    }
                }
                
                toggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    body.classList.toggle('sidebar-collapsed');
                    if (sidebar.classList.contains('collapsed')) {
                        if (toggleIcon) {
                            toggleIcon.classList.remove('fa-chevron-left');
                            toggleIcon.classList.add('fa-chevron-right');
                        }
                    } else {
                        if (toggleIcon) {
                            toggleIcon.classList.remove('fa-chevron-right');
                            toggleIcon.classList.add('fa-chevron-left');
                        }
                    }
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                });
            }

            const qualFilter = document.getElementById('qualificationFilter');
            if (qualFilter) {
                qualFilter.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            }

            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        document.getElementById('filterForm').submit();
                    }, 500);
                });
            }

            setTimeout(initWebSocket, 1000);
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                const toggleBtn = document.getElementById('sidebarToggle');
                if (toggleBtn) toggleBtn.click();
            }
        });
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