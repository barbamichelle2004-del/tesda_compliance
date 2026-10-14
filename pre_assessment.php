<?php
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
// RBAC: Get trainer qualification IDs
// ==========================================
$is_trainer = isTrainer();
$is_coordinator = isCoordinator();
$trainer_qualification_ids = [];

if ($is_trainer) {
    $trainer_qualification_ids = getTrainerQualificationIds($user_id);
    $_SESSION['qualification_ids'] = $trainer_qualification_ids;
}

$eligibilityService = new EligibilityService($db);

// ==========================================
// AJAX ENDPOINT - Returns JSON data for modal
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['trainee_id'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $trainee_id = (int)$_GET['trainee_id'];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $check_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $check_stmt->execute([$trainee_id]);
        $qual_id = $check_stmt->fetchColumn();
        if (!in_array($qual_id, $trainer_qualification_ids)) {
            echo json_encode(['success' => false, 'message' => 'Access denied to this trainee']);
            exit;
        }
    }
    
    $query = "
        SELECT 
            t.id, 
            t.first_name, 
            t.last_name, 
            t.section, 
            t.department,
            t.sex,
            t.qualification_id,
            q.qualification_code,
            q.qualification_name,
            c.course_code as dept_code,
            c.course_name as dept_name
        FROM trainees t
        JOIN courses c ON t.department = c.course_code
        LEFT JOIN qualifications q ON t.qualification_id = q.id
        WHERE t.id = ? AND t.deleted_at IS NULL
    ";
    $stmt = $db->prepare($query);
    $stmt->execute([$trainee_id]);
    $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trainee) {
        echo json_encode(['success' => false, 'message' => 'Trainee not found']);
        exit;
    }
    
    $comp_query = "
        SELECT 
            cs.id as competency_id,
            cs.competency_name,
            cs.code_no,
            cs.unit_of_competency as competency_type,
            (
                SELECT COUNT(DISTINCT pt.id)
                FROM performance_tasks pt
                WHERE pt.competency_id = cs.id
            ) as total_tasks,
            (
                SELECT COUNT(DISTINCT pt.id)
                FROM performance_tasks pt
                LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
                WHERE pt.competency_id = cs.id AND et.remarks = 'Done'
            ) as completed_tasks
        FROM competency_standards cs
        WHERE cs.qualification_id = ?
        ORDER BY cs.unit_of_competency, cs.id
    ";
    $comp_stmt = $db->prepare($comp_query);
    $comp_stmt->execute([$trainee_id, $trainee['qualification_id']]);
    $competencies = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($competencies as &$comp) {
        $task_query = "
            SELECT 
                pt.id as task_id,
                pt.task_name,
                et.id as tracker_id,
                et.remarks,
                CASE WHEN et.remarks = 'Done' THEN 1 ELSE 0 END as is_done
            FROM performance_tasks pt
            LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
            WHERE pt.competency_id = ?
            ORDER BY pt.id
        ";
        $task_stmt = $db->prepare($task_query);
        $task_stmt->execute([$trainee_id, $comp['competency_id']]);
        $comp['tasks'] = $task_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($comp);
    
    echo json_encode([
        'success' => true,
        'trainee' => $trainee,
        'competencies' => $competencies
    ]);
    exit;
}

// ==========================================
// AJAX ENDPOINT - Re-evaluate entire competency
// ==========================================
if (isset($_GET['re_evaluate_competency']) && $_GET['re_evaluate_competency'] == 1 && isset($_GET['competency_id']) && isset($_GET['trainee_id'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $competency_id = (int)$_GET['competency_id'];
    $trainee_id = (int)$_GET['trainee_id'];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $check_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $check_stmt->execute([$trainee_id]);
        $qual_id = $check_stmt->fetchColumn();
        if (!in_array($qual_id, $trainer_qualification_ids)) {
            echo json_encode(['success' => false, 'message' => 'Access denied to this trainee']);
            exit;
        }
    }
    
    try {
        $get_trackers = $db->prepare("
            SELECT et.id 
            FROM evaluation_tracker et
            JOIN performance_tasks pt ON et.task_id = pt.id
            WHERE et.trainee_id = ? AND pt.competency_id = ? AND et.remarks = 'Done'
        ");
        $get_trackers->execute([$trainee_id, $competency_id]);
        $tracker_ids = $get_trackers->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tracker_ids)) {
            echo json_encode(['success' => false, 'message' => 'No completed tasks found for this competency']);
            exit;
        }
        
        $placeholders = implode(',', array_fill(0, count($tracker_ids), '?'));
        $update = $db->prepare("
            UPDATE evaluation_tracker 
            SET remarks = 'Not Done', 
                status = 'not_complied', 
                evaluated_by = NULL, 
                evaluated_at = NULL, 
                updated_at = NOW() 
            WHERE id IN ($placeholders)
        ");
        $update->execute($tracker_ids);
        
        $update_trainee = $db->prepare("UPDATE trainees SET status = 'In Progress' WHERE id = ?");
        $update_trainee->execute([$trainee_id]);
        
        $check_pa_table = $db->query("SHOW TABLES LIKE 'pre_assessment'");
        if ($check_pa_table->rowCount() > 0) {
            $delete_pa = $db->prepare("DELETE FROM pre_assessment WHERE trainee_id = ?");
            $delete_pa->execute([$trainee_id]);
        }
        
        $update_elig = $db->prepare("UPDATE evaluation_eligibility SET is_eligible = 0, eligibility_date = NULL WHERE trainee_id = ?");
        $update_elig->execute([$trainee_id]);

        try {
            $reset_alert = $db->prepare("
                UPDATE trainees 
                SET completion_alert_sent = 0, 
                    completion_alert_sent_at = NULL 
                WHERE id = ?
            ");
            $reset_alert->execute([$trainee_id]);
        } catch (\Exception $e) {
            error_log("Failed to reset completion_alert_sent: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Competency flagged for re-evaluation',
            'redirect' => 'evaluation.php?trainee_id=' . $trainee_id . '&re_evaluated=1'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ==========================================
// Recalculate eligibility before loading
// ==========================================
function recalculateAllEligibility($db, $eligibilityService, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $sql = "SELECT id, department, qualification_id FROM trainees WHERE deleted_at IS NULL";
        $params = [];
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
            $sql .= " AND qualification_id IN ($placeholders)";
            $params = $trainer_qualification_ids;
        } elseif ($is_trainer) {
            $sql .= " AND 1=0";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $trainees = $stmt->fetchAll();
        $updated_count = 0;
        
        foreach ($trainees as $trainee) {
            $department = $trainee['department'] ?? '';
            $qualification_id = $trainee['qualification_id'] ?? 0;
            
            if (empty($department)) {
                continue;
            }
            
            $stmt = $db->prepare("SELECT id FROM courses WHERE course_code = ?");
            $stmt->execute([$department]);
            $course = $stmt->fetch(PDO::FETCH_ASSOC);
            $course_id = $course['id'] ?? 0;
            
            if (!$course_id) {
                continue;
            }
            
            $total_stmt = $db->prepare("
                SELECT COUNT(DISTINCT pt.id) 
                FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                JOIN qualifications q ON cs.qualification_id = q.id
                WHERE q.course_id = ?
            ");
            $total_stmt->execute([$course_id]);
            $total_tasks = (int)$total_stmt->fetchColumn();
            
            $completed_stmt = $db->prepare("
                SELECT COUNT(DISTINCT et.task_id)
                FROM evaluation_tracker et
                JOIN performance_tasks pt ON et.task_id = pt.id
                JOIN competency_standards cs ON pt.competency_id = cs.id
                JOIN qualifications q ON cs.qualification_id = q.id
                WHERE et.trainee_id = ? 
                AND et.remarks = 'Done'
                AND q.course_id = ?
            ");
            $completed_stmt->execute([$trainee['id'], $course_id]);
            $completed_tasks = (int)$completed_stmt->fetchColumn();
            
            $is_eligible = ($completed_tasks >= $total_tasks && $total_tasks > 0);
            
            $checkElig = $db->prepare("SELECT id FROM evaluation_eligibility WHERE trainee_id = ?");
            $checkElig->execute([$trainee['id']]);
            
            if ($checkElig->rowCount() > 0) {
                $update = $db->prepare("
                    UPDATE evaluation_eligibility 
                    SET is_eligible = ?, 
                        total_tasks = ?, 
                        completed_tasks = ?,
                        eligibility_date = CASE 
                            WHEN ? = 1 AND (eligibility_date IS NULL OR eligibility_date = '0000-00-00 00:00:00')
                                THEN NOW()
                            WHEN ? = 0 THEN NULL
                            ELSE eligibility_date
                        END,
                        checked_at = NOW()
                    WHERE trainee_id = ?
                ");
                $update->execute([
                    $is_eligible ? 1 : 0,
                    $total_tasks,
                    $completed_tasks,
                    $is_eligible ? 1 : 0,
                    $is_eligible ? 1 : 0,
                    $trainee['id']
                ]);
            } else {
                $insert = $db->prepare("
                    INSERT INTO evaluation_eligibility 
                    (trainee_id, is_eligible, total_tasks, completed_tasks, eligibility_date, checked_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $eligibility_date = $is_eligible ? date('Y-m-d H:i:s') : null;
                $insert->execute([$trainee['id'], $is_eligible ? 1 : 0, $total_tasks, $completed_tasks, $eligibility_date]);
            }
            
            if ($is_eligible) {
                $updated_count++;
            }
        }
        
        return $updated_count;
        
    } catch (PDOException $e) {
        error_log("Error recalculating all eligibility: " . $e->getMessage());
        return 0;
    }
}

// ==========================================
// ONE-TIME BACKFILL: Populate missing eligibility_date
// ==========================================
try {
    $backfill_check = $db->query("
        SELECT COUNT(*) FROM evaluation_eligibility 
        WHERE is_eligible = 1 
          AND (eligibility_date IS NULL OR eligibility_date = '0000-00-00 00:00:00')
    ");
    $needs_backfill = (int)$backfill_check->fetchColumn();
    
    if ($needs_backfill > 0) {
        $backfill = $db->prepare("
            UPDATE evaluation_eligibility ee
            JOIN trainees t ON ee.trainee_id = t.id
            SET ee.eligibility_date = COALESCE(
                t.completion_alert_sent_at,
                t.created_at,
                NOW()
            )
            WHERE ee.is_eligible = 1 
              AND (ee.eligibility_date IS NULL OR ee.eligibility_date = '0000-00-00 00:00:00')
        ");
        $backfill->execute();
    }
} catch (\Exception $e) {
    error_log("Eligibility backfill failed: " . $e->getMessage());
}

// ==========================================
// DISABLED FOR PERFORMANCE
// ==========================================
// recalculateAllEligibility($db, $eligibilityService, $trainer_qualification_ids, $is_trainer);

// ==========================================
// QUERY — includes completion_alert_sent flag + readiness date
// ==========================================
if ($is_coordinator) {
    $query = "
        SELECT 
            t.id, 
            t.first_name, 
            t.last_name, 
            t.section, 
            t.department,
            t.sex,
            t.qualification_id,
            t.status as trainee_status,
            t.completion_alert_sent,
            t.completion_alert_sent_at,
            COALESCE(
                ee.eligibility_date,
                t.completion_alert_sent_at,
                (
                    SELECT MAX(et.updated_at)
                    FROM evaluation_tracker et
                    JOIN performance_tasks pt ON et.task_id = pt.id
                    JOIN competency_standards cs ON pt.competency_id = cs.id
                    WHERE et.trainee_id = t.id
                      AND et.remarks = 'Done'
                      AND cs.qualification_id = t.qualification_id
                ),
                t.created_at
            ) as date_ready,
            q.qualification_code,
            q.qualification_name,
            c.course_code as dept_code,
            c.course_name as dept_name,
            c.color as dept_color,
            (
                SELECT COUNT(DISTINCT pt.id) 
                FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE cs.qualification_id = t.qualification_id
            ) as total_tasks,
            (
                SELECT COUNT(DISTINCT et.task_id)
                FROM evaluation_tracker et
                JOIN performance_tasks pt ON et.task_id = pt.id
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE et.trainee_id = t.id 
                AND et.remarks = 'Done'
                AND cs.qualification_id = t.qualification_id
            ) as completed_tasks,
            ee.is_eligible
        FROM trainees t
        JOIN courses c ON t.department = c.course_code
        LEFT JOIN qualifications q ON t.qualification_id = q.id
        LEFT JOIN evaluation_eligibility ee ON t.id = ee.trainee_id
        WHERE t.qualification_id IS NOT NULL
        AND t.qualification_id > 0
        AND t.deleted_at IS NULL
        AND (
            t.status IN ('Ready for Pre-Assessment', 'Ready for Assessment', 'Eligible for Pre-Assessment')
            OR (t.status = 'In Progress' AND ee.is_eligible = 1)
        )
        AND (
            SELECT COUNT(DISTINCT pt.id) 
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE cs.qualification_id = t.qualification_id
        ) > 0
        ORDER BY c.course_code, q.qualification_code, t.sex, t.last_name ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $eligible_trainees = $stmt->fetchAll();
} else {
    if (empty($trainer_qualification_ids)) {
        $eligible_trainees = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $query = "
            SELECT 
                t.id, 
                t.first_name, 
                t.last_name, 
                t.section, 
                t.department,
                t.sex,
                t.qualification_id,
                t.status as trainee_status,
                t.completion_alert_sent,
                t.completion_alert_sent_at,
                COALESCE(
                    ee.eligibility_date,
                    t.completion_alert_sent_at,
                    (
                        SELECT MAX(et.updated_at)
                        FROM evaluation_tracker et
                        JOIN performance_tasks pt ON et.task_id = pt.id
                        JOIN competency_standards cs ON pt.competency_id = cs.id
                        WHERE et.trainee_id = t.id
                          AND et.remarks = 'Done'
                          AND cs.qualification_id = t.qualification_id
                    ),
                    t.created_at
                ) as date_ready,
                q.qualification_code,
                q.qualification_name,
                c.course_code as dept_code,
                c.course_name as dept_name,
                c.color as dept_color,
                (
                    SELECT COUNT(DISTINCT pt.id) 
                    FROM performance_tasks pt
                    JOIN competency_standards cs ON pt.competency_id = cs.id
                    WHERE cs.qualification_id = t.qualification_id
                ) as total_tasks,
                (
                    SELECT COUNT(DISTINCT et.task_id)
                    FROM evaluation_tracker et
                    JOIN performance_tasks pt ON et.task_id = pt.id
                    JOIN competency_standards cs ON pt.competency_id = cs.id
                    WHERE et.trainee_id = t.id 
                    AND et.remarks = 'Done'
                    AND cs.qualification_id = t.qualification_id
                ) as completed_tasks,
                ee.is_eligible
            FROM trainees t
            JOIN courses c ON t.department = c.course_code
            LEFT JOIN qualifications q ON t.qualification_id = q.id
            LEFT JOIN evaluation_eligibility ee ON t.id = ee.trainee_id
            WHERE t.qualification_id IN ($placeholders)
            AND t.qualification_id IS NOT NULL
            AND t.qualification_id > 0
            AND t.deleted_at IS NULL
            AND (
                t.status IN ('Ready for Pre-Assessment', 'Ready for Assessment', 'Eligible for Pre-Assessment')
                OR (t.status = 'In Progress' AND ee.is_eligible = 1)
            )
            AND (
                SELECT COUNT(DISTINCT pt.id) 
                FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE cs.qualification_id = t.qualification_id
            ) > 0
            ORDER BY q.qualification_code, t.sex, t.last_name ASC
        ";

        $stmt = $db->prepare($query);
        $stmt->execute($trainer_qualification_ids);
        $eligible_trainees = $stmt->fetchAll();
    }
}

$total_eligible = count($eligible_trainees);

$departments_list = [
    'CSS' => ['name' => 'Computer Systems Servicing', 'color' => '#10b981', 'icon' => 'fa-laptop-code'],
    'ATS' => ['name' => 'Automotive Mechanics', 'color' => '#f59e0b', 'icon' => 'fa-cogs'],
    'HRS' => ['name' => 'Hotel and Restaurant Services', 'color' => '#8b5cf6', 'icon' => 'fa-hotel']
];

$grouped_eligible = [];
foreach ($eligible_trainees as $t) {
    $dept_code = $t['dept_code'] ?? 'HRS';
    $qual_id = $t['qualification_id'] ?? 0;
    $qual_code = $t['qualification_code'] ?? 'Unknown';
    $qual_name = $t['qualification_name'] ?? 'Qualification';
    $sex = $t['sex'] ?? 'Male';
    
    if (!isset($departments_list[$dept_code])) {
        $dept_code = 'HRS';
    }
    
    if (!isset($grouped_eligible[$dept_code])) {
        $grouped_eligible[$dept_code] = [
            'department_name' => $departments_list[$dept_code]['name'],
            'color' => $departments_list[$dept_code]['color'],
            'icon' => $departments_list[$dept_code]['icon'],
            'qualifications' => []
        ];
    }
    
    $qual_key = $qual_id . '|' . $qual_code;
    if (!isset($grouped_eligible[$dept_code]['qualifications'][$qual_key])) {
        $grouped_eligible[$dept_code]['qualifications'][$qual_key] = [
            'qualification_id' => $qual_id,
            'qualification_code' => $qual_code,
            'qualification_name' => $qual_name,
            'male_trainees' => [],
            'female_trainees' => []
        ];
    }
    
    if ($sex === 'Male' || $sex === 'male') {
        $grouped_eligible[$dept_code]['qualifications'][$qual_key]['male_trainees'][] = $t;
    } else {
        $grouped_eligible[$dept_code]['qualifications'][$qual_key]['female_trainees'][] = $t;
    }
}

$dept_stats = [];
foreach ($departments_list as $code => $dept) {
    $dept_stats[$code] = [
        'name' => $dept['name'],
        'eligible' => 0,
        'color' => $dept['color'],
        'icon' => $dept['icon']
    ];
    foreach ($eligible_trainees as $t) {
        if (($t['dept_code'] ?? '') === $code) {
            $dept_stats[$code]['eligible']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-Assessment - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
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

        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        body.sidebar-collapsed .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
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
            color: #10b981;
            font-size: 1.8rem;
        }

        .header-left p {
            color: #64748b;
            font-size: 1rem;
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
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #e8edf5;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
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

        .stat-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a1c2c;
            line-height: 1.2;
        }

        .stat-content p {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e8edf5;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            animation: fadeInPage 0.5s ease-out;
        }

        .filter-bar .filter-label {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .filter-bar .filter-label i {
            color: #8b5cf6;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }

        .filter-select {
            padding: 9px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
            color: #1e293b;
            cursor: pointer;
            transition: all 0.2s ease;
            outline: none;
            min-width: 180px;
        }

        .filter-select:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
        }

        .filter-select:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
            background: #ffffff;
        }

        .filter-search-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 200px;
        }

        .filter-search-wrapper i {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 0.85rem;
            pointer-events: none;
        }

        .filter-search {
            width: 100%;
            padding: 9px 16px 9px 38px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
            color: #1e293b;
            transition: all 0.2s ease;
            outline: none;
        }

        .filter-search::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .filter-search:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
        }

        .filter-search:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
            background: #ffffff;
        }

        .filter-result-count {
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            padding: 8px 18px;
            border-radius: 20px;
            white-space: nowrap;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .filter-result-count.has-results {
            background: #d1fae5;
            color: #065f46;
        }

        .filter-result-count.no-results {
            background: #fee2e2;
            color: #991b1b;
        }

        .filter-reset-btn {
            padding: 9px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .filter-reset-btn:hover {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .no-filter-results {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            display: none;
        }

        .no-filter-results i {
            font-size: 3.5rem;
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        .no-filter-results h3 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .no-filter-results p {
            font-size: 0.9rem;
            color: #94a3b8;
        }

        .dept-section {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .dept-section-header {
            padding: 24px 32px;
            border-bottom: 3px solid;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }

        .dept-section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a1c2c;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dept-badge {
            background: rgba(0,0,0,0.05);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            color: #64748b;
        }

        .dept-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-send-dept-alert {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25);
            white-space: nowrap;
        }

        .btn-send-dept-alert:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
        }

        .btn-send-dept-alert:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-send-dept-alert:disabled:hover {
            background: #10b981;
            transform: none;
        }

        .btn-send-dept-alert.sent {
            background: #64748b;
            cursor: default;
        }

        .btn-send-dept-alert .spinner-small {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        .qualification-block {
            border-bottom: 1px solid #e8edf5;
        }

        .qualification-block:last-child {
            border-bottom: none;
        }

        .qualification-header {
            padding: 16px 32px;
            background: #fafbfc;
            border-bottom: 1px solid #e8edf5;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 1.05rem;
            color: #1e293b;
        }

        .qualification-header i {
            color: #8b5cf6;
            font-size: 1.1rem;
        }

        .qualification-header .qual-badge {
            background: #ede9fe;
            color: #7c3aed;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .gender-section {
            padding: 16px 32px;
            border-bottom: 1px solid #e2e8f0;
        }

        .gender-section:last-child {
            border-bottom: none;
        }

        .gender-header {
            padding: 10px 16px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border-left: 4px solid;
        }

        .gender-header.male {
            border-left-color: #3b82f6;
            color: #1e40af;
        }

        .gender-header.female {
            border-left-color: #ec489a;
            color: #be185d;
        }

        .gender-count {
            background: #e2e8f0;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 5px;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 12px;
        }

        .trainee-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .trainee-table th {
            text-align: left;
            padding: 12px 16px;
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .trainee-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
            vertical-align: middle;
        }

        .trainee-table tbody tr:hover {
            background: #f8fafc;
        }

        .trainee-name-info strong {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.95rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-ready {
            background: #d1fae5;
            color: #065f46;
        }

        .date-ready-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #dbeafe;
            color: #1e40af;
            white-space: nowrap;
        }

        .date-ready-badge i {
            font-size: 0.75rem;
        }

        .date-ready-badge.empty {
            background: #f1f5f9;
            color: #94a3b8;
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-view {
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            font-weight: 500;
        }

        .btn-view:hover {
            background: #7c3aed;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139,92,246,0.3);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .empty-state .btn-empty {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 24px;
            background: #10b981;
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .empty-state .btn-empty:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }

        .modal-header {
            border-bottom: 1px solid #e8edf5;
            padding: 20px 28px;
            background: #f8fafc;
            border-radius: 20px 20px 0 0;
        }

        .modal-header .modal-title {
            font-weight: 700;
            font-size: 1.3rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header .modal-title i {
            color: #8b5cf6;
        }

        .modal-body {
            padding: 28px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .modal-trainee-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            background: #f8fafc;
            padding: 24px 20px;
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1px solid #e8edf5;
        }

        .modal-trainee-info .info-item {
            display: flex;
            flex-direction: column;
        }

        .modal-trainee-info .info-item .label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .modal-trainee-info .info-item .value {
            font-weight: 600;
            color: #1e293b;
            font-size: 1rem;
        }

        .modal-trainee-info .info-item .value .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            background: #d1fae5;
            color: #065f46;
        }

        .modal-competency {
            border: 1px solid #e8edf5;
            border-radius: 12px;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .modal-competency .comp-header {
            padding: 14px 20px;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e8edf5;
            flex-wrap: wrap;
            gap: 8px;
        }

        .modal-competency .comp-header .comp-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.95rem;
        }

        .modal-competency .comp-header .comp-status-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .modal-competency .comp-header .comp-status {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 12px;
            border-radius: 20px;
        }

        .modal-competency .comp-header .comp-status.done {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-re-evaluate-comp {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 4px 14px;
            border-radius: 20px;
            border: 2px solid #dc3545;
            background: transparent;
            color: #dc3545;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-re-evaluate-comp:hover {
            background: #dc3545;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-re-evaluate-comp:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-re-evaluate-comp:disabled:hover {
            background: transparent;
            color: #dc3545;
        }

        .modal-competency .task-list {
            padding: 8px 0;
        }

        .modal-competency .task-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-competency .task-item:last-child {
            border-bottom: none;
        }

        .modal-competency .task-item .task-name {
            font-size: 0.9rem;
            color: #334155;
        }

        .modal-competency .task-item .task-status.pending {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 12px;
            border-radius: 20px;
            background: #fee2e2;
            color: #991b1b;
        }

        .modal-footer {
            border-top: 1px solid #e8edf5;
            padding: 16px 28px;
            background: #f8fafc;
            border-radius: 0 0 20px 20px;
        }

        .btn-close-modal {
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 10px 28px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-close-modal:hover {
            background: #7c3aed;
            transform: translateY(-2px);
        }

        .modal-avatar-large {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
        }

        .modal-trainee-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .modal-trainee-header .trainee-detail-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
        }

        .send-alert-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            animation: sendAlertFadeIn 0.2s ease-out;
        }

        .send-alert-modal-overlay.active {
            display: flex;
        }

        @keyframes sendAlertFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes sendAlertPopIn {
            from { opacity: 0; transform: translateY(12px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .send-alert-modal-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 28px 24px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: sendAlertPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .send-alert-modal-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #059669;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.15);
        }

        .send-alert-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .send-alert-modal-text {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 8px;
        }

        .send-alert-modal-dept {
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 700;
            margin: 0 0 24px;
            padding: 10px 16px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            word-break: break-word;
        }

        .send-alert-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .send-alert-btn {
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

        .send-alert-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .send-alert-btn-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .send-alert-btn-confirm {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }

        .send-alert-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(16, 185, 129, 0.4);
        }

        .send-alert-btn-confirm:active,
        .send-alert-btn-cancel:active {
            transform: translateY(0);
        }

        .re-eval-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 100000;
            align-items: center;
            justify-content: center;
            animation: sendAlertFadeIn 0.2s ease-out;
        }

        .re-eval-modal-overlay.active {
            display: flex;
        }

        .re-eval-modal-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 28px 24px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: sendAlertPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .re-eval-modal-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #dc2626;
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.15);
        }

        .re-eval-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .re-eval-modal-text {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 12px;
        }

        .re-eval-modal-text strong {
            color: #dc2626;
            font-weight: 700;
        }

        .re-eval-modal-comp {
            font-size: 0.9rem;
            color: #1e293b;
            font-weight: 700;
            margin: 0 0 24px;
            padding: 10px 16px;
            background: #fef2f2;
            border-radius: 10px;
            border: 1px solid #fecaca;
            word-break: break-word;
        }

        .re-eval-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .re-eval-btn {
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

        .re-eval-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .re-eval-btn-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .re-eval-btn-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.3);
        }

        .re-eval-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(220, 38, 38, 0.4);
        }

        .re-eval-btn-confirm:active,
        .re-eval-btn-cancel:active {
            transform: translateY(0);
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-custom {
            background: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            border-left: 4px solid;
            animation: slideIn 0.3s ease;
        }

        .toast-custom.success {
            border-left-color: #10b981;
        }

        .toast-custom.error {
            border-left-color: #ef4444;
        }

        .toast-custom .toast-icon {
            font-size: 1.4rem;
        }

        .toast-custom .toast-message {
            font-weight: 500;
            color: #1e293b;
        }

        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46a0);
        }

        @media (max-width: 1200px) {
            body { min-width: 992px; }
            .main-content { margin-left: 80px; width: calc(100% - 80px); }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            body { min-width: auto; }
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-right { width: 100%; flex-wrap: wrap; }
            .stats-grid { grid-template-columns: 1fr; }
            .gender-section { padding: 15px; }
            .dept-section-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .dept-actions { width: 100%; justify-content: space-between; }
            .qualification-header { padding: 12px 16px; flex-wrap: wrap; }
            .gender-section { padding: 12px 16px; }
            .modal-trainee-info { grid-template-columns: 1fr; }
            .modal-body { padding: 16px; }
            .modal-competency .task-item { flex-wrap: wrap; gap: 8px; }
            .modal-competency .comp-header { flex-wrap: wrap; gap: 8px; }
            .profile-badge { padding: 6px 12px 6px 6px; }
            .profile-badge .profile-name { font-size: 0.85rem; }
            .send-alert-modal-card { padding: 25px 20px; }
            .re-eval-modal-card { padding: 25px 20px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-search-wrapper { width: 100%; }
            .filter-result-count { text-align: center; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-check-double"></i> Pre-Assessment</h1>
                <p>View trainees eligible for pre-assessment (completed all tasks)</p>
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

        <div class="stats-grid">
            <?php foreach ($dept_stats as $code => $dept): ?>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: <?= $dept['color'] ?>;">
                    <i class="fas <?= $dept['icon'] ?>"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $dept['eligible'] ?></h3>
                    <p><?= $code ?> Eligible</p>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #1a1c2c;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $total_eligible ?></h3>
                    <p>Total Eligible</p>
                </div>
            </div>
        </div>

        <div class="filter-bar" id="filterBar">
            <span class="filter-label"><i class="fas fa-filter"></i> Filter:</span>
            
            <div class="filter-group">
                <select class="filter-select" id="filterQualification">
                    <option value="">All Qualifications</option>
                </select>
            </div>

            <div class="filter-search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" class="filter-search" id="filterSearch" placeholder="Search trainee name...">
            </div>

            <button type="button" class="filter-reset-btn" id="filterResetBtn">
                <i class="fas fa-undo"></i> Reset
            </button>

            <span class="filter-result-count" id="filterResultCount">
                <?= $total_eligible ?> trainee<?= $total_eligible != 1 ? 's' : '' ?>
            </span>
        </div>

        <?php if (empty($grouped_eligible)): ?>
        <div class="empty-state">
            <i class="fas fa-user-check"></i>
            <h3>No Eligible Trainees Yet</h3>
            <p>Trainees will appear here once they complete all their tasks.</p>
            <a href="evaluation.php" class="btn-empty">
                <i class="fas fa-clipboard-check"></i> Go to Evaluation Module
            </a>
        </div>
        <?php else: ?>
            <div id="noFilterResults" class="no-filter-results">
                <i class="fas fa-search-minus"></i>
                <h3>No trainees match your filters</h3>
                <p>Try adjusting or resetting the filters above.</p>
            </div>

            <?php foreach ($grouped_eligible as $dept_code => $dept_data): ?>
                <?php
                    $dept_total_trainees = 0;
                    $dept_already_sent = 0;
                    foreach ($dept_data['qualifications'] as $qd) {
                        foreach (array_merge($qd['male_trainees'], $qd['female_trainees']) as $qt) {
                            $dept_total_trainees++;
                            if (!empty($qt['completion_alert_sent'])) {
                                $dept_already_sent++;
                            }
                        }
                    }
                    $all_dept_sent = ($dept_total_trainees > 0 && $dept_already_sent >= $dept_total_trainees);
                ?>
            <div class="dept-section" data-department="<?= htmlspecialchars($dept_code) ?>">
                <div class="dept-section-header" style="border-bottom-color: <?= $dept_data['color'] ?>">
                    <h2>
                        <i class="fas <?= $dept_data['icon'] ?>" style="color: <?= $dept_data['color'] ?>;"></i>
                        <?= htmlspecialchars($dept_data['department_name']) ?>
                    </h2>
                    <div class="dept-actions">
                        <span class="dept-badge"><?= $dept_code ?></span>
                        <button class="btn-send-dept-alert <?= $all_dept_sent ? 'sent' : '' ?>"
                                data-department="<?= htmlspecialchars($dept_code) ?>"
                                data-dept-name="<?= htmlspecialchars($dept_data['department_name']) ?>"
                                data-count="<?= $dept_total_trainees ?>"
                                <?= $all_dept_sent ? 'disabled' : '' ?>
                                onclick="openSendAlertModal('<?= htmlspecialchars($dept_code) ?>', '<?= htmlspecialchars(addslashes($dept_data['department_name'])) ?>', <?= $dept_total_trainees ?>, this)">
                            <?php if ($all_dept_sent): ?>
                                <i class="fas fa-check"></i> Alert Already Sent
                            <?php else: ?>
                                <i class="fas fa-paper-plane"></i> Send Completion Alert (<?= $dept_total_trainees ?>)
                            <?php endif; ?>
                        </button>
                    </div>
                </div>
                
                <?php foreach ($dept_data['qualifications'] as $qual_key => $qual_data): ?>
                <div class="qualification-block" 
                     data-qualification-id="<?= htmlspecialchars($qual_data['qualification_id']) ?>"
                     data-qualification-code="<?= htmlspecialchars($qual_data['qualification_code']) ?>">
                    <div class="qualification-header">
                        <i class="fas fa-graduation-cap"></i>
                        <?= htmlspecialchars($qual_data['qualification_code']) ?> 
                        <span class="qual-badge"><?= htmlspecialchars($qual_data['qualification_name']) ?></span>
                        <span style="font-size: 0.85rem;color:#94a3b8;margin-left:auto;">
                            <?= count($qual_data['male_trainees']) + count($qual_data['female_trainees']) ?> trainees
                        </span>
                    </div>
                    
                    <?php if (!empty($qual_data['male_trainees'])): ?>
                    <div class="gender-section" data-sex="Male">
                        <div class="gender-header male">
                            <i class="fas fa-mars"></i> Male Trainees
                            <span class="gender-count"><?= count($qual_data['male_trainees']) ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="trainee-table">
                                <thead>
                                    <tr>
                                        <th style="width:30%;">Name</th>
                                        <th style="width:10%;text-align:center;">Section</th>
                                        <th style="width:20%;text-align:center;">Date Ready</th>
                                        <th style="width:22%;text-align:center;">Status</th>
                                        <th style="width:18%;text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($qual_data['male_trainees'] as $t): 
                                        $date_ready_display = '—';
                                        $date_ready_class = 'empty';
                                        if (!empty($t['date_ready'])) {
                                            try {
                                                $dr = new DateTime($t['date_ready']);
                                                $date_ready_display = $dr->format('M j, Y g:i A');
                                                $date_ready_class = '';
                                            } catch (Exception $e) {
                                                $date_ready_display = '—';
                                            }
                                        }
                                    ?>
                                    <tr class="trainee-row"
                                        data-name="<?= htmlspecialchars(strtolower($t['last_name'] . ' ' . $t['first_name'])) ?>"
                                        data-alert-sent="<?= !empty($t['completion_alert_sent']) ? '1' : '0' ?>">
                                        <td>
                                            <div class="trainee-name-info">
                                                <strong><?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name']) ?></strong>
                                            </div>
                                        </td>
                                        <td style="text-align:center;"><?= htmlspecialchars($t['section'] ?? 'N/A') ?></td>
                                        <td style="text-align:center;">
                                            <span class="date-ready-badge <?= $date_ready_class ?>">
                                                <i class="fas fa-calendar-check"></i>
                                                <?= htmlspecialchars($date_ready_display) ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="status-badge status-ready">
                                                <i class="fas fa-check-circle"></i> Ready for Assessment
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="action-buttons" style="justify-content:center;">
                                                <button class="btn-view" onclick="openModal(<?= $t['id'] ?>)">
                                                    <i class="fas fa-eye"></i> View Remarks
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($qual_data['female_trainees'])): ?>
                    <div class="gender-section" data-sex="Female">
                        <div class="gender-header female">
                            <i class="fas fa-venus"></i> Female Trainees
                            <span class="gender-count"><?= count($qual_data['female_trainees']) ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="trainee-table">
                                <thead>
                                    <tr>
                                        <th style="width:30%;">Name</th>
                                        <th style="width:10%;text-align:center;">Section</th>
                                        <th style="width:20%;text-align:center;">Date Ready</th>
                                        <th style="width:22%;text-align:center;">Status</th>
                                        <th style="width:18%;text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($qual_data['female_trainees'] as $t): 
                                        $date_ready_display = '—';
                                        $date_ready_class = 'empty';
                                        if (!empty($t['date_ready'])) {
                                            try {
                                                $dr = new DateTime($t['date_ready']);
                                                $date_ready_display = $dr->format('M j, Y g:i A');
                                                $date_ready_class = '';
                                            } catch (Exception $e) {
                                                $date_ready_display = '—';
                                            }
                                        }
                                    ?>
                                    <tr class="trainee-row"
                                        data-name="<?= htmlspecialchars(strtolower($t['last_name'] . ' ' . $t['first_name'])) ?>"
                                        data-alert-sent="<?= !empty($t['completion_alert_sent']) ? '1' : '0' ?>">
                                        <td>
                                            <div class="trainee-name-info">
                                                <strong><?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name']) ?></strong>
                                            </div>
                                        </td>
                                        <td style="text-align:center;"><?= htmlspecialchars($t['section'] ?? 'N/A') ?></td>
                                        <td style="text-align:center;">
                                            <span class="date-ready-badge <?= $date_ready_class ?>">
                                                <i class="fas fa-calendar-check"></i>
                                                <?= htmlspecialchars($date_ready_display) ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="status-badge status-ready">
                                                <i class="fas fa-check-circle"></i> Ready for Assessment
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="action-buttons" style="justify-content:center;">
                                                <button class="btn-view" onclick="openModal(<?= $t['id'] ?>)">
                                                    <i class="fas fa-eye"></i> View Remarks
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="traineeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-graduate"></i> Trainee Accomplishment Report
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalBodyContent">
                    <div style="text-align:center;padding:40px;">
                        <div style="display:inline-block;width:36px;height:36px;border:4px solid #f3f3f3;border-top:4px solid #8b5cf6;border-radius:50%;animation:spin 1s linear infinite;"></div>
                        <p style="margin-top:12px;color:#94a3b8;">Loading trainee data...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="send-alert-modal-overlay" id="sendAlertModal">
        <div class="send-alert-modal-card">
            <div class="send-alert-modal-icon">
                <i class="fas fa-paper-plane"></i>
            </div>
            <h3 class="send-alert-modal-title">Send Completion Alert</h3>
            <p class="send-alert-modal-text">Send a congratulations to all eligible trainees in that department.</p>
            <p class="send-alert-modal-dept" id="sendAlertDeptName"></p>
            <div class="send-alert-modal-actions">
                <button type="button" class="send-alert-btn send-alert-btn-cancel" id="sendAlertCancel">Cancel</button>
                <button type="button" class="send-alert-btn send-alert-btn-confirm" id="confirmSendAlertBtn">
                    <i class="fas fa-check"></i> Yes, Send Alert
                </button>
            </div>
        </div>
    </div>

    <div class="re-eval-modal-overlay" id="reEvalModal">
        <div class="re-eval-modal-card">
            <div class="re-eval-modal-icon">
                <i class="fas fa-undo"></i>
            </div>
            <h3 class="re-eval-modal-title">Flag for Re-evaluation?</h3>
            <p class="re-eval-modal-text">
                This will reset <strong>ALL tasks</strong> in this competency to <strong>"Not Done"</strong>
                and send the trainee back to the Evaluation module.
            </p>
            <p class="re-eval-modal-comp" id="reEvalCompName"></p>
            <div class="re-eval-modal-actions">
                <button type="button" class="re-eval-btn re-eval-btn-cancel" id="reEvalCancel">Cancel</button>
                <button type="button" class="re-eval-btn re-eval-btn-confirm" id="reEvalConfirm">
                    <i class="fas fa-undo"></i> Yes, Re-evaluate
                </button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-custom ${type}`;
            toast.innerHTML = `
                <span class="toast-icon">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}" 
                       style="color: ${type === 'success' ? '#10b981' : '#ef4444'};"></i>
                </span>
                <span class="toast-message">${message}</span>
            `;
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        let currentAlertData = {};
        const sendAlertModal = document.getElementById('sendAlertModal');
        const sendAlertCancelBtn = document.getElementById('sendAlertCancel');
        const confirmSendAlertBtn = document.getElementById('confirmSendAlertBtn');

        function openSendAlertModal(departmentCode, deptName, traineeCount, btnElement) {
            if (traineeCount === 0) {
                showToast('⚠️ No eligible trainees in this department.', 'error');
                return;
            }

            currentAlertData = {
                departmentCode: departmentCode,
                deptName: deptName,
                traineeCount: traineeCount,
                btnElement: btnElement
            };

            document.getElementById('sendAlertDeptName').textContent = 
                deptName + ' (' + traineeCount + ' trainee' + (traineeCount > 1 ? 's' : '') + ')';

            sendAlertModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSendAlertModal() {
            sendAlertModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        if (sendAlertCancelBtn) {
            sendAlertCancelBtn.addEventListener('click', closeSendAlertModal);
        }

        sendAlertModal.addEventListener('click', function(e) {
            if (e.target === sendAlertModal) closeSendAlertModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sendAlertModal.classList.contains('active')) {
                closeSendAlertModal();
            }
        });

        confirmSendAlertBtn.addEventListener('click', function() {
            closeSendAlertModal();

            const { departmentCode, deptName, traineeCount, btnElement } = currentAlertData;

            if (!btnElement) return;

            const originalHTML = btnElement.innerHTML;
            btnElement.disabled = true;
            btnElement.innerHTML = '<span class="spinner-small"></span> Sending to ' + traineeCount + '...';

            const formData = new URLSearchParams();
            formData.append('department', departmentCode);

            fetch('send_completion_notif.php?_=' + Date.now(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.text())
            .then(text => {
                try {
                    const data = JSON.parse(text);

                    if (data.success) {
                        showToast(`✅ ${data.message}`, 'success');
                        btnElement.innerHTML = `<i class="fas fa-check"></i> Alert Already Sent`;
                        btnElement.classList.add('sent');
                        btnElement.disabled = true;

                        if (data.failed > 0 || data.skipped > 0) {
                            let detailMsg = '';
                            if (data.failed > 0) detailMsg += `${data.failed} failed. `;
                            if (data.skipped > 0) detailMsg += `${data.skipped} skipped (no email).`;
                            setTimeout(() => showToast('ℹ️ ' + detailMsg, 'error'), 1200);
                        }

                        applyFilters();
                    } else {
                        showToast('❌ ' + (data.message || 'Failed to send alerts'), 'error');
                        btnElement.disabled = false;
                        btnElement.innerHTML = originalHTML;
                    }
                } catch (e) {
                    console.error('JSON Parse Error:', e, 'Raw:', text);
                    showToast('❌ Server error. Check console.', 'error');
                    btnElement.disabled = false;
                    btnElement.innerHTML = originalHTML;
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                showToast('❌ Error: ' + error.message, 'error');
                btnElement.disabled = false;
                btnElement.innerHTML = originalHTML;
            });
        });

        let currentReEvalData = {};
        const reEvalModal = document.getElementById('reEvalModal');
        const reEvalCancelBtn = document.getElementById('reEvalCancel');
        const reEvalConfirmBtn = document.getElementById('reEvalConfirm');

        function reEvaluateCompetency(competencyId, traineeId, competencyName) {
            currentReEvalData = {
                competencyId: competencyId,
                traineeId: traineeId,
                competencyName: competencyName
            };

            document.getElementById('reEvalCompName').textContent = competencyName;

            reEvalModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeReEvalModal() {
            reEvalModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        if (reEvalCancelBtn) {
            reEvalCancelBtn.addEventListener('click', closeReEvalModal);
        }

        reEvalModal.addEventListener('click', function(e) {
            if (e.target === reEvalModal) closeReEvalModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && reEvalModal.classList.contains('active')) {
                closeReEvalModal();
            }
        });

        reEvalConfirmBtn.addEventListener('click', function() {
            closeReEvalModal();

            const { competencyId, traineeId } = currentReEvalData;

            if (!competencyId || !traineeId) return;

            const btn = document.querySelector(`button[data-competency-id="${competencyId}"]`);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }

            fetch('pre_assessment.php?re_evaluate_competency=1&competency_id=' + competencyId + '&trainee_id=' + traineeId)
                .then(response => response.text())
                .then(text => {
                    console.log('RAW RESPONSE FROM SERVER:', text);
                    
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseErr) {
                        console.error('JSON PARSE FAILED. Raw response was:', text);
                        showToast('❌ Server returned invalid response. Check console.', 'error');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-undo"></i> Re-evaluate';
                        }
                        return;
                    }
                    
                    if (data.success) {
                        showToast('Competency flagged for re-evaluation. Redirecting to Evaluation module...', 'success');

                        const modal = bootstrap.Modal.getInstance(document.getElementById('traineeModal'));
                        if (modal) modal.hide();

                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1000);
                    } else {
                        showToast(data.message || 'Failed to re-evaluate competency.', 'error');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-undo"></i> Re-evaluate';
                        }
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    showToast('Error: ' + error.message, 'error');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-undo"></i> Re-evaluate';
                    }
                });
        });

        function openModal(traineeId) {
            const modal = new bootstrap.Modal(document.getElementById('traineeModal'));
            const body = document.getElementById('modalBodyContent');
            
            body.innerHTML = `
                <div style="text-align:center;padding:40px;">
                    <div style="display:inline-block;width:36px;height:36px;border:4px solid #f3f3f3;border-top:4px solid #8b5cf6;border-radius:50%;animation:spin 1s linear infinite;"></div>
                    <p style="margin-top:12px;color:#94a3b8;">Loading trainee data...</p>
                </div>
            `;
            
            modal.show();
            
            fetch('pre_assessment.php?ajax=1&trainee_id=' + traineeId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderModalContent(data);
                    } else {
                        body.innerHTML = `
                            <div style="text-align:center;padding:40px;color:#ef4444;">
                                <i class="fas fa-exclamation-circle" style="font-size:3rem;margin-bottom:16px;"></i>
                                <p>${data.message || 'Failed to load trainee data.'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    body.innerHTML = `
                        <div style="text-align:center;padding:40px;color:#ef4444;">
                            <i class="fas fa-exclamation-circle" style="font-size:3rem;margin-bottom:16px;"></i>
                            <p>Error loading trainee data. Please try again.</p>
                            <p style="font-size: 0.85rem;color:#94a3b8;">${error.message}</p>
                        </div>
                    `;
                });
        }

        function renderModalContent(data) {
            const t = data.trainee;
            const competencies = data.competencies || [];
            
            const deptColors = {
                'CSS': '#10b981',
                'ATS': '#f59e0b',
                'HRS': '#8b5cf6'
            };
            const color = deptColors[t.dept_code] || '#8b5cf6';
            
            let html = `
                <div class="modal-trainee-header">
                    <div class="modal-avatar-large" style="background: ${color}">
                        ${(t.first_name ? t.first_name.charAt(0) : '') + (t.last_name ? t.last_name.charAt(0) : '')}
                    </div>
                    <div>
                        <div class="trainee-detail-name">${escapeHtml(t.last_name || '')}, ${escapeHtml(t.first_name || '')}</div>
                        <span style="font-size:0.85rem;color:#64748b;">
                            <i class="fas fa-graduation-cap"></i> ${escapeHtml(t.qualification_code || 'No Qual')}
                        </span>
                    </div>
                </div>
                
                <div class="modal-trainee-info">
                    <div class="info-item">
                        <span class="label">Department</span>
                        <span class="value">${escapeHtml(t.dept_name || t.department || 'N/A')}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Status</span>
                        <span class="value">
                            <span class="badge-status">
                                <i class="fas fa-check-circle"></i> Fully Complied / Ready for Assessment
                            </span>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label">Section</span>
                        <span class="value">${escapeHtml(t.section || 'N/A')}</span>
                    </div>
                    <div class="info-item">
                        <span class="label">Sex</span>
                        <span class="value">${escapeHtml(t.sex || 'N/A')}</span>
                    </div>
                </div>
                
                <h6 style="font-weight:700;color:#1e293b;margin-bottom:16px;">
                    <i class="fas fa-tasks" style="color:#8b5cf6;"></i> Completed Competencies
                    <span style="font-size: 0.85rem;font-weight:400;color:#94a3b8;margin-left:8px;">
                        (${competencies.length} competencies)
                    </span>
                </h6>
            `;
            
            if (competencies.length === 0) {
                html += `
                    <div style="text-align:center;padding:30px;background:#f8fafc;border-radius:12px;color:#94a3b8;">
                        <i class="fas fa-clipboard-list" style="font-size:1.8rem;margin-bottom:10px;display:block;"></i>
                        No competencies found for this trainee.
                    </div>
                `;
            } else {
                competencies.forEach(comp => {
                    const isComplete = comp.completed_tasks >= comp.total_tasks && comp.total_tasks > 0;
                    const tasks = comp.tasks || [];
                    
                    const safeCompName = escapeHtml(comp.competency_name || 'Unnamed Competency');
                    
                    html += `
                        <div class="modal-competency">
                            <div class="comp-header">
                                <span class="comp-name">${safeCompName}</span>
                                <div class="comp-status-group">
                                    <span class="comp-status ${isComplete ? 'done' : 'pending'}">
                                        ${isComplete ? '<i class="fas fa-check-circle"></i> 100% Done' : '<i class="fas fa-clock"></i> In Progress'}
                                    </span>
                                    ${isComplete ? `
                                        <button class="btn-re-evaluate-comp" 
                                                data-competency-id="${comp.competency_id}"
                                                data-trainee-id="${t.id}"
                                                data-competency-name="${safeCompName}">
                                            <i class="fas fa-undo"></i> Re-evaluate
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                            <div class="task-list">
                    `;
                    
                    if (tasks.length === 0) {
                        html += `
                            <div style="padding:12px 20px;color:#94a3b8;font-size:0.85rem;">
                                No tasks assigned for this competency.
                            </div>
                        `;
                    } else {
                        tasks.forEach(task => {
                            const isDone = task.is_done == 1 || task.remarks === 'Done';
                            
                            html += `
                                <div class="task-item">
                                    <span class="task-name">${escapeHtml(task.task_name || 'Unnamed Task')}</span>
                                    ${!isDone ? `
                                        <span class="task-status pending">
                                            <i class="fas fa-clock"></i> Not Done
                                        </span>
                                    ` : ''}
                                </div>
                            `;
                        });
                    }
                    
                    html += `
                            </div>
                        </div>
                    `;
                });
            }
            
            document.getElementById('modalBodyContent').innerHTML = html;
            
            document.querySelectorAll('.btn-re-evaluate-comp').forEach(btn => {
                btn.addEventListener('click', function() {
                    const compId = this.getAttribute('data-competency-id');
                    const traineeId = this.getAttribute('data-trainee-id');
                    const compName = this.getAttribute('data-competency-name');
                    reEvaluateCompetency(compId, traineeId, compName);
                });
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        (function() {
            const filterQual = document.getElementById('filterQualification');
            const filterSearch = document.getElementById('filterSearch');
            const filterResetBtn = document.getElementById('filterResetBtn');
            const filterResultCount = document.getElementById('filterResultCount');
            const noFilterResults = document.getElementById('noFilterResults');

            let allQualOptions = [];

            function buildQualificationOptions() {
                allQualOptions = [];
                const seen = new Set();
                const qualBlocks = document.querySelectorAll('.qualification-block');
                qualBlocks.forEach(block => {
                    const code = block.getAttribute('data-qualification-code');
                    if (code && !seen.has(code)) {
                        seen.add(code);
                        allQualOptions.push({ value: code, text: code });
                    }
                });
                populateQualDropdown(allQualOptions, '');
            }

            function populateQualDropdown(options, selectedValue) {
                while (filterQual.options.length > 1) {
                    filterQual.remove(1);
                }
                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.text;
                    filterQual.appendChild(option);
                });
                if (selectedValue && options.some(o => o.value === selectedValue)) {
                    filterQual.value = selectedValue;
                } else {
                    filterQual.value = '';
                }
            }

            window.applyFilters = function applyFilters() {
                const qualVal = filterQual.value;
                const searchVal = filterSearch.value.trim().toLowerCase();

                let totalVisible = 0;
                const visibleQualCodes = new Set();

                const deptSections = document.querySelectorAll('.dept-section');
                deptSections.forEach(deptSection => {
                    const qualBlocks = deptSection.querySelectorAll('.qualification-block');
                    qualBlocks.forEach(qualBlock => {
                        const qualCode = qualBlock.getAttribute('data-qualification-code');
                        let qualHasVisible = false;

                        if (qualVal && qualCode !== qualVal) {
                            qualBlock.style.display = 'none';
                            return;
                        }

                        const genderSections = qualBlock.querySelectorAll('.gender-section');
                        genderSections.forEach(genderSection => {
                            let genderHasVisible = false;
                            const rows = genderSection.querySelectorAll('.trainee-row');
                            let rowVisibleCount = 0;
                            rows.forEach(row => {
                                let visible = true;

                                if (searchVal && !row.getAttribute('data-name').includes(searchVal)) {
                                    visible = false;
                                }
                                row.style.display = visible ? '' : 'none';
                                if (visible) {
                                    rowVisibleCount++;
                                    totalVisible++;
                                }
                            });
                            if (rowVisibleCount > 0) {
                                genderSection.style.display = '';
                                genderHasVisible = true;
                            } else {
                                genderSection.style.display = 'none';
                            }
                        });

                        const visibleGenderSections = qualBlock.querySelectorAll('.gender-section:not([style*="display: none"])');
                        if (visibleGenderSections.length > 0) {
                            qualBlock.style.display = '';
                            qualHasVisible = true;
                            if (qualCode) visibleQualCodes.add(qualCode);
                        } else {
                            qualBlock.style.display = 'none';
                        }
                    });

                    const visibleQuals = deptSection.querySelectorAll('.qualification-block:not([style*="display: none"])');
                    if (visibleQuals.length > 0) {
                        deptSection.style.display = '';
                    } else {
                        deptSection.style.display = 'none';
                    }
                });

                if (searchVal) {
                    const filteredOptions = allQualOptions.filter(opt => visibleQualCodes.has(opt.value));
                    populateQualDropdown(filteredOptions, qualVal);
                } else {
                    populateQualDropdown(allQualOptions, qualVal);
                }

                filterResultCount.textContent = totalVisible + ' trainee' + (totalVisible !== 1 ? 's' : '');
                filterResultCount.classList.remove('has-results', 'no-results');
                if (totalVisible > 0 && totalVisible < <?= $total_eligible ?>) {
                    filterResultCount.classList.add('has-results');
                } else if (totalVisible === 0) {
                    filterResultCount.classList.add('no-results');
                }

                if (totalVisible === 0 && noFilterResults) {
                    noFilterResults.style.display = 'block';
                } else if (noFilterResults) {
                    noFilterResults.style.display = 'none';
                }
            }

            filterQual.addEventListener('change', applyFilters);
            filterSearch.addEventListener('input', applyFilters);

            filterResetBtn.addEventListener('click', function() {
                filterQual.value = '';
                filterSearch.value = '';
                populateQualDropdown(allQualOptions, '');
                applyFilters();
            });

            buildQualificationOptions();
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