<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/auth_check.php';
if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$user_role = $_SESSION['role'] ?? '';
$user_department = $_SESSION['department'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// ==========================================
// PUSHER HELPER - Real-time updates
// ==========================================
require_once __DIR__ . '/helpers/helpers/PusherHelper.php';

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
// UPPER PHP BLOCK - STAT CARDS CALCULATIONS WITH RBAC
// ==========================================
try {
    if ($is_coordinator) {
        $sql = "SELECT COUNT(*) FROM trainees t 
                WHERE t.deleted_at IS NULL 
                AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $stat_total_trainees = (int) $stmt->fetchColumn();
    } else if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $sql = "SELECT COUNT(DISTINCT t.id) FROM trainees t 
                WHERE t.qualification_id IN ($placeholders) 
                AND t.deleted_at IS NULL
                AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($trainer_qualification_ids);
        $stat_total_trainees = (int) $stmt->fetchColumn();
    } else {
        $stat_total_trainees = 0;
    }

    $complied_sql = "
        SELECT COUNT(DISTINCT t.id) 
        FROM trainees t
        LEFT JOIN qualifications q ON t.qualification_id = q.id
        LEFT JOIN competency_standards cs ON cs.qualification_id = t.qualification_id
        LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
        LEFT JOIN (
            SELECT et_latest.trainee_id, et_latest.task_id, et_latest.remarks
            FROM evaluation_tracker et_latest
            INNER JOIN (
                SELECT trainee_id, task_id, MAX(updated_at) AS max_updated
                FROM evaluation_tracker
                GROUP BY trainee_id, task_id
            ) et_max 
              ON et_latest.trainee_id = et_max.trainee_id
             AND et_latest.task_id    = et_max.task_id
             AND et_latest.updated_at = et_max.max_updated
        ) et ON et.task_id = pt.id AND et.trainee_id = t.id
        WHERE t.deleted_at IS NULL
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
    ";
    
    $complied_params = [];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $complied_sql .= " AND t.qualification_id IN ($placeholders)";
        $complied_params = $trainer_qualification_ids;
    } elseif ($is_trainer) {
        $complied_sql .= " AND 1=0";
    }
    
    $complied_sql .= "
        GROUP BY t.id
        HAVING 
            COUNT(DISTINCT pt.id) > 0
            AND COUNT(DISTINCT CASE WHEN et.remarks = 'Done' THEN pt.id END) >= COUNT(DISTINCT pt.id)
    ";
    
    $complied_stmt = $pdo->prepare("
        SELECT COUNT(*) as complied_count
        FROM (
            $complied_sql
        ) as complied_trainees
    ");
    $complied_stmt->execute($complied_params);
    $stat_complied_count = (int) $complied_stmt->fetchColumn();

    $stat_compliance_rate = ($stat_total_trainees > 0) 
        ? round(($stat_complied_count / $stat_total_trainees) * 100) 
        : 0;

    $stat_non_compliant = $stat_total_trainees - $stat_complied_count;
    if ($stat_non_compliant < 0) { $stat_non_compliant = 0; }

    $avg_sql = "
        SELECT 
            t.id,
            COUNT(DISTINCT pt.id) as total_tasks,
            COUNT(DISTINCT CASE WHEN et.remarks = 'Done' THEN pt.id END) as completed_tasks
        FROM trainees t
        LEFT JOIN qualifications q ON t.qualification_id = q.id
        LEFT JOIN competency_standards cs ON cs.qualification_id = t.qualification_id
        LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
        LEFT JOIN (
            SELECT et_latest.trainee_id, et_latest.task_id, et_latest.remarks
            FROM evaluation_tracker et_latest
            INNER JOIN (
                SELECT trainee_id, task_id, MAX(updated_at) AS max_updated
                FROM evaluation_tracker
                GROUP BY trainee_id, task_id
            ) et_max 
              ON et_latest.trainee_id = et_max.trainee_id
             AND et_latest.task_id    = et_max.task_id
             AND et_latest.updated_at = et_max.max_updated
        ) et ON et.task_id = pt.id AND et.trainee_id = t.id
        WHERE t.deleted_at IS NULL
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
    ";
    
    $avg_params = [];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $avg_sql .= " AND t.qualification_id IN ($placeholders)";
        $avg_params = $trainer_qualification_ids;
    } elseif ($is_trainer) {
        $avg_sql .= " AND 1=0";
    }
    
    $avg_sql .= " GROUP BY t.id";
    
    $avg_stmt = $pdo->prepare($avg_sql);
    $avg_stmt->execute($avg_params);
    $avg_data = $avg_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_progress = 0;
    $progress_count = 0;
    foreach ($avg_data as $row) {
        $total_tasks = (int)($row['total_tasks'] ?? 0);
        $completed_tasks = (int)($row['completed_tasks'] ?? 0);
        if ($total_tasks > 0) {
            $progress = round(($completed_tasks / $total_tasks) * 100);
            $total_progress += $progress;
            $progress_count++;
        }
    }
    $stat_avg_progress = $progress_count > 0 ? round($total_progress / $progress_count) : 0;

} catch (PDOException $e) {
    $stat_total_trainees = 0;
    $stat_complied_count = 0;
    $stat_compliance_rate = 0;
    $stat_non_compliant = 0;
    $stat_avg_progress = 0;
}

$departments = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();

if ($is_coordinator) {
    $qualifications = $pdo->query("
        SELECT q.*, c.course_name, c.course_code 
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        ORDER BY c.course_code, q.qualification_name
    ")->fetchAll();
} else if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $qual_stmt = $pdo->prepare("
        SELECT q.*, c.course_name, c.course_code 
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        WHERE q.id IN ($placeholders)
        ORDER BY c.course_code, q.qualification_name
    ");
    $qual_stmt->execute($trainer_qualification_ids);
    $qualifications = $qual_stmt->fetchAll();
} else {
    $qualifications = [];
}

$selected_course = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$selected_qualification = isset($_GET['qualification']) ? (int)$_GET['qualification'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($is_coordinator) {
    $sql_dc = "SELECT DISTINCT UPPER(t.department) as dept_code 
        FROM trainees t
        WHERE t.department IS NOT NULL AND t.department != '' 
        AND t.deleted_at IS NULL 
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
        ORDER BY dept_code";
    $stmt_dc = $pdo->prepare($sql_dc);
    $stmt_dc->execute();
    $dept_codes = $stmt_dc->fetchAll(PDO::FETCH_COLUMN);
} else if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $sql_dc = "SELECT DISTINCT UPPER(t.department) as dept_code 
        FROM trainees t 
        WHERE t.department IS NOT NULL AND t.department != '' 
        AND t.deleted_at IS NULL
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
        AND t.qualification_id IN ($placeholders)
        ORDER BY dept_code";
    $stmt_dc = $pdo->prepare($sql_dc);
    $stmt_dc->execute($trainer_qualification_ids);
    $dept_codes = $stmt_dc->fetchAll(PDO::FETCH_COLUMN);
} else {
    $dept_codes = [];
}

if (empty($dept_codes)) { $dept_codes = ['N/A']; }

function buildFilterConditions($selected_course, $selected_qualification, $search, $user_role, $user_department, $is_trainer, $trainer_qualification_ids) {
    $conditions = [];
    $params = [];
    
    if ($selected_course > 0) {
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            global $pdo;
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM qualifications 
                WHERE course_id = ? AND id IN (" . implode(',', array_fill(0, count($trainer_qualification_ids), '?')) . ")
            ");
            $check_params = array_merge([$selected_course], $trainer_qualification_ids);
            $check_stmt->execute($check_params);
            if ($check_stmt->fetchColumn() > 0) {
                $conditions[] = "c.id = ?";
                $params[] = $selected_course;
            }
        } else if ($is_coordinator) {
            $conditions[] = "c.id = ?";
            $params[] = $selected_course;
        }
    }
    
    if ($selected_qualification > 0) { 
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            if (in_array($selected_qualification, $trainer_qualification_ids)) {
                $conditions[] = "t.qualification_id = ?";
                $params[] = $selected_qualification;
            }
        } else if ($is_coordinator) {
            $conditions[] = "t.qualification_id = ?";
            $params[] = $selected_qualification;
        }
    }
    
    if (!empty($search)) {
        $conditions[] = "(t.first_name LIKE ? OR t.last_name LIKE ? OR t.email LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param; $params[] = $search_param; $params[] = $search_param;
    }
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $conditions[] = "t.qualification_id IN ($placeholders)";
        $params = array_merge($params, $trainer_qualification_ids);
    } elseif ($is_trainer) {
        $conditions[] = "1=0";
    }
    
    $conditions[] = "t.deleted_at IS NULL";
    $conditions[] = "(t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))";
    
    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    return ['where' => $where_clause, 'params' => $params];
}

$filter_result = buildFilterConditions($selected_course, $selected_qualification, $search, $user_role, $user_department, $is_trainer, $trainer_qualification_ids);
$where_clause = $filter_result['where'];
$params = $filter_result['params'];

$completion_column = 'remarks';
$done_value = 'Done';

try {
    $check_col = $pdo->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'remarks'");
    if ($check_col->rowCount() == 0) {
        $check_col2 = $pdo->query("SHOW COLUMNS FROM evaluation_tracker LIKE 'status'");
        if ($check_col2->rowCount() > 0) {
            $completion_column = 'status';
            $done_value = 'complied';
        }
    }
} catch (PDOException $e) { }

$dept_stats = [];
foreach ($dept_codes as $dept_code) {
    $dept_code = strtoupper($dept_code);
    $dept_sql = "
        SELECT 
            COUNT(DISTINCT t.id) AS total_trainees,
            SUM(CASE WHEN task_counts.total_tasks > 0 AND task_counts.completed_tasks >= task_counts.total_tasks THEN 1 ELSE 0 END) AS complied_trainees,
            SUM(CASE WHEN task_counts.total_tasks > 0 AND task_counts.completed_tasks < task_counts.total_tasks THEN 1 WHEN task_counts.total_tasks = 0 THEN 1 ELSE 0 END) AS not_complied_trainees,
            COALESCE(AVG(CASE WHEN task_counts.total_tasks > 0 THEN (task_counts.completed_tasks / task_counts.total_tasks) * 100 ELSE 0 END), 0) AS avg_progress
        FROM trainees t
        LEFT JOIN (
            SELECT t2.id AS trainee_id,
                COUNT(DISTINCT pt.id) AS total_tasks,
                COUNT(DISTINCT CASE WHEN et.{$completion_column} = '{$done_value}' THEN pt.id END) AS completed_tasks
            FROM trainees t2
            LEFT JOIN competency_standards cs2 ON cs2.qualification_id = t2.qualification_id
            LEFT JOIN performance_tasks pt ON pt.competency_id = cs2.id
            LEFT JOIN (
                SELECT et_latest.trainee_id, et_latest.task_id, et_latest.remarks
                FROM evaluation_tracker et_latest
                INNER JOIN (
                    SELECT trainee_id, task_id, MAX(updated_at) AS max_updated
                    FROM evaluation_tracker
                    GROUP BY trainee_id, task_id
                ) et_max 
                  ON et_latest.trainee_id = et_max.trainee_id
                 AND et_latest.task_id    = et_max.task_id
                 AND et_latest.updated_at = et_max.max_updated
            ) et ON et.task_id = pt.id AND et.trainee_id = t2.id
            WHERE t2.deleted_at IS NULL
            AND (t2.status IS NULL OR (t2.status != 'deleted' AND t2.status != 'Completed'))
            GROUP BY t2.id
        ) task_counts ON task_counts.trainee_id = t.id
        WHERE UPPER(t.department) = ?
        AND t.deleted_at IS NULL
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
    ";
    
    $dept_params = [$dept_code];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $dept_sql .= " AND t.qualification_id IN ($placeholders)";
        $dept_params = array_merge($dept_params, $trainer_qualification_ids);
    } elseif ($is_trainer) {
        $dept_sql .= " AND 1=0";
    }
    
    $dept_stmt = $pdo->prepare($dept_sql);
    $dept_stmt->execute($dept_params);
    $dept_data = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    
    $total = (int)($dept_data['total_trainees'] ?? 0);
    $complied = (int)($dept_data['complied_trainees'] ?? 0);
    $not_complied = (int)($dept_data['not_complied_trainees'] ?? 0);
    
    $compliance_rate = $total > 0 ? round(($complied / $total) * 100) : 0;
    $avg_progress = round((float)($dept_data['avg_progress'] ?? 0));

    if ($not_complied !== ($total - $complied)) {
        $not_complied = $total - $complied;
        if ($not_complied < 0) $not_complied = 0;
    }

    $dept_stats[] = [
        'code' => $dept_code,
        'total_trainees' => $total,
        'complied' => $complied,
        'not_complied' => $not_complied,
        'compliance_rate' => $compliance_rate,
        'avg_progress' => $avg_progress
    ];
}

$trainee_sql = "
    SELECT t.id, t.first_name, t.last_name, t.email, UPPER(t.department) as dept_code,
        q.qualification_code as qualification_acronym, q.qualification_name, c.course_name as department_name,
        COUNT(DISTINCT pt.id) AS total_tasks,
        COUNT(DISTINCT CASE WHEN et.{$completion_column} = '{$done_value}' THEN pt.id END) AS completed_tasks,
        COUNT(DISTINCT cs.id) AS total_competencies,
        COUNT(DISTINCT CASE WHEN et.{$completion_column} = '{$done_value}' THEN cs.id END) AS completed_competencies
    FROM trainees t
    LEFT JOIN courses c ON t.department = c.course_code
    LEFT JOIN qualifications q ON t.qualification_id = q.id
    LEFT JOIN competency_standards cs ON cs.qualification_id = t.qualification_id
    LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
    LEFT JOIN (
        SELECT et_latest.trainee_id, et_latest.task_id, et_latest.remarks
        FROM evaluation_tracker et_latest
        INNER JOIN (
            SELECT trainee_id, task_id, MAX(updated_at) AS max_updated
            FROM evaluation_tracker
            GROUP BY trainee_id, task_id
        ) et_max 
          ON et_latest.trainee_id = et_max.trainee_id
         AND et_latest.task_id    = et_max.task_id
         AND et_latest.updated_at = et_max.max_updated
    ) et ON et.task_id = pt.id AND et.trainee_id = t.id
    $where_clause
    GROUP BY t.id, t.first_name, t.last_name, t.email, t.department, q.qualification_code, q.qualification_name, c.course_name
    HAVING COUNT(DISTINCT CASE WHEN et.{$completion_column} = '{$done_value}' THEN pt.id END) < COUNT(DISTINCT pt.id)
    ORDER BY dept_code, t.last_name, t.first_name
";

$trainee_stmt = $pdo->prepare($trainee_sql);
$trainee_stmt->execute($params);
$trainees_list = $trainee_stmt->fetchAll(PDO::FETCH_ASSOC);

$qualification_options = [];
if ($selected_course > 0) {
    if ($is_coordinator) {
        $qual_stmt = $pdo->prepare("SELECT id, qualification_name, qualification_code FROM qualifications WHERE course_id = ? ORDER BY qualification_name");
        $qual_stmt->execute([$selected_course]);
        $qualification_options = $qual_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $qual_stmt = $pdo->prepare("
            SELECT id, qualification_name, qualification_code 
            FROM qualifications 
            WHERE course_id = ? AND id IN ($placeholders)
            ORDER BY qualification_name
        ");
        $qual_stmt->execute(array_merge([$selected_course], $trainer_qualification_ids));
        $qualification_options = $qual_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$total_trainees_display = count($trainees_list);

// ============================================================
// STATUS DISTRIBUTION CHART DATA
// ============================================================
$qual_labels = [];
$qual_complied = [];
$qual_not_complied = [];

try {
    $base_qual_chart_sql = "
        SELECT 
            UPPER(COALESCE(q.qualification_code, q.qualification_name, 'UNASSIGNED')) AS qual_name,
            COUNT(DISTINCT CASE WHEN task_counts.total_tasks > 0 AND task_counts.completed_tasks >= task_counts.total_tasks THEN t.id END) AS complied_count,
            COUNT(DISTINCT t.id) AS total_trainees
        FROM trainees t
        LEFT JOIN qualifications q ON t.qualification_id = q.id
        LEFT JOIN (
            SELECT t2.id AS trainee_id,
                COUNT(DISTINCT pt.id) AS total_tasks,
                COUNT(DISTINCT CASE WHEN et.{$completion_column} = '{$done_value}' THEN pt.id END) AS completed_tasks
            FROM trainees t2
            LEFT JOIN competency_standards cs2 ON cs2.qualification_id = t2.qualification_id
            LEFT JOIN performance_tasks pt ON pt.competency_id = cs2.id
            LEFT JOIN (
                SELECT et_latest.trainee_id, et_latest.task_id, et_latest.remarks
                FROM evaluation_tracker et_latest
                INNER JOIN (
                    SELECT trainee_id, task_id, MAX(updated_at) AS max_updated
                    FROM evaluation_tracker
                    GROUP BY trainee_id, task_id
                ) et_max 
                  ON et_latest.trainee_id = et_max.trainee_id
                 AND et_latest.task_id    = et_max.task_id
                 AND et_latest.updated_at = et_max.max_updated
            ) et ON et.task_id = pt.id AND et.trainee_id = t2.id
            WHERE t2.deleted_at IS NULL
            AND (t2.status IS NULL OR (t2.status != 'deleted' AND t2.status != 'Completed'))
            GROUP BY t2.id
        ) task_counts ON task_counts.trainee_id = t.id
        WHERE q.qualification_code IS NOT NULL AND q.qualification_code != ''
        AND t.deleted_at IS NULL
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
    ";

    $base_params = [];
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $base_qual_chart_sql .= " AND t.qualification_id IN ($placeholders)";
        $base_params = $trainer_qualification_ids;
    } elseif ($is_trainer) {
        $base_qual_chart_sql .= " AND 1=0";
    }

    $all_sql = $base_qual_chart_sql . " GROUP BY UPPER(COALESCE(q.qualification_code, q.qualification_name, 'UNASSIGNED')) ORDER BY qual_name";
    $all_stmt = $pdo->prepare($all_sql);
    $all_stmt->execute($base_params);
    while ($row = $all_stmt->fetch(PDO::FETCH_ASSOC)) {
        $qual = $row['qual_name'];
        $total = (int)$row['total_trainees'];
        $complied = (int)$row['complied_count'];
        $not_complied = $total - $complied;
        if ($not_complied < 0) $not_complied = 0;

        $qual_labels[] = $qual;
        $qual_complied[] = $complied;
        $qual_not_complied[] = $not_complied;
    }

} catch (PDOException $e) { }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainee Progress - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <script src="/tesda_compliance/assets/js/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body { background: #f0f2f5; min-width: auto; overflow-x: hidden; margin: 0; transition: all 0.3s ease; }
        body.sidebar-collapsed { min-width: auto; }
        .main-content { 
            margin-left: 280px; 
            padding: 30px; 
            width: calc(100% - 280px); 
            min-width: auto; 
            max-width: 100%;
            transition: margin-left 0.3s ease, width 0.3s ease; 
            animation: fadeInPage 0.4s ease-out; 
            box-sizing: border-box;
            overflow-x: hidden;
        }
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        body.sidebar-collapsed .main-content { margin-left: 80px; width: calc(100% - 80px); }
        
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
            flex-wrap: wrap;
            gap: 15px;
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
            color: #3b82f6;
            font-size: 1.8rem; 
        }
        .header-left p { 
            color: #64748b; 
            font-size: 1rem; 
            margin: 0;
            margin-top: 2px;
        }
        .header-right { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        
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
            background: #f8fafc;
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
        .date-badge i { color: #0f172a; font-size: 1rem; }
        .date-badge:hover { background-color: #f1f5f9; }
        
        .stats-overview { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { 
            background: white; 
            border-radius: 16px; 
            padding: 24px 20px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); 
            border: 1px solid #e8edf5; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.12); }
        .stat-card .stat-label { font-size: 0.85rem; color: #94a3b8; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card .stat-value { font-size: 1.8rem; font-weight: 700; color: #1e293b; margin: 4px 0 2px 0; }
        .stat-card .stat-sub { font-size: 0.85rem; color: #64748b; }
        .stat-card .stat-icon { 
            width: 40px; 
            height: 40px; 
            border-radius: 10px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.5rem; 
            float: right;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover .stat-icon { transform: scale(1.05); }
        .stat-card .stat-icon.blue { background: #dbeafe; color: #3b82f6; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #10b981; }
        .stat-card .stat-icon.red { background: #fee2e2; color: #ef4444; }
        .stat-card .stat-icon.purple { background: #ede9fe; color: #8b5cf6; }
        
        .stat-value { transition: color 0.3s ease, transform 0.3s ease; }
        .stat-value.update-flash { color: #3b82f6; transform: scale(1.05); }
        
        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }
        .chart-grid.coordinator-only { display: grid; }
        .chart-grid.trainer-hidden { display: none !important; }
        
        .chart-grid .chart-card {
            background: white;
            border-radius: 20px;
            padding: 24px 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e8edf5;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        .chart-card:hover { box-shadow: 0 20px 40px rgba(0,0,0,0.12); }
        .chart-card .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .chart-card .chart-header h4 { font-size: 1rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .chart-card .chart-header h4 i { color: #8b5cf6; }
        .chart-card .chart-header .chart-badge { font-size: 0.7rem; padding: 4px 14px; border-radius: 30px; background: #f1f5f9; color: #475569; font-weight: 600; }
        .chart-container { position: relative; height: 220px; width: 100%; }
        .chart-full { grid-column: 1 / -1; }
        .chart-full .chart-container { height: 260px; }
        
        .filter-section { background: white; border-radius: 20px; padding: 24px 32px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .filter-form { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 180px; }
        .filter-group label { display: block; margin-bottom: 6px; color: #475569; font-weight: 600; font-size: 0.85rem; }
        .filter-group label i { color: #8b5cf6; margin-right: 6px; }
        .filter-group input, .filter-group select { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s ease; background: white; color: #1e293b; }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
        .filter-actions { display: flex; gap: 10px; align-items: center; margin-left: auto; }
        .btn-primary { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; border: none; padding: 12px 28px; border-radius: 12px; font-weight: 600; font-size: 1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(139,92,246,0.3); }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 2px solid #e2e8f0; padding: 12px 28px; border-radius: 12px; font-weight: 600; font-size: 1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s ease; }
        .btn-secondary:hover { background: #e2e8f0; transform: translateY(-2px); }
        
        .table-container { background: white; border-radius: 20px; padding: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; }
        .table-header { padding: 20px 28px; border-bottom: 1px solid #eef2f6; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: #fafbfc; }
        .table-header h3 { font-size: 1.1rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .table-header h3 i { color: #8b5cf6; }
        .table-header .count-badge { background: #eef2ff; color: #4338ca; padding: 4px 14px; border-radius: 30px; font-size: 0.85rem; font-weight: 600; }
        .table-scroll { overflow-x: auto; padding: 0 4px 4px 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        thead { background: #f8fafc; }
        th { text-align: left; padding: 16px 16px 14px 16px; font-weight: 600; color: #475569; border-bottom: 2px solid #eef2f6; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tr:hover td { background: #fafbfc; }
        
        .trainee-name { font-weight: 600; color: #1e293b; }
        .trainee-email { font-size: 0.85rem; color: #64748b; }
        .dept-tag { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 0.7rem; font-weight: 500; color: white; }
        .dept-tag.css { background: #10b981; }
        .dept-tag.ats { background: #f59e0b; }
        .dept-tag.hrs { background: #8b5cf6; }
        
        .progress-wrapper { display: flex; align-items: center; gap: 12px; min-width: 130px; }
        .progress-bar { flex: 1; height: 8px; background: #e2e8f0; border-radius: 10px; overflow: hidden; min-width: 80px; }
        .progress-bar .fill { height: 100%; border-radius: 10px; transition: width 0.6s ease; }
        .progress-bar .fill.success { background: #10b981; }
        .progress-bar .fill.warning { background: #f59e0b; }
        .progress-bar .fill.danger { background: #ef4444; }
        .progress-bar .fill.secondary { background: #94a3b8; }
        .progress-text { font-weight: 600; font-size: 0.85rem; min-width: 44px; text-align: right; }
        
        .status-badge { display: inline-block; padding: 6px 18px; border-radius: 30px; font-size: 0.75rem; font-weight: 600; border: 1px solid; }
        .status-badge.complied { background: #d1fae5; color: #065f46; border-color: #10b981; }
        .status-badge.not-complied { background: #fee2e2; color: #991b1b; border-color: #ef4444; }
        .status-badge.no-tasks { background: #f1f5f9; color: #475569; border-color: #94a3b8; }
        
        .action-btn { padding: 6px 14px; border-radius: 8px; font-size: 0.75rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .action-btn.view { background: #e0e7ff; color: #4338ca; }
        .action-btn.view:hover { background: #c7d2fe; transform: translateY(-1px); }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 3.5rem; color: #cbd5e1; display: block; margin-bottom: 16px; }
        .empty-state h4 { color: #1e293b; margin-bottom: 4px; }
        
        .no-access-message {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .no-access-message i {
            font-size: 3rem;
            color: #cbd5e1;
            display: block;
            margin-bottom: 16px;
        }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46a0);
        }
        
        @media (max-width: 1200px) {  
            body { min-width: auto; } 
            .main-content { margin-left: 80px; width: calc(100% - 80px); } 
            .stats-overview { grid-template-columns: repeat(2, 1fr); } 
            .chart-grid { grid-template-columns: 1fr; } 
        }
        
        @media (max-width: 768px) { 
            .main-content { margin-left: 0; width: 100%; padding: 15px; } 
            .stats-overview { grid-template-columns: 1fr; } 
            .filter-form { flex-direction: column; } 
            .filter-group { min-width: 100%; } 
            .filter-actions { margin-left: 0; width: 100%; justify-content: flex-start; } 
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-right { width: 100%; flex-wrap: wrap; }
            .profile-badge { padding: 6px 12px 6px 6px; }
            .profile-badge .profile-name { font-size: 0.85rem; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-chart-line"></i> Trainee Progress Dashboard</h1>
                <p>Track competency completion and performance across departments</p>
            </div>
            <div class="header-right">
                <div class="profile-badge">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>

        <!-- STATS OVERVIEW -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Trainees</div>
                <div class="stat-value" id="stat-total-trainees"><?= $stat_total_trainees ?></div>
                <div class="stat-sub">Registered trainees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Compliance Rate</div>
                <div class="stat-value text-success" id="stat-compliance-rate"><?= $stat_compliance_rate ?>%</div>
                <div class="stat-sub text-success fw-semibold"><span id="stat-complied-count"><?= $stat_complied_count ?></span> fully complied</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-label">Non-Compliant</div>
                <div class="stat-value text-danger" id="stat-non-compliant"><?= $stat_non_compliant ?></div>
                <div class="stat-sub text-danger fw-semibold">Need attention</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-percentage"></i></div>
                <div class="stat-label">Average Progress</div>
                <div class="stat-value text-primary" id="stat-avg-progress"><?= $stat_avg_progress ?>%</div>
                <div class="stat-sub">Overall completion</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="filter-section">
            <form method="GET" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Department</label>
                    <select name="course" id="courseFilter">
                        <option value="0">All Departments</option>
                        <?php foreach ($departments as $dept): 
                            if ($is_trainer && !$is_coordinator) {
                                $has_access = false;
                                foreach ($qualifications as $qual) {
                                    if ($qual['course_id'] == $dept['id']) {
                                        $has_access = true;
                                        break;
                                    }
                                }
                                if (!$has_access) continue;
                            }
                        ?>
                            <option value="<?= $dept['id'] ?>" <?= $selected_course == $dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-certificate"></i> Qualification</label>
                    <select name="qualification" id="qualificationFilter">
                        <option value="0">All Qualifications</option>
                        <?php 
                        $qual_list = $selected_course > 0 ? $qualification_options : $qualifications;
                        foreach ($qual_list as $qual): 
                            if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
                                if (!in_array($qual['id'], $trainer_qualification_ids)) {
                                    continue;
                                }
                            }
                        ?>
                            <option value="<?= $qual['id'] ?>" <?= $selected_qualification == $qual['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($qual['qualification_code'] . ' - ' . $qual['qualification_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" id="searchInput" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="progress.php" class="btn-secondary"><i class="fas fa-undo-alt"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- CHARTS SECTION -->
        <div class="chart-grid <?= ($is_coordinator) ? 'coordinator-only' : 'trainer-hidden' ?>" id="chartGrid">
            <div class="chart-card">
                <div class="chart-header">
                    <h4><i class="fas fa-building"></i> Department Compliance</h4>
                    <span class="chart-badge">Compliance Rate</span>
                </div>
                <div class="chart-container">
                    <canvas id="departmentComplianceChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h4><i class="fas fa-chart-bar"></i> Average Progress by Department</h4>
                    <span class="chart-badge">Progress %</span>
                </div>
                <div class="chart-container">
                    <canvas id="averageProgressChart"></canvas>
                </div>
            </div>
            <div class="chart-card chart-full">
                <div class="chart-header">
                    <h4><i class="fas fa-pie-chart"></i> Trainee Status Distribution</h4>
                    <span class="chart-badge">Complied vs Not Complied</span>
                </div>
                <div class="chart-container">
                    <canvas id="statusDistributionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Trainee Progress Details Table -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-users"></i> Trainee Progress Details</h3>
                <span class="count-badge"><?= $total_trainees_display ?> Trainees</span>
            </div>
            <div class="table-scroll">
                <?php if (empty($trainees_list)): ?>
                    <?php if ($is_trainer && !$is_coordinator && empty($trainer_qualification_ids)): ?>
                        <div class="no-access-message">
                            <i class="fas fa-lock"></i>
                            <h4>No Qualifications Assigned</h4>
                            <p>You don't have any qualifications assigned. Please contact the coordinator.</p>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h4>No Trainees Found</h4>
                            <p>No trainees match your current filters or all trainees have reached 100% compliance.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Trainee</th>
                                <th>Department</th>
                                <th>Qualification</th>
                                <th>Progress</th>
                                <th>Percentage</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trainees_list as $row): 
                                $total_tasks = (int)($row['total_tasks'] ?? 0);
                                $completed_tasks = (int)($row['completed_tasks'] ?? 0);
                                $total_competencies = (int)($row['total_competencies'] ?? 0);
                                $completed_competencies = (int)($row['completed_competencies'] ?? 0);
                                
                                if ($total_tasks > 0) {
                                    $progress_percent = round(($completed_tasks / $total_tasks) * 100);
                                } else {
                                    $progress_percent = 0;
                                }
                                
                                if ($total_tasks == 0) {
                                    $status_badge = '<span class="status-badge no-tasks"><i class="fa-regular fa-circle"></i> No Tasks</span>';
                                } elseif ($completed_tasks >= $total_tasks && $total_tasks > 0) {
                                    $status_badge = '<span class="status-badge complied"><i class="fas fa-check-circle"></i> Complied</span>';
                                } else {
                                    $status_badge = '<span class="status-badge not-complied"><i class="fas fa-times-circle"></i> Not Complied</span>';
                                }
                                
                                if ($progress_percent >= 100) {
                                    $bar_class = 'success';
                                } elseif ($progress_percent >= 50) {
                                    $bar_class = 'warning';
                                } elseif ($progress_percent > 0) {
                                    $bar_class = 'danger';
                                } else {
                                    $bar_class = 'secondary';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="trainee-name"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                    <div class="trainee-email"><i class="fas fa-envelope" style="font-size:0.7rem;"></i> <?= htmlspecialchars($row['email']) ?></div>
                                </td>
                                <td>
                                    <span class="dept-tag <?= strtolower($row['dept_code'] ?? '') ?>">
                                        <?= htmlspecialchars($row['dept_code'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($row['qualification_acronym'] ?? 'N/A') ?></div>
                                    <div style="font-size:0.7rem;color:#94a3b8;"><?= htmlspecialchars($row['qualification_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <div class="progress-wrapper">
                                        <div class="progress-bar">
                                            <div class="fill <?= $bar_class ?>" style="width: <?= $progress_percent ?>%;"></div>
                                        </div>
                                    </div>
                                    <div style="font-size:0.7rem;color:#94a3b8;margin-top:2px;">
                                        <?= $completed_competencies ?? 0 ?>/<?= $total_competencies ?? 0 ?> competencies
                                    </div>
                                </td>
                                <td>
                                    <span class="progress-text"><?= $progress_percent ?>%</span>
                                </td>
                                <td><?= $status_badge ?></td>
                                <td>
                                    <a href="evaluation.php?trainee_id=<?= $row['id'] ?>&department=<?= $row['dept_code'] ?? '' ?>" class="action-btn view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
    <script>
        // ==========================================
        // SEAMLESS FILTER HANDLING
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            const courseFilter = document.getElementById('courseFilter');
            const qualFilter = document.getElementById('qualificationFilter');
            
            if (courseFilter) {
                courseFilter.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            }

            if (qualFilter) {
                qualFilter.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            }

            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.getElementById('filterForm').submit();
                    }
                });
            }
        });

        // ==========================================
        // PUSHER REAL-TIME UPDATES FOR PROGRESS
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Pusher !== 'undefined') {
                const pusher = new Pusher('9bfff3facb287d7bbcbd', {
                    cluster: 'ap1',
                    forceTLS: true
                });

                const channel = pusher.subscribe('compliance-channel');

                channel.bind('progress_updated', function(data) {
                    console.log('📊 Progress update received:', data);
                    
                    if (data.payload) {
                        const payload = data.payload;
                        
                        if (payload.total_trainees !== undefined) {
                            document.getElementById('stat-total-trainees').textContent = payload.total_trainees;
                        }
                        if (payload.compliance_rate !== undefined) {
                            document.getElementById('stat-compliance-rate').textContent = payload.compliance_rate + '%';
                        }
                        if (payload.complied_count !== undefined) {
                            document.getElementById('stat-complied-count').textContent = payload.complied_count;
                        }
                        if (payload.non_compliant !== undefined) {
                            document.getElementById('stat-non-compliant').textContent = payload.non_compliant;
                        }
                        if (payload.average_progress !== undefined) {
                            document.getElementById('stat-avg-progress').textContent = payload.average_progress + '%';
                        }
                        
                        document.querySelectorAll('.stat-value').forEach(el => {
                            el.classList.add('update-flash');
                            setTimeout(() => el.classList.remove('update-flash'), 600);
                        });
                    }
                });

                channel.bind('task_evaluated', function(data) {
                    console.log('📋 Task evaluated, refreshing progress...');
                });

                channel.bind('pre_assessment_ready', function(data) {
                    console.log('🎯 Pre-assessment ready, refreshing progress...');
                });

                console.log('✅ Pusher real-time progress listener initialized');
            }
        });

        // ==========================================
        // DYNAMIC CHART DATA (Initial Load)
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart !== 'undefined') {
                Chart.defaults.font.family = "'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
                Chart.defaults.font.size = 12;
                Chart.defaults.color = '#475569';
            }

            <?php if ($is_coordinator): ?>
            try {
                const deptStats = <?= json_encode(array_values($dept_stats)) ?>;
                
                const labels = deptStats.map(d => d.code); 
                const complianceRates = deptStats.map(d => d.compliance_rate || 0);
                const avgProgress = deptStats.map(d => d.avg_progress || 0);
                const compliedCounts = deptStats.map(d => d.complied || 0);
                const notCompliedCounts = deptStats.map(d => d.not_complied || 0);
                
                const deptColors = { 'ATS': '#f59e0b', 'CSS': '#10b981', 'HRS': '#8b5cf6', 'N/A': '#6b7280' };
                const colors = labels.map(code => deptColors[code] || '#6b7280');

                const complianceCtx = document.getElementById('departmentComplianceChart').getContext('2d');
                const complianceChart = new Chart(complianceCtx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Compliance Rate (%)',
                            data: complianceRates,
                            backgroundColor: colors.map(c => c + 'CC'),
                            borderColor: colors,
                            borderWidth: 2,
                            borderRadius: 6,
                            maxBarThickness: 60
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + '%';
                                    }
                                }
                            }
                        },
                        scales: { 
                            y: { 
                                beginAtZero: true, 
                                max: 100, 
                                ticks: { callback: function(value) { return value + '%'; } } 
                            },
                            x: { grid: { display: false } }
                        }
                    }
                });

                const progressCtx = document.getElementById('averageProgressChart').getContext('2d');
                const progressChart = new Chart(progressCtx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Average Progress (%)',
                            data: avgProgress,
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#8b5cf6',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { 
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + '%';
                                    }
                                }
                            }
                        },
                        scales: { 
                            y: { 
                                beginAtZero: true, 
                                max: 100, 
                                ticks: { callback: function(value) { return value + '%'; } } 
                            },
                            x: { grid: { display: false } }
                        }
                    }
                });

                const statusChart = new Chart(document.getElementById('statusDistributionChart').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($qual_labels); ?>,
                        datasets: [
                            {
                                label: 'Complied',
                                data: <?php echo json_encode($qual_complied); ?>,
                                backgroundColor: '#10b981', 
                                borderRadius: 6,
                                maxBarThickness: 60
                            },
                            {
                                label: 'Not Complied',
                                data: <?php echo json_encode($qual_not_complied); ?>,
                                backgroundColor: '#ef4444', 
                                borderRadius: 6,
                                maxBarThickness: 60
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    padding: 20,
                                    font: { size: 11, family: "'Poppins', 'Segoe UI', sans-serif" }
                                }
                            }
                        },
                        scales: {
                            x: {
                                title: { display: true, text: 'Qualifications', font: { family: "'Poppins', 'Segoe UI', sans-serif" } },
                                grid: {
                                    display: true,
                                    drawOnChartArea: true,
                                    color: '#cbd5e1',
                                    lineWidth: 1.5,
                                    drawTicks: false
                                },
                                border: {
                                    color: '#94a3b8',
                                    width: 2
                                },
                                ticks: {
                                    padding: 10,
                                    font: { size: 12, weight: '600', family: "'Poppins', 'Segoe UI', sans-serif" }
                                }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { 
                                    stepSize: 1,
                                    font: { family: "'Poppins', 'Segoe UI', sans-serif" }
                                },
                                grid: {
                                    color: '#f1f5f9',
                                    drawTicks: false
                                }
                            }
                        }
                    }
                });

                window.complianceChart = complianceChart;
                window.progressChart = progressChart;
                window.statusChart = statusChart;
            } catch (error) {
                console.error("Chart initialization error:", error);
            }
            <?php endif; ?>
        });
    </script>
<script src="/tesda_compliance/assets/js/seamless-nav.js"></script>
<script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
<script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
<script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>