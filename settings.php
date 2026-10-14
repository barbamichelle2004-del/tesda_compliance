<?php
// settings.php - System Settings Page
// ==========================================
// "Delete All Logs" performs a HARD DELETE.
// Records are permanently removed from the database.
// ==========================================

// ==========================================
// SESSION — must match login.php
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'trainer') {
    $_SESSION['error_message'] = "Access Denied: You don't have permission to access this page.";
    header("Location: dashboard.php");
    exit();
}

require_once __DIR__ . '/config/config.php';

$user = getCurrentUser();

if (!$user || !isset($user['id'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$error = '';

// ==========================================
// ENSURE audit_log TABLE HAS ALL COLUMNS
// ==========================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT,
        username VARCHAR(100),
        user_role VARCHAR(50),
        user_department VARCHAR(100),
        user_qualification VARCHAR(255),
        action VARCHAR(50),
        login_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        deleted_by INT DEFAULT NULL,
        is_deleted TINYINT(1) DEFAULT 0,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL
    )");
    
    $existing_columns = [];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
        $existing_columns = $cols ?: [];
    } catch (PDOException $e) { $existing_columns = []; }
    
    if (!in_array('user_qualification', $existing_columns)) $pdo->exec("ALTER TABLE audit_log ADD COLUMN user_qualification VARCHAR(255) DEFAULT NULL");
    if (!in_array('ip_address', $existing_columns)) $pdo->exec("ALTER TABLE audit_log ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL");
    if (!in_array('user_agent', $existing_columns)) $pdo->exec("ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL");
    if (!in_array('deleted_at', $existing_columns)) $pdo->exec("ALTER TABLE audit_log ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    if (!in_array('deleted_by', $existing_columns)) $pdo->exec("ALTER TABLE audit_log ADD COLUMN deleted_by INT DEFAULT NULL");
    if (!in_array('is_deleted', $existing_columns)) $pdo->exec("ALTER TABLE audit_log ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
} catch(PDOException $e) {
    error_log("Error ensuring audit_log columns: " . $e->getMessage());
}

// ==========================================
// HANDLE UPDATE SCHOOL INFO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_school'])) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(50) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $settings = [
            'school_name' => $_POST['school_name'],
            'school_address' => $_POST['school_address'],
            'school_email' => $_POST['school_email'],
            'school_phone' => $_POST['school_phone']
        ];
        
        foreach ($settings as $key => $value) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
            $check->execute([$key]);
            if ($check->fetchColumn() > 0) {
                $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
                $stmt->execute([$value, $user['id'], $key]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)");
                $stmt->execute([$key, $value, $user['id']]);
            }
        }
        $message = "School information updated successfully!";
    } catch(PDOException $e) {
        $error = "Error updating school info: " . $e->getMessage();
    }
}

// ==========================================
// HANDLE DELETE ALL AUDIT LOGS — HARD DELETE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_audit_log'])) {
    try {
        if ($user['role'] == 'coordinator' || $user['role'] == 'admin') {
            $count = $pdo->query("
                SELECT COUNT(*) FROM audit_log 
                WHERE LOWER(user_role) = 'trainer' 
            ")->fetchColumn();
            
            if ($count > 0) {
                $stmt = $pdo->prepare("
                    DELETE FROM audit_log 
                    WHERE LOWER(user_role) = 'trainer'
                ");
                $stmt->execute();
                $message = "All trainer login records have been permanently deleted. (" . number_format($count) . " records removed)";
            } else {
                $error = "No trainer login records found to delete.";
            }
        } else {
            $error = "You don't have permission to delete audit logs!";
        }
    } catch(PDOException $e) {
        $error = "Error deleting audit log: " . $e->getMessage();
    }
}

// ==========================================
// GET SYSTEM SETTINGS
// ==========================================
$school_settings = [];
try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $school_settings = [
        'school_name' => $settings['school_name'] ?? 'USAT College Sagay City, Inc.',
        'school_address' => $settings['school_address'] ?? 'National Highway, Sagay City, Negros Occidental',
        'school_email' => $settings['school_email'] ?? 'info@usat.edu.ph',
        'school_phone' => $settings['school_phone'] ?? '(034) 123-4567'
    ];
} catch(PDOException $e) {
    $school_settings = [
        'school_name' => 'USAT College Sagay City, Inc.',
        'school_address' => 'National Highway, Sagay City, Negros Occidental',
        'school_email' => 'info@usat.edu.ph',
        'school_phone' => '(034) 123-4567'
    ];
}

// ==========================================
// GET USER STATS
// ==========================================
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0;
$total_coordinators = $pdo->query("SELECT COUNT(*) FROM users WHERE role='coordinator'")->fetchColumn() ?: 0;
$total_trainers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='trainer'")->fetchColumn() ?: 0;

// ==========================================
// ANALYTICS DATA — COMPUTED ON THE FLY
// ==========================================
$total_eligible_trainees = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT t.id
            FROM trainees t
            WHERE t.deleted_at IS NULL
              AND t.qualification_id IS NOT NULL
              AND t.qualification_id > 0
              AND (
                SELECT COUNT(DISTINCT pt.id)
                FROM performance_tasks pt
                JOIN competency_standards cs ON cs.id = pt.competency_id
                WHERE cs.qualification_id = t.qualification_id
              ) > 0
              AND (
                SELECT COUNT(DISTINCT pt.id)
                FROM performance_tasks pt
                JOIN competency_standards cs ON cs.id = pt.competency_id
                JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
                WHERE cs.qualification_id = t.qualification_id
                  AND et.remarks = 'Done'
              ) >= (
                SELECT COUNT(DISTINCT pt.id)
                FROM performance_tasks pt
                JOIN competency_standards cs ON cs.id = pt.competency_id
                WHERE cs.qualification_id = t.qualification_id
              )
        ) AS eligible
    ");
    $total_eligible_trainees = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Total eligible count error: " . $e->getMessage());
}

$trainers_with_eligible = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        INNER JOIN user_qualifications uq ON uq.user_id = u.id
        INNER JOIN trainees t ON t.qualification_id = uq.qualification_id
        WHERE u.role = 'trainer'
          AND t.deleted_at IS NULL
          AND (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            WHERE cs.qualification_id = t.qualification_id
          ) > 0
          AND (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
            WHERE cs.qualification_id = t.qualification_id
              AND et.remarks = 'Done'
          ) >= (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            WHERE cs.qualification_id = t.qualification_id
          )
    ");
    $trainers_with_eligible = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Trainers with eligible error: " . $e->getMessage());
}

// ---- Top Trainers by Eligible Trainees ----
$top_trainers = [];
try {
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.full_name,
            u.username,
            COUNT(DISTINCT t.id) AS eligible_count
        FROM users u
        INNER JOIN user_qualifications uq ON uq.user_id = u.id
        INNER JOIN trainees t ON t.qualification_id = uq.qualification_id
        WHERE u.role = 'trainer'
          AND t.deleted_at IS NULL
          AND (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            WHERE cs.qualification_id = t.qualification_id
          ) > 0
          AND (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
            WHERE cs.qualification_id = t.qualification_id
              AND et.remarks = 'Done'
          ) >= (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            WHERE cs.qualification_id = t.qualification_id
          )
        GROUP BY u.id, u.full_name, u.username
        HAVING eligible_count > 0
        ORDER BY eligible_count DESC, u.full_name ASC
        LIMIT 10
    ");
    $top_trainers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Top trainers error: " . $e->getMessage());
    $top_trainers = [];
}

// ---- Get each trainer's primary qualification + course ----
$trainer_primary = [];
if (!empty($top_trainers)) {
    $ids = array_column($top_trainers, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $q = $pdo->prepare("
            SELECT 
                uq.user_id, 
                c.course_code,
                c.course_name,
                q.qualification_name
            FROM user_qualifications uq
            INNER JOIN qualifications q ON q.id = uq.qualification_id
            INNER JOIN courses c ON c.id = q.course_id
            WHERE uq.user_id IN ($ph)
            ORDER BY uq.id ASC
        ");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($trainer_primary[$row['user_id']])) {
                $trainer_primary[$row['user_id']] = [
                    'course_code' => $row['course_code'],
                    'course_name' => $row['course_name'],
                    'qualification_name' => $row['qualification_name'],
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("Trainer code lookup error: " . $e->getMessage());
    }
}

// ---- Eligible Trainees by Department ----
$eligible_by_dept = [
    'CSS'  => ['code' => 'CSS',  'name' => 'Computer Systems Servicing', 'count' => 0],
    'HRS'  => ['code' => 'HRS',  'name' => 'Hotel and Restaurant Services', 'count' => 0],
    'ATS'  => ['code' => 'ATS',  'name' => 'Automotive Mechanics', 'count' => 0],
    'EPAS' => ['code' => 'EPAS', 'name' => 'Electronic Products Assembly and Servicing', 'count' => 0],
];
try {
    $stmt = $pdo->query("
        SELECT 
            c.course_code,
            COUNT(DISTINCT t.id) AS eligible_count
        FROM courses c
        LEFT JOIN qualifications q ON q.course_id = c.id
        LEFT JOIN trainees t ON t.qualification_id = q.id AND t.deleted_at IS NULL
        WHERE (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            WHERE cs.qualification_id = t.qualification_id
          ) > 0
          AND (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
            WHERE cs.qualification_id = t.qualification_id
              AND et.remarks = 'Done'
          ) >= (
            SELECT COUNT(DISTINCT pt.id)
            FROM performance_tasks pt
            JOIN competency_standards cs ON cs.id = pt.competency_id
            WHERE cs.qualification_id = t.qualification_id
          )
        GROUP BY c.course_code
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($eligible_by_dept[$row['course_code']])) {
            $eligible_by_dept[$row['course_code']]['count'] = (int)$row['eligible_count'];
        }
    }
} catch (PDOException $e) {
    error_log("Eligible by dept error: " . $e->getMessage());
}

if ($eligible_by_dept['EPAS']['count'] === 0) {
    unset($eligible_by_dept['EPAS']);
}

$dept_total_eligible = array_sum(array_column($eligible_by_dept, 'count'));

// ---- Department colors ----
$dept_meta = [
    'CSS'  => ['color' => '#3b82f6', 'bar' => '#3b82f6', 'badge_bg' => '#dbeafe', 'badge_fg' => '#1e40af'],
    'HRS'  => ['color' => '#10b981', 'bar' => '#10b981', 'badge_bg' => '#d1fae5', 'badge_fg' => '#065f46'],
    'ATS'  => ['color' => '#8b5cf6', 'bar' => '#8b5cf6', 'badge_bg' => '#ede9fe', 'badge_fg' => '#5b21b6'],
    'EPAS' => ['color' => '#f59e0b', 'bar' => '#f59e0b', 'badge_bg' => '#fef3c7', 'badge_fg' => '#92400e'],
];
$bar_palette = ['#f59e0b', '#06b6d4', '#ec4899', '#84cc16', '#f97316'];

// ==========================================
// GET AUDIT LOGS (UNCHANGED)
// ==========================================
$recent_logs = [];
try {
    $recent_logs = $pdo->query("
        SELECT 
            al.id,
            al.user_id,
            al.username,
            al.user_role,
            al.user_department,
            al.user_qualification,
            al.action,
            al.login_time,
            al.ip_address,
            al.user_agent,
            COALESCE(u.full_name, al.username) AS full_name,
            u.id AS linked_user_id,
            uq.qualification_id,
            q.qualification_name
        FROM audit_log al
        LEFT JOIN users u ON al.username = u.username
        LEFT JOIN user_qualifications uq ON uq.user_id = u.id
        LEFT JOIN qualifications q ON q.id = uq.qualification_id
        WHERE LOWER(al.user_role) = 'trainer'
        ORDER BY al.login_time DESC 
        LIMIT 100
    ")->fetchAll();
} catch(PDOException $e) {
    error_log("Error fetching audit logs: " . $e->getMessage());
    $recent_logs = [];
}

// ==========================================
// GET LOG STATISTICS (UNCHANGED)
// ==========================================
$log_stats = [];
try {
    $log_stats['total_logs'] = $pdo->query("
        SELECT COUNT(*) FROM audit_log 
        WHERE LOWER(user_role) = 'trainer' 
    ")->fetchColumn() ?: 0;
    
    $log_stats['today_logs'] = $pdo->query("
        SELECT COUNT(*) FROM audit_log 
        WHERE LOWER(user_role) = 'trainer' 
        AND DATE(login_time) = CURDATE()
    ")->fetchColumn() ?: 0;
    
    $log_stats['week_logs'] = $pdo->query("
        SELECT COUNT(*) FROM audit_log 
        WHERE LOWER(user_role) = 'trainer' 
        AND login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn() ?: 0;
} catch(PDOException $e) {
    $log_stats = ['total_logs' => 0, 'today_logs' => 0, 'week_logs' => 0];
}

// ==========================================
// GET QUALIFICATION TRAINERS (UNCHANGED)
// ==========================================
$qualification_trainers = [];
try {
    $stmt = $pdo->query("
        SELECT q.id as qualification_id, q.qualification_name,
               u.id as trainer_id, u.full_name as trainer_name, u.username as trainer_username
        FROM qualifications q
        INNER JOIN user_qualifications uq ON uq.qualification_id = q.id
        INNER JOIN users u ON u.id = uq.user_id
        WHERE u.role = 'trainer'
        ORDER BY q.qualification_name, u.full_name
    ");
    while ($row = $stmt->fetch()) {
        if (!isset($qualification_trainers[$row['qualification_id']])) {
            $qualification_trainers[$row['qualification_id']] = [
                'name' => $row['qualification_name'],
                'trainers' => []
            ];
        }
        $qualification_trainers[$row['qualification_id']]['trainers'][] = [
            'name' => $row['trainer_name'],
            'username' => $row['trainer_username']
        ];
    }
} catch(PDOException $e) {
    error_log("Error fetching qualification trainers: " . $e->getMessage());
}

$user_qualification = '';
if ($user['role'] == 'trainer') {
    try {
        $stmt = $pdo->prepare("
            SELECT q.qualification_name 
            FROM qualifications q
            INNER JOIN user_qualifications uq ON uq.qualification_id = q.id
            WHERE uq.user_id = ?
        ");
        $stmt->execute([$user['id']]);
        $qual = $stmt->fetch();
        $user_qualification = $qual['qualification_name'] ?? '';
    } catch(PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f0f2f5; min-width: 1200px; overflow-x: auto; margin: 0; }
        .main-content { margin-left: 280px; padding: 30px; width: calc(100% - 280px); min-width: 920px; }
        .header { background: white; border-radius: 24px; padding: 25px 35px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 40px rgba(0,0,0,0.08); }
        .header-left h1 { font-size: 1.8rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 12px; margin: 0; }
        .header-left h1 i { color: #3b82f6; }
        .header-left p { color: #64748b; font-size: 1rem; margin: 0; margin-top: 2px; }
        .header-right { display: flex; align-items: center; gap: 18px; }
        .profile-badge { display: flex; align-items: center; gap: 12px; padding: 6px 20px 6px 6px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 50px; }
        .profile-badge .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background-color: #263868; display: flex; align-items: center; justify-content: center; }
        .profile-badge .avatar-circle i { color: #fff; font-size: 0.9rem; }
        .profile-badge .profile-name { font-weight: 600; font-size: 0.95rem; color: #1e293b; }
        .date-badge { background: #f8fafc; padding: 10px 20px; border-radius: 12px; color: #0f172a; font-weight: 500; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
            align-items: start;
        }

        .settings-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
        .card-header h2 { color: #1a1c2c; font-size: 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .card-header h2 i { color: #3b82f6; }
        .card-header .badge { background: #f1f5f9; color: #1a1c2c; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; }

        .school-info { display: flex; flex-direction: column; gap: 15px; }
        .info-item { display: flex; align-items: center; padding: 12px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; }
        .info-item i { width: 40px; height: 40px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #3b82f6; font-size: 1.3rem; margin-right: 15px; flex-shrink: 0; }
        .info-content { flex: 1; min-width: 0; }
        .info-label { color: #64748b; font-size: 0.82rem; }
        .edit-field { width: 100%; padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; font-family: 'Poppins', sans-serif; }
        .edit-field:focus { outline: none; border-color: #3b82f6; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; width: 100%; justify-content: center; font-family: 'Poppins', sans-serif; font-size: 0.92rem; }
        .btn-primary:hover { background: linear-gradient(135deg, #2563eb, #1d4ed8); transform: translateY(-2px); }
        .btn-danger { background: #ef4444; color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; justify-content: center; font-family: 'Poppins', sans-serif; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-2px); }

        .message { background: #dcfce7; color: #166534; padding: 15px; border-radius: 12px; margin-bottom: 20px; border-left: 5px solid #166534; }
        .error { background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 12px; margin-bottom: 20px; border-left: 5px solid #991b1b; }

        .stats-overview { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .stat-box {
            border-radius: 14px;
            padding: 22px 16px;
            text-align: center;
            border: 2px solid #e2e8f0;
            background: #ffffff;
        }
        .stat-box:nth-child(1) { border-color: #dbeafe; background: #f8fbff; }
        .stat-box:nth-child(2) { border-color: #d1fae5; background: #f5fdf9; }
        .stat-box:nth-child(3) { border-color: #ede9fe; background: #faf8ff; }
        .stat-box i { font-size: 1.6rem; margin-bottom: 10px; display: block; }
        .stat-box:nth-child(1) i { color: #3b82f6; }
        .stat-box:nth-child(2) i { color: #10b981; }
        .stat-box:nth-child(3) i { color: #8b5cf6; }
        .stat-box h3 { font-size: 1.8rem; font-weight: 700; color: #1a1c2c; margin-bottom: 4px; }
        .stat-box p { color: #64748b; font-size: 0.82rem; }

        /* ========================================= */
        /* TRAINER ANALYTICS                          */
        /* ========================================= */
        .analytics-panel {
            background: white;
            border-radius: 15px;
            padding: 24px 26px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 2px solid #eef2f6;
        }
        .analytics-title { display: flex; align-items: center; gap: 14px; }
        .analytics-title .at-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #dbeafe;
            color: #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }
        .analytics-title h2 { color: #1a1c2c; font-size: 1.25rem; font-weight: 700; margin: 0; line-height: 1.2; }
        .analytics-title p { color: #64748b; font-size: 0.82rem; margin: 2px 0 0; }

        /* Analytics is now full-width (donut removed) */
        .analytics-body {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            align-items: start;
        }

        /* Trainer table */
        .trainer-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .trainer-table thead th {
            padding: 12px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 2px solid #eef2f6;
            text-align: left;
            vertical-align: bottom;
        }
        .trainer-table thead th.col-rank        { width: 60px;  text-align: center; }
        .trainer-table thead th.col-trainer     { width: 260px; text-align: left; }
        .trainer-table thead th.col-dept        { width: 220px; text-align: left; }
        .trainer-table thead th.col-eligible    { width: 130px; text-align: center; }
        .trainer-table thead th.col-progress    { width: auto;  text-align: left; }

        .trainer-table tbody td {
            padding: 14px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 0.88rem;
            color: #1e293b;
        }
        .trainer-table tbody tr:hover { background: #f8fafc; }
        .trainer-table tbody tr:last-child td { border-bottom: none; }

        .trainer-table tbody td.col-rank        { text-align: center; font-weight: 700; color: #94a3b8; font-size: 0.85rem; }
        .trainer-table tbody td.col-trainer     { text-align: left; }
        .trainer-table tbody td.col-dept        { text-align: left; color: #334155; font-size: 0.85rem; }
        .trainer-table tbody td.col-eligible    { text-align: center; font-weight: 700; font-size: 0.95rem; }
        .trainer-table tbody td.col-progress    { text-align: left; }

        .trainer-info-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .trainer-avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.82rem;
            flex-shrink: 0;
            letter-spacing: 0.5px;
        }
        .trainer-info-cell .ti-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.92rem;
        }

        .progress-track {
            width: 100%;
            height: 12px;
            background: #f1f5f9;
            border-radius: 20px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 20px;
            transition: width 0.6s ease;
        }

        .no-trainers-msg {
            text-align: center;
            padding: 40px 10px;
            color: #94a3b8;
            font-size: 0.85rem;
            font-style: italic;
        }

        /* ========================================= */
        /* AUDIT LOG (unchanged)                      */
        /* ========================================= */
        .audit-log-container { margin-top: 30px; background: white; border-radius: 15px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .audit-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .audit-title { display: flex; align-items: center; gap: 10px; }
        .audit-title h2 { color: #1a1c2c; font-size: 1.5rem; font-weight: 600; }
        .audit-title i { color: #3b82f6; font-size: 1.5rem; }
        .audit-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        
        .audit-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .audit-stat-card { background: #f8fafc; border-radius: 12px; padding: 15px; text-align: center; border-left: 4px solid; }
        .audit-stat-card:nth-child(1) { border-left-color: #3b82f6; }
        .audit-stat-card:nth-child(2) { border-left-color: #10b981; }
        .audit-stat-card:nth-child(3) { border-left-color: #f59e0b; }
        .audit-stat-card h3 { font-size: 1.5rem; font-weight: 700; color: #1a1c2c; }
        .audit-stat-card p { color: #64748b; font-size: 0.85rem; }
        
        .audit-table { width: 100%; border-collapse: collapse; }
        .audit-table th { text-align: left; padding: 12px; background: #f8fafc; color: #1e293b; font-weight: 600; font-size: 0.85rem; border-bottom: 2px solid #e2e8f0; }
        .audit-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; vertical-align: top; }
        .audit-table tr:hover { background: #f1f5f9; }
        
        .badge-login { background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .badge-trainer { background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .qualification-text { color: #1a1c2c; font-size: 0.85rem; font-weight: 500; display: inline-block; }
        .qualification-text i { color: #1a1c2c; margin-right: 4px; }
        .trainer-count-badge { color: #1a1c2c; font-size: 0.7rem; font-weight: 400; display: inline-block; margin-left: 5px; }
        .no-qualification-text { color: #94a3b8; font-size: 0.85rem; }
        
        .trainer-list-tooltip { display: none; position: absolute; background: #1a1c2c; color: white; padding: 12px 16px; border-radius: 10px; font-size: 0.85rem; min-width: 200px; max-width: 400px; z-index: 1000; box-shadow: 0 10px 30px rgba(0,0,0,0.3); border-left: 3px solid #3b82f6; pointer-events: none; }
        .trainer-list-tooltip .tooltip-title { font-weight: 600; color: #60a5fa; margin-bottom: 5px; font-size: 0.85rem; }
        .trainer-list-tooltip .tooltip-list { max-height: 150px; overflow-y: auto; }
        .trainer-list-tooltip .tooltip-item { padding: 3px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 8px; }
        .trainer-list-tooltip .tooltip-item:last-child { border-bottom: none; }
        .trainer-list-tooltip .tooltip-item i { color: #60a5fa; font-size: 0.7rem; }
        .trainer-list-tooltip .tooltip-item .trainer-username { color: #94a3b8; font-size: 0.7rem; }
        .qualification-cell { position: relative; cursor: pointer; }
        .qualification-cell:hover .trainer-list-tooltip { display: block; }
        
        .filter-row { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-input { padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; flex: 1; min-width: 200px; }
        .filter-input:focus { outline: none; border-color: #3b82f6; }
        .text-muted { color: #94a3b8; font-style: italic; text-align: center; padding: 30px; }
        
        .custom-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; }
        .custom-modal-overlay.active { display: flex; }
        .custom-modal-box { background: #ffffff; border-radius: 20px; padding: 30px 28px 24px; width: 90%; max-width: 400px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25); text-align: center; }
        .custom-modal-icon { width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.7rem; background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }
        .custom-modal-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
        .custom-modal-text { font-size: 0.9rem; color: #64748b; line-height: 1.5; margin: 0 0 24px; }
        .custom-modal-text strong { color: #ef4444; }
        .custom-modal-actions { display: flex; gap: 10px; justify-content: center; }
        .custom-modal-btn { flex: 1; padding: 11px 18px; border-radius: 12px; font-weight: 600; font-size: 0.9rem; cursor: pointer; border: none; font-family: 'Poppins', sans-serif; }
        .custom-modal-btn.cancel { background: #f1f5f9; color: #475569; border: 2px solid #e2e8f0; }
        .custom-modal-btn.confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; }
        .custom-modal-btn.confirm:hover { transform: translateY(-2px); }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 10px; }
        
        @media (max-width: 1200px) {
            .settings-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .main-content { margin-left: 80px; width: calc(100% - 80px); }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .audit-stats { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .stats-overview { grid-template-columns: 1fr; }
        }
    </style>

    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-cog"></i> System Settings</h1>
                <p>Manage system configuration</p>
            </div>
            <div class="header-right">
                <div class="profile-badge">
                    <div class="avatar-circle"><i class="fas fa-user"></i></div>
                    <span class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message"><i class="fas fa-check-circle"></i> <?= $message ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
        <?php endif; ?>

        <div class="settings-grid">
            
            <div class="settings-card">
                <div class="card-header">
                    <h2><i class="fas fa-school"></i> School Information</h2>
                    <span class="badge">USAT College</span>
                </div>
                <?php if ($user['role'] == 'coordinator'): ?>
                <form method="POST" class="school-info">
                    <input type="hidden" name="update_school" value="1">
                    <div class="info-item">
                        <i class="fas fa-university"></i>
                        <div class="info-content">
                            <div class="info-label">School Name</div>
                            <input type="text" name="school_name" class="edit-field" value="<?= htmlspecialchars($school_settings['school_name']) ?>" required>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div class="info-content">
                            <div class="info-label">Address</div>
                            <input type="text" name="school_address" class="edit-field" value="<?= htmlspecialchars($school_settings['school_address']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="info-item">
                            <i class="fas fa-envelope"></i>
                            <div class="info-content">
                                <div class="info-label">Email</div>
                                <input type="email" name="school_email" class="edit-field" value="<?= htmlspecialchars($school_settings['school_email']) ?>" required>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-phone-alt"></i>
                            <div class="info-content">
                                <div class="info-label">Contact Number</div>
                                <input type="text" name="school_phone" class="edit-field" value="<?= htmlspecialchars($school_settings['school_phone']) ?>" required>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Save School Information
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="settings-card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-pie"></i> System Overview</h2>
                    <span class="badge">Statistics</span>
                </div>
                <div class="stats-overview">
                    <div class="stat-box">
                        <i class="fas fa-users"></i>
                        <h3><?= $total_users ?></h3>
                        <p>Total Users</p>
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-user-tie"></i>
                        <h3><?= $total_coordinators ?></h3>
                        <p>Coordinators</p>
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <h3><?= $total_trainers ?></h3>
                        <p>Trainers</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================ -->
        <!-- TRAINER ANALYTICS                            -->
        <!-- ============================================ -->
        <div class="analytics-panel">
            <div class="analytics-header">
                <div class="analytics-title">
                    <div class="at-icon"><i class="fas fa-chart-column"></i></div>
                    <div>
                        <h2>Trainer Analytics</h2>
                        <p>Top Trainers with the Most Trainees Eligible for Pre-Assessment</p>
                    </div>
                </div>
            </div>

            <div class="analytics-body">

                <div>
                    <?php if (empty($top_trainers)): ?>
                        <div class="no-trainers-msg">
                            <i class="fas fa-inbox" style="font-size:1.5rem;display:block;margin-bottom:8px;color:#cbd5e1;"></i>
                            No trainers with eligible trainees yet.
                        </div>
                    <?php else: 
                        $max_count = max(array_column($top_trainers, 'eligible_count'));
                        if ($max_count < 1) $max_count = 1;
                    ?>
                        <table class="trainer-table">
                            <thead>
                                <tr>
                                    <th class="col-rank">Rank</th>
                                    <th class="col-trainer">Trainer</th>
                                    <th class="col-dept">Department / Qualification</th>
                                    <th class="col-eligible">Eligible Trainees</th>
                                    <th class="col-progress">Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $rank = 0;
                                foreach ($top_trainers as $tt):
                                    $rank++;
                                    $pct = ($tt['eligible_count'] / $max_count) * 100;
                                    $meta = $trainer_primary[$tt['id']] ?? null;
                                    $code = $meta['course_code'] ?? '';
                                    $course_name = $meta['course_name'] ?? '';

                                    $dept_color = $dept_meta[$code]['color'] ?? '#64748b';
                                    $bar_color  = $dept_meta[$code]['bar']   ?? $bar_palette[($rank - 1) % count($bar_palette)];

                                    $name_parts = preg_split('/\s+/', trim($tt['full_name']));
                                    $initials = '';
                                    if (count($name_parts) >= 2) {
                                        $initials = strtoupper(mb_substr($name_parts[0], 0, 1) . mb_substr($name_parts[count($name_parts) - 1], 0, 1));
                                    } else {
                                        $initials = strtoupper(mb_substr($tt['full_name'], 0, 2));
                                    }
                                ?>
                                <tr>
                                    <td class="col-rank"><?= $rank ?></td>
                                    <td class="col-trainer">
                                        <div class="trainer-info-cell">
                                            <div class="trainer-avatar-circle" style="background: <?= $dept_color ?>;">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                            <span class="ti-name"><?= htmlspecialchars($tt['full_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="col-dept">
                                        <?php if ($code && $course_name): ?>
                                            <strong><?= htmlspecialchars($code) ?></strong> - <?= htmlspecialchars($course_name) ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-eligible"><?= (int)$tt['eligible_count'] ?></td>
                                    <td class="col-progress">
                                        <div class="progress-track">
                                            <div class="progress-fill" style="width: <?= (float)$pct ?>%; background: <?= $bar_color ?>;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <!-- ============ END TRAINER ANALYTICS ============ -->

        <?php if ($user['role'] == 'coordinator' || $user['role'] == 'admin'): ?>
        
        <div class="audit-log-container">
            <div class="audit-header">
                <div class="audit-title">
                    <i class="fas fa-history"></i>
                    <h2>Trainer Login History</h2>
                </div>
                <div class="audit-actions">
                    <button type="button" class="btn-danger" onclick="openDeleteModal()">
                        <i class="fas fa-trash-alt"></i> Delete All Logs
                    </button>
                </div>
            </div>
            
            <div class="audit-stats">
                <div class="audit-stat-card">
                    <h3><?= number_format($log_stats['total_logs']) ?></h3>
                    <p>Total Trainer Logins</p>
                </div>
                <div class="audit-stat-card">
                    <h3><?= number_format($log_stats['today_logs']) ?></h3>
                    <p>Today</p>
                </div>
                <div class="audit-stat-card">
                    <h3><?= number_format($log_stats['week_logs']) ?></h3>
                    <p>This Week</p>
                </div>
            </div>
            
            <div class="filter-row">
                <input type="text" id="auditSearch" class="filter-input" placeholder="Search by username or qualification..." onkeyup="filterAuditLog()">
                <input type="date" id="dateFilter" class="filter-input" style="max-width: 150px;" onchange="filterAuditLog()">
            </div>
            
            <table class="audit-table" id="auditTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Qualification</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_logs)): ?>
                    <tr>
                        <td colspan="6" class="text-muted">No trainer login records found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td><?= date('M d, Y H:i:s', strtotime($log['login_time'])) ?></td>
                            <td><strong><?= htmlspecialchars($log['username']) ?></strong></td>
                            <td><?= htmlspecialchars($log['full_name'] ?? '') ?></td>
                            <td><span class="badge-trainer">Trainer</span></td>
                            <td class="qualification-cell">
                                <?php 
                                $qualification_name = $log['qualification_name'] ?? '';
                                $qualification_id = $log['qualification_id'] ?? null;
                                ?>
                                <?php if (!empty($qualification_name)): ?>
                                    <span class="qualification-text">
                                        <i class="fas fa-certificate"></i>
                                        <?= htmlspecialchars($qualification_name) ?>
                                    </span>
                                    <?php 
                                    $assigned_trainers = [];
                                    if ($qualification_id && isset($qualification_trainers[$qualification_id])) {
                                        $assigned_trainers = $qualification_trainers[$qualification_id]['trainers'];
                                    }
                                    ?>
                                    <?php if (!empty($assigned_trainers)): ?>
                                        <span class="trainer-count-badge">(<?= count($assigned_trainers) ?>)</span>
                                        <div class="trainer-list-tooltip">
                                            <div class="tooltip-title">
                                                <i class="fas fa-user-tie"></i> Assigned Trainer<?= count($assigned_trainers) > 1 ? 's' : '' ?>
                                            </div>
                                            <div class="tooltip-list">
                                                <?php foreach ($assigned_trainers as $trainer): ?>
                                                    <div class="tooltip-item">
                                                        <i class="fas fa-user-circle"></i>
                                                        <?= htmlspecialchars($trainer['name']) ?>
                                                        <span class="trainer-username">(@<?= htmlspecialchars($trainer['username']) ?>)</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="no-qualification-text">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-login">
                                    <i class="fas fa-sign-in-alt"></i> LOGIN
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="custom-modal-overlay" id="deleteLogsModal">
        <div class="custom-modal-box">
            <div class="custom-modal-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <h3 class="custom-modal-title">Delete All Trainer Login Records?</h3>
            <p class="custom-modal-text">
                Are you sure you want to delete all <strong><?= number_format($log_stats['total_logs']) ?></strong> trainer login records? This action cannot be undone.
            </p>
            <div class="custom-modal-actions">
                <button type="button" class="custom-modal-btn cancel" onclick="closeDeleteModal()">Cancel</button>
                <form method="POST" style="display: inline-block; margin: 0; flex: 1;">
                    <input type="hidden" name="delete_audit_log" value="1">
                    <button type="submit" class="custom-modal-btn confirm" style="width: 100%;">Yes, Delete All</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openDeleteModal() {
            document.getElementById('deleteLogsModal').classList.add('active');
        }
        function closeDeleteModal() {
            document.getElementById('deleteLogsModal').classList.remove('active');
        }
        document.getElementById('deleteLogsModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDeleteModal();
        });

        (function() {
            const currentPath = window.location.pathname;
            const currentPage = currentPath.split('/').pop();
            document.querySelectorAll('.menu-item').forEach(item => {
                const href = item.getAttribute('href');
                if (!href || href === '#') return;
                const hrefFile = href.split('/').pop();
                if (hrefFile === currentPage) item.classList.add('active');
            });
        })();

        function filterAuditLog() {
            const searchInput = document.getElementById('auditSearch')?.value.toLowerCase() || '';
            const dateFilter = document.getElementById('dateFilter')?.value || '';
            const table = document.getElementById('auditTable');
            if (!table) return;
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            for (let row of rows) {
                let showRow = true;
                if (row.cells.length === 1 && row.cells[0].colSpan === 6) continue;
                if (searchInput && !row.textContent.toLowerCase().includes(searchInput)) showRow = false;
                if (showRow && dateFilter) {
                    const dateCell = row.cells[0]?.textContent || '';
                    const fd = new Date(dateFilter).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                    if (!dateCell.includes(fd)) showRow = false;
                }
                row.style.display = showRow ? '' : 'none';
            }
        }
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