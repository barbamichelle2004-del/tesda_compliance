<?php
// ============================================================
// SECURITY: Block forward button after logout
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
// ============================================================
// END SECURITY BLOCK
// ============================================================

require_once __DIR__ . '/auth_check.php';

$user = getCurrentUser();
$db = $pdo;

$user_context = getUserAccessContext();
$user_role = $user_context['role'];
$user_department = $user_context['department'];
$qualification_ids = $user_context['qualification_ids'];
$is_coordinator = $user_context['is_coordinator'];

// ==========================================
// PUSHER HELPER - Real-time updates
// ==========================================
require_once __DIR__ . '/helpers/helpers/PusherHelper.php';

// ==========================================
// SOFT-DELETE CONDITION HELPER
// ==========================================
function getActiveCondition($tableAlias = 't') {
    return "($tableAlias.deleted_at IS NULL)
            AND ($tableAlias.status IS NULL OR $tableAlias.status != 'deleted')";
}

// ==========================================
// GET DASHBOARD DATA
// ==========================================

// 1. TOTAL TRAINEES
$trainee_query = "
    SELECT COUNT(*) 
    FROM trainees 
    WHERE deleted_at IS NULL
    AND (status IS NULL OR (status != 'deleted' AND status != 'Completed'))
";
$trainee_params = [];

if (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    $trainee_query .= " AND qualification_id IN ($placeholders)";
    $trainee_params = $qualification_ids;
} elseif (!$is_coordinator) {
    $trainee_query .= " AND 1=0";
}

$stmt = $pdo->prepare($trainee_query);
$stmt->execute($trainee_params);
$total_trainees = (int)$stmt->fetchColumn();

// 2. TOTAL TASKS
$task_query = "
    SELECT COUNT(DISTINCT pt.id) as total
    FROM performance_tasks pt
    JOIN competency_standards cs ON pt.competency_id = cs.id
";
$task_params = [];

if (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    $task_query .= " WHERE cs.qualification_id IN ($placeholders)";
    $task_params = $qualification_ids;
}

$stmt = $pdo->prepare($task_query);
$stmt->execute($task_params);
$total_tasks = (int)$stmt->fetchColumn();

// 3. COMPLETED ALL TASKS
$completed_query = "
    SELECT COUNT(DISTINCT t.id) as completed_count
    FROM trainees t
    JOIN qualifications q ON t.qualification_id = q.id
    JOIN competency_standards cs ON cs.qualification_id = q.id
    JOIN performance_tasks pt ON pt.competency_id = cs.id
    LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
    WHERE t.deleted_at IS NULL
    AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
";
$completed_params = [];

if (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    $completed_query .= " AND t.qualification_id IN ($placeholders)";
    $completed_params = $qualification_ids;
}

$completed_query .= "
    GROUP BY t.id
    HAVING 
        COUNT(DISTINCT pt.id) > 0
        AND COUNT(DISTINCT CASE WHEN LOWER(et.remarks) IN ('complied', 'completed', 'done') THEN pt.id END) = COUNT(DISTINCT pt.id)
";

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ($completed_query) as completed_trainees");
$stmt->execute($completed_params);
$completed_all_tasks = (int)$stmt->fetchColumn();

// 4. NOT COMPLIED
$not_complied = $total_trainees - $completed_all_tasks;

// 5. PERCENTAGES
$completed_percent = $total_trainees > 0 ? round(($completed_all_tasks / $total_trainees) * 100) : 0;
$not_complied_percent = $total_trainees > 0 ? round(($not_complied / $total_trainees) * 100) : 0;
$overall_completion_percent = $total_trainees > 0 ? round(($completed_all_tasks / $total_trainees) * 100, 1) : 0;

// 6. COMPLETED TODAY
$today_query = "
    SELECT COUNT(DISTINCT t.id)
    FROM trainees t
    JOIN evaluation_tracker et ON t.id = et.trainee_id
    WHERE t.deleted_at IS NULL
    AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
    AND LOWER(et.remarks) IN ('complied', 'completed', 'done')
    AND DATE(et.updated_at) = CURDATE()
";
$today_params = [];

if (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    $today_query .= " AND t.qualification_id IN ($placeholders)";
    $today_params = $qualification_ids;
}

$stmt = $pdo->prepare($today_query);
$stmt->execute($today_params);
$completed_today = (int)$stmt->fetchColumn();

// 7. ELIGIBLE FOR PRE-ASSESSMENT
$eligible_for_preassessment = $completed_all_tasks;
$not_yet_eligible = $total_trainees - $completed_all_tasks;

// ==========================================
// 8. DEPARTMENT STATISTICS
// ==========================================
$departments = [];
$dept_total = 0;
$dept_completed_total = 0;
$dept_not_complied_total = 0;

if ($is_coordinator) {
    $dept_query = "
        SELECT 
            c.course_code as dept_code,
            c.course_name,
            c.id as dept_id,
            COUNT(DISTINCT t.id) as total_trainees,
            COUNT(DISTINCT CASE 
                WHEN pa.id IS NOT NULL THEN t.id 
                ELSE NULL 
            END) as students_completed,
            COUNT(DISTINCT CASE 
                WHEN pa.id IS NULL THEN t.id 
                ELSE NULL 
            END) as students_not_complied
        FROM courses c
        LEFT JOIN trainees t ON UPPER(t.department) = c.course_code 
            AND t.deleted_at IS NULL
            AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
        LEFT JOIN pre_assessment pa ON pa.trainee_id = t.id
        GROUP BY c.id, c.course_code, c.course_name
        ORDER BY c.course_code
    ";
    
    $stmt = $pdo->prepare($dept_query);
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($departments as $dept) {
        $dept_total += (int)($dept['total_trainees'] ?? 0);
        $dept_completed_total += (int)($dept['students_completed'] ?? 0);
        $dept_not_complied_total += (int)($dept['students_not_complied'] ?? 0);
    }
} elseif (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    
    $dept_query = "
        SELECT 
            c.course_code as dept_code,
            c.course_name,
            c.id as dept_id,
            COUNT(DISTINCT t.id) as total_trainees,
            COUNT(DISTINCT CASE 
                WHEN pa.id IS NOT NULL THEN t.id 
                ELSE NULL 
            END) as students_completed,
            COUNT(DISTINCT CASE 
                WHEN pa.id IS NULL THEN t.id 
                ELSE NULL 
            END) as students_not_complied
        FROM courses c
        LEFT JOIN trainees t ON UPPER(t.department) = c.course_code 
            AND t.deleted_at IS NULL
            AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
            AND t.qualification_id IN ($placeholders)
        LEFT JOIN pre_assessment pa ON pa.trainee_id = t.id
        WHERE c.course_code IN (
            SELECT DISTINCT c2.course_code 
            FROM qualifications q2 
            JOIN courses c2 ON q2.course_id = c2.id 
            WHERE q2.id IN ($placeholders)
        )
        GROUP BY c.id, c.course_code, c.course_name
        ORDER BY c.course_code
    ";
    
    $dept_params = array_merge(
        $qualification_ids,
        $qualification_ids
    );
    
    $stmt = $pdo->prepare($dept_query);
    $stmt->execute($dept_params);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($departments as $dept) {
        $dept_total += (int)($dept['total_trainees'] ?? 0);
        $dept_completed_total += (int)($dept['students_completed'] ?? 0);
        $dept_not_complied_total += (int)($dept['students_not_complied'] ?? 0);
    }
}

// ==========================================
// 8b. PRE-ASSESSMENT ELIGIBILITY BY QUALIFICATION (BAR CHART DATA)
// COORDINATOR ONLY
// ==========================================
$qualification_stats = [];
$max_qual_value = 0;

if ($is_coordinator) {
    $qual_query = "
        SELECT 
            q.id as qual_id,
            q.qualification_code,
            q.qualification_name,
            c.course_code as dept_code,
            c.course_name as dept_name,
            COUNT(DISTINCT pa.trainee_id) as eligible_count
        FROM qualifications q
        LEFT JOIN courses c ON c.id = q.course_id
        LEFT JOIN trainees t ON t.qualification_id = q.id
            AND t.deleted_at IS NULL
            AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
        LEFT JOIN pre_assessment pa ON pa.trainee_id = t.id
        WHERE q.id IN (
            SELECT DISTINCT t2.qualification_id 
            FROM trainees t2 
            WHERE t2.deleted_at IS NULL
            AND t2.qualification_id IS NOT NULL
        )
        GROUP BY q.id, q.qualification_code, q.qualification_name, c.course_code, c.course_name
        ORDER BY eligible_count DESC, q.qualification_code ASC
        LIMIT 8
    ";

    try {
        $stmt = $pdo->prepare($qual_query);
        $stmt->execute();
        $qualification_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($qualification_stats as $q) {
            $cnt = (int)$q['eligible_count'];
            if ($cnt > $max_qual_value) $max_qual_value = $cnt;
        }
    } catch (Exception $e) {
        error_log("Qualification stats query failed: " . $e->getMessage());
        $qualification_stats = [];
    }
}

$max_qual_value = max(1, $max_qual_value);

// 9. RECENT COMPLETED
$recent_query = "
    SELECT DISTINCT 
        t.id, 
        t.first_name, 
        t.last_name, 
        UPPER(t.department) as department,
        MAX(et.updated_at) as completed_at
    FROM trainees t
    JOIN qualifications q ON t.qualification_id = q.id
    JOIN competency_standards cs ON cs.qualification_id = q.id
    JOIN performance_tasks pt ON pt.competency_id = cs.id
    LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
    WHERE t.deleted_at IS NULL
    AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
";
$recent_params = [];

if (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    $recent_query .= " AND t.qualification_id IN ($placeholders)";
    $recent_params = $qualification_ids;
}

$recent_query .= "
    GROUP BY t.id
    HAVING 
        COUNT(DISTINCT pt.id) > 0
        AND COUNT(DISTINCT CASE WHEN LOWER(et.remarks) IN ('complied', 'completed', 'done') THEN pt.id END) = COUNT(DISTINCT pt.id)
    ORDER BY completed_at DESC
    LIMIT 10
";

$stmt = $pdo->prepare($recent_query);
$stmt->execute($recent_params);
$recent_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 10. RECENT ELIGIBLE
$eligible_recent_query = "
    SELECT DISTINCT 
        t.id, 
        t.first_name, 
        t.last_name, 
        UPPER(t.department) as department,
        MAX(et.updated_at) as eligible_at
    FROM trainees t
    JOIN qualifications q ON t.qualification_id = q.id
    JOIN competency_standards cs ON cs.qualification_id = q.id
    JOIN performance_tasks pt ON pt.competency_id = cs.id
    LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
    WHERE t.deleted_at IS NULL
    AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
";
$eligible_recent_params = [];

if (!$is_coordinator && !empty($qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($qualification_ids), '?'));
    $eligible_recent_query .= " AND t.qualification_id IN ($placeholders)";
    $eligible_recent_params = $qualification_ids;
}

$eligible_recent_query .= "
    GROUP BY t.id
    HAVING 
        COUNT(DISTINCT pt.id) > 0
        AND COUNT(DISTINCT CASE WHEN LOWER(et.remarks) IN ('complied', 'completed', 'done') THEN pt.id END) = COUNT(DISTINCT pt.id)
    ORDER BY eligible_at DESC
    LIMIT 10
";

$stmt = $pdo->prepare($eligible_recent_query);
$stmt->execute($eligible_recent_params);
$recent_eligible = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 11. RECENT ACTIVITIES
$recent_activities = [
    [
        'icon' => 'fa-check-double',
        'icon_color' => '#2563eb',
        'icon_bg' => '#dbeafe',
        'label' => 'Competency Tracking',
        'description' => 'Evaluated core competencies and task compliance',
        'timestamp' => date('h:i A'),
        'type' => 'competency',
        'link' => 'tasks.php'
    ],
    [
        'icon' => 'fa-chart-line',
        'icon_color' => '#059669',
        'icon_bg' => '#d1fae5',
        'label' => 'Progress Updates',
        'description' => 'Tracked overall trainee completion progress',
        'timestamp' => date('h:i A'),
        'type' => 'progress',
        'link' => 'progress.php'
    ],
    [
        'icon' => 'fa-bell',
        'icon_color' => '#d97706',
        'icon_bg' => '#fef3c7',
        'label' => 'System Notifications',
        'description' => 'Email and SMS alerts sent to trainers and trainees',
        'timestamp' => date('h:i A'),
        'type' => 'notification',
        'link' => 'notification_history.php'
    ]
];

$dept_full_names = [
    'ATS' => 'Automotive Mechanics',
    'CSS' => 'Computer Systems Servicing',
    'HRS' => 'Hotel and Restaurant Services'
];

$dept_icons = [
    'ATS' => 'fa-car',
    'CSS' => 'fa-laptop-code',
    'HRS' => 'fa-utensils'
];

$user_display_name = $user['full_name'] ?? $user['username'] ?? 'User';
$user_role_display = $is_coordinator ? 'Coordinator' : 'Trainer';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f8fafc;
            min-width: 1400px;
            overflow-x: auto;
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-width: 1120px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            animation: fadeInPage 0.4s ease-out;
        }

        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        body.sidebar-collapsed .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
        }

        /* HEADER */
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border: 1px solid rgba(226, 232, 240, 0.4);
            position: relative;
        }

        .header-left { display: flex; flex-direction: column; gap: 2px; }

        .header-left h1 {
            color: #1a1c2c;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }

        .header-left .header-subtitle {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 400;
            margin: 0;
        }

        .header-actions { display: flex; align-items: center; gap: 15px; }

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

        .profile-badge:hover { background-color: #f1f5f9; border-color: #cbd5e1; }

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

        .profile-badge:hover .avatar-circle { transform: scale(1.05); }

        .profile-badge .avatar-circle i { color: #ffffff; font-size: 0.9rem; }

        .profile-badge .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .date-badge {
            background-color: #ffffff;
            padding: 10px 20px;
            border-radius: 12px;
            color: #1e293b;
            font-weight: 500;
            font-size: 1rem;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-badge i { color: #1e293b; font-size: 1rem; }

        /* TOP STAT CARDS */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: white;
            border-radius: 16px;
            border: 1px solid rgba(226, 232, 240, 0.4);
            padding: 24px 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            min-height: 140px;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .metric-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.06); }

        .metric-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            height: 4px;
            border-radius: 4px 4px 0 0;
        }

        .metric-card.accent-blue::before { background: #3b82f6; }
        .metric-card.accent-green::before { background: #10b981; }
        .metric-card.accent-pink::before { background: #ec4899; }
        .metric-card.accent-amber::before { background: #f59e0b; }

        .metric-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }

        .metric-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .metric-card:hover .metric-icon { transform: scale(1.05); }

        .metric-icon.blue { background: #dbeafe; color: #2563eb; }
        .metric-icon.green { background: #d1fae5; color: #059669; }
        .metric-icon.pink { background: #fce7f3; color: #db2777; }
        .metric-icon.amber { background: #fef3c7; color: #d97706; }

        .metric-title {
            font-size: 0.9rem;
            font-weight: 500;
            color: #1e293b;
            letter-spacing: 0.2px;
        }

        .metric-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .metric-number.update-flash {
            color: #0f172a !important;
            animation: pulse 0.5s ease;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .metric-card .metric-progress {
            width: 100%;
            height: 6px;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 4px;
        }

        .metric-card.accent-blue .metric-progress { background: rgba(59, 130, 246, 0.12); }
        .metric-card.accent-blue .metric-progress-bar {
            background: linear-gradient(90deg, #2563eb, #3b82f6);
            height: 100%;
            border-radius: 10px;
        }
        .metric-card.accent-green .metric-progress { background: rgba(16, 185, 129, 0.12); }
        .metric-card.accent-green .metric-progress-bar {
            background: linear-gradient(90deg, #16a34a, #10b981);
            height: 100%;
            border-radius: 10px;
        }
        .metric-card.accent-pink .metric-progress { background: rgba(236, 72, 153, 0.12); }
        .metric-card.accent-pink .metric-progress-bar {
            background: linear-gradient(90deg, #db2777, #ec4899);
            height: 100%;
            border-radius: 10px;
        }
        .metric-card.accent-amber .metric-progress { background: rgba(245, 158, 11, 0.12); }
        .metric-card.accent-amber .metric-progress-bar {
            background: linear-gradient(90deg, #d97706, #f59e0b);
            height: 100%;
            border-radius: 10px;
        }

        .metric-footer {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 6px;
            font-weight: 400;
            display: flex;
            justify-content: space-between;
        }

        .metric-footer .metric-percent { font-weight: 500; color: #334155; }

        /* ========================================== */
        /* TWO-CARD LAYOUT — DYNAMIC (1 col for Trainer, 2 cols for Coordinator) */
        /* ========================================== */
        .completion-cards-grid {
            display: grid;
            grid-template-columns: <?= $is_coordinator ? '1fr 1fr' : '1fr' ?>;
            gap: 25px;
            margin-bottom: 30px;
        }

        .completion-card {
            background: white;
            border-radius: 20px;
            padding: 24px 28px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border: 1px solid rgba(226, 232, 240, 0.4);
            display: flex;
            flex-direction: column;
        }

        .completion-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .completion-card-header-left { display: flex; align-items: center; gap: 12px; }

        .completion-icon-badge {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .completion-icon-badge.orange { background: #fef3c7; color: #d97706; }
        .completion-icon-badge.purple { background: #ede9fe; color: #7c3aed; }

        .completion-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #0F172A;
            letter-spacing: -0.3px;
        }

        .completion-card-link {
            color: #2563EB;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .completion-card-body { display: flex; gap: 24px; flex: 1; align-items: center; }

        .circular-chart-container { flex: 0 0 140px; position: relative; }

        .circular-chart-container svg { display: block; width: 140px; height: 140px; }

        .circular-chart-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .circular-chart-number {
            font-size: 1.4rem;
            font-weight: 700;
            color: #0F172A;
            line-height: 1;
        }

        .circular-chart-label {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 400;
            margin-top: 2px;
        }

        .completion-stats { flex: 1; display: flex; flex-direction: column; gap: 12px; }

        .completion-stat-item { display: flex; align-items: center; justify-content: space-between; }

        .completion-stat-left { display: flex; align-items: center; gap: 10px; }

        .completion-stat-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

        .completion-stat-dot.green { background: #10B981; }
        .completion-stat-dot.red { background: #FF5A65; }

        .completion-stat-text {
            font-size: 0.8rem;
            color: #334155;
            font-weight: 500;
        }

        .completion-stat-percent {
            font-size: 0.8rem;
            font-weight: 600;
            color: #0F172A;
        }

        .completion-stat-percent.green { color: #10B981; }
        .completion-stat-percent.red { color: #FF5A65; }

        .completion-progress-bar {
            width: 100%;
            height: 6px;
            background: #f1f5f9;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 2px;
        }

        .completion-progress-bar .progress-fill { height: 100%; border-radius: 10px; }
        .completion-progress-bar .progress-fill.green { background: #10B981; }
        .completion-progress-bar .progress-fill.red { background: #FF5A65; }

        .completion-info-banner {
            margin-top: 16px;
            padding: 10px 14px;
            background: #eff6ff;
            border-radius: 10px;
            font-size: 0.8rem;
            color: #1e40af;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .completion-info-banner i { color: #3b82f6; }

        /* ========================================== */
        /* CLASSIC BAR CHART — PRE-ASSESSMENT BY QUALIFICATION */
        /* ========================================== */
        .classic-bar-chart-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 8px 0;
            min-height: 320px;
            position: relative;
        }

        .classic-bar-chart-svg {
            width: 100%;
            height: 280px;
            display: block;
            overflow: visible;
        }

        /* Bar hover tooltip */
        .bar-hover-tooltip {
            position: absolute;
            background: #1e293b;
            color: #ffffff;
            padding: 8px 14px;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            font-weight: 600;
            pointer-events: none;
            opacity: 0;
            transform: translate(-50%, -100%);
            transition: opacity 0.15s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            z-index: 100;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .bar-hover-tooltip::after {
            content: '';
            position: absolute;
            bottom: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid #1e293b;
        }

        .bar-hover-tooltip.show {
            opacity: 1;
        }

        .bar-hover-value {
            font-size: 14px;
            font-weight: 700;
            color: #a78bfa;
        }

        .bar-hover-label {
            font-size: 11px;
            color: #cbd5e1;
            font-weight: 500;
        }

        .bar-rect {
            cursor: pointer;
            transition: all 0.6s ease, filter 0.2s ease;
        }

        .bar-rect:hover {
            filter: brightness(1.15);
        }

        /* DEPARTMENT OVERVIEW */
        .dept-overview {
            background: white;
            border-radius: 20px;
            padding: 24px 28px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border: 1px solid rgba(226, 232, 240, 0.4);
        }

        .dept-overview.coordinator-only { display: block; }
        .dept-overview.trainer-hidden { display: none !important; }

        .dept-overview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .dept-overview-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            color: #0F172A;
        }

        .dept-overview-title i { color: #667eea; }

        .dept-cards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }

        .dept-card-compact {
            border-radius: 16px;
            padding: 24px 20px;
            border: 1px solid rgba(226, 232, 240, 0.4);
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            cursor: pointer;
            background: white;
        }

        .dept-card-compact.border-teal { border-left: 4px solid #10b981; }
        .dept-card-compact.border-blue { border-left: 4px solid #3b82f6; }
        .dept-card-compact.border-purple { border-left: 4px solid #8b5cf6; }

        .dept-card-compact.bg-teal { background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%); }
        .dept-card-compact.bg-blue { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); }
        .dept-card-compact.bg-purple { background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%); }

        .dept-card-compact-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }

        .dept-card-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .dept-card-icon.teal { background: #d1fae5; color: #059669; }
        .dept-card-icon.blue { background: #dbeafe; color: #2563eb; }
        .dept-card-icon.purple { background: #ede9fe; color: #7c3aed; }

        .dept-card-name {
            font-size: 0.8rem;
            font-weight: 500;
            color: #1e293b;
        }

        .dept-card-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0F172A;
            line-height: 1.1;
            margin-bottom: 2px;
        }

        .dept-card-label {
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 400;
            margin-bottom: 12px;
        }

        .dept-card-compact .dept-card-progress {
            width: 100%;
            height: 6px;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .dept-card-compact.border-teal .dept-card-progress { background: rgba(16, 185, 129, 0.15); }
        .dept-card-compact.border-teal .dept-progress-fill {
            background: #16a34a;
            height: 100%;
            border-radius: 10px;
        }
        .dept-card-compact.border-blue .dept-card-progress { background: rgba(59, 130, 246, 0.15); }
        .dept-card-compact.border-blue .dept-progress-fill {
            background: #2563eb;
            height: 100%;
            border-radius: 10px;
        }
        .dept-card-compact.border-purple .dept-card-progress { background: rgba(139, 92, 246, 0.15); }
        .dept-card-compact.border-purple .dept-progress-fill {
            background: #7c3aed;
            height: 100%;
            border-radius: 10px;
        }

        .dept-card-stats { display: flex; justify-content: space-between; margin-bottom: 14px; }

        .dept-card-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            background: rgba(255,255,255,0.6);
            padding: 4px 12px;
            border-radius: 20px;
            backdrop-filter: blur(4px);
        }

        .dept-card-stat .stat-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

        .dept-card-stat .stat-dot.green { background: #10b981; }
        .dept-card-stat .stat-dot.red { background: #ef4444; }

        .dept-card-stat .stat-label {
            color: #64748b;
            font-weight: 400;
            font-size: 0.7rem;
        }

        .dept-card-stat .stat-number {
            color: #0F172A;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .dept-card-stat .stat-number.green { color: #10b981; }
        .dept-card-stat .stat-number.red { color: #ef4444; }

        /* BOTTOM TWO-COLUMN LAYOUT */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border: 1px solid rgba(226, 232, 240, 0.4);
            display: flex;
            flex-direction: column;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .card-header h2 {
            color: #1a1c2c;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .card-header a {
            color: #2563EB;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .student-list { margin-top: 4px; flex: 1; }

        .student-table { width: 100%; border-collapse: collapse; }

        .student-table th {
            text-align: left;
            padding: 12px 8px;
            color: #1e293b;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #e2e8f0;
        }

        .student-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            color: #1e293b;
            vertical-align: middle;
        }

        .student-table tbody tr { cursor: pointer; }
        .student-table tbody tr:hover { background: #f8fafc; }

        .student-table th:first-child,
        .student-table td:first-child { padding-left: 0; }

        .student-table th:last-child,
        .student-table td:last-child { text-align: right; padding-right: 0; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-complied { background: #d1fae5; color: #065f46; }
        .status-eligible { background: #dbeafe; color: #1e40af; }

        .empty-state {
            text-align: center;
            padding: 30px 10px;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .icon-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .icon-badge.green { background: #d1fae5; color: #059669; }
        .icon-badge.purple { background: #ede9fe; color: #7c3aed; }
        .icon-badge.orange { background: #fef3c7; color: #d97706; }

        .dept-badge {
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            display: inline-block;
            color: #1e293b;
        }

        /* RECENT SYSTEM ACTIVITY CARD */
        .activity-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border: 1px solid rgba(226, 232, 240, 0.4);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .activity-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .activity-card-header-left { display: flex; align-items: center; gap: 12px; }

        .activity-card-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #dbeafe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #3b82f6;
            flex-shrink: 0;
        }

        .activity-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #0F172A;
        }

        .activity-view-link {
            color: #2563EB;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .activity-list { flex: 1; display: flex; flex-direction: column; gap: 12px; }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            background: #f8fafc;
            border-radius: 12px;
            gap: 14px;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .activity-item:hover { background: #f1f5f9; border-color: rgba(226, 232, 240, 0.6); }

        .activity-item-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .activity-item-content { flex: 1; display: flex; flex-direction: column; gap: 2px; }

        .activity-item-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #0F172A;
        }

        .activity-item-description {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 400;
        }

        .activity-item-time {
            font-size: 0.85rem;
            color: #94a3b8;
            font-weight: 400;
            white-space: nowrap;
            margin-left: 8px;
        }

        .activity-empty-state {
            text-align: center;
            padding: 30px 10px;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #cbd5e1, #94a3b8);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #94a3b8, #64748b);
        }

        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .bottom-grid { grid-template-columns: 1fr 1fr; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .dept-cards-grid { grid-template-columns: repeat(2, 1fr); }
            .completion-cards-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .bottom-grid { grid-template-columns: 1fr; }
            .metrics-grid { grid-template-columns: 1fr; }
            .dept-cards-grid { grid-template-columns: 1fr; }
            .completion-cards-grid { grid-template-columns: 1fr; }
            .dept-overview-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .student-table th, .student-table td { font-size: 0.85rem; padding: 6px 4px; }
            .completion-card-body { flex-direction: column; align-items: center; }
            .activity-item { flex-wrap: wrap; }
            .activity-item-time { margin-left: 0; width: 100%; padding-left: 54px; }
            .header { flex-direction: column; gap: 12px; align-items: flex-start; }
            .header-actions { width: 100%; justify-content: flex-start; flex-wrap: wrap; }
            .profile-badge { padding: 6px 12px 6px 6px; }
            .profile-badge .profile-name { font-size: 0.85rem; }
        }
    </style>

    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>Welcome, <?= htmlspecialchars($user_display_name) ?>!</h1>
                <p class="header-subtitle"><?= htmlspecialchars($user_role_display) ?></p>
            </div>
            <div class="header-actions">
                <div class="profile-badge">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="profile-name"><?= htmlspecialchars($user_display_name) ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>

        <!-- TOP STAT CARDS -->
        <div class="metrics-grid" id="metricsGrid">
            <div class="metric-card accent-blue" onclick="window.location.href='trainees.php'">
                <div class="metric-header">
                    <div class="metric-icon blue"><i class="fas fa-users"></i></div>
                    <span class="metric-title">Total Trainees</span>
                </div>
                <div class="metric-number" id="totalTrainees"><?= number_format($total_trainees) ?></div>
                <div class="metric-progress">
                    <div class="metric-progress-bar blue" style="width: 100%;"></div>
                </div>
                <div class="metric-footer">
                    <span>Active enrolled trainees</span>
                    <span class="metric-percent">100%</span>
                </div>
            </div>

            <div class="metric-card accent-green" onclick="window.location.href='tasks.php'">
                <div class="metric-header">
                    <div class="metric-icon green"><i class="fas fa-tasks"></i></div>
                    <span class="metric-title">Total Tasks</span>
                </div>
                <div class="metric-number" id="totalTasks"><?= number_format($total_tasks) ?></div>
                <div class="metric-progress">
                    <div class="metric-progress-bar green" style="width: 100%;"></div>
                </div>
                <div class="metric-footer">
                    <span>Total performance tasks</span>
                    <span class="metric-percent">100%</span>
                </div>
            </div>

            <div class="metric-card accent-pink" onclick="window.location.href='evaluation.php?filter=complied'">
                <div class="metric-header">
                    <div class="metric-icon pink"><i class="fas fa-check-circle"></i></div>
                    <span class="metric-title">Completed All Tasks</span>
                </div>
                <div class="metric-number" id="totalEvaluations"><?= number_format($completed_all_tasks) ?></div>
                <div class="metric-progress">
                    <div class="metric-progress-bar pink" style="width: <?= $completed_percent ?>%;"></div>
                </div>
                <div class="metric-footer">
                    <span>Active trainees who finished all tasks</span>
                    <span class="metric-percent"><?= $completed_percent ?>%</span>
                </div>
            </div>

            <div class="metric-card accent-amber" onclick="window.location.href='evaluation.php?filter=not_complied'">
                <div class="metric-header">
                    <div class="metric-icon amber"><i class="fas fa-exclamation-triangle"></i></div>
                    <span class="metric-title">Not Complied</span>
                </div>
                <div class="metric-number" id="notComplied"><?= number_format($not_complied) ?></div>
                <div class="metric-progress">
                    <div class="metric-progress-bar amber" style="width: <?= $not_complied_percent ?>%;"></div>
                </div>
                <div class="metric-footer">
                    <span>Active trainees not complied</span>
                    <span class="metric-percent"><?= $not_complied_percent ?>%</span>
                </div>
            </div>
        </div>

        <!-- DEPARTMENT OVERVIEW -->
        <?php if ($is_coordinator && !empty($departments)): ?>
        <div class="dept-overview coordinator-only" id="deptOverview">
            <div class="dept-overview-header">
                <div class="dept-overview-title">
                    <i class="fas fa-building"></i>
                    Department Overview
                </div>
                <span style="font-size: 0.85rem; color:#64748b;">
                    Active Trainees: <strong><?= $dept_total ?></strong>
                </span>
            </div>
            <div class="dept-cards-grid" id="departmentGrid">
                <?php foreach ($departments as $dept): 
                    $code = $dept['dept_code'] ?? $dept['course_code'] ?? 'N/A';
                    $total = (int)($dept['total_trainees'] ?? 0);
                    $completed = (int)($dept['students_completed'] ?? 0);
                    $not_complied_dept = (int)($dept['students_not_complied'] ?? 0);
                    
                    $percent = $total > 0 ? round(($completed / $total) * 100) : 0;
                    
                    $icon_class = match($code) {
                        'ATS' => 'teal',
                        'CSS' => 'blue',
                        'HRS' => 'purple',
                        default => 'teal'
                    };
                    $border_class = match($code) {
                        'ATS' => 'border-teal',
                        'CSS' => 'border-blue',
                        'HRS' => 'border-purple',
                        default => 'border-teal'
                    };
                    $bg_class = match($code) {
                        'ATS' => 'bg-teal',
                        'CSS' => 'bg-blue',
                        'HRS' => 'bg-purple',
                        default => 'bg-teal'
                    };
                    $icon = $dept_icons[$code] ?? 'fa-building';
                    $full_name = $dept_full_names[$code] ?? $dept['course_name'] ?? $code;
                ?>
                <div class="dept-card-compact <?= $border_class ?> <?= $bg_class ?>" data-dept-code="<?= $code ?>" onclick="window.location.href='trainees.php?department=<?= $code ?>'">
                    <div class="dept-card-compact-header">
                        <div class="dept-card-icon <?= $icon_class ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <span class="dept-card-name"><?= htmlspecialchars($code) ?></span>
                    </div>
                    <div class="dept-card-number"><?= number_format($total) ?></div>
                    <div class="dept-card-label">Active Trainees</div>
                    <div class="dept-card-progress">
                        <div class="dept-progress-fill <?= $icon_class ?>" style="width: <?= $percent ?>%;"></div>
                    </div>
                    <div class="dept-card-stats">
                        <div class="dept-card-stat">
                            <span class="stat-dot green"></span>
                            <span class="stat-label">Completed:</span>
                            <span class="stat-number green"><?= number_format($completed) ?></span>
                        </div>
                        <div class="dept-card-stat">
                            <span class="stat-dot red"></span>
                            <span class="stat-label">Not Complied:</span>
                            <span class="stat-number red"><?= number_format($not_complied_dept) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="dept-overview trainer-hidden" id="deptOverview"></div>
        <?php endif; ?>

        <!-- TWO-CARD LAYOUT -->
        <div class="completion-cards-grid">
            <div class="completion-card" id="progressCard">
                <div class="completion-card-header">
                    <div class="completion-card-header-left">
                        <div class="completion-icon-badge orange">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <span class="completion-card-title">Student Completion Progress</span>
                    </div>
                    <a href="evaluation.php" class="completion-card-link">View Details →</a>
                </div>

                <div class="completion-card-body">
                    <div class="circular-chart-container">
                        <?php
                        $circumference = 2 * M_PI * 50;
                        $overall_percent = $total_trainees > 0 ? ($completed_all_tasks / $total_trainees) * 100 : 0;
                        $completed_angle = ($overall_percent / 100) * 360;
                        $completed_offset = $circumference - ($completed_angle / 360) * $circumference;
                        ?>
                        <svg width="140" height="140" viewBox="0 0 140 140">
                            <circle cx="70" cy="70" r="50" fill="none" stroke="#f1f5f9" stroke-width="14"/>
                            <circle cx="70" cy="70" r="50" fill="none" stroke="#10B981" stroke-width="14"
                                stroke-dasharray="<?= $circumference ?>"
                                stroke-dashoffset="<?= $completed_offset ?>"
                                stroke-linecap="round"
                                transform="rotate(-90 70 70)">
                                <animate attributeName="stroke-dashoffset" from="<?= $circumference ?>" to="<?= $completed_offset ?>" dur="0.8s" fill="freeze"/>
                            </circle>
                        </svg>
                        <div class="circular-chart-center">
                            <div class="circular-chart-number"><?= number_format($overall_percent, 1) ?>%</div>
                            <div class="circular-chart-label">Overall Completion</div>
                        </div>
                    </div>

                    <div class="completion-stats">
                        <div>
                            <div class="completion-stat-item">
                                <div class="completion-stat-left">
                                    <span class="completion-stat-dot green"></span>
                                    <span class="completion-stat-text"><?= number_format($completed_all_tasks) ?> Trainees Completed</span>
                                </div>
                                <span class="completion-stat-percent green"><?= number_format($overall_percent, 1) ?>%</span>
                            </div>
                            <div class="completion-progress-bar">
                                <div class="progress-fill green" style="width: <?= $overall_percent ?>%;"></div>
                            </div>
                        </div>

                        <div>
                            <div class="completion-stat-item">
                                <div class="completion-stat-left">
                                    <span class="completion-stat-dot red"></span>
                                    <span class="completion-stat-text"><?= number_format($not_complied) ?> Trainees Not Complied</span>
                                </div>
                                <span class="completion-stat-percent red"><?= $total_trainees > 0 ? number_format(($not_complied / $total_trainees) * 100, 1) : 0 ?>%</span>
                            </div>
                            <div class="completion-progress-bar">
                                <div class="progress-fill red" style="width: <?= $total_trainees > 0 ? ($not_complied / $total_trainees) * 100 : 0 ?>%;"></div>
                            </div>
                        </div>

                        <div class="completion-info-banner">
                            <i class="fas fa-info-circle"></i>
                            Only active trainees with all required tasks marked as completed are considered fully compliant.
                        </div>
                    </div>
                </div>
            </div>

            <!-- CLASSIC BAR CHART: PRE-ASSESSMENT ELIGIBILITY BY QUALIFICATION -->
            <!-- COORDINATOR ONLY — HIDDEN FROM TRAINERS -->
            <?php if ($is_coordinator): ?>
            <div class="completion-card" id="statusCard">
                <div class="completion-card-header">
                    <div class="completion-card-header-left">
                        <div class="completion-icon-badge purple">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <span class="completion-card-title">Pre-Assessment Eligibility by Qualification</span>
                    </div>
                    <a href="pre_assessment.php" class="completion-card-link" id="viewAllStatusLink">View Details →</a>
                </div>

                <div class="classic-bar-chart-wrapper">
                    <?php if (empty($qualification_stats)): ?>
                        <div class="empty-state" style="padding: 40px 10px;">
                            <i class="fas fa-chart-bar" style="font-size: 2rem; color: #cbd5e1; display: block; margin-bottom: 10px;"></i>
                            No qualification data available
                        </div>
                    <?php else: ?>
                        <?php
                            // Chart dimensions
                            $chart_width  = 520;
                            $chart_height = 280;
                            $padding_left   = 55;
                            $padding_right  = 20;
                            $padding_top    = 20;
                            $padding_bottom = 55;
                            $plot_width  = $chart_width - $padding_left - $padding_right;
                            $plot_height = $chart_height - $padding_top - $padding_bottom;

                            // Fixed Y-axis: 0 to 10 with step 2
                            $nice_max = 10;
                            $num_lines = 5;
                            $step = $nice_max / $num_lines;

                            // Grid lines
                            $y_grid = [];
                            for ($i = 0; $i <= $num_lines; $i++) {
                                $val = $i * $step;
                                $y = $padding_top + $plot_height - (($val / $nice_max) * $plot_height);
                                $y_grid[] = ['val' => $val, 'y' => round($y, 2)];
                            }

                            // Department-based colors
                            $dept_bar_colors = [
                                'ATS' => ['from' => '#fbbf24', 'to' => '#f59e0b'],
                                'CSS' => ['from' => '#34d399', 'to' => '#10b981'],
                                'HRS' => ['from' => '#a78bfa', 'to' => '#8b5cf6'],
                            ];

                            $fallback_color = ['from' => '#94a3b8', 'to' => '#64748b'];

                            // Bar positions
                            $qual_count = count($qualification_stats);
                            $bar_gap = 14;
                            $total_bars_width = $plot_width - ($bar_gap * ($qual_count + 1));
                            $bar_width = $qual_count > 0 ? $total_bars_width / $qual_count : 40;
                            if ($bar_width > 90) $bar_width = 90;
                            if ($bar_width < 30) $bar_width = 30;

                            $total_used_width = ($bar_width * $qual_count) + ($bar_gap * ($qual_count + 1));
                            $start_x_offset = $padding_left + (($plot_width - $total_used_width) / 2);
                        ?>
                        <svg class="classic-bar-chart-svg" viewBox="0 0 <?= $chart_width ?> <?= $chart_height ?>" preserveAspectRatio="xMidYMid meet">
                            <!-- Y-axis grid lines + labels -->
                            <?php foreach ($y_grid as $g): ?>
                                <line x1="<?= $padding_left ?>" y1="<?= $g['y'] ?>" 
                                      x2="<?= $chart_width - $padding_right ?>" y2="<?= $g['y'] ?>" 
                                      stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4,4"/>
                                <text x="<?= $padding_left - 10 ?>" y="<?= $g['y'] + 4 ?>" 
                                      text-anchor="end" font-size="11" fill="#64748b" 
                                      font-family="Poppins, sans-serif" font-weight="500">
                                    <?= $g['val'] ?>
                                </text>
                            <?php endforeach; ?>

                            <!-- X-axis baseline -->
                            <line x1="<?= $padding_left ?>" y1="<?= $padding_top + $plot_height ?>" 
                                  x2="<?= $chart_width - $padding_right ?>" y2="<?= $padding_top + $plot_height ?>" 
                                  stroke="#94a3b8" stroke-width="1.5"/>

                            <!-- Y-axis -->
                            <line x1="<?= $padding_left ?>" y1="<?= $padding_top ?>" 
                                  x2="<?= $padding_left ?>" y2="<?= $padding_top + $plot_height ?>" 
                                  stroke="#94a3b8" stroke-width="1.5"/>

                            <!-- Bars -->
                            <?php foreach ($qualification_stats as $i => $q): 
                                $count = (int)$q['eligible_count'];
                                $bar_height = $nice_max > 0 ? min(($count / $nice_max) * $plot_height, $plot_height) : 0;
                                $bar_x = $start_x_offset + ($i * ($bar_width + $bar_gap));
                                $bar_y = $padding_top + $plot_height - $bar_height;

                                // Detect department for color
                                $dept_code = null;
                                if (!empty($q['dept_code'])) {
                                    $dept_code = strtoupper(trim($q['dept_code']));
                                } else {
                                    $qual_code = strtoupper($q['qualification_code'] ?? '');
                                    $qual_name_up = strtoupper($q['qualification_name'] ?? '');

                                    foreach (['ATS', 'CSS', 'HRS'] as $d) {
                                        if (strpos($qual_code, $d) !== false || strpos($qual_name_up, $d) !== false) {
                                            $dept_code = $d;
                                            break;
                                        }
                                    }

                                    if ($dept_code === null) {
                                        if (strpos($qual_name_up, 'AUTOMOTIVE') !== false || strpos($qual_name_up, 'AUTO') !== false) {
                                            $dept_code = 'ATS';
                                        } elseif (strpos($qual_name_up, 'COMPUTER') !== false || strpos($qual_name_up, 'SYSTEM') !== false || strpos($qual_name_up, 'SERVICING') !== false) {
                                            $dept_code = 'CSS';
                                        } elseif (strpos($qual_name_up, 'HOTEL') !== false || strpos($qual_name_up, 'RESTAURANT') !== false || strpos($qual_name_up, 'FOOD') !== false || strpos($qual_name_up, 'BEVERAGE') !== false) {
                                            $dept_code = 'HRS';
                                        }
                                    }
                                }

                                $color = ($dept_code && isset($dept_bar_colors[$dept_code])) 
                                    ? $dept_bar_colors[$dept_code] 
                                    : $fallback_color;

                                $grad_id = 'barGrad' . $i;
                            ?>
                                <defs>
                                    <linearGradient id="<?= $grad_id ?>" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="<?= $color['from'] ?>"/>
                                        <stop offset="100%" stop-color="<?= $color['to'] ?>"/>
                                    </linearGradient>
                                </defs>

                                <!-- Bar with hover tooltip -->
                                <rect x="<?= round($bar_x, 2) ?>" 
                                      y="<?= round($bar_y, 2) ?>" 
                                      width="<?= round($bar_width, 2) ?>" 
                                      height="<?= round($bar_height, 2) ?>" 
                                      fill="url(#<?= $grad_id ?>)" 
                                      rx="3" ry="3"
                                      class="bar-rect"
                                      data-value="<?= number_format($count) ?>"
                                      data-name="<?= htmlspecialchars($q['qualification_name'] ?: 'Unnamed') ?>">
                                    <animate attributeName="height" from="0" to="<?= round($bar_height, 2) ?>" dur="0.6s" fill="freeze"/>
                                    <animate attributeName="y" from="<?= $padding_top + $plot_height ?>" to="<?= round($bar_y, 2) ?>" dur="0.6s" fill="freeze"/>
                                </rect>

                                <!-- X-axis label (qualification code) -->
                                <text x="<?= round($bar_x + $bar_width / 2, 2) ?>" 
                                      y="<?= $padding_top + $plot_height + 22 ?>" 
                                      text-anchor="middle" font-size="11" font-weight="600" 
                                      fill="#1e293b" font-family="Poppins, sans-serif">
                                    <?= htmlspecialchars($q['qualification_code'] ?: 'N/A') ?>
                                </text>
                            <?php endforeach; ?>

                            <!-- Y-axis title (rotated) -->
                            <text x="15" y="<?= $padding_top + $plot_height / 2 ?>" 
                                  text-anchor="middle" font-size="11" font-weight="600" 
                                  fill="#475569" font-family="Poppins, sans-serif"
                                  transform="rotate(-90 15 <?= $padding_top + $plot_height / 2 ?>)">
                                Number of Trainees
                            </text>

                            <!-- X-axis title -->
                            <text x="<?= $chart_width / 2 ?>" y="<?= $chart_height - 5 ?>" 
                                  text-anchor="middle" font-size="11" font-weight="600" 
                                  fill="#475569" font-family="Poppins, sans-serif">
                                Qualification
                            </text>
                        </svg>

                        <!-- Hover tooltip box -->
                        <div class="bar-hover-tooltip" id="barHoverTooltip">
                            <span class="bar-hover-value">0</span>
                            <span class="bar-hover-label">trainees</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- BOTTOM TWO-COLUMN LAYOUT -->
        <div class="bottom-grid">
            <div>
                <div class="card" id="recentCompleted" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h2>
                            <span class="icon-badge green"><i class="fas fa-graduation-cap"></i></span>
                            Recently Completed Trainees
                        </h2>
                        <a href="evaluation.php?filter=complied">View All →</a>
                    </div>
                    <div class="student-list" id="studentList">
                        <?php if (empty($recent_completed)): ?>
                            <div class="empty-state">No active trainees have completed all tasks yet</div>
                        <?php else: ?>
                            <table class="student-table">
                                <thead>
                                    <tr>
                                        <th>Trainee Name</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_completed as $student): ?>
<tr>
    <td><strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong></td>
    <td><span class="dept-badge"><?= htmlspecialchars($dept_full_names[$student['department']] ?? $student['department'] ?? 'General') ?></span></td>
    <td><span class="status-badge status-complied"><i class="fas fa-check-circle"></i> Complied</span></td>
</tr>
<?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" id="eligibleForPreassessmentCard">
                    <div class="card-header">
                        <h2>
                            <span class="icon-badge purple"><i class="fas fa-star"></i></span>
                            Eligible for Pre-Assessment
                        </h2>
                        <a href="pre_assessment.php">View Eligible →</a>
                    </div>
                    <div class="student-list" id="eligibleStudentList">
                        <?php if (empty($recent_eligible)): ?>
                            <div class="empty-state">No active trainees are eligible for pre-assessment yet</div>
                        <?php else: ?>
                            <table class="student-table">
                                <thead>
                                    <tr>
                                        <th>Trainee Name</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_eligible as $student): ?>
<tr>
    <td><strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong></td>
    <td><span class="dept-badge"><?= htmlspecialchars($dept_full_names[$student['department']] ?? $student['department'] ?? 'General') ?></span></td>
    <td><span class="status-badge status-eligible"><i class="fas fa-star"></i> Eligible</span></td>
</tr>
<?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="activity-card" id="activityCard">
                <div class="activity-card-header">
                    <div class="activity-card-header-left">
                        <div class="activity-card-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <span class="activity-card-title">Recent System Activity</span>
                    </div>
                    <a href="#" class="activity-view-link" id="viewAllActivityLink">View All →</a>
                </div>

                <div class="activity-list" id="activityList">
                    <?php if (empty($recent_activities)): ?>
                        <div class="activity-empty-state">
                            <i class="fas fa-inbox" style="font-size: 1.8rem; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                            No recent activity
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item" onclick="window.location.href='<?= $activity['link'] ?>'">
                                <div class="activity-item-icon" style="background: <?= $activity['icon_bg'] ?>; color: <?= $activity['icon_color'] ?>;">
                                    <i class="fas <?= $activity['icon'] ?>"></i>
                                </div>
                                <div class="activity-item-content">
                                    <span class="activity-item-label"><?= htmlspecialchars($activity['label']) ?></span>
                                    <span class="activity-item-description"><?= htmlspecialchars($activity['description']) ?></span>
                                </div>
                                <span class="activity-item-time"><?= htmlspecialchars($activity['timestamp']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
    <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
    <script>
        // ==========================================
        // PUSHER REAL-TIME INTEGRATION
        // ==========================================
        
        let pusher = null;
        let pusherChannel = null;
        let pusherConnected = false;
        let refreshTimeout = null;
        const DEBOUNCE_DELAY = 500;

        const currentUser = {
            id: <?= json_encode($_SESSION['user_id'] ?? null) ?>,
            role: <?= json_encode($_SESSION['role'] ?? '') ?>,
            department: <?= json_encode($_SESSION['department'] ?? '') ?>,
            qualification_ids: <?= json_encode($qualification_ids ?? []) ?>,
            is_coordinator: <?= json_encode($is_coordinator) ?>
        };

        function initPusher() {
            try {
                if (typeof Pusher === 'undefined') {
                    console.log('⚠️ Pusher library not loaded');
                    return;
                }
                
                pusher = new Pusher('9bfff3facb287d7bbcbd', {
                    cluster: 'ap1',
                    forceTLS: true,
                    enabledTransports: ['ws', 'wss']
                });
                
                pusher.connection.bind('connected', function() {
                    console.log('✅ Pusher connected successfully');
                    pusherConnected = true;
                    updateConnectionStatus(true);
                });
                
                pusher.connection.bind('disconnected', function() {
                    console.log('❌ Pusher disconnected');
                    pusherConnected = false;
                    updateConnectionStatus(false);
                });
                
                pusher.connection.bind('error', function(error) {
                    console.error('⚠️ Pusher connection error:', error);
                    pusherConnected = false;
                });
                
                pusherChannel = pusher.subscribe('compliance-channel');
                
                var dashboardEvents = [
                    'dashboard_updated',
                    'task_evaluated',
                    'task_added',
                    'task_updated',
                    'task_deleted',
                    'trainee_added',
                    'trainee_updated',
                    'trainee_deleted',
                    'competency_updated',
                    'competency_added',
                    'competency_deleted',
                    'reminder_sent',
                    'pre_assessment_ready',
                    'pre_assessment_re_evaluated',
                    'progress_updated',
                    'evaluation_updated',
                    'global-update'
                ];
                
                dashboardEvents.forEach(function(eventName) {
                    pusherChannel.bind(eventName, function(data) {
                        console.log('📡 Pusher event received [' + eventName + ']:', data);
                        refreshDashboard();
                    });
                });
                
                pusherChannel.bind('pusher:subscription_succeeded', function() {
                    console.log('📡 Subscribed to Pusher channel: compliance-channel');
                });
                
                pusherChannel.bind('pusher:subscription_error', function(error) {
                    console.error('Pusher subscription error:', error);
                });
                
                console.log('✅ Pusher real-time listener initialized');
                
            } catch (e) {
                console.warn('Pusher initialization error:', e.message);
            }
        }

        function updateConnectionStatus(connected) {
            let statusEl = document.getElementById('realtime-status');
            if (statusEl) statusEl.style.display = 'none';
        }

        function refreshDashboard() {
            console.log('🔄 Refreshing dashboard data via AJAX...');
            
            document.querySelectorAll('.metric-number, .dept-card-number, .pending-count').forEach(el => {
                el.classList.add('update-flash');
                setTimeout(() => el.classList.remove('update-flash'), 600);
            });
            
            if (refreshTimeout) clearTimeout(refreshTimeout);
            
            refreshTimeout = setTimeout(function() {
                $.ajax({
                    url: 'api/get_dashboard_stats.php?t=' + new Date().getTime(),
                    type: 'GET',
                    dataType: 'json',
                    cache: false,
                    timeout: 10000,
                    success: function(response) {
                        if (response.success) {
                            updateDashboardUI(response.data);
                            console.log('✅ Dashboard updated successfully');
                        } else {
                            console.error('Error:', response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                    }
                });
            }, DEBOUNCE_DELAY);
        }

        function updateDashboardUI(data) {
            const total = data.total_trainees || 0;
            const totalTasks = data.total_tasks || 0;
            const completed = data.total_students_completed || data.completed_count || 0;
            const notComplied = data.students_with_not_complied || data.not_complied_count || 0;
            const activities = data.recent_activities || [];
            
            animateNumber('#totalTrainees', total);
            animateNumber('#totalTasks', totalTasks);
            animateNumber('#totalEvaluations', completed);
            animateNumber('#notComplied', notComplied);
            
            const completedPercent = total > 0 ? Math.round((completed / total) * 100) : 0;
            const notCompliedPercent = total > 0 ? Math.round((notComplied / total) * 100) : 0;
            
            $('.metric-card:nth-child(3) .metric-progress-bar').css('width', completedPercent + '%');
            $('.metric-card:nth-child(3) .metric-percent').text(completedPercent + '%');
            $('.metric-card:nth-child(4) .metric-progress-bar').css('width', notCompliedPercent + '%');
            $('.metric-card:nth-child(4) .metric-percent').text(notCompliedPercent + '%');
            
            <?php if ($is_coordinator): ?>
            if (data.departments && data.departments.length > 0) {
                updateDepartmentCards(data.departments);
            }
            <?php endif; ?>
            
            if (data.recent_completed && data.recent_completed.length > 0) {
                let studentHtml = `
                    <table class="student-table">
                        <thead>
                            <tr><th>Trainee Name</th><th>Department</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                `;
                data.recent_completed.forEach(function(student) {
                   studentHtml += `
    <tr>
        <td><strong>${escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</strong></td>
        <td><span class="dept-badge">${escapeHtml(getFullDepartmentName(student.department))}</span></td>
        <td><span class="status-badge status-complied"><i class="fas fa-check-circle"></i> Complied</span></td>
    </tr>
`;
                });
                studentHtml += `</tbody></table>`;
                $('#studentList').html(studentHtml);
            } else {
                $('#studentList').html('<div class="empty-state">No active trainees have completed all tasks yet</div>');
            }
            
            if (data.recent_eligible && data.recent_eligible.length > 0) {
                let eligibleHtml = `
                    <table class="student-table">
                        <thead>
                            <tr><th>Trainee Name</th><th>Department</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                `;
                data.recent_eligible.forEach(function(student) {
                    eligibleHtml += `
    <tr>
        <td><strong>${escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</strong></td>
        <td><span class="dept-badge">${escapeHtml(getFullDepartmentName(student.department))}</span></td>
        <td><span class="status-badge status-eligible"><i class="fas fa-star"></i> Eligible</span></td>
    </tr>
`;
                });
                eligibleHtml += `</tbody></table>`;
                $('#eligibleStudentList').html(eligibleHtml);
            } else {
                $('#eligibleStudentList').html('<div class="empty-state">No active trainees are eligible for pre-assessment yet</div>');
            }
            
            updateCompletionCards(data);
            updateActivityList(activities);
        }

        function updateCompletionCards(data) {
            const total = data.total_trainees || 0;
            const completed = data.total_students_completed || data.completed_count || 0;
            const notComplied = data.students_with_not_complied || data.not_complied_count || 0;
            
            const completedPercent = total > 0 ? ((completed / total) * 100).toFixed(1) : 0;
            const notCompliedPercent = total > 0 ? ((notComplied / total) * 100).toFixed(1) : 0;
            
            $('#progressCard .circular-chart-number').text(completedPercent + '%');
            $('#progressCard .completion-stat-item:first .completion-stat-percent').text(completedPercent + '%');
            $('#progressCard .completion-stat-item:last .completion-stat-percent').text(notCompliedPercent + '%');
            $('#progressCard .progress-fill.green').css('width', completedPercent + '%');
            $('#progressCard .progress-fill.red').css('width', notCompliedPercent + '%');
            $('#progressCard .completion-stat-text:first').text(formatNumber(completed) + ' Trainees Completed');
            $('#progressCard .completion-stat-text:last').text(formatNumber(notComplied) + ' Trainees Not Complied');
        }

        function updateActivityList(activities) {
            if (!activities || activities.length === 0) {
                $('#activityList').html(`
                    <div class="activity-empty-state">
                        <i class="fas fa-inbox" style="font-size: 1.8rem; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                        No recent activity
                    </div>
                `);
                return;
            }
            
            let html = '';
            activities.forEach(function(activity) {
                const link = activity.link || '#';
                html += `
                    <div class="activity-item" onclick="window.location.href='${link}'">
                        <div class="activity-item-icon" style="background: ${activity.icon_bg}; color: ${activity.icon_color};">
                            <i class="fas ${activity.icon}"></i>
                        </div>
                        <div class="activity-item-content">
                            <span class="activity-item-label">${escapeHtml(activity.label)}</span>
                            <span class="activity-item-description">${escapeHtml(activity.description)}</span>
                        </div>
                        <span class="activity-item-time">${escapeHtml(activity.timestamp)}</span>
                    </div>
                `;
            });
            $('#activityList').html(html);
        }

        <?php if ($is_coordinator): ?>
        function updateDepartmentCards(departments) {
            const deptCodes = ['ATS', 'CSS', 'HRS'];
            const deptMap = {};
            
            departments.forEach(function(dept) {
                const code = dept.dept_code || dept.course_code || 'N/A';
                deptMap[code] = dept;
            });
            
            deptCodes.forEach(function(code) {
                const dept = deptMap[code] || null;
                const total = dept ? parseInt(dept.total_trainees) || 0 : 0;
                const completed = dept ? parseInt(dept.students_completed) || 0 : 0;
                const notComplied = dept ? parseInt(dept.students_not_complied) || 0 : 0;
                
                let card = $(`.dept-card-compact[data-dept-code="${code}"]`);
                
                if (card.length > 0) {
                    const completedPercent = total > 0 ? Math.round((completed / total) * 100) : 0;
                    card.find('.dept-card-number').text(formatNumber(total));
                    card.find('.dept-progress-fill').css('width', completedPercent + '%');
                    card.find('.dept-card-stat:first .stat-number.green').text(formatNumber(completed));
                    card.find('.dept-card-stat:last .stat-number.red').text(formatNumber(notComplied));
                }
            });
        }
        <?php endif; ?>

        function animateNumber(selector, target) {
            const current = parseInt($(selector).text().replace(/,/g, '')) || 0;
            if (current === target) return;
            
            const duration = 500;
            const startTime = performance.now();
            const startValue = current;
            
            function updateNumber(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const currentValue = Math.round(startValue + (target - startValue) * eased);
                $(selector).text(formatNumber(currentValue));
                
                if (progress < 1) requestAnimationFrame(updateNumber);
            }
            requestAnimationFrame(updateNumber);
        }

        function getFullDepartmentName(code) {
            const names = {
                'ATS': 'Automotive Mechanics',
                'CSS': 'Computer Systems Servicing',
                'HRS': 'Hotel and Restaurant Services'
            };
            return names[code] || code || 'General';
        }

        function formatNumber(num) {
            if (num === undefined || num === null) return '0';
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        // ==========================================
        // BAR HOVER TOOLTIP — COORDINATOR ONLY
        // ==========================================
        function initBarTooltip() {
            const tooltip = document.getElementById('barHoverTooltip');
            if (!tooltip) return; // Trainer doesn't have this
            
            const totalTrainees = <?= (int)$total_trainees ?>;
            const bars = document.querySelectorAll('.bar-rect');
            
            bars.forEach(function(bar) {
                function showTooltip() {
                    const rawValue = bar.getAttribute('data-value') || '0';
                    const value = parseInt(rawValue.replace(/,/g, '')) || 0;
                    const percent = totalTrainees > 0 ? ((value / totalTrainees) * 100).toFixed(1) : '0.0';
                    
                    tooltip.querySelector('.bar-hover-value').textContent = rawValue;
                    tooltip.querySelector('.bar-hover-label').innerHTML = 
                        'trainee' + (value === 1 ? '' : 's') + 
                        ' <span style="color:#a78bfa;font-weight:700;">(' + percent + '%)</span>';
                    
                    const rect = bar.getBoundingClientRect();
                    const wrapper = bar.closest('.classic-bar-chart-wrapper').getBoundingClientRect();
                    
                    const x = rect.left + (rect.width / 2) - wrapper.left;
                    const y = rect.top - wrapper.top - 8;
                    
                    tooltip.style.left = x + 'px';
                    tooltip.style.top = y + 'px';
                    tooltip.classList.add('show');
                }
                
                function hideTooltip() {
                    tooltip.classList.remove('show');
                }
                
                bar.addEventListener('mouseenter', showTooltip);
                bar.addEventListener('mouseleave', hideTooltip);
                
                bar.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    showTooltip();
                    setTimeout(hideTooltip, 2500);
                });
                
                bar.addEventListener('click', function(e) {
                    e.stopPropagation();
                    showTooltip();
                    setTimeout(hideTooltip, 2500);
                });
            });
        }

        $(document).ready(function() {
            console.log('📊 Dashboard loading with Pusher real-time...');
            
            setTimeout(initPusher, 1000);
            initBarTooltip();
            
            setInterval(function() {
                $.ajax({
                    url: 'api/get_dashboard_stats.php?t=' + Date.now(),
                    type: 'GET',
                    dataType: 'json',
                    cache: false,
                    timeout: 10000,
                    success: function(response) {
                        if (response.success) {
                            updateDashboardUI(response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.warn('⚠️ Auto-poll failed:', error);
                    }
                });
            }, 4000);
        });

        window.triggerDashboardUpdate = function() { refreshDashboard(); };
        
        window.addEventListener('storage', function(e) {
            if (e.key === 'dashboard_update') refreshDashboard();
        });
        
        window.addEventListener('dashboard-update', function() { refreshDashboard(); });
    </script>

    <!-- ============================================================ -->
    <!-- BACK BUTTON GUARD — Force Logout When User Presses BACK      -->
    <!-- ============================================================ -->
    <script>
    (function() {
        'use strict';

        try {
            history.pushState({ page: 'dashboard', t: Date.now() }, '', location.href);
        } catch (e) {}

        window.addEventListener('popstate', function(event) {
            window.location.replace('logout.php');
        });

        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.replace('logout.php');
            }
        });
    })();
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