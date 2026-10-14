<?php
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Detect AJAX FIRST — before we set display_errors
$__is_ajax_early = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    !empty($_SERVER['HTTP_ACCEPT']) &&
    strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

if ($__is_ajax_early) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ob_start();
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// ============================================================
// Detect AJAX BEFORE session start
// ============================================================
$_eval_is_ajax = $__is_ajax_early;

// ============================================================
// SESSION START (para sa lahat ng requests)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

require_once __DIR__ . '/config/config.php';

// ============================================================
// LOGIN CHECK — simple lang, hindi token-based
// ============================================================
if (empty($_SESSION['user_id'])) {
    if ($_eval_is_ajax) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'reason' => 'not_logged_in']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// ============================================================
// NOW safe to load the rest
// ============================================================
require_once __DIR__ . '/services/EligibilityService.php';
require_once __DIR__ . '/evaluation_helper_functions.php';
require_once __DIR__ . '/helpers/helpers/PusherHelper.php';

$user = function_exists('getCurrentUser') ? getCurrentUser() : null;

if (!$user || empty($user['id'])) {
    $user = [
        'id'         => $_SESSION['user_id']    ?? 1,
        'username'   => $_SESSION['username']   ?? 'guest',
        'full_name'  => $_SESSION['full_name']  ?? 'Guest User',
        'role'       => $_SESSION['role']       ?? 'coordinator',
        'department' => $_SESSION['department'] ?? null,
    ];
}

$db = $pdo;
$user_role       = $_SESSION['role']       ?? ($user['role'] ?? '');
$user_department = $_SESSION['department'] ?? ($user['department'] ?? '');
$user_id         = $_SESSION['user_id']    ?? ($user['id'] ?? 0);

if (empty($user_id)) { $user_id = 1; }

// ============================================================
// NOTE: Runtime migration code removed.
// ============================================================

// ==========================================
// RBAC: Get trainer qualification IDs
// ==========================================
$is_trainer = function_exists('isTrainer') ? isTrainer() : false;
$is_coordinator = function_exists('isCoordinator') ? isCoordinator() : false;
$trainer_qualification_ids = [];

if ($is_trainer && function_exists('getTrainerQualificationIds')) {
    $trainer_qualification_ids = getTrainerQualificationIds($user_id);
    $_SESSION['qualification_ids'] = $trainer_qualification_ids;
}

// ==========================================
// Determine which departments the trainer has access to
// ==========================================
$trainer_accessible_depts = [];
if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $dept_stmt = $pdo->prepare("
        SELECT DISTINCT c.course_code 
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        WHERE q.id IN ($placeholders)
    ");
    $dept_stmt->execute($trainer_qualification_ids);
    $trainer_accessible_depts = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
}

$eligibilityService = new EligibilityService($db);

// ============================================================
// HELPER: Check if a table exists
// ============================================================
function tableExists($db, $table) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("tableExists error for '$table': " . $e->getMessage());
        return false;
    }
}

// ============================================================
// HELPER: Check if a column exists
// ============================================================
function columnExists($db, $table, $column) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// HELPER: Build the "exclude already-reminded trainees" clause
// ============================================================
function buildNotifiedLogsExclusion($db, &$params = null) {
    if (!tableExists($db, 'notified_logs')) {
        return '';
    }

    return " AND NOT EXISTS (
        SELECT 1 FROM notified_logs nl 
        WHERE nl.trainee_id = t.id 
        AND nl.delivery_status = 'Sent'
        AND (nl.returned_to_evaluation IS NULL OR nl.returned_to_evaluation = 0)
    )";
}

// ============================================================
// HELPER: Broadened status exclusion
// ============================================================
function buildStatusExclusion() {
    return " AND (
        t.status IS NULL 
        OR t.status = '' 
        OR t.status NOT IN (
            'Pre-Assessment', 'pre_assessment', 
            'Ready for Pre-Assessment', 'ready_for_pre_assessment',
            'Ready for Assessment', 'ready_for_assessment',
            'Assessed', 'assessed', 
            'Completed', 'completed',
            'Certified', 'certified'
        )
    )";
}

// ============================================================
// VIDEO path resolver helper
// ============================================================
if (!function_exists('resolveTaskVideoUrl')) {
    function resolveTaskVideoUrl($rawPath) {
        if (empty($rawPath)) {
            return '';
        }

        $rawPath = trim((string)$rawPath);

        if (preg_match('#^https?://#i', $rawPath)) {
            return $rawPath;
        }

        if (strpos($rawPath, '/') === 0) {
            return $rawPath;
        }

        $rawPath = str_replace('\\', '/', $rawPath);
        $rawPath = preg_replace('#^(\./)+#', '', $rawPath);
        $rawPath = str_replace('../', '', $rawPath);

        return '/tesda_compliance/' . ltrim($rawPath, '/');
    }
}

// ============================================================
// GET ALL TRAINEES - WITH RBAC FILTERING
// ============================================================
function getAllTraineesWithStatus($db, $user_role, $user_department, $specific_trainee_id = 0, $filter = 'all', $department_filter = '', $qualification_filter = 0, $search = '', $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $has_batch_code = columnExists($db, 'trainees', 'batch_code');
        
        $sql = "
            SELECT 
                t.id,
                t.first_name,
                t.last_name,
                t.email,
                t.contact,
                t.section,
                " . ($has_batch_code ? "t.batch_code," : "") . "
                t.status as trainee_status,
                t.qualification_id as primary_qualification_id,
                t.department,
                q.id as qualification_id,
                q.qualification_code,
                q.qualification_name
            FROM trainees t
            LEFT JOIN qualifications q ON q.id = t.qualification_id
            WHERE 1=1
              AND t.deleted_at IS NULL
        ";

        $sql .= buildStatusExclusion();
        $sql .= buildNotifiedLogsExclusion($db);

        $params = [];
        
        if ($is_trainer) {
            if (empty($trainer_qualification_ids)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
            $sql .= " AND t.qualification_id IN ($placeholders)";
            $params = array_merge($params, $trainer_qualification_ids);
        }
        
        if ($specific_trainee_id > 0) { 
            $sql .= " AND t.id = ?"; 
            $params[] = $specific_trainee_id; 
        }
        
        if (!empty($department_filter) && $department_filter !== 'All Departments' && $department_filter !== 'all') {
            $sql .= " AND t.department = ?";
            $params[] = $department_filter;
        }
        
        if (!empty($qualification_filter) && $qualification_filter > 0) {
            $sql .= " AND t.qualification_id = ?";
            $params[] = $qualification_filter;
        }
        
        if (!empty($search)) {
            $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        
        $sql .= " ORDER BY t.department, t.section, t.last_name";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $trainees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $unique_trainees = [];
        $seen_ids = [];
        foreach ($trainees as $t) {
            if (!in_array($t['id'], $seen_ids)) {
                $seen_ids[] = $t['id'];
                $unique_trainees[] = $t;
            }
        }
        $trainees = $unique_trainees;
        
        $stmt_counts = $db->prepare("
            SELECT 
                COUNT(DISTINCT pt.id) AS total_tasks,
                COUNT(DISTINCT CASE WHEN et.remarks = 'Done' THEN pt.id END) AS completed_tasks
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
            WHERE cs.qualification_id = ?
        ");
        
        $stmt_comp = $db->prepare("
            SELECT 
                COUNT(DISTINCT cs.id) AS total_competencies,
                COUNT(DISTINCT CASE WHEN et.remarks = 'Done' THEN cs.id END) AS completed_competencies
            FROM competency_standards cs
            LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
            LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
            WHERE cs.qualification_id = ?
        ");
        
        $stmt_elig = $db->prepare("SELECT is_eligible FROM evaluation_eligibility WHERE trainee_id = ?");
        
        foreach ($trainees as &$t) {
            $qid = $t['qualification_id'] ?? 0;
            
            $total_tasks = 0;
            $completed_tasks = 0;
            $total_competencies = 0;
            $completed_competencies = 0;
            
            if ($qid > 0) {
                $stmt_counts->execute([$t['id'], $qid]);
                $row_counts = $stmt_counts->fetch(PDO::FETCH_ASSOC);
                $total_tasks = (int)($row_counts['total_tasks'] ?? 0);
                $completed_tasks = (int)($row_counts['completed_tasks'] ?? 0);
                
                $stmt_comp->execute([$t['id'], $qid]);
                $row_comp = $stmt_comp->fetch(PDO::FETCH_ASSOC);
                $total_competencies = (int)($row_comp['total_competencies'] ?? 0);
                $completed_competencies = (int)($row_comp['completed_competencies'] ?? 0);
            }
            
            $t['total_tasks'] = $total_tasks;
            $t['completed_tasks'] = $completed_tasks;
            $t['total_competencies'] = $total_competencies;
            $t['completed_competencies'] = $completed_competencies;
            $t['incomplete_competencies'] = $total_competencies - $completed_competencies;
            $t['incomplete_tasks'] = $total_tasks - $completed_tasks;
            $t['completion_rate'] = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100, 1) : 0;
            $t['progress_percentage'] = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
            
            if ($total_tasks == 0) {
                $t['compliance_status'] = 'No Tasks Assigned';
            } elseif ($completed_tasks >= $total_tasks && $total_tasks > 0) {
                $t['compliance_status'] = 'Fully Complied';
            } else {
                $t['compliance_status'] = 'Non-Compliant';
            }
            
            $stmt_elig->execute([$t['id']]);
            $elig = $stmt_elig->fetch(PDO::FETCH_ASSOC);
            $t['is_eligible'] = $elig['is_eligible'] ?? 0;
        }
        unset($t);
        
        if ($filter === 'non_compliant') {
            $trainees = array_values(array_filter($trainees, function($t) {
                return ($t['compliance_status'] ?? '') === 'Non-Compliant';
            }));
        } elseif ($filter === 'fully_complied') {
            $trainees = array_values(array_filter($trainees, function($t) {
                return ($t['compliance_status'] ?? '') === 'Fully Complied';
            }));
        }
        
        return $trainees;
        
    } catch (PDOException $e) {
        error_log("Error fetching trainees: " . $e->getMessage());
        return [];
    }
}

function getTraineeTasksByCompetency($db, $trainee_id, $qualification_filter = 0, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $trainee_sql = "SELECT t.first_name, t.last_name, t.email, t.contact, t.section, 
                               t.qualification_id as primary_qualification_id,
                               t.department,
                               q.id as qualification_id,
                               q.qualification_code,
                               q.qualification_name,
                               t.status
                        FROM trainees t 
                        LEFT JOIN qualifications q ON q.id = t.qualification_id
                        WHERE t.id = ? AND t.deleted_at IS NULL";
        
        $stmt = $db->prepare($trainee_sql);
        $stmt->execute([$trainee_id]);
        $trainee_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trainee_info) {
            return ['trainee_info' => [], 'competencies' => []];
        }
        
        $qual_id = (int)($trainee_info['primary_qualification_id'] ?? 0);
        if ($qual_id == 0 && $qualification_filter > 0) {
            $qual_id = (int)$qualification_filter;
        }

        if ($qual_id == 0 && !empty($trainee_info['department'])) {
            try {
                $fb = $db->prepare("SELECT q.id FROM qualifications q JOIN courses c ON q.course_id = c.id WHERE c.course_code = ? ORDER BY q.id ASC LIMIT 1");
                $fb->execute([$trainee_info['department']]);
                $fb_id = $fb->fetchColumn();
                if ($fb_id) {
                    $qual_id = (int)$fb_id;
                    try {
                        $upd = $db->prepare("UPDATE trainees SET qualification_id = ? WHERE id = ? AND (qualification_id IS NULL OR qualification_id = 0)");
                        $upd->execute([$qual_id, $trainee_id]);
                    } catch (Exception $e) {}
                }
            } catch (Exception $e) {}
        }

        $qual_valid = false;
        if ($qual_id > 0) {
            try {
                $chk = $db->prepare("SELECT COUNT(*) FROM qualifications WHERE id = ?");
                $chk->execute([$qual_id]);
                $qual_valid = ((int)$chk->fetchColumn() > 0);
            } catch (Exception $e) { $qual_valid = false; }
        }

        $qual_has_comps = false;
        if ($qual_valid) {
            try {
                $chk2 = $db->prepare("SELECT COUNT(*) FROM competency_standards WHERE qualification_id = ?");
                $chk2->execute([$qual_id]);
                $qual_has_comps = ((int)$chk2->fetchColumn() > 0);
            } catch (Exception $e) { $qual_has_comps = false; }
        }

        if (!$qual_valid || !$qual_has_comps) {
            $resolved = 0;
            try {
                if (!empty($trainee_info['department'])) {
                    $fb2 = $db->prepare("SELECT q.id FROM qualifications q JOIN courses c ON q.course_id = c.id JOIN competency_standards cs ON cs.qualification_id = q.id WHERE c.course_code = ? GROUP BY q.id ORDER BY q.id ASC LIMIT 1");
                    $fb2->execute([$trainee_info['department']]);
                    $resolved = (int)$fb2->fetchColumn();
                }
                if (!$resolved && !empty($trainee_info['section'])) {
                    $fb3 = $db->prepare("SELECT q.id FROM qualifications q JOIN competency_standards cs ON cs.qualification_id = q.id WHERE q.qualification_code = ? GROUP BY q.id ORDER BY q.id ASC LIMIT 1");
                    $fb3->execute([$trainee_info['section']]);
                    $resolved = (int)$fb3->fetchColumn();
                }
                if (!$resolved) {
                    $fb4 = $db->prepare("SELECT q.id FROM qualifications q JOIN competency_standards cs ON cs.qualification_id = q.id GROUP BY q.id ORDER BY q.id ASC LIMIT 1");
                    $fb4->execute();
                    $resolved = (int)$fb4->fetchColumn();
                }
                if ($resolved > 0) {
                    $qual_id = $resolved;
                    try {
                        $upd2 = $db->prepare("UPDATE trainees SET qualification_id = ? WHERE id = ?");
                        $upd2->execute([$qual_id, $trainee_id]);
                    } catch (Exception $e) {}
                }
            } catch (Exception $e) {}
        }

        try {
            $refresh = $db->prepare($trainee_sql);
            $refresh->execute([$trainee_id]);
            $refreshed = $refresh->fetch(PDO::FETCH_ASSOC);
            if ($refreshed) {
                $trainee_info = $refreshed;
            }
        } catch (Exception $e) {}
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            if (!in_array($qual_id, $trainer_qualification_ids)) {
                return ['trainee_info' => $trainee_info, 'competencies' => [], 'no_competencies' => true, 'access_denied' => true];
            }
        }
        
        if ($qual_id == 0) {
            return ['trainee_info' => $trainee_info, 'competencies' => [], 'no_competencies' => true];
        }

        $has_video_col = false;
        try {
            $chk_v = $db->prepare("SHOW COLUMNS FROM evaluation_tracker LIKE 'video_path'");
            $chk_v->execute();
            $has_video_col = $chk_v->rowCount() > 0;
        } catch (Exception $e) { $has_video_col = false; }

        $video_select = $has_video_col ? "et.video_path" : "NULL AS video_path";
        
        $sql = "SELECT 
            cs.id as competency_id, 
            cs.competency_name, 
            cs.code_no,
            cs.unit_of_competency as competency_type,
            pt.id as task_id, 
            pt.task_name, 
            et.id as tracker_id, 
            et.remarks,
            et.status,
            et.performance_task,
            $video_select
            FROM competency_standards cs
            LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id 
            LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
            WHERE cs.qualification_id = ?
            ORDER BY cs.unit_of_competency, cs.id, pt.id";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$trainee_id, $qual_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return ['trainee_info' => $trainee_info, 'competencies' => [], 'no_competencies' => true];
        }
        
        $grouped = [];
        foreach ($results as $row) {
            $comp_id = $row['competency_id'];
            if (!isset($grouped[$comp_id])) {
                $grouped[$comp_id] = [
                    'competency_id' => $comp_id,
                    'competency_name' => $row['competency_name'] ?? 'Unnamed Competency',
                    'code_no' => $row['code_no'] ?? '',
                    'competency_type' => $row['competency_type'] ?? 'Other',
                    'tasks' => [],
                    'total_tasks' => 0,
                    'completed_tasks' => 0,
                    'is_complete' => false
                ];
            }
            if ($row['task_id']) {
                $is_done = ($row['remarks'] === 'Done');
                $grouped[$comp_id]['tasks'][] = [
                    'task_id' => $row['task_id'],
                    'tracker_id' => $row['tracker_id'] ?? 0,
                    'task_name' => $row['task_name'] ?? 'Unnamed Task',
                    'performance_task' => $row['performance_task'] ?? null,
                    'remarks' => $row['remarks'] ?? 'Not Done',
                    'is_done' => $is_done,
                    'video_path' => $row['video_path'] ?? null
                ];
                $grouped[$comp_id]['total_tasks']++;
                if ($is_done) {
                    $grouped[$comp_id]['completed_tasks']++;
                }
            }
        }
        
        $grouped = array_filter($grouped, function($comp) {
            return $comp['total_tasks'] > 0;
        });
        
        foreach ($grouped as &$comp) {
            $comp['is_complete'] = ($comp['total_tasks'] > 0 && $comp['completed_tasks'] >= $comp['total_tasks']);
        }
        unset($comp);
        
        return [
            'trainee_info' => $trainee_info,
            'competencies' => array_values($grouped),
            'no_competencies' => empty($grouped)
        ];
        
    } catch (PDOException $e) {
        error_log("Error in getTraineeTasksByCompetency: " . $e->getMessage());
        return ['trainee_info' => [], 'competencies' => []];
    }
}

function getDepartmentStats($db, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $sql = "SELECT 
            t.department as course_code, 
            COUNT(DISTINCT t.id) as total_trainees,
            0 as non_compliant_count
            FROM trainees t 
            WHERE 1=1
              AND t.department IS NOT NULL AND t.department != ''
              AND t.deleted_at IS NULL
        ";
        
        $sql .= buildStatusExclusion();
        $sql .= buildNotifiedLogsExclusion($db);
        
        $params = [];
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
            $sql .= " AND t.qualification_id IN ($placeholders)";
            $params = $trainer_qualification_ids;
        } elseif ($is_trainer) {
            $sql .= " AND 1=0";
        }
        
        $sql .= " GROUP BY t.department";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getQualificationsByDepartment($db, $department_code, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $sql = "SELECT q.id, q.qualification_code, q.qualification_name
                FROM qualifications q
                JOIN courses c ON q.course_id = c.id
                WHERE c.course_code = ?
        ";
        
        $params = [$department_code];
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
            $sql .= " AND q.id IN ($placeholders)";
            $params = array_merge($params, $trainer_qualification_ids);
        } elseif ($is_trainer) {
            $sql .= " AND 1=0";
        }
        
        $sql .= " ORDER BY q.qualification_code";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getOverallStats($db, $department_filter = '', $qualification_filter = 0, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $sql = "SELECT 
            COUNT(DISTINCT t.id) as total_trainees,
            SUM(CASE 
                WHEN COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     WHERE cs.qualification_id = t.qualification_id),
                    0
                ) = 0 
                THEN 0
                WHEN COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
                     WHERE cs.qualification_id = t.qualification_id AND et.remarks = 'Done'),
                    0
                ) >= COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     WHERE cs.qualification_id = t.qualification_id),
                    0
                )
                THEN 1 ELSE 0 END) as fully_complied_count,
            SUM(CASE 
                WHEN COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     WHERE cs.qualification_id = t.qualification_id),
                    0
                ) > 0
                AND COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
                     WHERE cs.qualification_id = t.qualification_id AND et.remarks = 'Done'),
                    0
                ) < COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     WHERE cs.qualification_id = t.qualification_id),
                    0
                )
                THEN 1 ELSE 0 END) as non_compliant_count,
            SUM(CASE 
                WHEN COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     WHERE cs.qualification_id = t.qualification_id),
                    0
                ) = 0 
                THEN 1 ELSE 0 END) as no_tasks_count,
            COUNT(DISTINCT t.department) as total_departments,
            SUM(
                COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     WHERE cs.qualification_id = t.qualification_id),
                    0
                ) - COALESCE(
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
                     WHERE cs.qualification_id = t.qualification_id AND et.remarks = 'Done'),
                    0
                )
            ) as incomplete_tasks
            FROM trainees t 
            WHERE 1=1
              AND t.department IS NOT NULL AND t.department != ''
              AND t.deleted_at IS NULL
        ";
        
        $sql .= buildStatusExclusion();
        $sql .= buildNotifiedLogsExclusion($db);
        
        $params = [];
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
            $sql .= " AND t.qualification_id IN ($placeholders)";
            $params = $trainer_qualification_ids;
        } elseif ($is_trainer) {
            $sql .= " AND 1=0";
        }
        
        if (!empty($department_filter) && $department_filter !== 'All Departments' && $department_filter !== 'all') {
            $sql .= " AND t.department = ?";
            $params[] = $department_filter;
        }
        
        if (!empty($qualification_filter) && $qualification_filter > 0) {
            $sql .= " AND t.qualification_id = ?";
            $params[] = $qualification_filter;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $result['incomplete_tasks'] = $result['incomplete_tasks'] ?? 0;
        }
        
        return $result ?: ['total_trainees' => 0, 'fully_complied_count' => 0, 'non_compliant_count' => 0, 'no_tasks_count' => 0, 'total_departments' => 0, 'incomplete_tasks' => 0];
    } catch (PDOException $e) {
        error_log("Error in getOverallStats: " . $e->getMessage());
        return ['total_trainees' => 0, 'fully_complied_count' => 0, 'non_compliant_count' => 0, 'no_tasks_count' => 0, 'total_departments' => 0, 'incomplete_tasks' => 0];
    }
}

function getCompetencyStats($db, $trainee_id, $qualification_filter = 0, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $q_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $q_stmt->execute([$trainee_id]);
        $q_result = $q_stmt->fetch(PDO::FETCH_ASSOC);
        $trainee_own_qual = (int)($q_result['qualification_id'] ?? 0);
        if ($trainee_own_qual > 0) {
            $qualification_filter = $trainee_own_qual;
        } elseif ($qualification_filter == 0) {
            return ['total_competencies' => 0, 'completed_competencies' => 0];
        }

        if ($is_trainer && !empty($trainer_qualification_ids)) {
            if (!in_array($qualification_filter, $trainer_qualification_ids)) {
                return ['total_competencies' => 0, 'completed_competencies' => 0, 'access_denied' => true];
            }
        }

        if ($qualification_filter == 0) {
            return ['total_competencies' => 0, 'completed_competencies' => 0];
        }

        $sql = "SELECT 
            COUNT(DISTINCT cs.id) AS total_competencies,
            COUNT(DISTINCT CASE 
                WHEN task_stats.total_tasks > 0 
                 AND task_stats.completed_tasks >= task_stats.total_tasks 
                THEN cs.id 
            END) AS completed_competencies
            FROM competency_standards cs
            LEFT JOIN (
                SELECT 
                    pt.competency_id,
                    COUNT(DISTINCT pt.id) AS total_tasks,
                    COUNT(DISTINCT CASE WHEN et.remarks = 'Done' THEN pt.id END) AS completed_tasks
                FROM performance_tasks pt
                LEFT JOIN evaluation_tracker et 
                    ON et.task_id = pt.id AND et.trainee_id = ?
                GROUP BY pt.competency_id
            ) task_stats ON task_stats.competency_id = cs.id
            WHERE cs.qualification_id = ?";

        $params = [$trainee_id, $qualification_filter];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: ['total_competencies' => 0, 'completed_competencies' => 0];

    } catch (PDOException $e) {
        return ['total_competencies' => 0, 'completed_competencies' => 0];
    }
}

function getIncompleteTasks($db, $trainee_id, $qualification_filter = 0, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $q_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $q_stmt->execute([$trainee_id]);
        $q_result = $q_stmt->fetch(PDO::FETCH_ASSOC);
        $trainee_own_qual = (int)($q_result['qualification_id'] ?? 0);
        if ($trainee_own_qual > 0) {
            $qualification_filter = $trainee_own_qual;
        } elseif ($qualification_filter == 0) {
            return [];
        }
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            if (!in_array($qualification_filter, $trainer_qualification_ids)) {
                return [];
            }
        }
        
        if ($qualification_filter == 0) {
            return [];
        }
        
        $sql = "SELECT 
            pt.task_name,
            cs.competency_name,
            cs.code_no
            FROM qualifications q
            JOIN competency_standards cs ON cs.qualification_id = q.id 
            LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id 
            LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
            WHERE q.id = ? AND (et.remarks IS NULL OR et.remarks != 'Done') AND pt.id IS NOT NULL";
        
        $params = [$trainee_id, $qualification_filter];
        
        $sql .= " ORDER BY cs.unit_of_competency, cs.id, pt.id";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        return [];
    }
}

function canAccessPreAssessment($db, $trainee_id, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $trainee_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $trainee_stmt->execute([$trainee_id]);
        $trainee = $trainee_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trainee || !$trainee['qualification_id']) {
            return ['can_access' => false, 'message' => 'No qualification assigned to this trainee'];
        }
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            if (!in_array($trainee['qualification_id'], $trainer_qualification_ids)) {
                return ['can_access' => false, 'message' => 'Access denied to this qualification'];
            }
        }
        
        $qual_id = $trainee['qualification_id'];
        
        $total_sql = "SELECT COUNT(DISTINCT pt.id) as total 
                      FROM performance_tasks pt
                      JOIN competency_standards cs ON pt.competency_id = cs.id
                      WHERE cs.qualification_id = ?";
        $total_stmt = $db->prepare($total_sql);
        $total_stmt->execute([$qual_id]);
        $total_tasks = (int)$total_stmt->fetchColumn();
        
        if ($total_tasks == 0) {
            return ['can_access' => false, 'message' => 'No performance tasks assigned for this qualification'];
        }
        
        $completed_sql = "SELECT COUNT(DISTINCT pt.id) as completed 
                          FROM performance_tasks pt
                          JOIN competency_standards cs ON pt.competency_id = cs.id
                          LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = ?
                          WHERE cs.qualification_id = ? AND et.remarks = 'Done'";
        $completed_stmt = $db->prepare($completed_sql);
        $completed_stmt->execute([$trainee_id, $qual_id]);
        $completed_tasks = (int)$completed_stmt->fetchColumn();
        
        $completion_rate = ($completed_tasks / $total_tasks) * 100;
        
        if ($completion_rate < 100) {
            return [
                'can_access' => false, 
                'message' => 'You must complete all required performance tasks before taking the Pre-Assessment.',
                'completion_rate' => $completion_rate,
                'total_tasks' => $total_tasks,
                'completed_tasks' => $completed_tasks,
                'pending_tasks' => $total_tasks - $completed_tasks
            ];
        }
        
        return [
            'can_access' => true,
            'message' => 'All tasks completed. Ready for Pre-Assessment.',
            'completion_rate' => 100,
            'total_tasks' => $total_tasks,
            'completed_tasks' => $completed_tasks,
            'pending_tasks' => 0
        ];
        
    } catch (PDOException $e) {
        error_log("Error checking pre-assessment access: " . $e->getMessage());
        return ['can_access' => false, 'message' => 'Database error occurred'];
    }
}

function markAllTasksDone($db, $trainee_id, $user_id, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        $check_status = $db->prepare("SELECT id, status, qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $check_status->execute([$trainee_id]);
        $trainee_data = $check_status->fetch(PDO::FETCH_ASSOC);
        
        if (!$trainee_data) {
            return false;
        }
        
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            if (!in_array($trainee_data['qualification_id'], $trainer_qualification_ids)) {
                return [
                    'marked' => 0,
                    'message' => 'Access denied to this trainee',
                    'transferred' => false,
                    'transfer_message' => 'Access denied'
                ];
            }
        }
        
        $pre_assessment_statuses = ['Pre-Assessment', 'pre_assessment', 'Ready for Assessment'];
        if (in_array($trainee_data['status'], $pre_assessment_statuses)) {
            return [
                'marked' => 0,
                'message' => 'Trainee is already in Pre-Assessment',
                'transferred' => true,
                'transfer_message' => 'Trainee already in Pre-Assessment',
                'already_transferred' => true
            ];
        }
        
        $qual_id = $trainee_data['qualification_id'] ?? 0;
        
        if ($qual_id == 0) {
            return [
                'marked' => 0,
                'message' => 'Trainee has no qualification assigned',
                'transferred' => false,
                'transfer_message' => 'No qualification assigned'
            ];
        }
        
        $tasks = $db->prepare("
            SELECT pt.id, pt.task_name 
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE cs.qualification_id = ?
        ");
        $tasks->execute([$qual_id]);
        $all_tasks = $tasks->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($all_tasks)) {
            return [
                'marked' => 0,
                'message' => 'No tasks found for this qualification',
                'transferred' => false,
                'transfer_message' => 'No tasks available'
            ];
        }
        
        $marked_count = 0;
        
        $upsert = $db->prepare("
            INSERT INTO evaluation_tracker 
                (trainee_id, task_id, performance_task, remarks, status, evaluated_by, evaluated_at, created_at, updated_at) 
            VALUES (?, ?, ?, 'Done', 'complied', ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                performance_task = VALUES(performance_task),
                remarks = 'Done',
                status = 'complied',
                evaluated_by = VALUES(evaluated_by),
                evaluated_at = NOW(),
                updated_at = NOW()
        ");
        
        foreach ($all_tasks as $task) {
            $check = $db->prepare("SELECT id, remarks FROM evaluation_tracker WHERE trainee_id = ? AND task_id = ?");
            $check->execute([$trainee_id, $task['id']]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing || $existing['remarks'] != 'Done') {
                $upsert->execute([$trainee_id, $task['id'], $task['task_name'], $user_id]);
                $marked_count++;
            }
        }
        
        $check_all = $db->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN remarks = 'Done' THEN 1 ELSE 0 END) as completed
            FROM evaluation_tracker
            WHERE trainee_id = ?
        ");
        $check_all->execute([$trainee_id]);
        $stats = $check_all->fetch(PDO::FETCH_ASSOC);
        $total = (int)($stats['total'] ?? 0);
        $completed = (int)($stats['completed'] ?? 0);
        
        if ($total > 0 && $completed >= $total) {
            $update_trainee = $db->prepare("UPDATE trainees SET status = 'Ready for Pre-Assessment' WHERE id = ?");
            $update_trainee->execute([$trainee_id]);
            
            try {
                if (tableExists($db, 'evaluation_history')) {
                    $log = $db->prepare("INSERT INTO evaluation_history 
                        (trainee_id, action, performed_by, remarks, created_at) 
                        VALUES (?, 'all_tasks_completed', ?, 'All tasks completed - Ready for Pre-Assessment', NOW())");
                    $log->execute([$trainee_id, $user_id]);
                }
            } catch (Exception $e) {}
            
            try {
                if (!tableExists($db, 'pre_assessment')) {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS pre_assessment (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            trainee_id INT NOT NULL UNIQUE,
                            trainee_name VARCHAR(255),
                            qualification_id INT,
                            qualification_name VARCHAR(255),
                            status VARCHAR(50) DEFAULT 'pending',
                            moved_by INT,
                            moved_at DATETIME,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            INDEX idx_trainee_id (trainee_id)
                        )
                    ");
                }
                
                $trainee_stmt = $db->prepare("
                    SELECT t.first_name, t.last_name, t.qualification_id, q.qualification_name
                    FROM trainees t
                    LEFT JOIN qualifications q ON q.id = t.qualification_id
                    WHERE t.id = ?
                ");
                $trainee_stmt->execute([$trainee_id]);
                $trainee_data_name = $trainee_stmt->fetch(PDO::FETCH_ASSOC);
                $trainee_name = ($trainee_data_name['first_name'] ?? '') . ' ' . ($trainee_data_name['last_name'] ?? '');
                
                $check = $db->prepare("SELECT id FROM pre_assessment WHERE trainee_id = ?");
                $check->execute([$trainee_id]);
                
                if ($check->rowCount() > 0) {
                    $update = $db->prepare("UPDATE pre_assessment 
                        SET status = 'pending', moved_by = ?, moved_at = NOW(), updated_at = NOW(),
                            trainee_name = ?, qualification_id = ?, qualification_name = ?
                        WHERE trainee_id = ?");
                    $update->execute([
                        $user_id, $trainee_name, 
                        $trainee_data_name['qualification_id'] ?? null,
                        $trainee_data_name['qualification_name'] ?? null,
                        $trainee_id
                    ]);
                } else {
                    $insert = $db->prepare("INSERT INTO pre_assessment 
                        (trainee_id, trainee_name, qualification_id, qualification_name, status, moved_by, moved_at) 
                        VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
                    $insert->execute([
                        $trainee_id, $trainee_name,
                        $trainee_data_name['qualification_id'] ?? null,
                        $trainee_data_name['qualification_name'] ?? null,
                        $user_id
                    ]);
                }
            } catch (Exception $e) {}
            
            return [
                'marked' => $marked_count,
                'message' => "All {$marked_count} tasks completed - Moved to Pre-Assessment",
                'transferred' => true,
                'transfer_message' => 'Trainee moved to Pre-Assessment'
            ];
        } else {
            return [
                'marked' => $marked_count,
                'message' => "Marked {$marked_count} tasks as done. " . ($total - $completed) . " tasks remaining.",
                'transferred' => false,
                'transfer_message' => 'Tasks updated, not yet fully complied'
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Error in markAllTasksDone: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Error in markAllTasksDone: " . $e->getMessage());
        return false;
    }
}

function markCompetencyComplete($db, $trainee_id, $competency_id, $user_id, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            $q_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
            $q_stmt->execute([$trainee_id]);
            $qual_id = $q_stmt->fetchColumn();
            if (!in_array($qual_id, $trainer_qualification_ids)) {
                return false;
            }
        }
        
        $tasks = $db->prepare("
            SELECT pt.id, pt.task_name 
            FROM performance_tasks pt
            WHERE pt.competency_id = ?
        ");
        $tasks->execute([$competency_id]);
        $all_tasks = $tasks->fetchAll(PDO::FETCH_ASSOC);
        
        $upsert_not_done = $db->prepare("
            INSERT INTO evaluation_tracker 
                (trainee_id, task_id, performance_task, remarks, status, created_at, updated_at) 
            VALUES (?, ?, ?, 'Not Done', 'not_complied', NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                performance_task = VALUES(performance_task),
                updated_at = NOW()
        ");
        
        foreach ($all_tasks as $task) {
            $upsert_not_done->execute([$trainee_id, $task['id'], $task['task_name']]);
        }
        
        $stmt = $db->prepare("UPDATE evaluation_tracker et
            JOIN performance_tasks pt ON pt.id = et.task_id
            SET et.remarks = 'Done', et.status = 'complied', et.evaluated_by = ?, et.evaluated_at = NOW(), et.updated_at = NOW()
            WHERE et.trainee_id = ? AND pt.competency_id = ? AND (et.remarks IS NULL OR et.remarks != 'Done')");
        $stmt->execute([$user_id, $trainee_id, $competency_id]);
        $marked = $stmt->rowCount();
        return ['marked' => $marked, 'message' => "Marked {$marked} tasks as done in this competency"];
    } catch (PDOException $e) {
        error_log("Error in markCompetencyComplete: " . $e->getMessage());
        return false;
    }
}

function checkAndAutoTransfer($db, $trainee_id, $user_id, $trainer_qualification_ids = [], $is_trainer = false) {
    return markAllTasksDone($db, $trainee_id, $user_id, $trainer_qualification_ids, $is_trainer);
}

function sendReminder($db, $trainee_id, $trainer_qualification_ids = [], $is_trainer = false) {
    try {
        if ($is_trainer && !empty($trainer_qualification_ids)) {
            $q_stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
            $q_stmt->execute([$trainee_id]);
            $qual_id = $q_stmt->fetchColumn();
            if (!in_array($qual_id, $trainer_qualification_ids)) {
                return ['success' => false, 'message' => 'Access denied to this trainee'];
            }
        }
        
        $trainee_sql = "SELECT t.first_name, t.last_name, t.email, t.contact 
                        FROM trainees t WHERE t.id = ? AND t.deleted_at IS NULL";
        $stmt = $db->prepare($trainee_sql);
        $stmt->execute([$trainee_id]);
        $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trainee) {
            return ['success' => false, 'message' => 'Trainee not found'];
        }
        
        $incomplete_tasks = getIncompleteTasks($db, $trainee_id, 0, $trainer_qualification_ids, $is_trainer);
        
        if (empty($incomplete_tasks)) {
            return ['success' => false, 'message' => 'No incomplete tasks found for this trainee'];
        }
        
        try {
            if (tableExists($db, 'evaluation_history')) {
                $log = $db->prepare("INSERT INTO evaluation_history 
                    (trainee_id, action, performed_by, remarks, created_at) 
                    VALUES (?, 'reminder_sent', ?, 'Reminder sent for incomplete tasks', NOW())");
                $log->execute([$trainee_id, $user_id]);
            }
        } catch (Exception $e) {}
        
        return [
            'success' => true, 
            'message' => 'Reminder sent successfully',
            'trainee' => $trainee,
            'incomplete_count' => count($incomplete_tasks),
            'tasks' => $incomplete_tasks
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error'];
    }
}

// ============================================================
// AJAX HANDLING
// ============================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        switch ($_POST['action']) {
            case 'mark_task_done':
                $tracker_id = (int)$_POST['tracker_id'];
                $trainee_id = (int)$_POST['trainee_id'];
                $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
                $user_id = $user['id'];
                
                if ($is_trainer && !empty($trainer_qualification_ids)) {
                    $q_stmt = $pdo->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
                    $q_stmt->execute([$trainee_id]);
                    $qual_id = $q_stmt->fetchColumn();
                    if (!in_array($qual_id, $trainer_qualification_ids)) {
                        $response = ['success' => false, 'message' => 'Access denied to this trainee'];
                        break;
                    }
                }
                
                if ($tracker_id > 0) {
                    $result = markTaskDone($db, $tracker_id, $user_id);
                    $action = 'update';
                } elseif ($task_id > 0 && $trainee_id > 0) {
                    $result = createAndMarkTaskDone($db, $trainee_id, $task_id, $user_id);
                    $action = 'insert';
                } else {
                    $response = ['success' => false, 'message' => 'Missing tracker_id or task_id/trainee_id'];
                    break;
                }
                
                if ($result !== false) {
                    $pre_access = canAccessPreAssessment($db, $trainee_id, $trainer_qualification_ids, $is_trainer);
                    
                    if ($pre_access['can_access']) {
                        $auto_transfer = checkAndAutoTransfer($db, $trainee_id, $user['id'], $trainer_qualification_ids, $is_trainer);
                        
                        $trainee_stmt = $pdo->prepare("SELECT first_name, last_name, department FROM trainees WHERE id = ?");
                        $trainee_stmt->execute([$trainee_id]);
                        $trainee_info = $trainee_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $payload = [
                            'trainee_id' => $trainee_id,
                            'trainee_name' => ($trainee_info['first_name'] ?? '') . ' ' . ($trainee_info['last_name'] ?? ''),
                            'department' => $trainee_info['department'] ?? '',
                            'transferred' => true,
                            'all_done' => true
                        ];
                        triggerPusherUpdate('task_evaluated', $payload, 'compliance-channel');
                        
                        $response = [
                            'success' => true, 
                            'message' => 'Task marked as done successfully', 
                            'eligibility' => $result,
                            'transferred' => $auto_transfer['transferred'] ?? false,
                            'transfer_message' => $auto_transfer['transfer_message'] ?? $auto_transfer['message'] ?? '',
                            'all_done' => true,
                            'pending_tasks' => 0,
                            'action' => $action
                        ];
                    } else {
                        $trainee_stmt = $pdo->prepare("SELECT first_name, last_name, department FROM trainees WHERE id = ?");
                        $trainee_stmt->execute([$trainee_id]);
                        $trainee_info = $trainee_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $payload = [
                            'trainee_id' => $trainee_id,
                            'trainee_name' => ($trainee_info['first_name'] ?? '') . ' ' . ($trainee_info['last_name'] ?? ''),
                            'department' => $trainee_info['department'] ?? '',
                            'transferred' => false,
                            'all_done' => false,
                            'pending_tasks' => $pre_access['pending_tasks'] ?? 0
                        ];
                        triggerPusherUpdate('task_evaluated', $payload, 'compliance-channel');
                        
                        $response = [
                            'success' => true, 
                            'message' => 'Task marked as done successfully', 
                            'eligibility' => $result,
                            'transferred' => false,
                            'all_done' => false,
                            'pending_tasks' => $pre_access['pending_tasks'] ?? 0,
                            'completion_message' => $pre_access['message'] ?? '',
                            'action' => $action
                        ];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Failed to mark task. Check error logs.'];
                }
                break;
                
            case 'reset_task':
                $tracker_id = (int)$_POST['tracker_id'];
                $user_id = $user['id'];
                
                $result = resetTask($db, $tracker_id, $user_id);
                $response = ($result !== false) 
                    ? ['success' => true, 'message' => 'Task reset successfully', 'eligibility' => $result] 
                    : ['success' => false, 'message' => 'Failed to reset task'];
                break;
                
            case 'mark_competency_complete':
                $trainee_id = (int)$_POST['trainee_id'];
                $competency_id = (int)$_POST['competency_id'];
                $user_id = $user['id'];
                
                $result = markCompetencyComplete($db, $trainee_id, $competency_id, $user_id, $trainer_qualification_ids, $is_trainer);
                if ($result !== false) {
                    $pre_access = canAccessPreAssessment($db, $trainee_id, $trainer_qualification_ids, $is_trainer);
                    
                    if ($pre_access['can_access']) {
                        $auto_transfer = checkAndAutoTransfer($db, $trainee_id, $user['id'], $trainer_qualification_ids, $is_trainer);
                        
                        $trainee_stmt = $pdo->prepare("SELECT first_name, last_name, department FROM trainees WHERE id = ?");
                        $trainee_stmt->execute([$trainee_id]);
                        $trainee_info = $trainee_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $payload = [
                            'trainee_id' => $trainee_id,
                            'trainee_name' => ($trainee_info['first_name'] ?? '') . ' ' . ($trainee_info['last_name'] ?? ''),
                            'department' => $trainee_info['department'] ?? '',
                            'transferred' => true,
                            'all_done' => true,
                            'competency_id' => $competency_id
                        ];
                        triggerPusherUpdate('task_evaluated', $payload, 'compliance-channel');
                        
                        $response = [
                            'success' => true, 
                            'message' => "Marked {$result['marked']} tasks as done in this competency",
                            'transferred' => $auto_transfer['transferred'] ?? false,
                            'transfer_message' => $auto_transfer['transfer_message'] ?? $auto_transfer['message'] ?? '',
                            'all_done' => true
                        ];
                    } else {
                        $response = [
                            'success' => true, 
                            'message' => "Marked {$result['marked']} tasks as done in this competency",
                            'transferred' => false,
                            'all_done' => false,
                            'pending_tasks' => $pre_access['pending_tasks'] ?? 0
                        ];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Failed to mark competency'];
                }
                break;
                
            case 'mark_all_tasks_done':
                $trainee_id = (int)$_POST['trainee_id'];
                $user_id = $user['id'];
                
                try {
                    $validate = $db->prepare("SELECT id, first_name, last_name, qualification_id, status, department FROM trainees WHERE id = ? AND deleted_at IS NULL");
                    $validate->execute([$trainee_id]);
                    $trainee_data = $validate->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$trainee_data) {
                        $response = ['success' => false, 'message' => 'Trainee not found'];
                        break;
                    }
                    
                    if ($is_trainer && !empty($trainer_qualification_ids)) {
                        if (!in_array($trainee_data['qualification_id'], $trainer_qualification_ids)) {
                            $response = ['success' => false, 'message' => 'Access denied to this trainee'];
                            break;
                        }
                    }
                    
                    $result = markAllTasksDone($db, $trainee_id, $user_id, $trainer_qualification_ids, $is_trainer);
                    
                    if ($result !== false) {
                        if ($result['transferred'] ?? false) {
                            $payload = [
                                'trainee_id' => $trainee_id,
                                'trainee_name' => ($trainee_data['first_name'] ?? '') . ' ' . ($trainee_data['last_name'] ?? ''),
                                'department' => $trainee_data['department'] ?? '',
                                'qualification_id' => $trainee_data['qualification_id'] ?? 0
                            ];
                            triggerPusherUpdate('pre_assessment_ready', $payload, 'compliance-channel');
                        }
                        
                        $response = [
                            'success' => true, 
                            'message' => $result['message'],
                            'marked' => $result['marked'] ?? 0,
                            'transferred' => $result['transferred'] ?? false,
                            'transfer_message' => $result['transfer_message'] ?? '',
                            'already_transferred' => $result['already_transferred'] ?? false,
                            'trainee_name' => ($trainee_data['first_name'] ?? '') . ' ' . ($trainee_data['last_name'] ?? '')
                        ];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to mark all tasks.'];
                    }
                } catch (Exception $e) {
                    error_log("Error in mark_all_tasks_done: " . $e->getMessage());
                    $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
                }
                break;
                
            case 'get_trainee_tasks':
                $trainee_id = (int)$_POST['trainee_id'];

                $tq = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ? AND deleted_at IS NULL");
                $tq->execute([$trainee_id]);
                $trainee_own_qual = $tq->fetchColumn();
                $trainee_own_qual = ($trainee_own_qual === null || $trainee_own_qual === false) ? 0 : (int)$trainee_own_qual;

                if ($trainee_own_qual > 0) {
                    $qualification = $trainee_own_qual;
                } else {
                    $qualification = isset($_POST['qualification']) ? (int)$_POST['qualification'] : 0;
                }
                
                $data = getTraineeTasksByCompetency($db, $trainee_id, $qualification, $trainer_qualification_ids, $is_trainer);
                $stats = getCompetencyStats($db, $trainee_id, $qualification, $trainer_qualification_ids, $is_trainer);
                $incomplete_tasks = getIncompleteTasks($db, $trainee_id, $qualification, $trainer_qualification_ids, $is_trainer);
                $pre_access = canAccessPreAssessment($db, $trainee_id, $trainer_qualification_ids, $is_trainer);
                
                $competencies_arr = $data['competencies'] ?? [];
                
                $response = [
                    'success' => true,
                    'trainee_info' => $data['trainee_info'] ?? [],
                    'competencies' => $competencies_arr,
                    'stats' => $stats,
                    'no_competencies' => empty($competencies_arr),
                    'has_incomplete_tasks' => !empty($incomplete_tasks),
                    'pre_assessment_access' => $pre_access,
                    'pending_tasks' => $pre_access['pending_tasks'] ?? 0,
                    '_debug' => [
                        'trainee_id' => $trainee_id,
                        'qualification_used' => $qualification,
                        'primary_qualification_id' => $data['trainee_info']['primary_qualification_id'] ?? null,
                        'competency_count' => count($competencies_arr),
                        'access_denied' => $data['access_denied'] ?? false
                    ]
                ];
                break;
                
            case 'send_reminder':
                $trainee_id = (int)$_POST['trainee_id'];
                $result = sendReminder($db, $trainee_id, $trainer_qualification_ids, $is_trainer);
                $response = $result;
                break;
                
            case 'filter_trainees':
                $search = trim($_POST['search'] ?? '');
                $department = $_POST['department'] ?? '';
                $filter = $_POST['filter'] ?? 'all';
                $qualification = isset($_POST['qualification']) ? (int)$_POST['qualification'] : 0;
                
                if ($department === 'All Departments' || $department === 'all' || empty($department)) {
                    $department = '';
                }
                
                $trainees = getAllTraineesWithStatus($db, $user_role, $user_department, 0, $filter, $department, $qualification, $search, $trainer_qualification_ids, $is_trainer);
                $stats = getOverallStats($db, $department, $qualification, $trainer_qualification_ids, $is_trainer);
                $response = [
                    'success' => true, 
                    'trainees' => $trainees, 
                    'count' => count($trainees),
                    'stats' => $stats
                ];
                break;
                
            case 'get_department_trainees':
                $department = trim($_POST['department'] ?? '');
                $qualification = isset($_POST['qualification']) ? (int)$_POST['qualification'] : 0;
                $search = trim($_POST['search'] ?? '');
                
                if (empty($department) || $department === 'All Departments' || $department === 'all') {
                    $department = 'HRS';
                }
                
                $trainees = getAllTraineesWithStatus($db, $user_role, $user_department, 0, 'all', $department, $qualification, $search, $trainer_qualification_ids, $is_trainer);
                $stats = getOverallStats($db, $department, $qualification, $trainer_qualification_ids, $is_trainer);
                $response = [
                    'success' => true, 
                    'trainees' => $trainees, 
                    'count' => count($trainees),
                    'stats' => $stats
                ];
                break;
                
            case 'get_qualifications':
                $department = trim($_POST['department'] ?? '');
                if (empty($department)) {
                    $response = ['success' => false, 'message' => 'Department code is required'];
                    break;
                }
                $qualifications = getQualificationsByDepartment($db, $department, $trainer_qualification_ids, $is_trainer);
                $response = ['success' => true, 'qualifications' => $qualifications];
                break;
                
            case 'get_stats':
                $department = $_POST['department'] ?? '';
                $qualification = isset($_POST['qualification']) ? (int)$_POST['qualification'] : 0;
                $stats = getOverallStats($db, $department, $qualification, $trainer_qualification_ids, $is_trainer);
                $response = [
                    'success' => true, 
                    'stats' => $stats, 
                    'department_stats' => getDepartmentStats($db, $trainer_qualification_ids, $is_trainer)
                ];
                break;
                
            case 'check_pre_assessment_access':
                $trainee_id = (int)$_POST['trainee_id'];
                $result = canAccessPreAssessment($db, $trainee_id, $trainer_qualification_ids, $is_trainer);
                $response = ['success' => true, 'data' => $result];
                break;

            default:
                $response = ['success' => false, 'message' => 'Unknown action: ' . $_POST['action']];
                break;
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
        $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ============================================================
// MAIN PAGE LOAD
// ============================================================
$filter = $_GET['filter'] ?? 'all';

if (isset($_GET['department'])) {
    $selected_department = $_GET['department'];
} else {
    $selected_department = 'HRS';
    try {
        $where = "t.department IS NOT NULL AND t.department != '' AND t.deleted_at IS NULL";
        $where .= buildStatusExclusion();
        $where .= buildNotifiedLogsExclusion($pdo);
        
        $first_dept_stmt = $pdo->query("
            SELECT t.department 
            FROM trainees t 
            WHERE $where
            GROUP BY t.department 
            ORDER BY COUNT(*) DESC 
            LIMIT 1
        ");
        $first_dept = $first_dept_stmt->fetchColumn();
        if ($first_dept) {
            $selected_department = $first_dept;
        }
    } catch (PDOException $e) {
    }
}

$selected_qualification = isset($_GET['qualification']) ? (int)$_GET['qualification'] : 0;

if ($is_trainer && !$is_coordinator) {
    if (!empty($trainer_accessible_depts)) {
        if (!in_array($selected_department, $trainer_accessible_depts)) {
            $selected_department = $trainer_accessible_depts[0];
        }
    }
}

if ($selected_qualification == 0) {
    $first_quals = getQualificationsByDepartment($db, $selected_department, $trainer_qualification_ids, $is_trainer);
    if (!empty($first_quals)) {
        $selected_qualification = (int)$first_quals[0]['id'];
    }
}

$trainees = getAllTraineesWithStatus($db, $user_role, $user_department, 0, $filter, $selected_department, $selected_qualification, '', $trainer_qualification_ids, $is_trainer);
$stats = getOverallStats($db, $selected_department, $selected_qualification, $trainer_qualification_ids, $is_trainer);
$department_stats = getDepartmentStats($db, $trainer_qualification_ids, $is_trainer);

$total_trainees = $stats['total_trainees'] ?? 0;
$non_compliant_count = $stats['non_compliant_count'] ?? 0;
$fully_complied_count = $stats['fully_complied_count'] ?? 0;
$no_tasks_count = $stats['no_tasks_count'] ?? 0;
$total_incomplete_tasks = $stats['incomplete_tasks'] ?? 0;
$total_departments = $stats['total_departments'] ?? 0;
$compliance_rate = $total_trainees > 0 ? round((($fully_complied_count) / $total_trainees) * 100) : 0;

$selected_trainee_id = isset($_GET['trainee_id']) ? (int)$_GET['trainee_id'] : 0;
if ($selected_trainee_id === 0 && !empty($trainees)) {
    $selected_trainee_id = $trainees[0]['id'];
}

$departments = [
    'HRS' => ['name' => 'Hotel & Restaurant Services', 'icon' => 'fa-utensils'],
    'CSS' => ['name' => 'Computer System Servicing', 'icon' => 'fa-laptop-code'],
    'ATS' => ['name' => 'Automotive Servicing', 'icon' => 'fa-car']
];
$current_dept = $departments[$selected_department] ?? $departments['HRS'];

$qualifications = getQualificationsByDepartment($db, $selected_department, $trainer_qualification_ids, $is_trainer);

$dept_non_compliant = array_filter($department_stats, fn($d) => ($d['course_code'] ?? '') === $selected_department);
$dept_non_compliant_count = !empty($dept_non_compliant) ? reset($dept_non_compliant)['non_compliant_count'] : 0;

$qualification_name = '';
foreach ($qualifications as $q) {
    if ($q['id'] == $selected_qualification) {
        $qualification_name = $q['qualification_code'];
        break;
    }
}

$disabled_tabs = [];
if ($is_trainer && !$is_coordinator) {
    foreach (array_keys($departments) as $dept_code) {
        if (!in_array($dept_code, $trainer_accessible_depts)) {
            $disabled_tabs[] = $dept_code;
        }
    }
}

if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script_name = $_SERVER['SCRIPT_NAME'];
        $base_path = rtrim(dirname($script_name), '/') . '/';
        if (strpos($base_path, '/reports/') !== false) {
            $base_path = str_replace('/reports/', '/', $base_path);
        }
        return $protocol . $host . $base_path;
    }
}
$base_url = getBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non-Compliant Trainees | Evaluation Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="/tesda_compliance/assets/js/socket.io.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; min-height: 100vh; color: #1e293b; }
        .main-content { margin-left: 280px; padding: 28px 32px 40px 32px; min-height: 100vh; }
        .sidebar-collapsed .main-content { margin-left: 80px; }
        
        .dashboard-card { background: #ffffff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: 1px solid rgba(226,232,240,0.4); overflow: hidden; }
        .dashboard-card .card-body { padding: 24px 28px; }
        
        .dashboard-header {
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
        
        .dashboard-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 45px rgba(0,0,0,0.12);
        }
        
        .dashboard-header .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .dashboard-header .header-left .alert-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: #fee2e2;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef4444;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .dashboard-header .header-left .title-group h4 {
            font-weight: 700;
            font-size: 1.8rem;
            margin: 0;
            color: #0f172a;
        }
        
        .dashboard-header .header-left .title-group .subtitle {
            font-size: 1rem;
            color: #64748b;
            margin: 0;
            margin-top: 4px;
        }
        
        .dashboard-header .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        
        .profile-btn {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 6px 20px 6px 6px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
        }

        .profile-btn:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }

        .profile-btn .avatar-circle {
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

        .profile-btn:hover .avatar-circle {
            transform: scale(1.05);
        }

        .profile-btn .avatar-circle i {
            color: #ffffff;
            font-size: 0.9rem;
        }

        .profile-btn .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        
        .dashboard-header .header-right .date-badge {
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

        .dashboard-header .header-right .date-badge i {
            color: #0f172a;
            font-size: 1rem;
        }

        .dashboard-header .header-right .date-badge:hover {
            background-color: #f1f5f9;
        }
        
        .filter-bar { background: #ffffff; border-radius: 16px; padding: 24px 20px; margin-bottom: 28px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: 1px solid rgba(226,232,240,0.4); }
        .filter-bar .form-label { font-weight: 600; font-size: 0.85rem; color: #475569; margin-bottom: 4px; }
        .filter-bar .form-control, .filter-bar .form-select { border-radius: 12px; border: 1.5px solid #e2e8f0; padding: 10px 16px; font-size: 0.9rem; background: #fafbfc; }
        .filter-bar .form-control:focus, .filter-bar .form-select:focus { border-color: #8b5cf6; box-shadow: 0 0 0 4px rgba(139,92,246,0.10); background: #ffffff; }
        .filter-bar .input-group-text { background: #fafbfc; border: 1.5px solid #e2e8f0; border-right: none; border-radius: 12px 0 0 12px; color: #94a3b8; }
        .btn-reset-filters { background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0; padding: 10px 24px; border-radius: 12px; font-weight: 600; font-size: 0.9rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; width: 100%; justify-content: center; transition: all 0.3s ease; }
        .btn-reset-filters:hover { background: #e2e8f0; transform: translateY(-2px); }
        
        .filter-bar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .filter-bar-header h5 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-bar-header h5 i { color: #8b5cf6; }
        
        .content-grid { display: grid; grid-template-columns: 380px 1fr; gap: 24px; }
        
        .trainees-list-card .card-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; background: transparent; }
        .trainees-list-card .card-header h6 { font-weight: 700; font-size: 0.95rem; margin: 0; display: flex; align-items: center; gap: 8px; }
        .trainees-list-card .card-body { padding: 16px 20px; max-height: 600px; overflow-y: auto; }
        .trainees-list-card .card-body::-webkit-scrollbar { width: 4px; }
        .trainees-list-card .card-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .trainee-list-item { padding: 12px 14px; border-radius: 12px; border: 1px solid #f1f5f9; margin-bottom: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s ease; }
        .trainee-list-item:hover { background: #f8fafc; border-color: #e2e8f0; transform: translateX(4px); }
        .trainee-list-item.active { background: #ede9fe; border-color: #8b5cf6; box-shadow: 0 2px 8px rgba(139,92,246,0.15); }
        .trainee-list-item .trainee-name { font-weight: 600; font-size: 0.9rem; color: #1e293b; }
        .trainee-list-item .trainee-detail { font-size: 0.75rem; color: #94a3b8; }
        .trainee-list-item .trainee-badge { font-size: 0.65rem; padding: 2px 10px; border-radius: 20px; font-weight: 600; }
        .trainee-list-item .trainee-badge.non-compliant { background: #fee2e2; color: #ef4444; }
        .trainee-list-item .trainee-badge.fully-complied { background: #d1fae5; color: #10b981; }
        .trainee-list-item .trainee-badge.no-tasks { background: #fef3c7; color: #f59e0b; }
        .trainee-list-item.removing { opacity: 0; transform: translateX(-20px); transition: all 0.4s ease; pointer-events: none; }
        
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-state .empty-icon { font-size: 3rem; color: #cbd5e1; margin-bottom: 16px; }
        .empty-state .empty-icon i { background: #f1f5f9; padding: 20px; border-radius: 50%; }
        .empty-state h6 { font-weight: 600; color: #1e293b; margin-bottom: 6px; }
        .empty-state p { color: #94a3b8; font-size: 0.9rem; margin-bottom: 0; }
        
        .floating-message { position: fixed; top: 24px; right: 24px; padding: 16px 28px; border-radius: 16px; z-index: 9999; display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 0.95rem; box-shadow: 0 8px 32px rgba(0,0,0,0.15); }
        .floating-message.success { background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; }
        .floating-message.error { background: linear-gradient(135deg, #ef4444, #dc2626); color: #ffffff; }
        .floating-message.info { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #ffffff; }
        
        .dept-tabs { display: flex; justify-content: space-around; align-items: center; gap: 8px; padding: 8px 16px; background: #f1f5f9; border-radius: 14px; margin-bottom: 16px; border: 1px solid #e2e8f0; flex-wrap: wrap; width: 100%; }
        .dept-tab-wrapper { display: inline-flex; align-items: center; background: transparent; border-radius: 30px; padding: 4px 10px 4px 14px; transition: all 0.2s ease; gap: 6px; position: relative; flex: 0 1 auto; }
        .dept-tab-wrapper.active-tab { background: #ffffff; padding: 6px 16px 6px 20px; border-radius: 30px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.07); border: 1px solid rgba(255, 255, 255, 0.6); }
        .dept-tab-wrapper.disabled-tab { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
        .dept-tab-static { display: inline-flex; align-items: center; gap: 8px; padding: 4px 2px; font-weight: 600; font-size: 0.9rem; color: #475569; cursor: default; user-select: none; background: transparent; border: none; border-radius: 8px; white-space: nowrap; }
        .dept-tab-static .tab-icon { font-size: 0.9rem; color: #64748b; width: 18px; text-align: center; }
        .dept-tab-static .tab-label { color: #475569; font-weight: 600; font-size: 0.95rem; letter-spacing: 0.2px; }
        .dept-tab-wrapper.active-tab .dept-tab-static .tab-label { color: #1e293b; }
        .dept-tab-wrapper.active-tab .dept-tab-static .tab-icon { color: #7c3aed; }
        .dept-dropdown-arrow { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; border: none; background: transparent; color: #94a3b8; cursor: pointer; font-size: 0.7rem; transition: all 0.2s ease; padding: 0; flex-shrink: 0; }
        .dept-dropdown-arrow:hover { background: rgba(0, 0, 0, 0.06); color: #7c3aed; }
        .dept-dropdown-arrow:focus { outline: none; }
        .dept-dropdown-arrow.open { color: #7c3aed; transform: rotate(180deg); }
        .dept-tab-wrapper.disabled-tab .dept-dropdown-arrow { display: none; }
        .qual-dropdown { display: none; position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%); background: #ffffff; border-radius: 14px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12); border: 1px solid #e2e8f0; z-index: 1050; min-width: 220px; padding: 6px 0; }
        .qual-dropdown.show { display: block !important; }
        .qual-dropdown .qual-item { padding: 6px 16px; font-size: 0.85rem; color: #1e293b; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 10px; white-space: nowrap; }
        .qual-dropdown .qual-item:hover { background: #f8fafc; }
        .qual-dropdown .qual-item.active { background: #ede9fe; color: #7c3aed; font-weight: 600; }
        .qual-dropdown .qual-item .qual-check { color: #7c3aed; font-size: 0.65rem; margin-left: auto; }
        .qual-dropdown .qual-divider { height: 1px; background: #f1f5f9; margin: 4px 12px; }
        .qual-dropdown .qual-all { padding: 6px 16px; font-size: 0.85rem; color: #7c3aed; cursor: pointer; font-weight: 600; border-bottom: 1px solid #f1f5f9; margin-bottom: 4px; display: flex; align-items: center; gap: 10px; }
        .qual-dropdown .qual-all:hover { background: #f8fafc; }
        .dept-tab-wrapper { position: relative; }
        
        .header-card { background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 24px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .dept-header-row { display: flex; justify-content: space-between; align-items: center; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9; }
        .dept-header-row .dept-title { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 1rem; color: #1e293b; }
        .dept-header-row .dept-title .accent-line { width: 4px; height: 24px; background: #8b5cf6; border-radius: 4px; flex-shrink: 0; }
        .dept-header-row .dept-title .dept-icon { color: #8b5cf6; font-size: 1.1rem; }
        .dept-header-row .dept-count { background: #f1f5f9; color: #475569; padding: 4px 14px; border-radius: 30px; font-size: 0.75rem; font-weight: 600; }
        
        .trainee-profile-card { display: flex; align-items: center; gap: 14px; background: #f8fafc; border-radius: 12px; padding: 12px 18px; margin-top: 14px; border: 1px solid #f1f5f9; }
        .trainee-profile-card .avatar { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #a78bfa); display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 700; font-size: 1rem; flex-shrink: 0; }
        .trainee-profile-card .trainee-info { display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .trainee-profile-card .trainee-info .trainee-name-row { display: flex; align-items: center; gap: 10px; }
        .trainee-profile-card .trainee-info .trainee-name-row .name { font-weight: 600; font-size: 0.95rem; color: #1e293b; }
        .trainee-profile-card .trainee-info .trainee-name-row .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .trainee-profile-card .trainee-info .trainee-name-row .status-dot.has-tasks { background: #f59e0b; }
        .trainee-profile-card .trainee-info .trainee-name-row .status-dot.all-done { background: #10b981; }
        .trainee-profile-card .trainee-info .qualification-row { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #64748b; }
        .trainee-profile-card .trainee-info .qualification-row i { color: #94a3b8; font-size: 0.7rem; }
        .trainee-profile-card .action-buttons { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .trainee-profile-card .action-buttons .btn-mark-all { background: #8b5cf6; color: #ffffff; border: none; padding: 6px 16px; border-radius: 30px; font-weight: 600; font-size: 0.7rem; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .trainee-profile-card .action-buttons .btn-mark-all:hover { background: #7c3aed; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(139,92,246,0.3); }
        .trainee-profile-card .action-buttons .btn-mark-all:disabled { opacity: 0.5; cursor: not-allowed; transform: none; background: #94a3b8; }
        .trainee-profile-card .action-buttons .btn-reminder { background: #f59e0b; color: #ffffff; border: none; padding: 6px 16px; border-radius: 30px; font-weight: 600; font-size: 0.7rem; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .trainee-profile-card .action-buttons .btn-reminder:hover { background: #d97706; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245,158,11,0.3); }
        .trainee-profile-card .action-buttons .btn-reminder:disabled { opacity: 0.5; cursor: not-allowed; transform: none; background: #94a3b8; }
        .trainee-profile-card .action-buttons .spinner-small { display: inline-block; width: 12px; height: 12px; border: 2px solid #ffffff; border-top: 2px solid transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .metrics-bar { display: flex; flex-wrap: wrap; gap: 16px 40px; padding: 14px 18px; margin-top: 14px; background: #f8fafc; border-radius: 12px; border: 1px solid #f1f5f9; align-items: center; }
        .metrics-bar .metric-item { display: flex; align-items: center; gap: 10px; }
        .metrics-bar .metric-item .metric-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
        .metrics-bar .metric-item .metric-icon.purple { background: #ede9fe; color: #8b5cf6; }
        .metrics-bar .metric-item .metric-icon.green { background: #d1fae5; color: #10b981; }
        .metrics-bar .metric-item .metric-icon.red { background: #fee2e2; color: #ef4444; }
        .metrics-bar .metric-item .metric-icon.orange { background: #fef3c7; color: #f59e0b; }
        .metrics-bar .metric-item .metric-info { display: flex; flex-direction: column; }
        .metrics-bar .metric-item .metric-info .metric-value { font-weight: 700; font-size: 1rem; color: #1e293b; line-height: 1.2; }
        .metrics-bar .metric-item .metric-info .metric-label { font-size: 0.6rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; letter-spacing: 0.3px; }
        .metrics-bar .metric-item .metric-info .status-pill { font-size: 0.7rem; padding: 2px 12px; border-radius: 30px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .metrics-bar .metric-item .metric-info .status-pill.eligible { background: #d1fae5; color: #065f46; }
        .metrics-bar .metric-item .metric-info .status-pill.not-eligible { background: #fee2e2; color: #991b1b; }
        .metrics-bar .metric-item .metric-info .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .metrics-bar .metric-item .metric-info .status-pill.eligible .dot { background: #10b981; }
        .metrics-bar .metric-item .metric-info .status-pill.not-eligible .dot { background: #ef4444; }
        
        .evaluation-content { min-height: 250px; }
        .no-competencies-msg { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 12px; padding: 20px; text-align: center; }
        .no-competencies-msg i { font-size: 1.8rem; color: #f59e0b; margin-bottom: 8px; }
        .loading-spinner { text-align: center; padding: 40px 20px; }
        .loading-spinner .spinner { width: 36px; height: 36px; border: 4px solid #f3f3f3; border-top: 4px solid #8b5cf6; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px; }
        
        .category-section { margin-bottom: 18px; }
        .category-title { font-weight: 700; font-size: 0.9rem; color: #1e293b; padding: 6px 0 4px 0; border-bottom: 2px solid #e2e8f0; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        .competency-card { background: #faf5f6; border-radius: 14px; padding: 14px 18px; margin-bottom: 10px; border-left: 4px solid #f5a0b0; transition: all 0.2s ease; }
        .competency-card.complete { border-left-color: #10b981; background: #f0fdf4; }
        .competency-card .comp-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .competency-card .comp-title { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
        .competency-card .comp-progress { font-size: 0.7rem; color: #6b7280; }
        .competency-card .task-list { margin-top: 10px; padding-left: 0; list-style: none; }
        .competency-card .task-list li { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #f3e8ea; font-size: 0.85rem; }
        .competency-card .task-list li:last-child { border-bottom: none; }
        .btn-task-action { width: 28px; height: 28px; border-radius: 6px; border: none; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; cursor: pointer; transition: all 0.2s; }
        .btn-task-action.check { background: #10b981; color: #ffffff; }
        .btn-task-action.check:hover { background: #059669; transform: scale(1.05); }
        .btn-task-action.reset { background: #ef4444; color: #ffffff; }
        .btn-task-action.reset:hover { background: #dc2626; transform: scale(1.05); }
        .badge-task-status { font-size: 0.6rem; padding: 2px 8px; border-radius: 20px; font-weight: 600; }
        .badge-task-status.done { background: #d1fae5; color: #065f46; }
        .badge-task-status.pending { background: #fee2e2; color: #991b1b; }
        .btn-complete-all { background: #8b5cf6; color: #ffffff; border: none; padding: 3px 12px; border-radius: 30px; font-weight: 600; font-size: 0.65rem; cursor: pointer; transition: all 0.2s; }
        .btn-complete-all:hover { background: #7c3aed; transform: scale(1.05); }
        .btn-complete-all:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .task-code { font-weight: 500; color: #475569; min-width: 60px; font-size: 0.75rem; }
        .task-name { color: #334155; flex: 1; padding-left: 6px; font-size: 0.85rem; }
        .task-actions { display: flex; align-items: center; gap: 6px; }

        .btn-watch-video {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.65rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .btn-watch-video:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }
        .btn-watch-video i { font-size: 0.7rem; }

        .video-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 100001;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .video-overlay.active { display: flex; }

        .video-overlay-card {
            background: #0f172a;
            border-radius: 20px;
            width: 90%;
            max-width: 900px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6);
            animation: slideUp 0.35s ease-out;
        }
        .video-overlay-header {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: #ffffff;
            padding: 14px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .video-overlay-header h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
        }
        .video-overlay-header h3 i { color: #60a5fa; }
        .video-overlay-close {
            background: none;
            border: none;
            color: #cbd5e1;
            font-size: 1.8rem;
            cursor: pointer;
            padding: 0 6px;
            line-height: 1;
            transition: all 0.2s;
        }
        .video-overlay-close:hover { color: #f87171; transform: rotate(90deg); }
        .video-overlay-body {
            background: #000;
        }
        .video-overlay-body video {
            width: 100%;
            max-height: 520px;
            display: block;
            background: #000;
        }
        .video-overlay-footer {
            padding: 12px 22px;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.82rem;
            color: #475569;
            border-top: 1px solid #e2e8f0;
        }

        .reminder-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            animation: reminderFadeIn 0.2s ease-out;
        }

        .reminder-modal-overlay.active {
            display: flex;
        }

        @keyframes reminderFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes reminderPopIn {
            from { opacity: 0; transform: translateY(12px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .reminder-modal-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 28px 24px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: reminderPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .reminder-modal-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #d97706;
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.15);
        }

        .reminder-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .reminder-modal-text {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 8px;
        }

        .reminder-modal-name {
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 700;
            margin: 0 0 8px;
            padding: 10px 16px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            word-break: break-word;
        }

        .reminder-modal-note {
            font-size: 0.85rem;
            color: #64748b;
            margin: 0 0 24px;
            padding: 8px 12px;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 10px;
            line-height: 1.4;
        }

        .reminder-modal-note i {
            color: #3b82f6;
            margin-right: 6px;
        }

        .reminder-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .reminder-btn {
            flex: 1;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: 'Inter', sans-serif;
        }

        .reminder-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .reminder-btn-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .reminder-btn-confirm {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3);
        }

        .reminder-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(245, 158, 11, 0.4);
        }

        .reminder-btn-confirm:active,
        .reminder-btn-cancel:active {
            transform: translateY(0);
        }

        .reminder-btn-confirm:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 10px; transition: all 0.3s ease; }
        ::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #5a67d8, #6b46a0); }
        
        @keyframes slideUp {
            from { transform: translateY(-30px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        
        @media (max-width: 1200px) { .content-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { 
            .main-content { margin-left: 0; padding: 16px; } 
            .dashboard-header { flex-direction: column; align-items: flex-start; } 
            .dashboard-header .header-right { width: 100%; flex-wrap: wrap; }
            .metrics-bar { gap: 12px; } 
            .dept-tabs { flex-direction: row; flex-wrap: wrap; justify-content: space-evenly; gap: 4px; padding: 8px 6px; }
            .dept-tab-wrapper { padding: 2px 6px; }
            .dept-tab-wrapper.active-tab { padding: 4px 12px 4px 16px; }
            .qual-dropdown { left: 0; transform: none; width: 100%; }
            .trainee-profile-card { flex-wrap: wrap; }
            .trainee-profile-card .action-buttons { margin-left: 0; width: 100%; justify-content: flex-start; }
            .dept-header-row { flex-wrap: wrap; gap: 8px; }
            .reminder-modal-card { padding: 25px 20px; }
            .filter-bar-header { flex-direction: column; align-items: flex-start; gap: 12px; }
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

    <div class="modal fade" id="markAllModal" tabindex="-1" aria-labelledby="markAllModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); border: none;">
                <div class="modal-body" style="padding: 32px 32px 24px 32px;">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #ede9fe; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                            <i class="fas fa-check-double" style="color: #7c3aed; font-size: 1.8rem;"></i>
                        </div>
                        <h5 style="font-weight: 700; color: #1e293b; margin-bottom: 8px;" id="markAllModalTitle">Mark ALL tasks as completed and move to Pre-Assessment?</h5>
                        <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 0;">This action will mark all pending tasks as completed and move the trainee to Pre-Assessment.</p>
                    </div>
                    <div style="display: flex; gap: 12px; justify-content: center; margin-top: 12px;">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="padding: 10px 32px; border-radius: 10px; font-weight: 600; font-size: 0.9rem; border-color: #e2e8f0; color: #475569;">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary" id="confirmMarkAllBtn" style="padding: 10px 32px; border-radius: 10px; font-weight: 600; font-size: 0.9rem; background: #7c3aed; border: none;">
                            <i class="fas fa-check me-2"></i> OK
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="reminder-modal-overlay" id="reminderModal">
        <div class="reminder-modal-card">
            <div class="reminder-modal-icon">
                <i class="fas fa-bell"></i>
            </div>
            <h3 class="reminder-modal-title">Send Reminder</h3>
            <p class="reminder-modal-text">Send a reminder about their incomplete tasks?</p>
            <p class="reminder-modal-name" id="reminderTraineeName"></p>
            <p class="reminder-modal-note">
                <i class="fas fa-paper-plane"></i> This will send via Email AND SMS.
            </p>
            <div class="reminder-modal-actions">
                <button type="button" class="reminder-btn reminder-btn-cancel" id="reminderCancel">Cancel</button>
                <button type="button" class="reminder-btn reminder-btn-confirm" id="reminderConfirm">
                    <i class="fas fa-check"></i> Yes, Send
                </button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="videoPlayerModal" tabindex="-1" aria-labelledby="videoPlayerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); border: none; overflow: hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #1e293b, #334155); color: #ffffff; border: none; padding: 16px 22px;">
                    <h5 class="modal-title" id="videoPlayerModalLabel" style="font-weight: 600; font-size: 1rem; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-play-circle" style="color: #60a5fa;"></i>
                        <span id="videoModalTaskName">Performance Task Video</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="background: #0f172a; padding: 0;">
                    <video id="taskVideoPlayer"
                           controls
                           controlsList="nodownload"
                           preload="metadata"
                           playsinline
                           style="width: 100%; max-height: 480px; background: #000; display: block;">
                        Your browser does not support the video tag.
                    </video>
                </div>
                <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 22px;">
                    <small class="text-muted" id="videoModalTraineeName" style="margin-right: auto;"></small>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-times me-1"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="videoOverlay" class="video-overlay">
        <div class="video-overlay-card">
            <div class="video-overlay-header">
                <h3><i class="fas fa-play-circle"></i> <span id="videoOverlayTitle">Performance Task Video</span></h3>
                <button class="video-overlay-close" onclick="closeVideoOverlay()">&times;</button>
            </div>
            <div class="video-overlay-body">
                <video id="videoOverlayPlayer"
                       controls
                       controlsList="nodownload"
                       preload="metadata"
                       playsinline>
                    Your browser does not support the video tag.
                </video>
            </div>
            <div class="video-overlay-footer">
                <span id="videoOverlayMeta"></span>
                <button type="button" onclick="closeVideoOverlay()" style="padding:6px 18px; background:#f1f5f9; color:#475569; border:2px solid #e2e8f0; border-radius:8px; font-weight:600; cursor:pointer;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <div id="floatingMessage" style="display: none;"></div>
    
    <div class="main-content" id="mainContent">
        <div class="dashboard-header">
            <div class="header-left">
                <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="title-group">
                    <h4>Non-Compliant Trainees</h4>
                    <p class="subtitle">Monitor trainees with incomplete tasks and ensure timely compliance.</p>
                </div>
            </div>
            <div class="header-right">
                <div class="profile-btn">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'User') ?></span>
                    <i class="fas fa-chevron-down" style="font-size: 0.6rem; opacity: 0.5;"></i>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>
        
        <div class="filter-bar">
            <div class="filter-bar-header">
                <h5>
                    <i class="fas fa-search"></i> Search & Filter Trainees
                </h5>
            </div>

            <form id="filterForm" onsubmit="return false;">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-search me-1"></i> Search Trainee</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Enter trainee name..">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-building me-1"></i> Department</label>
                        <select class="form-select" id="departmentSelect">
                            <option value="">All Departments</option>
                            <option value="HRS">Hotel & Restaurant Services (HRS)</option>
                            <option value="CSS">Computer System Servicing (CSS)</option>
                            <option value="ATS">Automotive Servicing (ATS)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-filter me-1"></i> Status</label>
                        <select class="form-select" id="filterSelect">
                            <option value="all">All Trainees</option>
                            <option value="non_compliant">Non-Compliant Only</option>
                            <option value="fully_complied">Fully Complied</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn-reset-filters" onclick="resetAllFilters()"><i class="fas fa-redo"></i> Reset Filters</button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="content-grid">
            <div class="dashboard-card trainees-list-card">
                <div class="card-header">
                    <h6><i class="fas fa-users"></i> All Trainees</h6>
                    <span class="badge bg-secondary" id="traineeCountBadge"><?= count($trainees) ?></span>
                </div>
                <div class="card-body" id="traineeListContainer">
                    <?php if (empty($trainees)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-users" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                            <h6>No Trainees Found</h6>
                            <p>Loading trainees...</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($trainees as $t): ?>
                            <?php 
                            $status_class = match($t['compliance_status'] ?? 'No Tasks Assigned') {
                                'Fully Complied' => 'fully-complied',
                                'No Tasks Assigned' => 'no-tasks',
                                default => 'non-compliant'
                            };
                            $active_class = ($t['id'] == $selected_trainee_id) ? 'active' : '';
                            $qual_display = $t['qualification_code'] ?? 'No Qual';
                            ?>
                            <div class="trainee-list-item <?= $active_class ?>" data-trainee-id="<?= $t['id'] ?>" data-qualification-id="<?= $t['qualification_id'] ?? 0 ?>" onclick="selectTrainee(<?= $t['id'] ?>, '<?= htmlspecialchars($t['first_name'] ?? '') ?> <?= htmlspecialchars($t['last_name'] ?? '') ?>')">
                                <div>
                                    <div class="trainee-name"><?= htmlspecialchars(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?></div>
                                    <div class="trainee-detail"><?= htmlspecialchars($t['section'] ?? 'N/A') ?> • <?= htmlspecialchars($qual_display) ?></div>
                                </div>
                                <span class="trainee-badge <?= $status_class ?>">
                                    <?php if (($t['compliance_status'] ?? '') === 'Fully Complied'): ?>
                                        <i class="fas fa-check"></i> Complied
                                    <?php elseif (($t['compliance_status'] ?? '') === 'No Tasks Assigned'): ?>
                                        <i class="fas fa-clock"></i> No Tasks
                                    <?php else: ?>
                                        <?= $t['incomplete_tasks'] ?? 0 ?> pending
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-body">
                    <div class="dept-tabs" id="deptTabs">
                        <?php foreach ($departments as $code => $dept): ?>
                            <?php 
                            $dept_quals = getQualificationsByDepartment($db, $code, $trainer_qualification_ids, $is_trainer);
                            $is_active = ($code === $selected_department);
                            $is_disabled = in_array($code, $disabled_tabs);
                            ?>
                            <div class="dept-tab-wrapper <?= $is_active ? 'active-tab' : '' ?> <?= $is_disabled ? 'disabled-tab' : '' ?>" data-dept="<?= $code ?>">
                                <span class="dept-tab-static">
                                    <i class="fas <?= $dept['icon'] ?> tab-icon"></i>
                                    <span class="tab-label"><?= $code ?></span>
                                </span>
                                
                                <?php if (!$is_disabled): ?>
                                <button type="button" 
                                        class="dept-dropdown-arrow" 
                                        onclick="event.stopPropagation(); toggleDropdown('<?= $code ?>')"
                                        aria-expanded="false"
                                        title="Select qualification">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <?php endif; ?>
                                
                                <?php if (!empty($dept_quals) && !$is_disabled): ?>
                                    <div class="qual-dropdown" id="dropdown-<?= $code ?>">
                                        <div class="qual-all" onclick="event.stopPropagation(); selectQualification('<?= $code ?>', 0, 'All Qualifications')">
                                            <i class="fas fa-asterisk" style="font-size: 0.5rem;"></i> All Qualifications
                                            <?php if ($selected_qualification == 0): ?>
                                                <span class="qual-check"><i class="fas fa-check"></i></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="qual-divider"></div>
                                        <?php foreach ($dept_quals as $qual): ?>
                                            <div class="qual-item <?= $selected_qualification == $qual['id'] ? 'active' : '' ?>" 
                                                 onclick="event.stopPropagation(); selectQualification('<?= $code ?>', <?= $qual['id'] ?>, '<?= htmlspecialchars($qual['qualification_code']) ?>')">
                                                <i class="fas fa-graduation-cap" style="font-size: 0.65rem; color: #94a3b8;"></i>
                                                <?= htmlspecialchars($qual['qualification_code']) ?> - <?= htmlspecialchars($qual['qualification_name']) ?>
                                                <?php if ($selected_qualification == $qual['id']): ?>
                                                    <span class="qual-check"><i class="fas fa-check"></i></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="header-card" id="headerCard">
                        <div class="dept-header-row">
                            <div class="dept-title">
                                <span class="accent-line"></span>
                                <i class="fas <?= $current_dept['icon'] ?> dept-icon"></i>
                                <?= htmlspecialchars($current_dept['name']) ?> (<?= $selected_department ?>)
                                <?php if ($selected_qualification > 0 && !empty($qualification_name)): ?>
                                    <span style="font-size: 0.75rem; color: #8b5cf6; background: #ede9fe; padding: 2px 12px; border-radius: 20px; font-weight: 600;">
                                        <?= htmlspecialchars($qualification_name) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="dept-count" id="deptTraineeCount"><?= count($trainees) ?> Trainees</span>
                        </div>
                        
                        <div class="trainee-profile-card" id="traineeProfileCard">
                            <div class="avatar" id="traineeAvatar">MT</div>
                            <div class="trainee-info">
                                <div class="trainee-name-row">
                                    <span class="name" id="traineeNameDisplay">Select a trainee</span>
                                    <span class="status-dot has-tasks" id="traineeStatusDot"></span>
                                </div>
                                <div class="qualification-row" id="qualificationDisplay">
                                    <i class="fas fa-graduation-cap"></i>
                                    <span id="qualificationNameText">No qualification selected</span>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <button class="btn-mark-all" id="markAllBtn" onclick="markAllTraineeTasksDone()">
                                    <i class="fas fa-check-double"></i> <span id="markAllBtnText">Mark All Done</span>
                                </button>
                                <button class="btn-reminder" id="reminderBtn" onclick="sendReminder()">
                                    <i class="fas fa-bell"></i> Send Reminder
                                </button>
                            </div>
                        </div>
                        
                        <div class="metrics-bar" id="metricsBar">
                            <div class="metric-item">
                                <div class="metric-icon purple"><i class="fas fa-layer-group"></i></div>
                                <div class="metric-info">
                                    <span class="metric-value" id="totalCompValue">0</span>
                                    <span class="metric-label">Total Competencies</span>
                                </div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-icon green"><i class="fas fa-check-circle"></i></div>
                                <div class="metric-info">
                                    <span class="metric-value" id="completedCompValue">0</span>
                                    <span class="metric-label">Completed</span>
                                </div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-icon red"><i class="fas fa-times-circle"></i></div>
                                <div class="metric-info">
                                    <span class="metric-value" id="incompleteCompValue">0</span>
                                    <span class="metric-label">Incomplete</span>
                                </div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-icon orange"><i class="fas fa-info-circle"></i></div>
                                <div class="metric-info">
                                    <span class="metric-value" id="statusPill">
                                        <span class="status-pill not-eligible">
                                            <span class="dot"></span> Not Eligible
                                        </span>
                                    </span>
                                    <span class="metric-label">Status</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="evaluation-content" id="evaluationContent">
                        <?php if ($selected_trainee_id > 0 && !empty($trainees)): ?>
                            <div id="evaluationContentInner"></div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-user-graduate" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                                <h6>Select a Trainee</h6>
                                <p>Click on a trainee from the list to view their competency assessment.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const BASE_URL = '<?= $base_url ?>';

        let cachedTraineeData = [];
        let cachedTraineeTasks = {};
        let currentTraineeId = <?= $selected_trainee_id ?>;
        let currentTraineeName = '';
        let currentTraineeQualification = '';
        let currentDepartment = '<?= $selected_department ?>';
        let currentQualification = <?= $selected_qualification ?>;
        let filterTimeout = null;
        let openDropdown = null;
        let isSendingReminder = false;
        let hasIncompleteTasks = true;
        let preAssessmentData = null;

        const isTrainer = <?= json_encode($is_trainer && !$is_coordinator) ?>;
        const trainerQualIds = <?= json_encode($trainer_qualification_ids) ?>;
        const disabledTabs = <?= json_encode($disabled_tabs) ?>;

        function closeAllDropdowns() {
            document.querySelectorAll('.qual-dropdown').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.dept-dropdown-arrow').forEach(a => a.classList.remove('open'));
            openDropdown = null;
        }

        function toggleDropdown(department) {
            if (disabledTabs.includes(department)) return;
            
            const dropdown = document.getElementById('dropdown-' + department);
            if (!dropdown) return;
            
            if (window.event) window.event.stopPropagation();
            
            document.querySelectorAll('.qual-dropdown').forEach(d => {
                if (d !== dropdown) d.classList.remove('show');
            });
            document.querySelectorAll('.dept-dropdown-arrow').forEach(a => a.classList.remove('open'));
            
            dropdown.classList.toggle('show');
            const arrow = dropdown.parentElement.querySelector('.dept-dropdown-arrow');
            if (arrow) arrow.classList.toggle('open');
            
            openDropdown = dropdown.classList.contains('show') ? dropdown : null;
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.qual-dropdown').forEach(dropdown => {
                dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
            });
        });

        document.addEventListener('click', function(e) {
            const isInsideDropdown = e.target.closest('.qual-dropdown');
            const isInsideToggle = e.target.closest('.dept-dropdown-arrow');
            const isInsideStatic = e.target.closest('.dept-tab-static');
            
            if (!isInsideDropdown && !isInsideToggle && !isInsideStatic) {
                closeAllDropdowns();
            }
        });

        function selectQualification(department, qualificationId, qualificationName) {
            if (window.event) window.event.stopPropagation();
            if (disabledTabs.includes(department)) return;
            
            currentQualification = qualificationId;
            currentDepartment = department;
            
            const url = new URL(window.location);
            url.searchParams.set('department', department);
            if (qualificationId > 0) {
                url.searchParams.set('qualification', qualificationId);
            } else {
                url.searchParams.delete('qualification');
            }
            window.history.pushState({}, '', url);
            
            const dropdown = document.getElementById('dropdown-' + department);
            if (dropdown) {
                dropdown.querySelectorAll('.qual-item').forEach(item => item.classList.remove('active'));
                dropdown.querySelectorAll('.qual-item').forEach(item => {
                    const onclick = item.getAttribute('onclick');
                    if (onclick && onclick.includes(qualificationId)) item.classList.add('active');
                });
                const allItem = dropdown.querySelector('.qual-all');
                if (allItem) {
                    if (qualificationId === 0) {
                        allItem.innerHTML = '<i class="fas fa-asterisk" style="font-size: 0.5rem;"></i> All Qualifications <span class="qual-check"><i class="fas fa-check"></i></span>';
                    } else {
                        allItem.innerHTML = '<i class="fas fa-asterisk" style="font-size: 0.5rem;"></i> All Qualifications';
                    }
                }
            }
            
            document.querySelectorAll('.dept-tab-wrapper').forEach(w => w.classList.remove('active-tab'));
            document.querySelectorAll('.dept-tab-wrapper').forEach(w => {
                const label = w.querySelector('.dept-tab-static .tab-label');
                if (label && label.textContent.trim() === department) w.classList.add('active-tab');
            });
            
            const deptNames = { 'HRS': 'Hotel & Restaurant Services', 'CSS': 'Computer System Servicing', 'ATS': 'Automotive Servicing' };
            const deptIcons = { 'HRS': 'fa-utensils', 'CSS': 'fa-laptop-code', 'ATS': 'fa-car' };
            const deptHeader = document.querySelector('.dept-header-row .dept-title');
            if (deptHeader) {
                let qualDisplay = '';
                if (qualificationId > 0 && qualificationName) {
                    qualDisplay = `<span style="font-size: 0.75rem; color: #8b5cf6; background: #ede9fe; padding: 2px 12px; border-radius: 20px; font-weight: 600;">${qualificationName}</span>`;
                }
                deptHeader.innerHTML = `
                    <span class="accent-line"></span>
                    <i class="fas ${deptIcons[department] || 'fa-building'} dept-icon"></i>
                    ${deptNames[department] || department} (${department})
                    ${qualDisplay}
                `;
            }
            
            currentTraineeId = 0;
            currentTraineeName = '';
            currentTraineeQualification = '';
            resetHeaderUI();
            
            if (dropdown) {
                dropdown.classList.remove('show');
                const arrow = dropdown.parentElement.querySelector('.dept-dropdown-arrow');
                if (arrow) arrow.classList.remove('open');
                openDropdown = null;
            }
            
            loadDepartmentData(department, qualificationId);
        }

        function filterTraineesByQualification(qualId) {
            const traineeItems = document.querySelectorAll('.trainee-list-item');
            
            if (!qualId || qualId === 0 || qualId === '0') {
                traineeItems.forEach(item => item.style.display = 'flex');
                updateTraineeCount(traineeItems.length);
                return;
            }
            
            let visibleCount = 0;
            traineeItems.forEach(item => {
                const itemQual = item.getAttribute('data-qualification-id');
                if (itemQual && parseInt(itemQual) === parseInt(qualId)) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            updateTraineeCount(visibleCount);
        }

        function updateTraineeCount(count) {
            const badge = document.getElementById('traineeCountBadge');
            const deptCount = document.getElementById('deptTraineeCount');
            if (badge) badge.textContent = count;
            if (deptCount) deptCount.textContent = count + ' Trainees';
            
            const container = document.getElementById('traineeListContainer');
            if (container && count === 0) {
                const items = container.querySelectorAll('.trainee-list-item');
                if (items.length === 0) {
                    container.innerHTML = `<div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-users" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                        <h6>No Trainees Found</h6>
                        <p>No trainees match the current filters.</p>
                    </div>`;
                }
            }
        }

        function showFloatingMessage(message, type = 'success') {
            const msgDiv = document.getElementById('floatingMessage');
            msgDiv.style.display = 'flex';
            msgDiv.className = `floating-message ${type}`;
            const iconMap = {
                'success': 'fa-check-circle',
                'error': 'fa-exclamation-circle',
                'info': 'fa-info-circle'
            };
            msgDiv.innerHTML = `<i class="fas ${iconMap[type] || 'fa-info-circle'}"></i> ${message}`;
            setTimeout(() => { msgDiv.style.display = 'none'; }, 5000);
        }

        function resetHeaderUI() {
            document.getElementById('traineeNameDisplay').textContent = 'Select a trainee';
            document.getElementById('qualificationNameText').textContent = 'No qualification selected';
            document.getElementById('traineeAvatar').textContent = '?';
            document.getElementById('traineeStatusDot').className = 'status-dot';
            document.getElementById('totalCompValue').textContent = '0';
            document.getElementById('completedCompValue').textContent = '0';
            document.getElementById('incompleteCompValue').textContent = '0';
            document.getElementById('statusPill').innerHTML = `
                <span class="status-pill not-eligible">
                    <span class="dot"></span> Not Eligible
                </span>
            `;
            const markAllBtn = document.getElementById('markAllBtn');
            markAllBtn.disabled = false;
            markAllBtn.innerHTML = '<i class="fas fa-check-double"></i> Mark All Done';
        }

        function updateHeaderUI(traineeName, qualification, hasIncomplete, stats) {
            const firstName = traineeName.split(' ')[0] || '?';
            const lastName = traineeName.split(' ')[1] || '';
            document.getElementById('traineeNameDisplay').textContent = traineeName;
            document.getElementById('traineeAvatar').textContent = (firstName.charAt(0) + (lastName ? lastName.charAt(0) : '')).toUpperCase();
            
            document.getElementById('qualificationNameText').textContent = qualification || 'No qualification';
            
            const dot = document.getElementById('traineeStatusDot');
            dot.className = 'status-dot ' + (hasIncomplete ? 'has-tasks' : 'all-done');
            
            const total = parseInt(stats.total_competencies) || 0;
            const completed = parseInt(stats.completed_competencies) || 0;
            const incomplete = total - completed;
            const isEligible = total > 0 && completed >= total;
            
            document.getElementById('totalCompValue').textContent = total;
            document.getElementById('completedCompValue').textContent = completed;
            document.getElementById('incompleteCompValue').textContent = incomplete;
            
            document.getElementById('statusPill').innerHTML = `
                <span class="status-pill ${isEligible ? 'eligible' : 'not-eligible'}">
                    <span class="dot"></span> ${isEligible ? 'Eligible for Assessment' : 'Not Eligible'}
                </span>
            `;
            
            const markAllBtn = document.getElementById('markAllBtn');
            if (!hasIncomplete) {
                markAllBtn.disabled = true;
                markAllBtn.innerHTML = '<i class="fas fa-check-double"></i> All Done';
            } else {
                markAllBtn.disabled = false;
                markAllBtn.innerHTML = '<i class="fas fa-check-double"></i> Mark All Done';
            }
        }

        function loadDepartmentData(department, qualificationId, search = '') {
            let body = 'action=get_department_trainees&department=' + encodeURIComponent(department);
            if (qualificationId > 0) {
                body += '&qualification=' + qualificationId;
            }
            if (search) {
                body += '&search=' + encodeURIComponent(search);
            }

            fetch(BASE_URL + 'evaluation.php?_=' + Date.now(), { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                body: body
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('traineeListContainer');
                    const deptTraineeCount = document.getElementById('deptTraineeCount');
                    const traineeCountBadge = document.getElementById('traineeCountBadge');
                    
                    if (deptTraineeCount) deptTraineeCount.textContent = data.count + ' Trainees';
                    if (traineeCountBadge) traineeCountBadge.textContent = data.count;
                    
                    if (data.count === 0) {
                        container.innerHTML = `<div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-users" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                            <h6>No Trainees Found</h6>
                            <p>No trainees match the current filters.</p>
                        </div>`;
                        document.getElementById('evaluationContent').innerHTML = `<div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-user-graduate" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                            <h6>No Trainees Available</h6>
                            <p>No trainees match the current filters.</p>
                        </div>`;
                        resetHeaderUI();
                    } else {
                        let html = '';
                        data.trainees.forEach(t => {
                            let statusClass = 'non-compliant';
                            if (t.compliance_status === 'Fully Complied') statusClass = 'fully-complied';
                            else if (t.compliance_status === 'No Tasks Assigned') statusClass = 'no-tasks';
                            
                            const qualDisplay = t.qualification_code || 'No Qual';
                            
                            html += `<div class="trainee-list-item" data-trainee-id="${t.id}" data-qualification-id="${t.qualification_id || 0}" onclick="selectTrainee(${t.id}, '${escapeHtml(t.first_name || '')} ${escapeHtml(t.last_name || '')}')">
                                <div>
                                    <div class="trainee-name">${escapeHtml(t.first_name || '')} ${escapeHtml(t.last_name || '')}</div>
                                    <div class="trainee-detail">${escapeHtml(t.section || 'N/A')} • ${escapeHtml(qualDisplay)}</div>
                                </div>
                                <span class="trainee-badge ${statusClass}">${t.compliance_status === 'Fully Complied' ? '<i class="fas fa-check"></i> Complied' : (t.compliance_status === 'No Tasks Assigned' ? '<i class="fas fa-clock"></i> No Tasks' : (t.incomplete_tasks || 0) + ' pending')}</span>
                            </div>`;
                        });
                        container.innerHTML = html;
                        
                        cachedTraineeData = data.trainees;
                        
                        if (qualificationId > 0) {
                            filterTraineesByQualification(qualificationId);
                        }
                        
                        const firstTrainee = data.trainees[0];
                        if (firstTrainee) {
                            currentTraineeName = (firstTrainee.first_name || '') + ' ' + (firstTrainee.last_name || '');
                            currentTraineeQualification = firstTrainee.qualification_name || '';
                            selectTrainee(firstTrainee.id, currentTraineeName);
                        }
                    }
                } else { 
                    showFloatingMessage(data.message || 'Error loading department data', 'error'); 
                }
            })
            .catch(() => { showFloatingMessage('Error loading department data', 'error'); });
        }

        function removeTraineeFromList(traineeId) {
            const items = document.querySelectorAll('.trainee-list-item');
            let removed = false;
            items.forEach(item => {
                const id = item.getAttribute('data-trainee-id');
                if (id && parseInt(id) === traineeId) {
                    item.classList.add('removing');
                    removed = true;
                    setTimeout(() => {
                        item.remove();
                        const count = document.getElementById('deptTraineeCount');
                        if (count) {
                            const currentCount = parseInt(count.textContent);
                            if (!isNaN(currentCount) && currentCount > 0) {
                                count.textContent = (currentCount - 1) + ' Trainees';
                            }
                        }
                        const badge = document.getElementById('traineeCountBadge');
                        if (badge) {
                            const currentBadge = parseInt(badge.textContent);
                            if (!isNaN(currentBadge) && currentBadge > 0) {
                                badge.textContent = currentBadge - 1;
                            }
                        }
                        const container = document.getElementById('traineeListContainer');
                        if (container && container.querySelectorAll('.trainee-list-item').length === 0) {
                            container.innerHTML = `<div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-users" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                                <h6>All Trainees Moved to Pre-Assessment</h6>
                                <p>All trainees have completed their tasks and moved to Pre-Assessment.</p>
                            </div>`;
                        }
                    }, 400);
                }
            });
            return removed;
        }

        function selectTrainee(traineeId, traineeName) {
            if (!traineeId) return;
            
            currentTraineeId = parseInt(traineeId);
            currentTraineeName = traineeName || 'Trainee';
            
            document.querySelectorAll('.trainee-list-item').forEach(item => {
                item.classList.remove('active');
                const onclick = item.getAttribute('onclick');
                if (onclick && onclick.includes(`selectTrainee(${traineeId}`)) {
                    item.classList.add('active');
                }
            });

            const content = document.getElementById('evaluationContent');

            if (cachedTraineeTasks[traineeId]) {
                const data = cachedTraineeTasks[traineeId];
                renderCompetencyView(data);
                updateHeaderUI(
                    currentTraineeName, 
                    currentTraineeQualification, 
                    data.has_incomplete_tasks || false, 
                    data.stats || {total_competencies: 0, completed_competencies: 0}
                );
                return;
            }

            content.innerHTML = '';

            const traineeItem = document.querySelector(`.trainee-list-item[data-trainee-id="${traineeId}"]`);
            const traineeQual = traineeItem ? parseInt(traineeItem.getAttribute('data-qualification-id')) || 0 : 0;
            const qualParam = traineeQual > 0 ? '&qualification=' + traineeQual : '';

            fetch(BASE_URL + 'evaluation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_trainee_tasks&trainee_id=' + traineeId + qualParam
            })
            .then(response => response.json())
            .then(data => {
                console.log('get_trainee_tasks response:', data);

                if (data.success) {
                    cachedTraineeTasks[traineeId] = data;
                    
                    hasIncompleteTasks = data.has_incomplete_tasks || false;
                    const qualName = data.trainee_info?.qualification_name || '';
                    currentTraineeQualification = qualName;
                    
                    updateHeaderUI(currentTraineeName, qualName, hasIncompleteTasks, data.stats);
                    renderCompetencyView(data);
                } else {
                    content.innerHTML = `<div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-exclamation-triangle" style="background: #fee2e2; color: #ef4444;"></i></div>
                        <h6 class="text-danger">${data.message || 'No Data Found'}</h6>
                        <button onclick="selectTrainee(${traineeId}, '${currentTraineeName}')" class="btn btn-sm btn-outline-primary mt-2">
                            <i class="fas fa-redo me-1"></i> Retry
                        </button>
                    </div>`;
                }
            })
            .catch(error => {
                content.innerHTML = `<div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-exclamation-triangle" style="background: #fee2e2; color: #ef4444;"></i></div>
                    <h6 class="text-danger">Error Loading Data</h6>
                    <p class="text-muted">${error.message}</p>
                </div>`;
            });
        }

        function renderCompetencyView(data) {
            const competencies = data.competencies || [];
            const content = document.getElementById('evaluationContent');

            if (data.trainee_info) {
                const qualName = data.trainee_info.qualification_name || '';
                currentTraineeQualification = qualName;
                hasIncompleteTasks = data.has_incomplete_tasks || false;
                updateHeaderUI(currentTraineeName, qualName, hasIncompleteTasks, data.stats || {total_competencies: 0, completed_competencies: 0});
            }

            if (data.no_competencies) {
                content.innerHTML = `<div class="no-competencies-msg">
                    <i class="fas fa-clock"></i>
                    <h6>No Competencies Assigned Yet</h6>
                    <p class="mb-0">This trainee is registered but competencies are being set up.</p>
                </div>`;
                return;
            }

            if (competencies.length === 0) {
                content.innerHTML = `<div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-clipboard-list" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                    <h6>No Competencies Found</h6>
                    <p>This trainee has no competencies assigned yet.</p>
                </div>`;
                return;
            }

            const types = {};
            competencies.forEach(comp => {
                const type = comp.competency_type || 'Other';
                if (!types[type]) types[type] = [];
                types[type].push(comp);
            });

            const typeOrder = { 'Basic': 1, 'Common': 2, 'Core': 3 };
            const sortedTypes = Object.keys(types).sort((a, b) => (typeOrder[a] || 99) - (typeOrder[b] || 99));

            let html = '';

            sortedTypes.forEach(type => {
                const comps = types[type];
                
                html += `
                    <div class="category-section">
                        <div class="category-title">${type} Competencies</div>
                `;

                comps.forEach(comp => {
                    const isComplete = comp.is_complete;
                    const totalTasks = comp.total_tasks;
                    const completedTasks = comp.completed_tasks;
                    
                    let displayName = comp.competency_name || 'Unnamed Competency';

                    html += `
                        <div class="competency-card ${isComplete ? 'complete' : ''}">
                            <div class="comp-header">
                                <div>
                                    <span class="comp-title">${escapeHtml(displayName)}</span>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="comp-progress">${completedTasks}/${totalTasks} tasks</span>
                                    ${isComplete ? `<span class="badge-task-status done"><i class="fas fa-check"></i> Complete</span>` : ''}
                                </div>
                            </div>
                            <ul class="task-list">
                                ${comp.tasks.map(task => {
                                    const isDone = task.is_done || false;
                                    const trackerId = task.tracker_id || 0;
                                    const taskId = task.task_id || 0;
                                    const taskName = task.task_name || '';
                                    
                                    const rawVideoPath = task.video_path || '';
                                    let videoUrl = '';
                                    if (rawVideoPath) {
                                        if (/^https?:\/\//i.test(rawVideoPath)) {
                                            videoUrl = rawVideoPath;
                                        } else if (rawVideoPath.startsWith('/')) {
                                            videoUrl = rawVideoPath;
                                        } else {
                                            videoUrl = '/tesda_compliance/' + rawVideoPath.replace(/^\.?\/*/, '');
                                        }
                                    }
                                    const hasVideo = videoUrl !== '';
                                    
                                    return `
                                        <li>
                                            <div>
                                                <span class="task-name">${escapeHtml(taskName)}</span>
                                            </div>
                                            <div class="task-actions">
                                                <span class="badge-task-status ${isDone ? 'done' : 'pending'}">${isDone ? 'Done' : 'Not Done'}</span>
                                                
                                                ${hasVideo ? `
                                                    <button type="button"
                                                            class="btn-watch-video"
                                                            onclick="openVideoModal('${escapeJs(videoUrl)}', '${escapeJs(taskName)}', '${escapeJs(currentTraineeName)}')"
                                                            title="Watch uploaded video">
                                                        <i class="fas fa-play-circle"></i> Watch Video
                                                    </button>
                                                ` : ''}
                                                
                                                ${!isDone ? 
                                                    `<button class="btn-task-action check" onclick="markTaskDone(${trackerId}, ${taskId})"><i class="fas fa-check"></i></button>` : 
                                                    `<button class="btn-task-action reset" onclick="resetTask(${trackerId})"><i class="fas fa-undo"></i></button>`
                                                }
                                            </div>
                                        </li>
                                    `;
                                }).join('')}
                            </ul>
                        </div>
                    `;
                });

                html += `</div>`;
            });

            content.innerHTML = html;
        }

        function handleTaskCompletion(response, traineeId) {
            if (response.transferred) {
                showFloatingMessage('✅ All tasks completed! Trainee moved to Pre-Assessment.', 'success');
                removeTraineeFromList(traineeId);
                const content = document.getElementById('evaluationContent');
                content.innerHTML = `<div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-check-circle" style="background: #d1fae5; color: #10b981;"></i></div>
                    <h6>Trainee Moved to Pre-Assessment</h6>
                    <p>${currentTraineeName} has completed all tasks and has been moved to the Pre-Assessment module.</p>
                </div>`;
                resetHeaderUI();
                currentTraineeId = 0;
                currentTraineeName = '';
                currentTraineeQualification = '';
            } else {
                if (traineeId) {
                    delete cachedTraineeTasks[traineeId];
                    selectTrainee(traineeId, currentTraineeName);
                }
            }
        }

        function markTaskDone(trackerId, taskId) {
            if (!currentTraineeId) {
                showFloatingMessage('Please select a trainee first', 'error');
                return;
            }
            
            if (trackerId === 0 || trackerId === null || trackerId === undefined) {
                if (!taskId) {
                    showFloatingMessage('Unable to mark task: missing task information.', 'error');
                    return;
                }
                fetch(BASE_URL + 'evaluation.php', { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                    body: 'action=mark_task_done&tracker_id=0&trainee_id=' + currentTraineeId + '&task_id=' + taskId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) { 
                        if (data.transferred) {
                            handleTaskCompletion(data, currentTraineeId);
                        } else {
                            showFloatingMessage('Task marked as done!', 'success'); 
                            delete cachedTraineeTasks[currentTraineeId];
                            selectTrainee(currentTraineeId, currentTraineeName); 
                        }
                    } else { 
                        showFloatingMessage(data.message || 'Failed to mark task', 'error'); 
                    }
                })
                .catch(() => { showFloatingMessage('Error marking task', 'error'); });
                return;
            }
            
            if (!confirm('Mark this task as completed?')) return;
            fetch(BASE_URL + 'evaluation.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                body: 'action=mark_task_done&tracker_id=' + trackerId + '&trainee_id=' + currentTraineeId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) { 
                    if (data.transferred) {
                        handleTaskCompletion(data, currentTraineeId);
                    } else {
                        showFloatingMessage('Task marked as done!', 'success'); 
                        delete cachedTraineeTasks[currentTraineeId];
                        selectTrainee(currentTraineeId, currentTraineeName); 
                    }
                } else { 
                    showFloatingMessage(data.message || 'Failed', 'error'); 
                }
            })
            .catch(() => { showFloatingMessage('Error marking task', 'error'); });
        }

        function resetTask(trackerId) {
            if (!trackerId || trackerId === 0) {
                showFloatingMessage('No tracker record to reset.', 'error');
                return;
            }
            if (!confirm('Reset this task to Not Done?')) return;
            fetch(BASE_URL + 'evaluation.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                body: 'action=reset_task&tracker_id=' + trackerId 
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) { 
                    showFloatingMessage('Task reset successfully', 'success'); 
                    delete cachedTraineeTasks[currentTraineeId];
                    selectTrainee(currentTraineeId, currentTraineeName); 
                } else { 
                    showFloatingMessage(data.message || 'Failed', 'error'); 
                }
            })
            .catch(() => { showFloatingMessage('Error resetting task', 'error'); });
        }

        function markCompetencyComplete(competencyId) {
            if (!confirm('Mark ALL tasks in this competency as completed?')) return;
            fetch(BASE_URL + 'evaluation.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                body: 'action=mark_competency_complete&trainee_id=' + currentTraineeId + '&competency_id=' + competencyId 
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) { 
                    if (data.transferred) {
                        handleTaskCompletion(data, currentTraineeId);
                    } else {
                        showFloatingMessage(data.message, 'success'); 
                        delete cachedTraineeTasks[currentTraineeId];
                        selectTrainee(currentTraineeId, currentTraineeName); 
                    }
                } else { 
                    showFloatingMessage(data.message || 'Failed', 'error'); 
                }
            })
            .catch(() => { showFloatingMessage('Error marking competency', 'error'); });
        }

        function markAllTraineeTasksDone() {
            if (!currentTraineeId) {
                showFloatingMessage('Please select a trainee first', 'error');
                return;
            }
            
            document.getElementById('markAllModalTitle').textContent = 
                `Mark ALL tasks for ${currentTraineeName} as completed and move to Pre-Assessment?`;
            
            const modal = new bootstrap.Modal(document.getElementById('markAllModal'));
            modal.show();
            
            const confirmBtn = document.getElementById('confirmMarkAllBtn');
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            newConfirmBtn.addEventListener('click', function() {
                const modalInstance = bootstrap.Modal.getInstance(document.getElementById('markAllModal'));
                modalInstance.hide();
                
                const markAllBtn = document.getElementById('markAllBtn');
                markAllBtn.disabled = true;
                markAllBtn.innerHTML = '<span class="spinner-small"></span> Processing...';
                
                fetch(BASE_URL + 'evaluation.php', { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
                    body: 'action=mark_all_tasks_done&trainee_id=' + currentTraineeId 
                })
                .then(response => response.json())
                .then(data => {
                    markAllBtn.disabled = false;
                    markAllBtn.innerHTML = '<i class="fas fa-check-double"></i> Mark All Done';
                    
                    if (data.success) { 
                        if (data.transferred || data.already_transferred) {
                            showFloatingMessage('✅ All tasks completed! ' + (data.trainee_name || currentTraineeName) + ' moved to Pre-Assessment.', 'success');
                            removeTraineeFromList(currentTraineeId);
                            const content = document.getElementById('evaluationContent');
                            content.innerHTML = `<div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-check-circle" style="background: #d1fae5; color: #10b981;"></i></div>
                                <h6>Trainee Moved to Pre-Assessment</h6>
                                <p>${data.trainee_name || currentTraineeName} has completed all tasks and has been moved to the Pre-Assessment module.</p>
                            </div>`;
                            resetHeaderUI();
                            currentTraineeId = 0;
                            currentTraineeName = '';
                            currentTraineeQualification = '';
                            setTimeout(() => {
                                loadDepartmentData(currentDepartment, currentQualification);
                            }, 300);
                        } else {
                            showFloatingMessage(data.message || 'Tasks marked as done successfully!', 'success'); 
                            delete cachedTraineeTasks[currentTraineeId];
                            selectTrainee(currentTraineeId, currentTraineeName); 
                        }
                    } else { 
                        showFloatingMessage(data.message || 'Failed to mark all tasks. Please try again.', 'error'); 
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showFloatingMessage('Error marking all tasks: ' + error.message, 'error');
                    markAllBtn.disabled = false;
                    markAllBtn.innerHTML = '<i class="fas fa-check-double"></i> Mark All Done';
                });
            });
        }

        function sendReminder() {
            if (!currentTraineeId) {
                showFloatingMessage('Please select a trainee first', 'error');
                return;
            }
            
            if (isSendingReminder) return;
            
            document.getElementById('reminderTraineeName').textContent = currentTraineeName;
            
            document.getElementById('reminderModal').classList.add('active');
        }

        function closeReminderModal() {
            document.getElementById('reminderModal').classList.remove('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const reminderModal = document.getElementById('reminderModal');
            const reminderCancel = document.getElementById('reminderCancel');
            const reminderConfirm = document.getElementById('reminderConfirm');
            
            if (reminderCancel) {
                reminderCancel.addEventListener('click', closeReminderModal);
            }
            
            if (reminderModal) {
                reminderModal.addEventListener('click', function(e) {
                    if (e.target === reminderModal) closeReminderModal();
                });
            }
            
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && reminderModal && reminderModal.classList.contains('active')) {
                    closeReminderModal();
                }
            });
            
            if (reminderConfirm) {
                reminderConfirm.addEventListener('click', function() {
                    closeReminderModal();
                    
                    isSendingReminder = true;
                    const reminderBtn = document.getElementById('reminderBtn');
                    if (reminderBtn) {
                        reminderBtn.disabled = true;
                        reminderBtn.innerHTML = '<span class="spinner-small"></span> Sending...';
                    }
                    
                    const params = new URLSearchParams();
                    params.append('trainee_id', currentTraineeId);
                    
                    fetch(BASE_URL + 'send_reminder.php?_=' + Date.now(), { 
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    })
                    .then(response => response.text())
                    .then(text => {
                        try {
                            const data = JSON.parse(text);
                            isSendingReminder = false;
                            
                            if (reminderBtn) {
                                reminderBtn.disabled = false;
                                reminderBtn.innerHTML = '<i class="fas fa-bell"></i> Send Reminder';
                            }
                            
                            if (data.success) {
                                const toastMessage = data.message || `Reminder sent to ${currentTraineeName}`;
                                showFloatingMessage(toastMessage, 'success');

                                if (data.email_sent === true && data.sms_sent === false && data.sms_error) {
                                    setTimeout(() => {
                                        showFloatingMessage('⚠️ SMS failed: ' + data.sms_error, 'error');
                                    }, 1500);
                                }

                                const remindedId = currentTraineeId;
                                const remindedName = currentTraineeName;

                                removeTraineeFromList(remindedId);
                                delete cachedTraineeTasks[remindedId];

                                const content = document.getElementById('evaluationContent');
                                content.innerHTML = `<div class="empty-state">
                                    <div class="empty-icon"><i class="fas fa-bell" style="background: #dbeafe; color: #3b82f6;"></i></div>
                                    <h6>Reminder Sent</h6>
                                    <p>${remindedName} has been notified.</p>
                                </div>`;
                                resetHeaderUI();

                                currentTraineeId = 0;
                                currentTraineeName = '';
                                currentTraineeQualification = '';

                                setTimeout(() => {
                                    loadDepartmentData(currentDepartment, currentQualification);
                                }, 500);

                            } else {
                                showFloatingMessage(data.message || 'Failed to send reminder', 'error');
                            }
                        } catch (e) {
                            console.error('JSON Parse Error:', e, 'Raw:', text);
                            showFloatingMessage('Server error. Check console.', 'error');
                            isSendingReminder = false;
                            if (reminderBtn) {
                                reminderBtn.disabled = false;
                                reminderBtn.innerHTML = '<i class="fas fa-bell"></i> Send Reminder';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Fetch Error:', error);
                        isSendingReminder = false;
                        showFloatingMessage('Error sending reminder: ' + error.message, 'error');
                        if (reminderBtn) {
                            reminderBtn.disabled = false;
                            reminderBtn.innerHTML = '<i class="fas fa-bell"></i> Send Reminder';
                        }
                    });
                });
            }
        });

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>"]/g, function(m) { 
                if (m === '&') return '&amp;'; 
                if (m === '<') return '&lt;'; 
                if (m === '>') return '&gt;'; 
                if (m === '"') return '&quot;';
                return m; 
            });
        }

        function escapeJs(text) {
            if (!text) return '';
            return String(text)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/"/g, '\\"')
                .replace(/\n/g, '\\n')
                .replace(/\r/g, '\\r');
        }

        function openVideoModal(videoUrl, taskName, traineeName) {
            const modalEl = document.getElementById('videoPlayerModal');
            if (!modalEl) {
                showFloatingMessage('Video player unavailable.', 'error');
                return;
            }
            const player = document.getElementById('taskVideoPlayer');
            document.getElementById('videoModalTaskName').textContent = taskName || 'Performance Task Video';
            document.getElementById('videoModalTraineeName').textContent = traineeName ? ('Trainee: ' + traineeName) : '';

            try { player.pause(); } catch (e) {}
            player.removeAttribute('src');
            player.load();

            player.src = videoUrl;
            player.load();

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();

            modalEl.addEventListener('shown.bs.modal', function onShown() {
                const p = player.play();
                if (p && typeof p.catch === 'function') {
                    p.catch(() => { });
                }
                modalEl.removeEventListener('shown.bs.modal', onShown);
            });

            modalEl.addEventListener('hidden.bs.modal', function onHidden() {
                try { player.pause(); } catch (e) {}
                player.removeAttribute('src');
                player.load();
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            }, { once: true });
        }

        function resetAllFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('departmentSelect').value = '';
            document.getElementById('filterSelect').value = 'all';
            currentQualification = 0;
            applyFilters();
        }

        function applyFilters() {
            const search = document.getElementById('searchInput').value.trim();
            let department = document.getElementById('departmentSelect').value;
            const filter = document.getElementById('filterSelect').value;

            if (department === 'All Departments' || department === 'all' || department === '') {
                department = '';
            } else {
                currentDepartment = department;
            }

            if (!department) {
                department = currentDepartment;
            }

            fetch(BASE_URL + 'evaluation.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=filter_trainees&search=' + encodeURIComponent(search) + '&department=' + encodeURIComponent(department) + '&filter=' + encodeURIComponent(filter) + '&qualification=' + currentQualification
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const container = document.getElementById('traineeListContainer');
                    const traineeCountBadge = document.getElementById('traineeCountBadge');

                    if (data.count === 0) {
                        container.innerHTML = `<div class="empty-state"><div class="empty-icon"><i class="fas fa-search" style="background: #f1f5f9; color: #94a3b8;"></i></div><h6>No Trainees Found</h6><p>No trainees match your search criteria.</p></div>`;
                        if (traineeCountBadge) traineeCountBadge.textContent = '0';
                    } else {
                        let html = '';
                        data.trainees.forEach(t => {
                            let statusClass = 'non-compliant';
                            if (t.compliance_status === 'Fully Complied') statusClass = 'fully-complied';
                            else if (t.compliance_status === 'No Tasks Assigned') statusClass = 'no-tasks';
                            
                            const activeClass = (t.id == currentTraineeId) ? 'active' : '';
                            const qualDisplay = t.qualification_code || 'No Qual';
                            
                            html += `<div class="trainee-list-item ${activeClass}" data-trainee-id="${t.id}" data-qualification-id="${t.qualification_id || 0}" onclick="selectTrainee(${t.id}, '${escapeHtml(t.first_name || '')} ${escapeHtml(t.last_name || '')}')">
                                <div>
                                    <div class="trainee-name">${escapeHtml(t.first_name || '')} ${escapeHtml(t.last_name || '')}</div>
                                    <div class="trainee-detail">${escapeHtml(t.section || 'N/A')} • ${escapeHtml(qualDisplay)}</div>
                                </div>
                                <span class="trainee-badge ${statusClass}">${t.compliance_status === 'Fully Complied' ? '<i class="fas fa-check"></i> Complied' : (t.compliance_status === 'No Tasks Assigned' ? '<i class="fas fa-clock"></i> No Tasks' : (t.incomplete_tasks || 0) + ' pending')}</span>
                            </div>`;
                        });
                        container.innerHTML = html;
                        if (traineeCountBadge) traineeCountBadge.textContent = data.count;
                        
                        cachedTraineeData = data.trainees;
                        
                        if (currentQualification > 0) {
                            filterTraineesByQualification(currentQualification);
                        }
                    }
                }
            })
            .catch(error => console.error('Filter error:', error));
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
                    
                    showFloatingMessage(
                        `🔔 Reminder sent to ${data.trainee_name || 'trainee'} (${data.pending_tasks || 0} tasks pending)`,
                        'info'
                    );
                    
                    if (currentTraineeId && data.trainee_id === currentTraineeId) {
                        const items = document.querySelectorAll('.trainee-list-item');
                        items.forEach(item => {
                            const id = item.getAttribute('data-trainee-id');
                            if (id && parseInt(id) === currentTraineeId) {
                                item.classList.add('removing');
                                setTimeout(() => {
                                    item.remove();
                                    const count = document.getElementById('deptTraineeCount');
                                    if (count) {
                                        const currentCount = parseInt(count.textContent);
                                        if (!isNaN(currentCount) && currentCount > 0) {
                                            count.textContent = (currentCount - 1) + ' Trainees';
                                        }
                                    }
                                    const badge = document.getElementById('traineeCountBadge');
                                    if (badge) {
                                        const currentBadge = parseInt(badge.textContent);
                                        if (!isNaN(currentBadge) && currentBadge > 0) {
                                            badge.textContent = currentBadge - 1;
                                        }
                                    }
                                    const container = document.getElementById('traineeListContainer');
                                    if (container && container.querySelectorAll('.trainee-list-item').length === 0) {
                                        container.innerHTML = `<div class="empty-state">
                                            <div class="empty-icon"><i class="fas fa-users" style="background: #f1f5f9; color: #94a3b8;"></i></div>
                                            <h6>All Trainees Notified</h6>
                                            <p>All trainees have been sent reminders and are being monitored.</p>
                                        </div>`;
                                    }
                                    const content = document.getElementById('evaluationContent');
                                    content.innerHTML = `<div class="empty-state">
                                        <div class="empty-icon"><i class="fas fa-bell" style="background: #dbeafe; color: #3b82f6;"></i></div>
                                        <h6>Reminder Sent</h6>
                                        <p>${currentTraineeName} has been notified.</p>
                                    </div>`;
                                    resetHeaderUI();
                                    currentTraineeId = 0;
                                    currentTraineeName = '';
                                    delete cachedTraineeTasks[currentTraineeId];
                                }, 400);
                            }
                        });
                    }
                });
                
                socketInstance.on('trainee_returned', function(data) {
                    console.log('📬 Trainee returned event received:', data);
                    showFloatingMessage(
                        `↩️ ${data.trainee_name || 'Trainee'} returned to Evaluation`,
                        'info'
                    );
                    setTimeout(() => {
                        loadDepartmentData(currentDepartment, currentQualification);
                    }, 500);
                });
                
                socketInstance.on('task_evaluated', function(data) {
                    console.log('📬 Task evaluated event received:', data);
                    if (data.transferred) {
                        showFloatingMessage(
                            `✅ ${data.trainee_name || 'Trainee'} moved to Pre-Assessment!`,
                            'success'
                        );
                    }
                });
                
                socketInstance.on('new_notification', function(notification) {
                    console.log('📬 New notification received:', notification);
                    
                    showFloatingMessage(
                        '📬 Reminder sent to ' + (notification.trainee_name || 'trainee'),
                        'info'
                    );
                    
                    if (window.location.pathname.includes('notification_history.php')) {
                        if (typeof loadNotifications === 'function') {
                            setTimeout(loadNotifications, 500);
                        }
                    }
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
                setTimeout(initWebSocket, 5000);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const departmentSelect = document.getElementById('departmentSelect');
            const filterSelect = document.getElementById('filterSelect');
            const applyFiltersDelayed = () => { clearTimeout(filterTimeout); filterTimeout = setTimeout(applyFilters, 400); };
            searchInput.addEventListener('input', applyFiltersDelayed);
            departmentSelect.addEventListener('change', applyFilters);
            filterSelect.addEventListener('change', applyFilters);

            const container = document.getElementById('traineeListContainer');
            const hasPHPTrainees = container && container.querySelectorAll('.trainee-list-item').length > 0;

            if (hasPHPTrainees) {
                <?php if ($selected_trainee_id > 0 && !empty($trainees)): ?>
                    <?php foreach ($trainees as $t): ?>
                        <?php if ($t['id'] == $selected_trainee_id): ?>
                            currentTraineeName = '<?= htmlspecialchars($t['first_name'] ?? '') ?> <?= htmlspecialchars($t['last_name'] ?? '') ?>';
                            currentTraineeQualification = '<?= htmlspecialchars($t['qualification_name'] ?? '') ?>';
                        <?php endif; ?>
                    <?php endforeach; ?>
                    selectTrainee(<?= $selected_trainee_id ?>, currentTraineeName);
                <?php endif; ?>
            } else {
                <?php if ($selected_qualification == 0 && !empty($qualifications)): ?>
                    currentQualification = <?= (int)$qualifications[0]['id'] ?>;
                <?php endif; ?>
                const defaultDept = currentDepartment || 'HRS';
                loadDepartmentData(defaultDept, currentQualification);
            }
            
            setTimeout(initWebSocket, 1000);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const overlay = document.getElementById('videoOverlay');
                if (overlay && overlay.classList.contains('active')) {
                    closeVideoOverlay();
                }
            }
        });

        function closeVideoOverlay() {
            const overlay = document.getElementById('videoOverlay');
            const player = document.getElementById('videoOverlayPlayer');
            try { player.pause(); } catch (e) {}
            player.removeAttribute('src');
            player.load();
            overlay.classList.remove('active');
        }

        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    </script>
</html>