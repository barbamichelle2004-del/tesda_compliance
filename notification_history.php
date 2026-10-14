<?php
ob_start();

// Error reporting - log only, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/services/EligibilityService.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$db = $pdo;

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
// RBAC: Get trainer qualification IDs
// ==========================================
$is_trainer = isTrainer();
$is_coordinator = isCoordinator();
$trainer_qualification_ids = [];

if ($is_trainer) {
    $trainer_qualification_ids = getTrainerQualificationIds($user_id);
    $_SESSION['qualification_ids'] = $trainer_qualification_ids;
}

// Get filter parameters
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// Ensure notification_history table exists
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'notification_history'");
    if ($checkTable->rowCount() == 0) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainee_id INT NOT NULL,
                trainee_name VARCHAR(255),
                trainee_email VARCHAR(255),
                department VARCHAR(100),
                trainer_name VARCHAR(255),
                trainer_id INT,
                qualification_id VARCHAR(255),
                notification_type VARCHAR(50),
                channel VARCHAR(50),
                message TEXT,
                status VARCHAR(100),
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_trainee (trainee_id),
                INDEX idx_sent_at (sent_at),
                INDEX idx_status (status),
                INDEX idx_department (department)
            )
        ");
    }
} catch (PDOException $e) {
    error_log("Error creating notification history table: " . $e->getMessage());
}

// Handle delete notification request - Coordinator only
if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    try {
        $notification_id = (int)$_POST['notification_id'];
        
        if ($is_coordinator) {
            $delete_stmt = $db->prepare("DELETE FROM notification_history WHERE id = ?");
            $delete_stmt->execute([$notification_id]);
            $deleted = $delete_stmt->rowCount();
            
            if ($deleted > 0) {
                send_json([
                    'success' => true,
                    'message' => 'Notification deleted successfully',
                    'deleted_id' => $notification_id,
                    'redirect' => 'notification_history.php'
                ]);
            } else {
                send_json(['success' => false, 'message' => 'Notification not found or already deleted']);
            }
        } else {
            send_json(['success' => false, 'message' => 'Permission denied. Only coordinators can delete notifications.']);
        }
    } catch (PDOException $e) {
        error_log("Error deleting notification: " . $e->getMessage());
        send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// Handle delete all notifications - Coordinator only
if (isset($_POST['delete_all_notifications'])) {
    try {
        if ($is_coordinator) {
            $stmt = $db->prepare("DELETE FROM notification_history");
            $stmt->execute();
            $deleted_count = $stmt->rowCount();
            
            try {
                $db->exec("ALTER TABLE notification_history AUTO_INCREMENT = 1");
            } catch (PDOException $e) {}
            
            send_json([
                'success' => true,
                'message' => "All notifications deleted successfully ({$deleted_count} records)",
                'deleted_count' => $deleted_count,
                'redirect' => 'notification_history.php'
            ]);
        } else {
            send_json(['success' => false, 'message' => 'Permission denied. Only coordinators can delete all notifications.']);
        }
    } catch (PDOException $e) {
        error_log("Error deleting all notifications: " . $e->getMessage());
        send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// Department mapping
$department_mapping = [
    'CSS' => ['name' => 'Computer Systems Servicing', 'color' => '#10b981', 'icon' => 'fa-laptop-code'],
    'ATS' => ['name' => 'Automotive Mechanics', 'color' => '#f59e0b', 'icon' => 'fa-cogs'],
    'HRS' => ['name' => 'Hotel and Restaurant Services', 'color' => '#8b5cf6', 'icon' => 'fa-hotel']
];

// Notification TYPE labels + icons (for display)
$type_labels = [
    'regular_reminder' => ['label' => 'Reminder', 'color' => '#f59e0b', 'icon' => 'fa-bell'],
    'completion_alert' => ['label' => 'Completion Alert', 'color' => '#10b981', 'icon' => 'fa-check-double'],
    'pre_assessment' => ['label' => 'Pre-Assessment', 'color' => '#8b5cf6', 'icon' => 'fa-clipboard-check'],
    'assessment_ready' => ['label' => 'Assessment Ready', 'color' => '#3b82f6', 'icon' => 'fa-graduation-cap'],
    'system_notification' => ['label' => 'System', 'color' => '#64748b', 'icon' => 'fa-cog'],
    'manual_notification' => ['label' => 'Manual', 'color' => '#0ea5e9', 'icon' => 'fa-paper-plane'],
];

// Get unique subjects/departments for filter
$departments = $db->query("
    SELECT DISTINCT course_code, course_name
    FROM courses
    ORDER BY course_code
")->fetchAll();

// ==========================================
// Build query — includes qualification info for hierarchy grouping
// ==========================================
$query = "
    SELECT 
        nh.id,
        nh.trainee_id,
        nh.trainee_name,
        nh.trainee_email,
        nh.department,
        nh.trainer_name,
        nh.trainer_id,
        nh.qualification_id AS nh_qualification_id,
        nh.notification_type,
        nh.channel,
        nh.message,
        nh.status,
        nh.sent_at,
        COALESCE(t.first_name, '') as first_name,
        COALESCE(t.last_name, '') as last_name,
        COALESCE(t.email, nh.trainee_email) as trainee_email,
        COALESCE(t.qualification_id, nh.qualification_id) as qualification_id,
        COALESCE(c.course_code, nh.department) as dept_code,
        COALESCE(c.course_name, nh.department) as dept_name,
        q.qualification_code,
        q.qualification_name
    FROM notification_history nh
    LEFT JOIN trainees t ON nh.trainee_id = t.id
    LEFT JOIN courses c ON t.department = c.course_code
    LEFT JOIN qualifications q ON COALESCE(t.qualification_id, nh.qualification_id) = q.id
    WHERE 1=1
";

$params = [];

// RBAC: Trainer qualification filter
if ($is_trainer && !$is_coordinator) {
    if (!empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $query .= " AND (t.qualification_id IN ($placeholders) OR nh.trainee_id IS NULL)";
        $params = array_merge($params, $trainer_qualification_ids);
    } else {
        $query .= " AND 1=0";
    }
}

if (!empty($department_filter)) {
    $query .= " AND (c.course_code = ? OR nh.department LIKE ?)";
    $params[] = $department_filter;
    $params[] = "%$department_filter%";
}

if (!empty($date_from)) {
    $query .= " AND DATE(nh.sent_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(nh.sent_at) <= ?";
    $params[] = $date_to;
}

// Filter by notification TYPE
if (!empty($type_filter) && $type_filter != 'all') {
    $query .= " AND nh.notification_type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY nh.sent_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// ==========================================
// Calculate statistics
// ==========================================
$total_notifications_filtered = count($notifications);
$email_sent_count = 0;
$sms_sent_count = 0;
$failed_count_filtered = 0;

foreach ($notifications as $n) {
    $statusText = $n['status'] ?? '';
    $channelText = strtolower($n['channel'] ?? '');
    $combined = strtolower($statusText . ' ' . $channelText);

    if (strpos($statusText, 'Failed') !== false 
        || strpos($statusText, 'Wrong') !== false 
        || strpos($statusText, 'Error') !== false) {
        $failed_count_filtered++;
        continue;
    }

    $isEmail = (strpos($channelText, 'email') !== false) || (strpos($combined, 'email') !== false);
    $isSms   = (strpos($channelText, 'sms') !== false)   || (strpos($combined, 'sms') !== false);

    if ($isEmail) $email_sent_count++;
    if ($isSms)   $sms_sent_count++;

    if (!$isEmail && !$isSms) {
        if (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false) {
            $email_sent_count++;
        } else {
            $failed_count_filtered++;
        }
    }
}

// ==========================================
// Group notifications: Department → Qualification → Trainee records
// ==========================================
$hierarchy = [];
foreach ($notifications as $n) {
    $dept_code = $n['dept_code'] ?? 'Other';
    $qual_id   = $n['qualification_id'] ?? 0;
    $qual_code = $n['qualification_code'] ?? 'N/A';
    $qual_name = $n['qualification_name'] ?? 'Unassigned Qualification';

    if (!isset($department_mapping[$dept_code])) {
        $department_mapping[$dept_code] = [
            'name' => $n['dept_name'] ?? $dept_code,
            'color' => '#64748b',
            'icon' => 'fa-building'
        ];
    }

    if (!isset($hierarchy[$dept_code])) {
        $hierarchy[$dept_code] = [
            'dept_code' => $dept_code,
            'dept_name' => $department_mapping[$dept_code]['name'] ?? $dept_code,
            'dept_color' => $department_mapping[$dept_code]['color'] ?? '#64748b',
            'dept_icon' => $department_mapping[$dept_code]['icon'] ?? 'fa-building',
            'qualifications' => []
        ];
    }

    $qual_key = $qual_id . '|' . $qual_code;
    if (!isset($hierarchy[$dept_code]['qualifications'][$qual_key])) {
        $hierarchy[$dept_code]['qualifications'][$qual_key] = [
            'qualification_id' => $qual_id,
            'qualification_code' => $qual_code,
            'qualification_name' => $qual_name,
            'notifications' => []
        ];
    }

    $hierarchy[$dept_code]['qualifications'][$qual_key]['notifications'][] = $n;
}

function cleanMessageText($html) {
    if (empty($html)) return 'No message';
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    return $text;
}

function getNotificationTypeInfo($type, $type_labels) {
    if (isset($type_labels[$type])) {
        return $type_labels[$type];
    }
    return [
        'label' => ucwords(str_replace('_', ' ', $type ?: 'Notification')),
        'color' => '#64748b',
        'icon'  => 'fa-bell'
    ];
}

// Get notification for delete
$delete_notification_id = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
$delete_notification_name = '';
if ($delete_notification_id > 0) {
    $stmt = $db->prepare("SELECT trainee_name FROM notification_history WHERE id = ?");
    $stmt->execute([$delete_notification_id]);
    $del_result = $stmt->fetch();
    if ($del_result) {
        $delete_notification_name = $del_result['trainee_name'] ?? 'Notification';
    }
}

// Build query string for cancel link
$query_string = '';
if ($department_filter) $query_string .= 'department=' . urlencode($department_filter) . '&';
if ($type_filter) $query_string .= 'type=' . urlencode($type_filter) . '&';
if ($date_from) $query_string .= 'date_from=' . urlencode($date_from) . '&';
if ($date_to) $query_string .= 'date_to=' . urlencode($date_to) . '&';
$query_string = rtrim($query_string, '&');

// Check if delete all modal should show
$show_delete_all_modal = isset($_GET['delete_all']) && $_GET['delete_all'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification History - TESDA Compliance System</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
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
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            transition: margin-left 0.3s ease;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        .header {
            background: white;
            border-radius: 24px;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .header-left h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #000000;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left h1 i {
            color: #000000;
            font-size: 1.6rem;
        }

        .header-left p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 4px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .role-badge {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 10px 24px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }

        .role-badge i {
            font-size: 1.5rem;
            color: #1e3a8a;
        }

        .role-badge .role-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
            letter-spacing: 0.3px;
        }

        .date-badge {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 10px 24px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
        }

        .date-badge i {
            font-size: 1rem;
            color: #1e293b;
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
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-top: 4px solid;
            transition: all 0.3s ease;
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .stat-card.total { border-top-color: #3b82f6; }
        .stat-card.total h3 { color: #3b82f6; }
        .stat-card.email { border-top-color: #10b981; }
        .stat-card.email h3 { color: #10b981; }
        .stat-card.sms { border-top-color: #f59e0b; }
        .stat-card.sms h3 { color: #f59e0b; }
        .stat-card.failed { border-top-color: #ef4444; }
        .stat-card.failed h3 { color: #ef4444; }

        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 160px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: #4a5568;
            font-weight: 500;
            font-size: 0.75rem;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            background: white;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .btn-reset {
            background: #e2e8f0;
            color: #4a5568;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-reset:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }

        .btn-delete-all {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-delete-all:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .btn-delete-all:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .dept-notification-section {
            background: white;
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .dept-section-header {
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #f8fafc;
            border-bottom: 2px solid;
        }

        .dept-section-header h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dept-section-header h2 i {
            font-size: 1.5rem;
        }

        .dept-stats {
            display: flex;
            gap: 15px;
            font-size: 0.75rem;
            flex-wrap: wrap;
        }

        .dept-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            background: white;
            border-radius: 20px;
            font-weight: 500;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .qual-block {
            border-bottom: 1px solid #e8edf5;
        }

        .qual-block:last-child {
            border-bottom: none;
        }

        .qual-header {
            padding: 14px 24px 14px 40px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            background: #fafbfc;
            transition: background 0.2s ease;
        }

        .qual-header:hover {
            background: #f1f5f9;
        }

        .qual-header .qual-icon {
            color: #8b5cf6;
            font-size: 0.9rem;
        }

        .qual-header .qual-code {
            font-weight: 700;
            font-size: 0.9rem;
            color: #1e293b;
        }

        .qual-header .qual-name {
            font-weight: 500;
            font-size: 0.82rem;
            color: #7c3aed;
            background: #ede9fe;
            padding: 3px 12px;
            border-radius: 20px;
        }

        .qual-header .qual-count {
            margin-left: auto;
            font-size: 0.72rem;
            color: #64748b;
            background: #f1f5f9;
            padding: 3px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .qual-header .toggle-arrow {
            color: #94a3b8;
            font-size: 0.85rem;
            transition: transform 0.3s ease;
            width: 22px;
            text-align: center;
        }

        .qual-header .toggle-arrow.open {
            transform: rotate(180deg);
        }

        .qual-body {
            overflow: hidden;
            max-height: 0;
            transition: max-height 0.35s ease;
        }

        .qual-body.open {
            max-height: 5000px;
        }

        .table-container {
            overflow-x: auto;
            padding: 0 24px 16px 40px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 12px;
            border: 1px solid #eef2f6;
            overflow: hidden;
        }

        th {
            text-align: left;
            padding: 10px 15px;
            color: #64748b;
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eef2f6;
            font-size: 0.82rem;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #fafbfc;
        }

        .delivery-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.68rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin: 2px;
        }

        .delivery-badge.email {
            background: #dbeafe;
            color: #1e40af;
        }

        .delivery-badge.sms {
            background: #fef3c7;
            color: #92400e;
        }

        .delivery-badge.both {
            background: #ede9fe;
            color: #5b21b6;
        }

        .delivery-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-view {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.3s ease;
        }

        .btn-view:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .btn-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
            background: white;
            border-radius: 16px;
            margin-bottom: 30px;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 15px;
        }

        .floating-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            z-index: 10000;
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }

        .floating-message.error {
            background: #ef4444;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .delete-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .delete-modal-overlay.active {
            display: flex;
        }

        .delete-modal {
            background: white;
            border-radius: 20px;
            padding: 32px 36px 28px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
            animation: modalFadeIn 0.2s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .delete-modal .modal-icon {
            width: 60px;
            height: 60px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .delete-modal .modal-icon i {
            font-size: 1.8rem;
            color: #ef4444;
        }

        .delete-modal h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .delete-modal .modal-subtitle {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 400;
        }

        .delete-modal .item-name {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 12px 0 20px;
            padding: 10px 16px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            display: inline-block;
        }

        .delete-modal .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .delete-modal .btn-cancel {
            background: #e2e8f0;
            color: #4a5568;
            border: none;
            padding: 10px 32px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 100px;
            text-decoration: none;
            display: inline-block;
        }

        .delete-modal .btn-cancel:hover {
            background: #cbd5e1;
        }

        .delete-modal .btn-confirm-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 32px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 100px;
        }

        .delete-modal .btn-confirm-delete:hover {
            background: #dc2626;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .close-modal:hover {
            color: #ef4444;
        }

        .modal-message {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 400px;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #1e293b;
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46a0);
        }

        @media (max-width: 1200px) {
            .main-content { margin-left: 80px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 16px; }
            .header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .header-right { width: 100%; flex-wrap: wrap; }
            .filter-form { flex-direction: column; }
            .filter-group { min-width: 100%; }
            .stats-grid { grid-template-columns: 1fr; }
            .dept-section-header { flex-direction: column; align-items: flex-start; }
            .table-container { padding: 0 12px 12px 12px; }
            .delete-modal { padding: 24px 20px 20px; }
            .delete-modal .modal-actions { flex-direction: column; align-items: center; }
            .delete-modal .btn-cancel,
            .delete-modal .btn-confirm-delete { width: 100%; min-width: unset; }
        }
    </style>

<link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1>
                    <i class="fas fa-bell"></i> 
                    Notification History
                </h1>
                <p>View all sent notifications and their delivery status</p>
                <?php if ($is_trainer && !empty($user_department)): ?>
                <span style="font-size: 0.75rem; background: #e2e8f0; padding: 2px 10px; border-radius: 20px; margin-left: 10px; display: inline-block; margin-top: 5px;">
                    <i class="fas fa-building"></i> <?= htmlspecialchars($department_mapping[$user_department]['name'] ?? $user_department) ?> Department
                </span>
                <?php endif; ?>
            </div>
            <div class="header-right">
                <div class="role-badge">
                    <i class="fas fa-user-circle"></i>
                    <span class="role-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <div class="date-badge">
                    <i class="fas fa-calendar-alt"></i>
                    <?= date('F j, Y') ?>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card total">
                <h3 id="statTotal"><?= $total_notifications_filtered ?></h3>
                <p><i class="fas fa-paper-plane"></i> Total Notifications</p>
            </div>
            <div class="stat-card email">
                <h3 id="statEmail"><?= $email_sent_count ?></h3>
                <p><i class="fas fa-envelope"></i> Email Delivered</p>
            </div>
            <div class="stat-card sms">
                <h3 id="statSms"><?= $sms_sent_count ?></h3>
                <p><i class="fas fa-phone"></i> SMS Delivered</p>
            </div>
            <div class="stat-card failed">
                <h3 id="statFailed"><?= $failed_count_filtered ?></h3>
                <p><i class="fas fa-exclamation-circle"></i> Failed</p>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Department</label>
                    <select name="department" id="filterDepartment">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['course_code']) ?>" <?= ($department_filter == $dept['course_code']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['course_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-tag"></i> Type</label>
                    <select name="type" id="filterType">
                        <option value="all" <?= ($type_filter == 'all' || empty($type_filter)) ? 'selected' : '' ?>>All Types</option>
                        <option value="regular_reminder" <?= ($type_filter == 'regular_reminder') ? 'selected' : '' ?>>Reminder</option>
                        <option value="completion_alert" <?= ($type_filter == 'completion_alert') ? 'selected' : '' ?>>Completion Alert</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" name="date_from" id="filterDateFrom" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" name="date_to" id="filterDateTo" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="filter-group filter-actions">
                    <label>&nbsp;</label>
                    <div style="display: flex; gap: 10px;">
                        <button type="reset" class="btn-reset" id="resetFiltersBtn">
                            <i class="fas fa-undo-alt"></i> Reset
                        </button>
                        <?php if ($is_coordinator && $total_notifications_filtered > 0): ?>
                        <button type="button" class="btn-delete-all" onclick="openDeleteAllModal()">
                            <i class="fas fa-trash-alt"></i> Delete All
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <?php if (empty($hierarchy)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h3>No notifications found</h3>
                <p>Send a reminder from the Evaluation module to see notifications here.</p>
                <div style="margin-top: 20px;">
                    <a href="evaluation.php" class="btn-view" style="text-decoration: none; display: inline-block;">
                        <i class="fas fa-arrow-left"></i> Go to Evaluation
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($hierarchy as $dept_code => $dept_data): 
                $dept_total = 0; $dept_email = 0; $dept_sms = 0; $dept_failed = 0;
                foreach ($dept_data['qualifications'] as $qd) {
                    foreach ($qd['notifications'] as $n) {
                        $dept_total++;
                        $statusText = $n['status'] ?? '';
                        $channelText = strtolower($n['channel'] ?? '');
                        $combined = strtolower($statusText . ' ' . $channelText);

                        if (strpos($statusText, 'Failed') !== false || strpos($statusText, 'Wrong') !== false || strpos($statusText, 'Error') !== false) {
                            $dept_failed++;
                            continue;
                        }
                        $isEmail = (strpos($channelText, 'email') !== false) || (strpos($combined, 'email') !== false);
                        $isSms   = (strpos($channelText, 'sms') !== false)   || (strpos($combined, 'sms') !== false);
                        if ($isEmail) $dept_email++;
                        if ($isSms)   $dept_sms++;
                        if (!$isEmail && !$isSms) {
                            if (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false) {
                                $dept_email++;
                            } else {
                                $dept_failed++;
                            }
                        }
                    }
                }
            ?>
            <div class="dept-notification-section">
                <div class="dept-section-header" style="border-bottom-color: <?= $dept_data['dept_color'] ?>;">
                    <h2>
                        <i class="fas <?= $dept_data['dept_icon'] ?>" style="color: <?= $dept_data['dept_color'] ?>;"></i>
                        <?= htmlspecialchars($dept_data['dept_name']) ?>
                        <span style="font-size: 0.7rem; background: rgba(0,0,0,0.05); padding: 3px 12px; border-radius: 20px; font-weight: 600; color: #64748b; margin-left: 6px;">
                            <?= htmlspecialchars($dept_code) ?>
                        </span>
                    </h2>
                    <div class="dept-stats">
                        <span><i class="fas fa-envelope" style="color: #10b981;"></i> <?= $dept_email ?> Email</span>
                        <span><i class="fas fa-phone" style="color: #f59e0b;"></i> <?= $dept_sms ?> SMS</span>
                        <span><i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> <?= $dept_failed ?> Failed</span>
                        <span><i class="fas fa-bell"></i> <?= $dept_total ?> Total</span>
                    </div>
                </div>

                <?php 
                $first_qual_shown = false;
                foreach ($dept_data['qualifications'] as $qual_key => $qual_data): 
                    $is_open = !$first_qual_shown;
                    $first_qual_shown = true;

                    $qual_total = count($qual_data['notifications']);
                    $unique_trainees = [];
                    foreach ($qual_data['notifications'] as $n) {
                        $unique_trainees[$n['trainee_id']] = true;
                    }
                    $unique_count = count($unique_trainees);
                ?>
                <div class="qual-block">
                    <div class="qual-header" onclick="toggleQualAccordion(this)">
                        <i class="fas fa-graduation-cap qual-icon"></i>
                        <span class="qual-code"><?= htmlspecialchars($qual_data['qualification_code']) ?></span>
                        <span class="qual-name"><?= htmlspecialchars($qual_data['qualification_name']) ?></span>
                        <span class="qual-count">
                            <?= $unique_count ?> trainee<?= $unique_count !== 1 ? 's' : '' ?> · <?= $qual_total ?> notification<?= $qual_total !== 1 ? 's' : '' ?>
                        </span>
                        <span class="toggle-arrow <?= $is_open ? 'open' : '' ?>">
                            <i class="fas fa-chevron-down"></i>
                        </span>
                    </div>

                    <div class="qual-body <?= $is_open ? 'open' : '' ?>">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Trainee</th>
                                        <th>Type</th>
                                        <th>Channel</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($qual_data['notifications'] as $n): 
                                        $statusText = $n['status'] ?? '';
                                        $channelText = $n['channel'] ?? '';
                                        $typeKey = $n['notification_type'] ?? '';

                                        $typeInfo = getNotificationTypeInfo($typeKey, $type_labels);

                                        $isFailed = (strpos($statusText, 'Failed') !== false 
                                                  || strpos($statusText, 'Wrong') !== false 
                                                  || strpos($statusText, 'Error') !== false);

                                        $channelLower = strtolower($channelText . ' ' . $statusText);
                                        $isEmail = strpos($channelLower, 'email') !== false;
                                        $isSms   = strpos($channelLower, 'sms') !== false;

                                        $cleanMessage = cleanMessageText($n['message'] ?? 'No message');
                                        $escapedFullMessage = htmlspecialchars($cleanMessage, ENT_QUOTES, 'UTF-8');
                                        $traineeName = htmlspecialchars(trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: ($n['trainee_name'] ?? 'Unknown'));
                                        $deleteUrl = '?delete_id=' . $n['id'];
                                        if ($query_string) $deleteUrl .= '&' . $query_string;
                                    ?>
                                    <tr>
                                        <td style="white-space: nowrap;">
                                            <?= date('M d, Y h:i A', strtotime($n['sent_at'])) ?>
                                        </td>
                                        <td>
                                            <strong><?= $traineeName ?></strong>
                                            <div style="font-size: 0.7rem; color: #64748b;">
                                                <?= htmlspecialchars($n['trainee_email'] ?? '-') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="type-badge" 
                                                  style="background: <?= htmlspecialchars($typeInfo['color']) ?>15; color: <?= htmlspecialchars($typeInfo['color']) ?>; border: 1px solid <?= htmlspecialchars($typeInfo['color']) ?>30;">
                                                <i class="fas <?= htmlspecialchars($typeInfo['icon']) ?>"></i>
                                                <?= htmlspecialchars($typeInfo['label']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isEmail && $isSms): ?>
                                                <span class="delivery-badge both">
                                                    <i class="fas fa-envelope-open-text"></i> Email & SMS
                                                </span>
                                            <?php elseif ($isSms): ?>
                                                <span class="delivery-badge sms">
                                                    <i class="fas fa-phone"></i> SMS
                                                </span>
                                            <?php elseif ($isEmail): ?>
                                                <span class="delivery-badge email">
                                                    <i class="fas fa-envelope"></i> Email
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size: 0.7rem; color: #94a3b8;">
                                                    <?= htmlspecialchars($channelText ?: 'N/A') ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-view" 
                                                        data-message="<?= $escapedFullMessage ?>" 
                                                        data-trainee="<?= $traineeName ?>" 
                                                        data-channel="<?= htmlspecialchars($channelText ?: 'N/A') ?>" 
                                                        data-status="<?= htmlspecialchars($statusText) ?>" 
                                                        data-type="<?= htmlspecialchars($typeInfo['label']) ?>"
                                                        data-time="<?= date('F j, Y h:i A', strtotime($n['sent_at'])) ?>"
                                                        onclick="viewFullDetails(this)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($is_coordinator): ?>
                                                <a href="<?= $deleteUrl ?>" class="btn-delete">
                                                    <i class="fas fa-trash-alt"></i> Delete
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="delete-modal-overlay <?= $delete_notification_id > 0 ? 'active' : '' ?>" id="deleteModalOverlay">
        <div class="delete-modal">
            <div class="modal-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h2>Delete Notification</h2>
            <p class="modal-subtitle">Are you sure you want to delete this notification?</p>
            <div class="item-name">
                <?= htmlspecialchars($delete_notification_name ?: 'Notification') ?>
            </div>
            <div class="modal-actions">
                <a href="?<?= $query_string ?>" class="btn-cancel">Cancel</a>
                <form method="POST" id="singleDeleteForm" style="display: inline;">
                    <input type="hidden" name="delete_notification" value="1">
                    <input type="hidden" name="notification_id" value="<?= $delete_notification_id ?>">
                    <button type="submit" class="btn-confirm-delete">Yes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="delete-modal-overlay" id="deleteAllModalOverlay">
        <div class="delete-modal">
            <div class="modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h2>Delete All Notifications</h2>
            <p class="modal-subtitle">Are you sure you want to delete all notifications?</p>
            <div class="item-name" style="background: #fef2f2; border-color: #fca5a5;">
                <i class="fas fa-trash-alt" style="color: #ef4444; margin-right: 8px;"></i>
                <?= $total_notifications_filtered ?> notification<?= $total_notifications_filtered != 1 ? 's' : '' ?> will be deleted
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeDeleteAllModal()">Cancel</button>
                <form method="POST" id="deleteAllForm" style="display: inline;">
                    <input type="hidden" name="delete_all_notifications" value="1">
                    <button type="submit" class="btn-confirm-delete">Yes</button>
                </form>
            </div>
        </div>
    </div>

    <div id="messageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope-open-text"></i> Notification Details</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <div id="modalDetailContent">
                <div style="margin-bottom: 12px;">
                    <strong>Recipient:</strong> <span id="modalTrainee"></span>
                </div>
                <div style="margin-bottom: 12px;">
                    <strong>Type:</strong> <span id="modalType"></span>
                </div>
                <div style="margin-bottom: 12px;">
                    <strong>Channel:</strong> <span id="modalChannel"></span>
                </div>
                <div style="margin-bottom: 12px;">
                    <strong>Status:</strong> <span id="modalStatus"></span>
                </div>
                <div style="margin-bottom: 12px;">
                    <strong>Sent At:</strong> <span id="modalTime"></span>
                </div>
                <div>
                    <strong>Full Message:</strong>
                    <div id="modalMessage" class="modal-message"></div>
                </div>
            </div>
            <div style="text-align: right; margin-top: 15px;">
                <button class="btn-view" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function toggleQualAccordion(headerEl) {
            var body = headerEl.nextElementSibling;
            var arrow = headerEl.querySelector('.toggle-arrow');
            if (!body) return;

            if (body.classList.contains('open')) {
                body.classList.remove('open');
                if (arrow) arrow.classList.remove('open');
            } else {
                body.classList.add('open');
                if (arrow) arrow.classList.add('open');
            }
        }

        function openDeleteAllModal() {
            var overlay = document.getElementById('deleteAllModalOverlay');
            if (overlay) {
                overlay.classList.add('active');
            }
        }

        function closeDeleteAllModal() {
            var overlay = document.getElementById('deleteAllModalOverlay');
            if (overlay) {
                overlay.classList.remove('active');
            }
        }

        function viewFullDetails(btn) {
            var modal = document.getElementById('messageModal');
            document.getElementById('modalTrainee').textContent = btn.dataset.trainee || 'N/A';
            document.getElementById('modalType').textContent = btn.dataset.type || 'N/A';
            document.getElementById('modalChannel').textContent = btn.dataset.channel || 'N/A';
            document.getElementById('modalStatus').textContent = btn.dataset.status || 'N/A';
            document.getElementById('modalTime').textContent = btn.dataset.time || 'N/A';
            var msg = btn.dataset.message || 'No message';
            document.getElementById('modalMessage').textContent = msg;
            modal.style.display = 'block';
        }

        function closeModal() {
            document.getElementById('messageModal').style.display = 'none';
        }

        function showFloatingMessage(message, type) {
            type = type || 'success';
            var existing = document.querySelector('.floating-message');
            if (existing) existing.remove();
            
            var msgDiv = document.createElement('div');
            msgDiv.className = 'floating-message ' + type;
            
            var iconMap = {
                'success': 'fa-check-circle',
                'error': 'fa-exclamation-circle'
            };
            
            var icon = iconMap[type] || 'fa-info-circle';
            msgDiv.innerHTML = '<i class="fas ' + icon + '"></i> ' + message;
            document.body.appendChild(msgDiv);
            
            setTimeout(function() {
                if (msgDiv.parentNode) {
                    msgDiv.parentNode.removeChild(msgDiv);
                }
            }, 5000);
        }

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

        document.addEventListener('DOMContentLoaded', function() {
            var singleDeleteForm = document.getElementById('singleDeleteForm');
            if (singleDeleteForm) {
                singleDeleteForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    var notificationId = this.querySelector('input[name="notification_id"]').value;
                    var submitBtn = this.querySelector('button[type="submit"]');
                    var originalText = submitBtn.innerHTML;
                    
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                    
                    var formData = new URLSearchParams();
                    formData.append('delete_notification', '1');
                    formData.append('notification_id', notificationId);
                    
                    safeAjax('notification_history.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData.toString()
                    })
                    .then(function(data) {
                        if (data.success) {
                            showFloatingMessage(data.message, 'success');
                            setTimeout(function() {
                                window.location.href = data.redirect || 'notification_history.php';
                            }, 800);
                        } else {
                            showFloatingMessage(data.message || 'Failed to delete', 'error');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }
                    })
                    .catch(function(error) {
                        console.error('Error Details:', error);
                        showFloatingMessage('Error: ' + error.message, 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    });
                });
            }
            
            var deleteAllForm = document.getElementById('deleteAllForm');
            if (deleteAllForm) {
                deleteAllForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    var submitBtn = this.querySelector('button[type="submit"]');
                    var originalText = submitBtn.innerHTML;
                    
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                    
                    var formData = new URLSearchParams();
                    formData.append('delete_all_notifications', '1');
                    
                    safeAjax('notification_history.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData.toString()
                    })
                    .then(function(data) {
                        if (data.success) {
                            showFloatingMessage(data.message, 'success');
                            setTimeout(function() {
                                window.location.href = data.redirect || 'notification_history.php';
                            }, 800);
                        } else {
                            showFloatingMessage(data.message || 'Failed to delete all', 'error');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }
                    })
                    .catch(function(error) {
                        console.error('Error Details:', error);
                        showFloatingMessage('Error: ' + error.message, 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    });
                });
            }
        });

        window.onclick = function(event) {
            var modal = document.getElementById('messageModal');
            if (event.target == modal) closeModal();
            
            var deleteOverlay = document.getElementById('deleteModalOverlay');
            if (event.target == deleteOverlay && deleteOverlay.classList.contains('active')) {
                var url = new URL(window.location.href);
                url.searchParams.delete('delete_id');
                window.location.href = url.toString();
            }
            
            var deleteAllOverlay = document.getElementById('deleteAllModalOverlay');
            if (event.target == deleteAllOverlay && deleteAllOverlay.classList.contains('active')) {
                closeDeleteAllModal();
            }
        };
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                
                var deleteOverlay = document.getElementById('deleteModalOverlay');
                if (deleteOverlay.classList.contains('active')) {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('delete_id');
                    window.location.href = url.toString();
                }
                
                var deleteAllOverlay = document.getElementById('deleteAllModalOverlay');
                if (deleteAllOverlay.classList.contains('active')) {
                    closeDeleteAllModal();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            var filterElements = document.querySelectorAll('#filterDepartment, #filterType, #filterDateFrom, #filterDateTo');
            filterElements.forEach(function(element) {
                element.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
        });

        document.getElementById('resetFiltersBtn').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('filterDepartment').value = '';
            document.getElementById('filterType').value = 'all';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterForm').submit();
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