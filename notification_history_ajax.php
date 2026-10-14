<?php
// notification_history.php - View notification history
// FIXED: Complete rewrite with proper query structure and accurate statistics
// UPDATED: Added Delete All functionality and clean message preview

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/services/EligibilityService.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$db = $pdo;

// Get user role and department from session
$user_role = $_SESSION['role'] ?? '';
$user_department = $_SESSION['department'] ?? '';

// Get filter parameters
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$subject_filter = isset($_GET['subject']) ? $_GET['subject'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

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
                notification_type VARCHAR(50),
                channel VARCHAR(50),
                message TEXT,
                status VARCHAR(100),
                error_details TEXT,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_trainee (trainee_id),
                INDEX idx_sent_at (sent_at),
                INDEX idx_status (status),
                INDEX idx_department (department)
            )
        ");
    } else {
        // Ensure 'channel' column exists
        $checkCol = $db->query("SHOW COLUMNS FROM notification_history LIKE 'channel'");
        if ($checkCol->rowCount() == 0) {
            $db->exec("ALTER TABLE notification_history ADD COLUMN channel VARCHAR(50) DEFAULT NULL AFTER notification_type");
        }
        // Ensure 'trainee_email' column exists
        $checkCol2 = $db->query("SHOW COLUMNS FROM notification_history LIKE 'trainee_email'");
        if ($checkCol2->rowCount() == 0) {
            $db->exec("ALTER TABLE notification_history ADD COLUMN trainee_email VARCHAR(255) DEFAULT NULL AFTER trainee_name");
        }
        // Ensure 'trainer_id' column exists
        $checkCol3 = $db->query("SHOW COLUMNS FROM notification_history LIKE 'trainer_id'");
        if ($checkCol3->rowCount() == 0) {
            $db->exec("ALTER TABLE notification_history ADD COLUMN trainer_id INT DEFAULT NULL AFTER trainer_name");
        }
    }
} catch (PDOException $e) {
    error_log("Error creating notification history table: " . $e->getMessage());
}

// Handle delete notification request - Coordinator only
if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    header('Content-Type: application/json');
    try {
        $notification_id = (int)$_POST['notification_id'];
        
        if ($user_role === 'coordinator') {
            $delete_stmt = $db->prepare("DELETE FROM notification_history WHERE id = ?");
            $delete_stmt->execute([$notification_id]);
            
            echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Permission denied. Only coordinators can delete notifications.']);
        }
    } catch (PDOException $e) {
        error_log("Error deleting notification: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle delete all notifications - Coordinator only
if (isset($_POST['delete_all_notifications'])) {
    header('Content-Type: application/json');
    try {
        if ($user_role === 'coordinator') {
            // Use TRUNCATE for better performance (resets auto-increment)
            $db->exec("TRUNCATE TABLE notification_history");
            
            echo json_encode(['success' => true, 'message' => 'All notifications deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Permission denied. Only coordinators can delete all notifications.']);
        }
    } catch (PDOException $e) {
        error_log("Error deleting all notifications: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Department mapping
$department_mapping = [
    'CSS' => ['name' => 'Computer Systems Servicing', 'color' => '#10b981', 'icon' => 'fa-laptop-code'],
    'ATS' => ['name' => 'Automotive Mechanics', 'color' => '#f59e0b', 'icon' => 'fa-cogs'],
    'HRS' => ['name' => 'Hotel and Restaurant Services', 'color' => '#8b5cf6', 'icon' => 'fa-hotel']
];

// Get all trainees for filter dropdown
$trainees = $db->query("
    SELECT t.id, t.first_name, t.last_name, t.department
    FROM trainees t
    ORDER BY t.last_name
")->fetchAll();

// Get unique subjects/departments for filter
$departments = $db->query("
    SELECT DISTINCT course_code, course_name
    FROM courses
    ORDER BY course_code
")->fetchAll();

// ==========================================
// FIXED: Build query with LEFT JOIN to show all notifications
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
        nh.notification_type,
        nh.channel,
        nh.message,
        nh.status,
        nh.sent_at,
        COALESCE(t.first_name, '') as first_name,
        COALESCE(t.last_name, '') as last_name,
        COALESCE(t.email, nh.trainee_email) as trainee_email,
        COALESCE(c.course_code, nh.department) as dept_code,
        COALESCE(c.course_name, nh.department) as dept_name
    FROM notification_history nh
    LEFT JOIN trainees t ON nh.trainee_id = t.id
    LEFT JOIN courses c ON t.department = c.course_code
    WHERE 1=1
";

$params = [];

if (!empty($department_filter)) {
    $query .= " AND (c.course_code = ? OR nh.department LIKE ?)";
    $params[] = $department_filter;
    $params[] = "%$department_filter%";
}

if (!empty($subject_filter)) {
    $query .= " AND (c.course_name LIKE ? OR nh.department LIKE ?)";
    $params[] = "%$subject_filter%";
    $params[] = "%$subject_filter%";
}

if (!empty($date_from)) {
    $query .= " AND DATE(nh.sent_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(nh.sent_at) <= ?";
    $params[] = $date_to;
}

if (!empty($status_filter) && $status_filter != 'all') {
    if ($status_filter === 'delivered') {
        $query .= " AND (nh.status LIKE '%Delivered%' OR nh.status LIKE '%Sent%' OR nh.status LIKE '%Success%')";
        $query .= " AND nh.status NOT LIKE '%Failed%'";
    } elseif ($status_filter === 'failed') {
        $query .= " AND (nh.status LIKE '%Failed%' OR nh.status LIKE '%Wrong%' OR nh.status LIKE '%Error%')";
    }
}

// For trainer role, only show notifications from their department
if ($user_role === 'trainer' && !empty($user_department)) {
    $query .= " AND (c.course_code = ? OR nh.department LIKE ?)";
    $params[] = $user_department;
    $params[] = "%$user_department%";
}

$query .= " ORDER BY nh.sent_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// ==========================================
// FIXED: Calculate statistics directly from filtered results
// ==========================================
$total_notifications_filtered = count($notifications);
$sent_count_filtered = 0;
$failed_count_filtered = 0;

foreach ($notifications as $n) {
    $statusText = $n['status'] ?? '';
    if (strpos($statusText, 'Failed') !== false || strpos($statusText, 'Wrong') !== false || strpos($statusText, 'Error') !== false) {
        $failed_count_filtered++;
    } elseif (strpos($statusText, 'Email') !== false && (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false)) {
        $sent_count_filtered++;
    } elseif (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false) {
        $sent_count_filtered++;
    } else {
        $failed_count_filtered++;
    }
}

// Group notifications by department
$grouped_notifications = [];
foreach ($notifications as $n) {
    $dept = $n['dept_code'] ?? 'Other';
    if (!isset($grouped_notifications[$dept])) {
        $grouped_notifications[$dept] = [];
    }
    $grouped_notifications[$dept][] = $n;
}

// Function to clean HTML content and return plain text
function cleanMessageText($html) {
    if (empty($html)) {
        return 'No message';
    }
    
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    return $text;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification History - TESDA Compliance System</title>
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

        .header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.6rem;
        }

        .header p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 4px;
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
            color: #3b82f6;
        }

        .role-badge .role-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
            letter-spacing: 0.3px;
        }

        /* ============================================================ */
        /* FIXED: Filter action buttons — single line + no stretch      */
        /* ============================================================ */
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
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
            height: 42px;
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
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
            height: 42px;
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

        /* ============================================================ */
        /* FIXED: Filter actions container — natural width, no stretch  */
        /* ============================================================ */
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex: 0 0 auto;
            min-width: auto;
            max-width: fit-content;
        }

        .stats-grid {
            display: flex;
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
            flex: 1;
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
        .stat-card.sent { border-top-color: #10b981; }
        .stat-card.sent h3 { color: #10b981; }
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
            min-width: 180px;
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

        .search-input {
            position: relative;
        }
        
        .search-input i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.85rem;
        }
        
        .search-input input {
            padding-left: 35px;
        }

        .dept-notification-section {
            background: white;
            border-radius: 16px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .dept-section-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #fafbfc;
        }

        .dept-section-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dept-section-header h2 i {
            font-size: 1.1rem;
        }

        .dept-badge {
            background: #e2e8f0;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            color: #475569;
        }

        .dept-stats {
            display: flex;
            gap: 15px;
            font-size: 0.7rem;
        }

        .dept-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 15px;
            color: #64748b;
            font-weight: 600;
            font-size: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-delivered {
            background: #d1fae5;
            color: #065f46;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .delivery-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin: 2px;
        }
        
        .delivery-badge.email {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .delivery-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .type-reminder {
            background: #dbeafe;
            color: #1e40af;
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
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
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
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
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

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .confirm-modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
        }

        .confirm-modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .confirm-modal-content .icon-warning {
            font-size: 3rem;
            color: #ef4444;
            margin-bottom: 15px;
        }

        .confirm-modal-content h3 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 10px;
        }

        .confirm-modal-content p {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }

        .confirm-modal-content .confirm-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .confirm-modal-content .btn-confirm-yes {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .confirm-modal-content .btn-confirm-yes:hover {
            background: #dc2626;
        }

        .confirm-modal-content .btn-confirm-no {
            background: #e2e8f0;
            color: #4a5568;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .confirm-modal-content .btn-confirm-no:hover {
            background: #cbd5e1;
        }

        @media (max-width: 1200px) {
            .main-content {
                margin-left: 80px;
            }
            .stats-grid {
                flex-direction: column;
            }
        }
    </style>

    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1><i class="fas fa-bell"></i> Notification History</h1>
                <p>View all sent notifications and their delivery status</p>
                <?php if ($user_role === 'trainer' && !empty($user_department)): ?>
                <span style="font-size: 0.75rem; background: #e2e8f0; padding: 2px 10px; border-radius: 20px; margin-left: 10px;">
                    <i class="fas fa-building"></i> <?= htmlspecialchars($department_mapping[$user_department]['name'] ?? $user_department) ?> Department
                </span>
                <?php endif; ?>
            </div>
            <div class="role-badge">
                <i class="fas fa-user-circle"></i>
                <span class="role-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card total">
                <h3 id="statTotal"><?= $total_notifications_filtered ?></h3>
                <p><i class="fas fa-paper-plane"></i> Total Notifications</p>
            </div>
            <div class="stat-card sent">
                <h3 id="statSent"><?= $sent_count_filtered ?></h3>
                <p><i class="fas fa-check-circle"></i> Email Delivered</p>
            </div>
            <div class="stat-card failed">
                <h3 id="statFailed"><?= $failed_count_filtered ?></h3>
                <p><i class="fas fa-exclamation-circle"></i> Failed</p>
            </div>
        </div>

        <div class="filter-section">
            <div class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Department</label>
                    <select id="filterDepartment">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['course_code']) ?>"><?= htmlspecialchars($dept['course_name']) ?> (<?= htmlspecialchars($dept['course_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-book"></i> Subject</label>
                    <div class="search-input">
                        <i class="fas fa-search"></i>
                        <input type="text" id="filterSubject" placeholder="Search by subject name...">
                    </div>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-chart-line"></i> Status</label>
                    <select id="filterStatus">
                        <option value="all">All Status</option>
                        <option value="delivered">Email Delivered</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" id="filterDateFrom">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" id="filterDateTo">
                </div>
                <div class="filter-actions">
                    <button class="btn-reset" id="resetFiltersBtn">
                        <i class="fas fa-undo-alt"></i> Reset
                    </button>
                    <?php if ($user_role === 'coordinator'): ?>
                    <button class="btn-delete-all" id="deleteAllBtn" <?= $total_notifications_filtered == 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-trash-alt"></i> Delete All
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (empty($grouped_notifications)): ?>
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
            <?php foreach ($grouped_notifications as $dept_code => $dept_notifications): 
                $dept_info = $department_mapping[$dept_code] ?? ['name' => $dept_code, 'color' => '#64748b', 'icon' => 'fa-building'];
                $dept_name = $dept_info['name'];
                $dept_color = $dept_info['color'];
                $dept_icon = $dept_info['icon'];
                $dept_sent = 0;
                $dept_failed = 0;
                foreach ($dept_notifications as $n) {
                    $statusText = $n['status'];
                    if (strpos($statusText, 'Failed') !== false || strpos($statusText, 'Wrong') !== false || strpos($statusText, 'Error') !== false) {
                        $dept_failed++;
                    } elseif (strpos($statusText, 'Email') !== false && (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false)) {
                        $dept_sent++;
                    } elseif (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false) {
                        $dept_sent++;
                    } else {
                        $dept_failed++;
                    }
                }
            ?>
            <div class="dept-notification-section" data-dept="<?= $dept_code ?>">
                <div class="dept-section-header" style="border-left: 4px solid <?= $dept_color ?>;">
                    <h2>
                        <i class="fas <?= $dept_icon ?>" style="color: <?= $dept_color ?>;"></i>
                        <?= htmlspecialchars($dept_name) ?>
                    </h2>
                    <div class="dept-stats">
                        <span><i class="fas fa-check-circle" style="color: #10b981;"></i> <?= $dept_sent ?> Email Delivered</span>
                        <span><i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> <?= $dept_failed ?> Failed</span>
                        <span><i class="fas fa-bell"></i> <?= count($dept_notifications) ?> Total</span>
                    </div>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Trainee</th>
                                <th>Type</th>
                                <th>Message</th>
                                <th>Delivery Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dept_notifications as $n): 
                                $statusText = $n['status'];
                                $isFailed = (strpos($statusText, 'Failed') !== false || strpos($statusText, 'Wrong') !== false || strpos($statusText, 'Error') !== false);
                                $isEmailDelivered = (strpos($statusText, 'Email') !== false && (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false)) ||
                                                    (strpos($statusText, 'Delivered') !== false && strpos($statusText, 'Email') !== false);
                                
                                if (!$isEmailDelivered && !$isFailed) {
                                    if (strpos($statusText, 'Delivered') !== false || strpos($statusText, 'Sent') !== false) {
                                        $isEmailDelivered = true;
                                    }
                                }
                                
                                $cleanMessage = cleanMessageText($n['message'] ?? 'No message');
                                $previewMessage = strlen($cleanMessage) > 80 ? mb_substr($cleanMessage, 0, 80) . '...' : $cleanMessage;
                                $escapedFullMessage = addslashes($cleanMessage);
                            ?>
                            <tr>
                                <td style="white-space: nowrap;"><?= date('M d, Y h:i A', strtotime($n['sent_at'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($n['first_name'] . ' ' . $n['last_name']) ?></strong>
                                    <div style="font-size: 0.7rem; color: #64748b;"><?= htmlspecialchars($n['trainee_email'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <span class="type-badge type-reminder">
                                        <i class="fas fa-bell"></i> <?= $n['notification_type'] == 'regular_reminder' ? 'Regular Reminder' : 'Pre-Assessment Reminder' ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($cleanMessage) ?>">
                                        <?= htmlspecialchars($previewMessage) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isFailed): ?>
                                        <span class="status-badge status-failed">
                                            <i class="fas fa-exclamation-circle"></i> Failed
                                        </span>
                                    <?php elseif ($isEmailDelivered): ?>
                                        <span class="delivery-badge email">
                                            <i class="fas fa-envelope"></i> Email Delivered
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-view" 
                                                data-message="<?= $escapedFullMessage ?>" 
                                                data-trainee="<?= htmlspecialchars($n['first_name'] . ' ' . $n['last_name']) ?>" 
                                                data-channel="<?= htmlspecialchars($n['channel'] ?? 'N/A') ?>" 
                                                data-status="<?= htmlspecialchars($n['status']) ?>" 
                                                data-time="<?= date('F j, Y h:i A', strtotime($n['sent_at'])) ?>"
                                                onclick="viewFullDetails(this)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($user_role === 'coordinator'): ?>
                                        <button class="btn-delete" onclick="deleteNotification(<?= $n['id'] ?>, this)">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="confirm-modal" id="confirmModal">
        <div class="confirm-modal-content">
            <div class="icon-warning">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3>Delete All Notifications?</h3>
            <p>Are you sure you want to delete all notification records? This action cannot be undone.</p>
            <div class="confirm-actions">
                <button class="btn-confirm-no" onclick="closeConfirmModal()">Cancel</button>
                <button class="btn-confirm-yes" onclick="confirmDeleteAll()">Delete All</button>
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
        let autoRefreshInterval = null;
        let typingTimer;
        const typingDelay = 500;

        function viewFullDetails(btn) {
            const modal = document.getElementById('messageModal');
            document.getElementById('modalTrainee').textContent = btn.dataset.trainee || 'N/A';
            document.getElementById('modalChannel').textContent = btn.dataset.channel || 'N/A';
            document.getElementById('modalStatus').textContent = btn.dataset.status || 'N/A';
            document.getElementById('modalTime').textContent = btn.dataset.time || 'N/A';
            const msg = btn.dataset.message || 'No message';
            document.getElementById('modalMessage').innerHTML = msg.replace(/\n/g, '<br>');
            modal.style.display = 'block';
        }

        function closeModal() {
            document.getElementById('messageModal').style.display = 'none';
        }

        function showConfirmModal() {
            document.getElementById('confirmModal').style.display = 'flex';
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        function confirmDeleteAll() {
            closeConfirmModal();
            deleteAllNotifications();
        }

        function deleteAllNotifications() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'flex';
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_all_notifications=1'
            })
            .then(response => response.json())
            .then(data => {
                loadingOverlay.style.display = 'none';
                if (data.success) {
                    showFloatingMessage(data.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                } else {
                    showFloatingMessage(data.message, 'error');
                }
            })
            .catch(error => {
                loadingOverlay.style.display = 'none';
                console.error('Error:', error);
                showFloatingMessage('Error deleting all notifications', 'error');
            });
        }

        function showFloatingMessage(message, type = 'success') {
            const existing = document.querySelector('.floating-message');
            if (existing) existing.remove();
            
            const msgDiv = document.createElement('div');
            msgDiv.className = `floating-message ${type}`;
            msgDiv.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(msgDiv);
            setTimeout(() => msgDiv.remove(), 3000);
        }

        function deleteNotification(notificationId, buttonElement) {
            if (confirm('Are you sure you want to delete this notification? This action cannot be undone.')) {
                const loadingOverlay = document.getElementById('loadingOverlay');
                loadingOverlay.style.display = 'flex';
                
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'delete_notification=1&notification_id=' + notificationId
                })
                .then(response => response.json())
                .then(data => {
                    loadingOverlay.style.display = 'none';
                    if (data.success) {
                        showFloatingMessage(data.message, 'success');
                        const row = buttonElement.closest('tr');
                        if (row) {
                            row.remove();
                            const tbody = row.closest('tbody');
                            if (tbody && tbody.children.length === 0) {
                                const section = row.closest('.dept-notification-section');
                                if (section) {
                                    section.remove();
                                }
                            }
                            updateDepartmentStats();
                            updateOverallStats();
                        }
                        setTimeout(() => loadNotifications(), 500);
                    } else {
                        showFloatingMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    loadingOverlay.style.display = 'none';
                    console.error('Error:', error);
                    showFloatingMessage('Error deleting notification', 'error');
                });
            }
        }

        function updateDepartmentStats() {
            document.querySelectorAll('.dept-notification-section').forEach(section => {
                const rows = section.querySelectorAll('tbody tr');
                let sent = 0;
                let failed = 0;
                
                rows.forEach(row => {
                    const hasFailedBadge = row.querySelector('.status-badge.status-failed');
                    const hasEmailDelivered = row.querySelector('.delivery-badge.email');
                    
                    if (hasFailedBadge) {
                        failed++;
                    } else if (hasEmailDelivered) {
                        sent++;
                    } else {
                        sent++;
                    }
                });
                
                const total = rows.length;
                const statsDiv = section.querySelector('.dept-stats');
                if (statsDiv) {
                    statsDiv.innerHTML = `
                        <span><i class="fas fa-check-circle" style="color: #10b981;"></i> ${sent} Email Delivered</span>
                        <span><i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> ${failed} Failed</span>
                        <span><i class="fas fa-bell"></i> ${total} Total</span>
                    `;
                }
            });
        }
        
        function updateOverallStats() {
            let total = 0;
            let sent = 0;
            let failed = 0;
            
            document.querySelectorAll('.dept-notification-section tbody tr').forEach(row => {
                total++;
                const hasFailedBadge = row.querySelector('.status-badge.status-failed');
                const hasEmailDelivered = row.querySelector('.delivery-badge.email');
                
                if (hasFailedBadge) {
                    failed++;
                } else {
                    sent++;
                }
            });
            
            document.getElementById('statTotal').textContent = total;
            document.getElementById('statSent').textContent = sent;
            document.getElementById('statFailed').textContent = failed;
            
            const deleteAllBtn = document.getElementById('deleteAllBtn');
            if (deleteAllBtn) {
                deleteAllBtn.disabled = total === 0;
            }
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('notification_sent') === '1') {
            showFloatingMessage('Notification sent successfully!', 'success');
            const newUrl = window.location.pathname;
            window.history.replaceState({}, document.title, newUrl);
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
                    timeout: 5000
                });
                
                socketInstance.on('connect', function() {
                    console.log('✅ Connected to notification server');
                });
                
                socketInstance.on('new_notification', function(notification) {
                    console.log('📬 New notification received:', notification);
                    loadNotifications();
                });
                
                socketInstance.on('disconnect', function() {
                    console.log('❌ Disconnected from notification server');
                    setTimeout(initWebSocket, 5000);
                });
                
                socketInstance.on('connect_error', function(error) {
                    console.log('⚠️ WebSocket connection error:', error);
                });
                
            } catch (e) {
                console.log('WebSocket initialization error:', e);
            }
        }

        function loadNotifications() {
            const department = document.getElementById('filterDepartment').value;
            const subject = document.getElementById('filterSubject').value;
            const status = document.getElementById('filterStatus').value;
            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            
            let url = 'notification_history_ajax.php?';
            if (department) url += `department=${encodeURIComponent(department)}&`;
            if (subject) url += `subject=${encodeURIComponent(subject)}&`;
            if (status && status !== 'all') url += `status=${encodeURIComponent(status)}&`;
            if (dateFrom) url += `date_from=${dateFrom}&`;
            if (dateTo) url += `date_to=${dateTo}&`;
            url += `t=${Date.now()}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updatePage(data);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function updatePage(data) {
            const grouped = {};
            (data.notifications || []).forEach(n => {
                const dept = n.dept_code || 'Other';
                if (!grouped[dept]) grouped[dept] = [];
                grouped[dept].push(n);
            });
            
            document.querySelectorAll('.dept-notification-section, .empty-state').forEach(el => el.remove());
            
            if (Object.keys(grouped).length === 0) {
                document.querySelector('.main-content').insertAdjacentHTML('beforeend', `
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
                `);
                document.getElementById('statTotal').textContent = 0;
                document.getElementById('statSent').textContent = 0;
                document.getElementById('statFailed').textContent = 0;
                const deleteAllBtn = document.getElementById('deleteAllBtn');
                if (deleteAllBtn) deleteAllBtn.disabled = true;
                return;
            }
            
            const departmentColors = <?= json_encode($department_mapping) ?>;
            const isCoordinator = <?= $user_role === 'coordinator' ? 'true' : 'false' ?>;
            let totalOverall = 0;
            let sentOverall = 0;
            let failedOverall = 0;
            
            for (const [deptCode, deptNotifications] of Object.entries(grouped)) {
                const deptInfo = departmentColors[deptCode] || { name: deptCode, color: '#64748b', icon: 'fa-building' };
                let sent = 0, failed = 0;
                
                deptNotifications.forEach(n => {
                    totalOverall++;
                    const statusText = n.status || '';
                    if (statusText.includes('Failed') || statusText.includes('Wrong') || statusText.includes('Error')) {
                        failed++;
                        failedOverall++;
                    } else if (statusText.includes('Email') && (statusText.includes('Delivered') || statusText.includes('Sent'))) {
                        sent++;
                        sentOverall++;
                    } else if (statusText.includes('Delivered') || statusText.includes('Sent')) {
                        sent++;
                        sentOverall++;
                    } else {
                        sent++;
                        sentOverall++;
                    }
                });
                
                let tableRows = '';
                deptNotifications.forEach(n => {
                    const statusText = n.status || '';
                    const isFailed = (statusText && (statusText.includes('Failed') || statusText.includes('Wrong') || statusText.includes('Error')));
                    const isEmailDelivered = (statusText && (statusText.includes('Email') && (statusText.includes('Delivered') || statusText.includes('Sent')))) ||
                                             (statusText && (statusText.includes('Delivered') || statusText.includes('Sent')));
                    
                    const sentDate = new Date(n.sent_at);
                    const formattedDate = sentDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + 
                                          ' ' + sentDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                    
                    const cleanMessage = n.message || 'No message';
                    const previewMessage = cleanMessage.length > 80 ? cleanMessage.substring(0, 80) + '...' : cleanMessage;
                    const escapedMessage = cleanMessage.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    
                    let deliveryHtml = '';
                    if (isFailed) {
                        deliveryHtml = `<span class="status-badge status-failed"><i class="fas fa-exclamation-circle"></i> Failed</span>`;
                    } else if (isEmailDelivered) {
                        deliveryHtml = `<span class="delivery-badge email"><i class="fas fa-envelope"></i> Email Delivered</span>`;
                    } else {
                        deliveryHtml = `<span class="status-badge status-pending"><i class="fas fa-clock"></i> Pending</span>`;
                    }
                    
                    tableRows += `
                        <tr>
                            <td style="white-space: nowrap;">${formattedDate}</td>
                            <td>
                                <strong>${escapeHtml(n.first_name || '')} ${escapeHtml(n.last_name || '')}</strong>
                                <div style="font-size: 0.7rem; color: #64748b;">${escapeHtml(n.trainee_email || '-')}</div>
                            </td>
                            <td>
                                <span class="type-badge type-reminder">
                                    <i class="fas fa-bell"></i> ${n.notification_type === 'regular_reminder' ? 'Regular Reminder' : 'Pre-Assessment Reminder'}
                                </span>
                            </td>
                            <td>
                                <div style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(cleanMessage)}">
                                    ${escapeHtml(previewMessage)}
                                </div>
                            </td>
                            <td>${deliveryHtml}</td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-view" 
                                            data-message="${escapedMessage}" 
                                            data-trainee="${escapeHtml(n.first_name || '')} ${escapeHtml(n.last_name || '')}" 
                                            data-channel="${escapeHtml(n.channel || 'N/A')}" 
                                            data-status="${escapeHtml(n.status)}" 
                                            data-time="${formattedDate}"
                                            onclick="viewFullDetails(this)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    ${isCoordinator ? `<button class="btn-delete" onclick="deleteNotification(${n.id}, this)">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </button>` : ''}
                                </div>
                            </td>
                        </tr>
                    `;
                });
                
                const sectionHtml = `
                    <div class="dept-notification-section" data-dept="${deptCode}">
                        <div class="dept-section-header" style="border-left: 4px solid ${deptInfo.color};">
                            <h2>
                                <i class="fas ${deptInfo.icon}" style="color: ${deptInfo.color};"></i>
                                ${escapeHtml(deptInfo.name)}
                            </h2>
                            <div class="dept-stats">
                                <span><i class="fas fa-check-circle" style="color: #10b981;"></i> ${sent} Email Delivered</span>
                                <span><i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> ${failed} Failed</span>
                                <span><i class="fas fa-bell"></i> ${deptNotifications.length} Total</span>
                            </div>
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Trainee</th>
                                        <th>Type</th>
                                        <th>Message</th>
                                        <th>Delivery Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${tableRows}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
                document.querySelector('.main-content').insertAdjacentHTML('beforeend', sectionHtml);
            }
            
            document.getElementById('statTotal').textContent = totalOverall;
            document.getElementById('statSent').textContent = sentOverall;
            document.getElementById('statFailed').textContent = failedOverall;
            
            const deleteAllBtn = document.getElementById('deleteAllBtn');
            if (deleteAllBtn) {
                deleteAllBtn.disabled = totalOverall === 0;
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function resetFilters() {
            document.getElementById('filterDepartment').value = '';
            document.getElementById('filterSubject').value = '';
            document.getElementById('filterStatus').value = 'all';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            loadNotifications();
        }

        function setupAutoRefresh() {
            const selectFilters = ['filterDepartment', 'filterStatus'];
            selectFilters.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', () => loadNotifications());
                }
            });
            
            const dateFilters = ['filterDateFrom', 'filterDateTo'];
            dateFilters.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', () => loadNotifications());
                }
            });
            
            const subjectInput = document.getElementById('filterSubject');
            if (subjectInput) {
                subjectInput.addEventListener('input', function() {
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(() => {
                        loadNotifications();
                    }, typingDelay);
                });
            }
            
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(loadNotifications, 10000);
        }

        window.onclick = function(event) {
            const modal = document.getElementById('messageModal');
            if (event.target == modal) closeModal();
            const confirmModal = document.getElementById('confirmModal');
            if (event.target == confirmModal) closeConfirmModal();
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeConfirmModal();
            }
        });
        
        document.getElementById('resetFiltersBtn').addEventListener('click', resetFilters);
        
        const deleteAllBtn = document.getElementById('deleteAllBtn');
        if (deleteAllBtn) {
            deleteAllBtn.addEventListener('click', function() {
                if (this.disabled) return;
                showConfirmModal();
            });
        }
        
        setupAutoRefresh();
        loadNotifications();
        setTimeout(initWebSocket, 1000);
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