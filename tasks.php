<?php
require_once __DIR__ . '/auth_check.php';
ob_start();

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$display_name = 'User';
if (is_array($user)) {
    $display_name = $user['full_name'] ?? $user['username'] ?? $user['name'] ?? 'User';
}

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

// ==========================================
// Determine which departments the trainer has access to
// ==========================================
$trainer_accessible_depts = [];
if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
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

$message = '';
$error = '';

$competency_types = ['core'];

$has_task_evaluations = false;
try {
    $check_te = $pdo->query("SHOW TABLES LIKE 'task_evaluations'");
    $has_task_evaluations = $check_te->rowCount() > 0;
} catch (PDOException $e) {
    $has_task_evaluations = false;
}

$has_pre_assessment = false;
try {
    $check_pa = $pdo->query("SHOW TABLES LIKE 'pre_assessment'");
    $has_pre_assessment = $check_pa->rowCount() > 0;
} catch (PDOException $e) {
    $has_pre_assessment = false;
}

// ==========================================
// HELPER: Send clean JSON response
// ==========================================
function sendJson($data) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data);
    exit;
}

// ==========================================
// HELPER: Safe Pusher trigger
// ==========================================
function safeTriggerPusher($event, $payload, $channel = 'compliance-channel') {
    try {
        $helper_path = __DIR__ . '/helpers/helpers/PusherHelper.php';
        if (file_exists($helper_path)) {
            ob_start();
            require_once $helper_path;
            ob_end_clean();
            if (function_exists('triggerPusherUpdate')) {
                triggerPusherUpdate($event, $payload, $channel);
            }
        }
    } catch (Throwable $e) {
        error_log("Pusher trigger failed (non-critical): " . $e->getMessage());
    }
}

// ==========================================
// HELPER: Reset trainees in qualification back to "In Progress"
// Called when a NEW task is added so they are no longer eligible
// ==========================================
function resetTraineesForQualification($pdo, $qualification_id, $has_pre_assessment = false) {
    $result = [
        'reset_count' => 0,
        'pre_assessment_cleared' => 0,
        'eligibility_cleared' => 0
    ];
    
    if ($qualification_id <= 0) {
        return $result;
    }
    
    try {
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM trainees 
            WHERE qualification_id = ? 
              AND deleted_at IS NULL
              AND status IN ('Ready for Pre-Assessment', 'Ready for Assessment', 'Eligible for Pre-Assessment')
        ");
        $count_stmt->execute([$qualification_id]);
        $result['reset_count'] = (int)$count_stmt->fetchColumn();
        
        $reset_trainees = $pdo->prepare("
            UPDATE trainees 
            SET status = 'In Progress',
                completion_alert_sent = 0,
                completion_alert_sent_at = NULL
            WHERE qualification_id = ? 
              AND deleted_at IS NULL
        ");
        $reset_trainees->execute([$qualification_id]);
        
        $reset_elig = $pdo->prepare("
            UPDATE evaluation_eligibility 
            SET is_eligible = 0,
                eligibility_date = NULL,
                checked_at = NOW()
            WHERE trainee_id IN (
                SELECT id FROM trainees 
                WHERE qualification_id = ? 
                  AND deleted_at IS NULL
            )
        ");
        $reset_elig->execute([$qualification_id]);
        $result['eligibility_cleared'] = $reset_elig->rowCount();
        
        if ($has_pre_assessment) {
            try {
                $delete_pa = $pdo->prepare("
                    DELETE FROM pre_assessment 
                    WHERE trainee_id IN (
                        SELECT id FROM trainees 
                        WHERE qualification_id = ? 
                          AND deleted_at IS NULL
                    )
                ");
                $delete_pa->execute([$qualification_id]);
                $result['pre_assessment_cleared'] = $delete_pa->rowCount();
            } catch (PDOException $e) {
                error_log("Failed to delete pre_assessment: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to reset trainees for qualification $qualification_id: " . $e->getMessage());
    }
    
    return $result;
}

// ==========================================
// HELPER: Recalculate eligibility after task deletion
// Checks if any trainees in the qualification are now eligible again
// (because fewer tasks are needed) and moves them to Pre-Assessment
// ==========================================
function recalculateEligibilityAfterDelete($pdo, $qualification_id, $has_pre_assessment = false) {
    $result = ['restored_count' => 0, 'affected_trainees' => []];
    
    if ($qualification_id <= 0) {
        return $result;
    }
    
    try {
        // Get all trainees in this qualification who are NOT yet eligible
        $trainees_stmt = $pdo->prepare("
            SELECT t.id, t.first_name, t.last_name, t.status, t.qualification_id
            FROM trainees t
            WHERE t.qualification_id = ?
              AND t.deleted_at IS NULL
              AND (
                  t.status IS NULL 
                  OR t.status = '' 
                  OR t.status = 'In Progress'
                  OR t.status NOT IN (
                      'Pre-Assessment', 'pre_assessment', 
                      'Ready for Pre-Assessment', 'ready_for_pre_assessment',
                      'Ready for Assessment', 'ready_for_assessment',
                      'Assessed', 'assessed', 
                      'Completed', 'completed',
                      'Certified', 'certified'
                  )
              )
        ");
        $trainees_stmt->execute([$qualification_id]);
        $affected_trainees = $trainees_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($affected_trainees as $t) {
            $trainee_id = (int)$t['id'];
            
            // Count total tasks for this qualification
            $total_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT pt.id) 
                FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE cs.qualification_id = ?
            ");
            $total_stmt->execute([$qualification_id]);
            $total_tasks = (int)$total_stmt->fetchColumn();
            
            // Count completed tasks for this trainee
            $completed_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT et.task_id)
                FROM evaluation_tracker et
                JOIN performance_tasks pt ON et.task_id = pt.id
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE et.trainee_id = ? 
                AND et.remarks = 'Done'
                AND cs.qualification_id = ?
            ");
            $completed_stmt->execute([$trainee_id, $qualification_id]);
            $completed_tasks = (int)$completed_stmt->fetchColumn();
            
            // If all remaining tasks are complete, mark trainee as eligible again
            if ($total_tasks > 0 && $completed_tasks >= $total_tasks) {
                // Update trainee status back to Ready for Assessment
                $update_trainee = $pdo->prepare("
                    UPDATE trainees 
                    SET status = 'Ready for Assessment',
                        completion_alert_sent = 0,
                        completion_alert_sent_at = NULL
                    WHERE id = ?
                ");
                $update_trainee->execute([$trainee_id]);
                
                // Update or insert evaluation_eligibility
                $check_elig = $pdo->prepare("SELECT id FROM evaluation_eligibility WHERE trainee_id = ?");
                $check_elig->execute([$trainee_id]);
                
                if ($check_elig->rowCount() > 0) {
                    $update_elig = $pdo->prepare("
                        UPDATE evaluation_eligibility 
                        SET is_eligible = 1,
                            total_tasks = ?,
                            completed_tasks = ?,
                            eligibility_date = NOW(),
                            checked_at = NOW()
                        WHERE trainee_id = ?
                    ");
                    $update_elig->execute([$total_tasks, $completed_tasks, $trainee_id]);
                } else {
                    $insert_elig = $pdo->prepare("
                        INSERT INTO evaluation_eligibility 
                        (trainee_id, is_eligible, total_tasks, completed_tasks, eligibility_date, checked_at)
                        VALUES (?, 1, ?, ?, NOW(), NOW())
                    ");
                    $insert_elig->execute([$trainee_id, $total_tasks, $completed_tasks]);
                }
                
                // Move trainee to pre_assessment table (so they appear in pre_assessment.php)
                if ($has_pre_assessment) {
                    try {
                        // Get trainee info
                        $t_info = $pdo->prepare("
                            SELECT t.first_name, t.last_name, t.qualification_id, q.qualification_name
                            FROM trainees t
                            LEFT JOIN qualifications q ON q.id = t.qualification_id
                            WHERE t.id = ?
                        ");
                        $t_info->execute([$trainee_id]);
                        $t_data = $t_info->fetch(PDO::FETCH_ASSOC);
                        $trainee_name = ($t_data['first_name'] ?? '') . ' ' . ($t_data['last_name'] ?? '');
                        
                        $check_pa = $pdo->prepare("SELECT id FROM pre_assessment WHERE trainee_id = ?");
                        $check_pa->execute([$trainee_id]);
                        
                        if ($check_pa->rowCount() > 0) {
                            $update_pa = $pdo->prepare("
                                UPDATE pre_assessment 
                                SET status = 'pending', 
                                    moved_at = NOW(), 
                                    updated_at = NOW()
                                WHERE trainee_id = ?
                            ");
                            $update_pa->execute([$trainee_id]);
                        } else {
                            $insert_pa = $pdo->prepare("
                                INSERT INTO pre_assessment 
                                (trainee_id, trainee_name, qualification_id, qualification_name, status, moved_at)
                                VALUES (?, ?, ?, ?, 'pending', NOW())
                            ");
                            $insert_pa->execute([
                                $trainee_id, 
                                $trainee_name,
                                $t_data['qualification_id'] ?? null,
                                $t_data['qualification_name'] ?? null
                            ]);
                        }
                    } catch (PDOException $e) {
                        error_log("Failed to insert into pre_assessment: " . $e->getMessage());
                    }
                }
                
                $result['restored_count']++;
                $result['affected_trainees'][] = [
                    'id' => $trainee_id,
                    'name' => $t['first_name'] . ' ' . $t['last_name']
                ];
                
                error_log("DELETE_TASK: Restored trainee #$trainee_id (completed $completed_tasks/$total_tasks)");
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to recalculate eligibility: " . $e->getMessage());
    }
    
    return $result;
}

// ==========================================
// AJAX: Get qualifications for a department
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_qualifications') {
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    
    if ($is_trainer && !$is_coordinator) {
        $course_stmt = $pdo->prepare("
            SELECT c.course_code 
            FROM courses c
            JOIN qualifications q ON q.course_id = c.id
            WHERE c.id = ? AND q.id IN (" . implode(',', array_fill(0, count($trainer_qualification_ids), '?')) . ")
            LIMIT 1
        ");
        $params = array_merge([$course_id], $trainer_qualification_ids);
        $course_stmt->execute($params);
        if (!$course_stmt->fetch()) {
            sendJson(['success' => false, 'status' => 'error', 'message' => 'Access denied to this department']);
        }
    }
    
    if ($course_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, qualification_name, qualification_code 
            FROM qualifications 
            WHERE course_id = ?
            ORDER BY qualification_name
        ");
        $stmt->execute([$course_id]);
        $qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
            $qualifications = array_filter($qualifications, function($q) use ($trainer_qualification_ids) {
                return in_array($q['id'], $trainer_qualification_ids);
            });
            $qualifications = array_values($qualifications);
        }
        
        sendJson(['success' => true, 'status' => 'success', 'data' => $qualifications]);
    } else {
        sendJson(['success' => false, 'status' => 'error', 'message' => 'Invalid course ID']);
    }
}

// ==========================================
// AJAX: Get competencies for a qualification
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_competencies') {
    $qualification_id = isset($_GET['qualification_id']) ? (int)$_GET['qualification_id'] : 0;
    
    if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
        if (!in_array($qualification_id, $trainer_qualification_ids)) {
            sendJson(['success' => false, 'status' => 'error', 'message' => 'Access denied']);
        }
    }
    
    if ($qualification_id > 0) {
        $stmt = $pdo->prepare("
            SELECT cs.id, cs.unit_of_competency, cs.competency_name, cs.code_no
            FROM competency_standards cs
            WHERE cs.qualification_id = ? 
              AND LOWER(cs.unit_of_competency) = 'core'
            ORDER BY cs.id ASC
        ");
        $stmt->execute([$qualification_id]);
        $competencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendJson(['success' => true, 'status' => 'success', 'data' => $competencies]);
    } else {
        sendJson(['success' => false, 'status' => 'error', 'message' => 'Invalid parameters']);
    }
}

// ==========================================
// AJAX: Check if competency code exists
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'check_code') {
    $code = isset($_GET['code']) ? trim($_GET['code']) : '';
    $exclude_id = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;
    
    if (!empty($code)) {
        if ($exclude_id > 0) {
            $stmt = $pdo->prepare("SELECT id FROM competency_standards WHERE code_no = ? AND id != ?");
            $stmt->execute([$code, $exclude_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM competency_standards WHERE code_no = ?");
            $stmt->execute([$code]);
        }
        $exists = $stmt->fetch() ? true : false;
        sendJson(['exists' => $exists]);
    } else {
        sendJson(['exists' => false]);
    }
}

// ==========================================
// AJAX: Get task details
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_task') {
    $stmt = $pdo->prepare("
        SELECT pt.*, 
               cs.competency_name, cs.unit_of_competency, cs.qualification_id, cs.code_no,
               c.id as course_id, c.course_name, c.course_code, c.color,
               q.qualification_name, q.qualification_code
        FROM performance_tasks pt
        LEFT JOIN competency_standards cs ON pt.competency_id = cs.id
        LEFT JOIN qualifications q ON cs.qualification_id = q.id
        LEFT JOIN courses c ON q.course_id = c.id
        WHERE pt.id = ? 
          AND LOWER(cs.unit_of_competency) = 'core'
    ");
    $stmt->execute([$_GET['task_id']]);
    $task = $stmt->fetch();
    sendJson(['success' => true, 'status' => 'success', 'data' => $task]);
}

// ==========================================
// AJAX: Get stats
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_stats') {
    $all_courses = $pdo->query("SELECT id, course_name, course_code, color FROM courses ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC);
    
    $department_competencies = [];
    $total_tasks = 0;
    
    foreach ($all_courses as $course) {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT cs.id) 
            FROM competency_standards cs
            JOIN qualifications q ON cs.qualification_id = q.id
            WHERE q.course_id = ? 
              AND LOWER(cs.unit_of_competency) = 'core'
        ");
        $stmt->execute([$course['id']]);
        $count = (int)$stmt->fetchColumn();
        
        $has_access = false;
        $accessible_count = 0;
        
        if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
            $qual_stmt = $pdo->prepare("SELECT id FROM qualifications WHERE course_id = ?");
            $qual_stmt->execute([$course['id']]);
            $dept_qual_ids = $qual_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $accessible = array_intersect($dept_qual_ids, $trainer_qualification_ids);
            if (!empty($accessible)) {
                $has_access = true;
                $placeholders = implode(',', array_fill(0, count($accessible), '?'));
                $comp_stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT cs.id) 
                    FROM competency_standards cs
                    WHERE cs.qualification_id IN ($placeholders)
                      AND LOWER(cs.unit_of_competency) = 'core'
                ");
                $comp_stmt->execute($accessible);
                $accessible_count = (int)$comp_stmt->fetchColumn();
            } else {
                $has_access = false;
                $accessible_count = 0;
            }
        } else {
            $has_access = true;
            $accessible_count = $count;
        }
        
        $department_competencies[] = [
            'id' => $course['id'],
            'name' => $course['course_name'],
            'code' => $course['course_code'],
            'color' => $course['color'] ?? '#4F46E5',
            'count' => $accessible_count,
            'has_access' => $has_access
        ];
    }
    
    if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $task_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT pt.id) 
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE cs.qualification_id IN ($placeholders)
              AND LOWER(cs.unit_of_competency) = 'core'
        ");
        $task_stmt->execute($trainer_qualification_ids);
        $total_tasks = (int)$task_stmt->fetchColumn();
    } else {
        $total_tasks = (int)$pdo->query("
            SELECT COUNT(DISTINCT pt.id) 
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE LOWER(cs.unit_of_competency) = 'core'
        ")->fetchColumn();
    }
    
    sendJson([
        'success' => true,
        'status' => 'success',
        'total_tasks' => $total_tasks,
        'department_competencies' => $department_competencies
    ]);
}

// ==========================================
// AJAX: Get hierarchy
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_hierarchy') {
    $all_courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();
    $tasks_hierarchy = [];
    
    foreach ($all_courses as $course) {
        $course_id = $course['id'];
        $tasks_hierarchy[$course_id] = [
            'course_name' => $course['course_name'],
            'course_code' => $course['course_code'],
            'color' => $course['color'] ?? '#4F46E5',
            'qualifications' => []
        ];
        
        $qual_stmt = $pdo->prepare("
            SELECT id, qualification_name, qualification_code 
            FROM qualifications 
            WHERE course_id = ?
            ORDER BY qualification_name
        ");
        $qual_stmt->execute([$course_id]);
        $qualifications = $qual_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($qualifications as $qual) {
            $qual_id = $qual['id'];
            
            if ($is_trainer && !$is_coordinator) {
                if (!in_array($qual_id, $trainer_qualification_ids)) {
                    continue;
                }
            }
            
            $comp_stmt = $pdo->prepare("
                SELECT cs.id, cs.unit_of_competency, cs.competency_name, cs.code_no
                FROM competency_standards cs
                WHERE cs.qualification_id = ? 
                  AND LOWER(cs.unit_of_competency) = 'core'
                ORDER BY cs.id ASC
            ");
            $comp_stmt->execute([$qual_id]);
            $competencies = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $competencies_with_tasks = [];
            foreach ($competencies as $comp) {
                $task_stmt = $pdo->prepare("
                    SELECT id, task_name
                    FROM performance_tasks
                    WHERE competency_id = ?
                    ORDER BY id ASC
                ");
                $task_stmt->execute([$comp['id']]);
                $tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $competencies_with_tasks[] = [
                    'id' => $comp['id'],
                    'name' => $comp['competency_name'],
                    'code' => $comp['code_no'],
                    'unit' => $comp['unit_of_competency'] ?: 'core',
                    'tasks' => $tasks,
                    'task_count' => count($tasks)
                ];
            }
            
            $tasks_hierarchy[$course_id]['qualifications'][$qual_id] = [
                'qualification_name' => $qual['qualification_name'],
                'qualification_code' => $qual['qualification_code'],
                'competencies' => $competencies_with_tasks
            ];
        }
    }
    
    $unit_display = [
        'core' => ['label' => 'CORE', 'icon' => 'fa-star', 'color' => '#8b5cf6', 'bg' => '#fce7f3']
    ];
    
    ob_start();
    foreach ($tasks_hierarchy as $course_id => $course_data):
        if ($is_trainer && !$is_coordinator && !empty($trainer_accessible_depts)) {
            if (!in_array($course_data['course_code'], $trainer_accessible_depts)) {
                continue;
            }
        }
        
        $color = $course_data['color'] ?? '#4F46E5';
        $icon = 'fa-building';
        if ($course_data['course_code'] == 'HRS') $icon = 'fa-utensils';
        elseif ($course_data['course_code'] == 'CSS') $icon = 'fa-laptop-code';
        elseif ($course_data['course_code'] == 'ATS') $icon = 'fa-car';
    ?>
        <div class="department-section" data-course-id="<?= $course_id ?>">
            <div class="department-header" onclick="toggleDepartment(this)" style="border-bottom-color: <?= $color ?>">
                <div class="dept-icon" style="background: <?= $color ?>">
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <div class="dept-info">
                    <h3>
                        <?= htmlspecialchars($course_data['course_name']) ?>
                        <span class="dept-code"><?= htmlspecialchars($course_data['course_code']) ?></span>
                    </h3>
                    <p>
                        <?= count($course_data['qualifications']) ?> qualification(s) available
                    </p>
                </div>
                <div class="dept-toggle">▸</div>
            </div>
            
            <div class="department-content" style="display:none;">
                <?php foreach ($course_data['qualifications'] as $qual_id => $qual_data): 
                    $total_tasks = 0;
                    foreach ($qual_data['competencies'] as $comp) {
                        $total_tasks += $comp['task_count'];
                    }
                ?>
                <div class="qualification-section" data-qualification-id="<?= $qual_id ?>">
                    <div class="qualification-header" onclick="toggleQualification(this)">
                        <div class="qualification-title">
                            <span class="qual-toggle">▸</span>
                            <i class="fas fa-certificate qual-icon"></i>
                            <h4><?= htmlspecialchars($qual_data['qualification_name']) ?></h4>
                            <span class="task-count-badge" id="taskCount_<?= $qual_id ?>">
                                <?= $total_tasks ?> task<?= $total_tasks != 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <?php if ($is_coordinator || ($is_trainer && in_array($qual_id, $trainer_qualification_ids))): ?>
                        <button class="btn-add-task-sm" onclick="event.stopPropagation(); openAddTaskModalWithQualification(<?= $course_id ?>, <?= $qual_id ?>)">
                            <i class="fas fa-plus"></i> Add Core Task
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="qualification-content" id="qualContent_<?= $qual_id ?>" style="display:none;">
                        <?php if (empty($qual_data['competencies'])): ?>
                        <div class="empty-state" style="padding:20px;">
                            <i class="fas fa-inbox"></i>
                            <p>No Core competencies configured for this qualification.</p>
                            <span class="sub-text">Click "Add Core Task" to create your first Core competency.</span>
                        </div>
                        <?php else: ?>
                            <?php 
                            $grouped = [];
                            foreach ($qual_data['competencies'] as $comp) {
                                $unit = $comp['unit'];
                                if (!isset($grouped[$unit])) {
                                    $grouped[$unit] = [];
                                }
                                $grouped[$unit][] = $comp;
                            }
                            ?>
                            <?php foreach ($grouped as $unit_key => $competencies): ?>
                            <div class="unit-group" data-unit="core">
                                <div class="unit-group-label" style="border-left-color: <?= $unit_display['core']['color'] ?>">
                                    <div class="unit-icon core">
                                        <i class="fas <?= $unit_display['core']['icon'] ?>"></i>
                                    </div>
                                    <span><?= $unit_display['core']['label'] ?></span>
                                </div>
                                
                                <?php foreach ($competencies as $comp): ?>
                                <div class="competency-card" data-competency-id="<?= $comp['id'] ?>" data-qualification-id="<?= $qual_id ?>">
                                    <div class="competency-header">
                                        <?php if (!empty($comp['code'])): ?>
                                        <span class="competency-code-badge"><?= htmlspecialchars($comp['code']) ?></span>
                                        <?php endif; ?>
                                        <span class="competency-name-text"><?= htmlspecialchars(strtoupper($comp['name'])) ?></span>
                                    </div>
                                    
                                    <div class="competency-tasks" id="compTasks_<?= $comp['id'] ?>">
                                        <?php if (!empty($comp['tasks'])): ?>
                                            <?php foreach ($comp['tasks'] as $task): ?>
                                            <div class="task-item" data-task-id="<?= $task['id'] ?>" data-qualification-id="<?= $qual_id ?>" data-competency-id="<?= $comp['id'] ?>">
                                                <div class="task-name-only">
                                                    <span class="task-bullet">•</span>
                                                    <span class="task-name-text"><?= htmlspecialchars($task['task_name']) ?></span>
                                                </div>
                                                <div class="task-actions">
                                                    <button class="btn-view-task" onclick="event.stopPropagation(); viewTask(<?= $task['id'] ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($is_coordinator || ($is_trainer && in_array($qual_id, $trainer_qualification_ids))): ?>
                                                    <button class="btn-edit-task" onclick="event.stopPropagation(); editTask(<?= $task['id'] ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn-delete-task" onclick="event.stopPropagation(); openDeleteModal(<?= $task['id'] ?>, '<?= htmlspecialchars(addslashes($task['task_name'])) ?>')">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="empty-state" style="padding:8px;">
                                                <p style="font-size:0.85rem; color:#94a3b8;">No tasks for this competency</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php
    endforeach;
    $html = ob_get_clean();
    
    sendJson([
        'success' => true,
        'status' => 'success',
        'html' => $html
    ]);
}

// ==========================================
// Helper: Generate UNIQUE Competency Code
// ==========================================
function generateCompetencyCode($db, $qualification_id, $unit_of_competency) {
    $stmt = $db->prepare("SELECT qualification_code FROM qualifications WHERE id = ?");
    $stmt->execute([$qualification_id]);
    $qual = $stmt->fetch(PDO::FETCH_ASSOC);
    $qual_code = $qual['qualification_code'] ?? 'UNK';
    
    $unit_map = [
        'basic' => 'B',
        'common' => 'C',
        'core' => 'CO'
    ];
    $unit_short = $unit_map[$unit_of_competency] ?? strtoupper(substr($unit_of_competency, 0, 2));
    
    $count_stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM competency_standards 
        WHERE qualification_id = ? 
          AND unit_of_competency = ?
          AND LOWER(unit_of_competency) = 'core'
    ");
    $count_stmt->execute([$qualification_id, $unit_of_competency]);
    $count = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $next_num = ($count['count'] ?? 0) + 1;
    
    $base_code = $qual_code . '-' . $unit_short . '-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    $code = $base_code;
    $counter = 1;
    
    while (true) {
        $check_stmt = $db->prepare("SELECT id FROM competency_standards WHERE code_no = ?");
        $check_stmt->execute([$code]);
        if (!$check_stmt->fetch()) {
            break;
        }
        $code = $base_code . '-' . str_pad($counter, 2, '0', STR_PAD_LEFT);
        $counter++;
    }
    
    return $code;
}

// ==========================================
// Handle Add Task
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_task'])) {
    try {
        if ($is_trainer && !$is_coordinator) {
            $qual_id = (int)$_POST['qualification_id'];
            if (!in_array($qual_id, $trainer_qualification_ids)) {
                throw new Exception("You don't have permission to add tasks to this qualification.");
            }
        }
        
        if (empty($_POST['qualification_id'])) {
            throw new Exception("Please select a qualification.");
        }
        if (empty($_POST['unit_of_competency'])) {
            throw new Exception("Please select a unit of competency.");
        }
        if (strtolower(trim($_POST['unit_of_competency'])) !== 'core') {
            throw new Exception("Only Core Competencies are allowed in this module.");
        }
        if (empty($_POST['competency_name'])) {
            throw new Exception("Please enter a competency name.");
        }
        if (empty($_POST['task_names']) || !is_array($_POST['task_names'])) {
            throw new Exception("Please add at least one task.");
        }
        
        $qualification_id = (int)$_POST['qualification_id'];
        $competency_name = strtoupper(trim($_POST['competency_name']));
        $unit_of_competency = 'core';
        $task_names = $_POST['task_names'];
        $competency_code = isset($_POST['competency_code']) ? trim($_POST['competency_code']) : '';
        
        foreach ($task_names as $task_name) {
            $task_name = trim($task_name);
            if (strlen($task_name) > 500) {
                throw new Exception("Task name exceeds maximum length of 500 characters: " . substr($task_name, 0, 50) . "...");
            }
        }
        
        $stmt = $pdo->prepare("
            SELECT id, code_no FROM competency_standards 
            WHERE qualification_id = ? 
              AND competency_name = ? 
              AND unit_of_competency = ?
              AND LOWER(unit_of_competency) = 'core'
        ");
        $stmt->execute([$qualification_id, $competency_name, $unit_of_competency]);
        $competency = $stmt->fetch();
        
        $was_existing_competency = false;
        if ($competency) {
            $was_existing_competency = true;
            $competency_id = $competency['id'];
            if (empty($competency_code)) {
                $competency_code = $competency['code_no'];
            }
        } else {
            if (empty($competency_code)) {
                $competency_code = generateCompetencyCode($pdo, $qualification_id, $unit_of_competency);
            } else {
                $check_stmt = $pdo->prepare("SELECT id FROM competency_standards WHERE code_no = ?");
                $check_stmt->execute([$competency_code]);
                if ($check_stmt->fetch()) {
                    $competency_code = generateCompetencyCode($pdo, $qualification_id, $unit_of_competency);
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO competency_standards 
                (qualification_id, unit_of_competency, competency_name, code_no) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$qualification_id, $unit_of_competency, $competency_name, $competency_code]);
            $competency_id = $pdo->lastInsertId();
        }
        
        $inserted_tasks = [];
        foreach ($task_names as $task_name) {
            $task_name = trim($task_name);
            if (empty($task_name)) continue;
            
            $stmt = $pdo->prepare("
                INSERT INTO performance_tasks 
                (competency_id, task_name) 
                VALUES (?, ?)
            ");
            $stmt->execute([$competency_id, $task_name]);
            $inserted_tasks[] = $pdo->lastInsertId();
        }
        
        if (empty($inserted_tasks)) {
            throw new Exception("No valid tasks to insert.");
        }
        
        // RESET ALL TRAINEES IN THIS QUALIFICATION
        $reset_info = resetTraineesForQualification($pdo, $qualification_id, $has_pre_assessment);
        
        $stmt = $pdo->prepare("
            SELECT pt.*, 
                   cs.competency_name, cs.unit_of_competency, cs.id as competency_id, cs.code_no,
                   q.id as qualification_id, q.qualification_name, q.qualification_code,
                   c.id as course_id, c.course_name, c.course_code, c.color
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            JOIN qualifications q ON cs.qualification_id = q.id
            JOIN courses c ON q.course_id = c.id
            WHERE pt.id = ? 
              AND LOWER(cs.unit_of_competency) = 'core'
        ");
        $stmt->execute([$inserted_tasks[0]]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $inserted_tasks_data = [];
        if (!empty($inserted_tasks)) {
            $placeholders = implode(',', array_fill(0, count($inserted_tasks), '?'));
            $t_stmt = $pdo->prepare("
                SELECT id, task_name
                FROM performance_tasks
                WHERE id IN ($placeholders)
                ORDER BY id ASC
            ");
            $t_stmt->execute($inserted_tasks);
            $inserted_tasks_data = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $response = [
            'success' => true, 
            'status' => 'success',
            'message' => count($inserted_tasks) . ' task(s) saved successfully.',
            'task' => $task,
            'task_id' => $inserted_tasks[0],
            'total_inserted' => count($inserted_tasks),
            'competency_id' => $competency_id,
            'competency_code' => $competency_code,
            'competency_name' => $competency_name,
            'qualification_id' => $qualification_id,
            'course_id' => $task['course_id'],
            'inserted_tasks' => $inserted_tasks_data,
            'was_existing_competency' => $was_existing_competency,
            'eligibility_reset' => ($reset_info['reset_count'] > 0),
            'reset_count' => $reset_info['reset_count'],
            'pre_assessment_cleared' => $reset_info['pre_assessment_cleared']
        ];
        
        if ($reset_info['reset_count'] > 0) {
            $response['message'] .= ' ' . $reset_info['reset_count'] . ' trainee(s) reset to "In Progress".';
        }
        
        safeTriggerPusher('competency_updated', [
            'competency_id' => $competency_id,
            'competency_name' => $competency_name,
            'qualification_id' => $qualification_id,
            'course_id' => $task['course_id'],
            'action' => 'task_added',
            'task_count' => count($inserted_tasks),
            'trainees_reset' => $reset_info['reset_count']
        ], 'compliance-channel');
        
        sendJson($response);
    } catch(Exception $e) {
        sendJson(['success' => false, 'status' => 'error', 'message' => $e->getMessage()]);
    }
}

// ==========================================
// Handle Edit Task
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_task'])) {
    try {
        if ($is_trainer && !$is_coordinator) {
            $qual_id = (int)$_POST['qualification_id'];
            if (!in_array($qual_id, $trainer_qualification_ids)) {
                throw new Exception("You don't have permission to edit tasks in this qualification.");
            }
        }
        
        if (empty($_POST['id'])) {
            throw new Exception("Invalid task ID.");
        }
        if (empty($_POST['qualification_id'])) {
            throw new Exception("Please select a qualification.");
        }
        if (empty($_POST['unit_of_competency'])) {
            throw new Exception("Please select a unit of competency.");
        }
        if (strtolower(trim($_POST['unit_of_competency'])) !== 'core') {
            throw new Exception("Only Core Competencies are allowed in this module.");
        }
        if (empty($_POST['competency_name'])) {
            throw new Exception("Please enter a competency name.");
        }
        if (empty($_POST['task_name'])) {
            throw new Exception("Please enter a task name.");
        }
        
        $task_id = (int)$_POST['id'];
        $qualification_id = (int)$_POST['qualification_id'];
        $competency_name = strtoupper(trim($_POST['competency_name']));
        $unit_of_competency = 'core';
        $task_name = trim($_POST['task_name']);
        $competency_code = isset($_POST['competency_code']) ? trim($_POST['competency_code']) : '';
        $competency_id = isset($_POST['competency_id']) ? (int)$_POST['competency_id'] : 0;
        $existing_code = isset($_POST['existing_code']) ? trim($_POST['existing_code']) : '';
        
        if (strlen($task_name) > 500) {
            throw new Exception("Task name exceeds maximum length of 500 characters: " . substr($task_name, 0, 50) . "...");
        }
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT competency_id FROM performance_tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $current_task = $stmt->fetch();
        $old_competency_id = $current_task ? $current_task['competency_id'] : 0;
        
        $competency = null;
        $competency_id_to_use = 0;
        
        if ($competency_id > 0) {
            $stmt = $pdo->prepare("
                SELECT id, code_no FROM competency_standards 
                WHERE id = ? 
                  AND qualification_id = ?
                  AND LOWER(unit_of_competency) = 'core'
            ");
            $stmt->execute([$competency_id, $qualification_id]);
            $competency = $stmt->fetch();
        }
        
        if (!$competency) {
            $stmt = $pdo->prepare("
                SELECT id, code_no FROM competency_standards 
                WHERE qualification_id = ? 
                  AND competency_name = ? 
                  AND unit_of_competency = ?
                  AND LOWER(unit_of_competency) = 'core'
            ");
            $stmt->execute([$qualification_id, $competency_name, $unit_of_competency]);
            $competency = $stmt->fetch();
        }
        
        if ($competency) {
            $competency_id_to_use = $competency['id'];
            
            $stmt = $pdo->prepare("UPDATE competency_standards SET competency_name = ? WHERE id = ?");
            $stmt->execute([$competency_name, $competency_id_to_use]);
            
            if (!empty($competency_code) && $competency_code != $competency['code_no']) {
                $check_stmt = $pdo->prepare("SELECT id FROM competency_standards WHERE code_no = ? AND id != ?");
                $check_stmt->execute([$competency_code, $competency_id_to_use]);
                if ($check_stmt->fetch()) {
                    throw new Exception("Competency code '$competency_code' already exists. Please use a different code.");
                }
                
                $stmt = $pdo->prepare("UPDATE competency_standards SET code_no = ? WHERE id = ?");
                $stmt->execute([$competency_code, $competency_id_to_use]);
                $updated_code = $competency_code;
            } else {
                $updated_code = $competency['code_no'];
                if (empty($competency_code)) {
                    $competency_code = $competency['code_no'];
                }
            }
        } else {
            if (empty($competency_code)) {
                $competency_code = generateCompetencyCode($pdo, $qualification_id, $unit_of_competency);
            } else {
                $check_stmt = $pdo->prepare("SELECT id FROM competency_standards WHERE code_no = ?");
                $check_stmt->execute([$competency_code]);
                if ($check_stmt->fetch()) {
                    $competency_code = generateCompetencyCode($pdo, $qualification_id, $unit_of_competency);
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO competency_standards 
                (qualification_id, unit_of_competency, competency_name, code_no) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$qualification_id, $unit_of_competency, $competency_name, $competency_code]);
            $competency_id_to_use = $pdo->lastInsertId();
            $updated_code = $competency_code;
        }
        
        $stmt = $pdo->prepare("
            UPDATE performance_tasks 
            SET task_name=?, competency_id=? 
            WHERE id=?
        ");
        $stmt->execute([$task_name, $competency_id_to_use, $task_id]);
        
        if ($old_competency_id && $old_competency_id != $competency_id_to_use) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM performance_tasks WHERE competency_id = ?");
            $stmt->execute([$old_competency_id]);
            $task_count = $stmt->fetchColumn();
            
            if ($task_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM competency_standards WHERE id = ? AND LOWER(unit_of_competency) = 'core'");
                $stmt->execute([$old_competency_id]);
            }
        }
        
        $pdo->commit();
        
        $reset_info = ['reset_count' => 0, 'pre_assessment_cleared' => 0, 'eligibility_cleared' => 0];
        if ($old_competency_id && $old_competency_id != $competency_id_to_use) {
            $reset_info = resetTraineesForQualification($pdo, $qualification_id, $has_pre_assessment);
        }
        
        $stmt = $pdo->prepare("
            SELECT pt.*, 
                   cs.competency_name, cs.unit_of_competency, cs.id as competency_id, cs.code_no,
                   q.id as qualification_id, q.qualification_name, q.qualification_code,
                   c.id as course_id, c.course_name, c.course_code, c.color
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            JOIN qualifications q ON cs.qualification_id = q.id
            JOIN courses c ON q.course_id = c.id
            WHERE pt.id = ? 
              AND LOWER(cs.unit_of_competency) = 'core'
        ");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $response = [
            'success' => true, 
            'status' => 'success',
            'message' => 'Task updated successfully.',
            'task' => $task,
            'task_id' => $task_id,
            'competency_id' => $competency_id_to_use,
            'competency_code' => $updated_code,
            'qualification_id' => $qualification_id,
            'course_id' => $task['course_id'],
            'competency_name' => $competency_name,
            'unit_of_competency' => $unit_of_competency,
            'task_name' => $task_name,
            'eligibility_reset' => ($reset_info['reset_count'] > 0),
            'reset_count' => $reset_info['reset_count'],
            'pre_assessment_cleared' => $reset_info['pre_assessment_cleared']
        ];
        
        if ($reset_info['reset_count'] > 0) {
            $response['message'] .= ' ' . $reset_info['reset_count'] . ' trainee(s) reset to "In Progress".';
        }
        
        safeTriggerPusher('competency_updated', [
            'competency_id' => $competency_id_to_use,
            'competency_name' => $competency_name,
            'qualification_id' => $qualification_id,
            'course_id' => $task['course_id'],
            'action' => 'task_edited',
            'task_id' => $task_id,
            'trainees_reset' => $reset_info['reset_count']
        ], 'compliance-channel');
        
        sendJson($response);
    } catch(Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJson(['success' => false, 'status' => 'error', 'message' => $e->getMessage()]);
    }
}

// ==========================================
// Handle Delete Task
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_task'])) {
    try {
        $task_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if ($task_id <= 0) {
            sendJson(['success' => false, 'status' => 'error', 'message' => 'Invalid task ID.']);
        }
        
        $pdo->beginTransaction();
        
        // IMPORTANT: Get qualification_id BEFORE deleting the task/competency
        $stmt = $pdo->prepare("
            SELECT pt.competency_id, cs.qualification_id 
            FROM performance_tasks pt
            LEFT JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE pt.id = ?
        ");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        $competency_id = $task ? $task['competency_id'] : null;
        $affected_qual_id = $task ? (int)$task['qualification_id'] : 0;
        
        try {
            $stmt = $pdo->prepare("DELETE FROM evaluation_tracker WHERE task_id = ?");
            $stmt->execute([$task_id]);
        } catch (PDOException $e) {}
        
        if ($has_task_evaluations) {
            try {
                $stmt = $pdo->prepare("DELETE FROM task_evaluations WHERE task_id = ?");
                $stmt->execute([$task_id]);
            } catch (PDOException $e) {}
        }
        
        $stmt = $pdo->prepare("DELETE FROM performance_tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $deleted = $stmt->rowCount();
        
        if ($deleted == 0) {
            $pdo->rollBack();
            sendJson(['success' => false, 'status' => 'error', 'message' => 'Task not found or already deleted.']);
        }
        
        if ($competency_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM performance_tasks WHERE competency_id = ?");
            $stmt->execute([$competency_id]);
            $task_count = $stmt->fetchColumn();
            
            if ($task_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM competency_standards WHERE id = ? AND LOWER(unit_of_competency) = 'core'");
                $stmt->execute([$competency_id]);
            }
        }
        
        $pdo->commit();
        
        // ==========================================
        // RECALCULATE ELIGIBILITY FOR AFFECTED TRAINEES
        // Use $affected_qual_id captured BEFORE deletion
        // ==========================================
        $recalc_info = ['restored_count' => 0, 'affected_trainees' => []];
        
        if ($affected_qual_id > 0) {
            $recalc_info = recalculateEligibilityAfterDelete($pdo, $affected_qual_id, $has_pre_assessment);
        }
        
        // NO Pusher trigger for delete to prevent auto-refresh
        
        $response = [
            'success' => true,
            'status' => 'success',
            'message' => 'Task deleted successfully!',
            'competency_id' => $competency_id,
            'task_id' => $task_id,
            'restored_count' => $recalc_info['restored_count'],
            'restored_trainees' => $recalc_info['affected_trainees']
        ];
        
        if ($recalc_info['restored_count'] > 0) {
            $response['message'] .= ' ' . $recalc_info['restored_count'] . ' trainee(s) restored to "Ready for Assessment".';
        }
        
        sendJson($response);
    } catch(Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Delete task error: " . $e->getMessage());
        sendJson(['success' => false, 'status' => 'error', 'message' => 'Failed to delete task: ' . $e->getMessage()]);
    }
}

// ==========================================
// Handle Delete All Tasks
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_all_tasks'])) {
    try {
        $pdo->beginTransaction();
        
        // Get all affected qualification IDs BEFORE deletion
        $all_qual_stmt = $pdo->query("
            SELECT DISTINCT cs.qualification_id 
            FROM competency_standards cs
            WHERE LOWER(cs.unit_of_competency) = 'core'
        ");
        $all_affected_quals = $all_qual_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $pdo->query("
            DELETE FROM evaluation_tracker 
            WHERE task_id IN (
                SELECT pt.id FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                WHERE LOWER(cs.unit_of_competency) = 'core'
            )
        ");
        
        if ($has_task_evaluations) {
            $pdo->exec("SAVEPOINT sp_task_eval");
            try {
                $pdo->query("
                    DELETE FROM task_evaluations 
                    WHERE task_id IN (
                        SELECT pt.id FROM performance_tasks pt
                        JOIN competency_standards cs ON pt.competency_id = cs.id
                        WHERE LOWER(cs.unit_of_competency) = 'core'
                    )
                ");
                $pdo->exec("RELEASE SAVEPOINT sp_task_eval");
            } catch (PDOException $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT sp_task_eval");
            }
        }
        
        $pdo->query("
            DELETE FROM performance_tasks 
            WHERE competency_id IN (
                SELECT id FROM competency_standards WHERE LOWER(unit_of_competency) = 'core'
            )
        ");
        
        $pdo->query("DELETE FROM competency_standards WHERE LOWER(unit_of_competency) = 'core'");
        
        $pdo->commit();
        
        // Reset all affected trainees back to In Progress
        $total_restored = 0;
        foreach ($all_affected_quals as $qual_id) {
            $reset = resetTraineesForQualification($pdo, (int)$qual_id, $has_pre_assessment);
            $total_restored += $reset['reset_count'];
        }
        
        sendJson([
            'success' => true,
            'status' => 'success',
            'message' => 'All Core tasks and competencies deleted successfully!' . 
                        ($total_restored > 0 ? " $total_restored trainee(s) affected." : '')
        ]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Delete all tasks error: " . $e->getMessage());
        sendJson(['success' => false, 'status' => 'error', 'message' => $e->getMessage()]);
    }
}

// ==========================================
// GET ALL DATA FOR PAGE LOAD
// ==========================================
$all_courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();

$tasks_hierarchy = [];

foreach ($all_courses as $course) {
    $course_id = $course['id'];
    $tasks_hierarchy[$course_id] = [
        'course_name' => $course['course_name'],
        'course_code' => $course['course_code'],
        'color' => $course['color'] ?? '#4F46E5',
        'qualifications' => []
    ];
    
    $qual_stmt = $pdo->prepare("
        SELECT id, qualification_name, qualification_code 
        FROM qualifications 
        WHERE course_id = ?
        ORDER BY qualification_name
    ");
    $qual_stmt->execute([$course_id]);
    $qualifications = $qual_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($qualifications as $qual) {
        $qual_id = $qual['id'];
        
        if ($is_trainer && !$is_coordinator) {
            if (!in_array($qual_id, $trainer_qualification_ids)) {
                continue;
            }
        }
        
        $comp_stmt = $pdo->prepare("
            SELECT cs.id, cs.unit_of_competency, cs.competency_name, cs.code_no
            FROM competency_standards cs
            WHERE cs.qualification_id = ? 
              AND LOWER(cs.unit_of_competency) = 'core'
            ORDER BY cs.id ASC
        ");
        $comp_stmt->execute([$qual_id]);
        $competencies = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $competencies_with_tasks = [];
        foreach ($competencies as $comp) {
            $task_stmt = $pdo->prepare("
                SELECT id, task_name
                FROM performance_tasks
                WHERE competency_id = ?
                ORDER BY id ASC
            ");
            $task_stmt->execute([$comp['id']]);
            $tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $competencies_with_tasks[] = [
                'id' => $comp['id'],
                'name' => $comp['competency_name'],
                'code' => $comp['code_no'],
                'unit' => $comp['unit_of_competency'] ?: 'core',
                'tasks' => $tasks,
                'task_count' => count($tasks)
            ];
        }
        
        $tasks_hierarchy[$course_id]['qualifications'][$qual_id] = [
            'qualification_name' => $qual['qualification_name'],
            'qualification_code' => $qual['qualification_code'],
            'competencies' => $competencies_with_tasks
        ];
    }
}

if ($is_trainer && !$is_coordinator && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $task_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT pt.id) 
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
        WHERE cs.qualification_id IN ($placeholders)
          AND LOWER(cs.unit_of_competency) = 'core'
    ");
    $task_stmt->execute($trainer_qualification_ids);
    $total_tasks = (int)$task_stmt->fetchColumn();
} else {
    $total_tasks = (int)$pdo->query("
        SELECT COUNT(DISTINCT pt.id) 
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
        WHERE LOWER(cs.unit_of_competency) = 'core'
    ")->fetchColumn();
}

$unit_display = [
    'core' => ['label' => 'CORE', 'icon' => 'fa-star', 'color' => '#8b5cf6', 'bg' => '#fce7f3']
];

$has_accessible_content = false;
if ($is_trainer && !$is_coordinator) {
    foreach ($tasks_hierarchy as $course_data) {
        if (!empty($course_data['qualifications'])) {
            $has_accessible_content = true;
            break;
        }
    }
} else {
    $has_accessible_content = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management - TESDA Compliance</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
            color: #4F46E5;
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
        }

        .profile-badge .avatar-circle i {
            color: #ffffff;
            font-size: 14px;
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

        .stat-card.no-access {
            opacity: 0.7;
            cursor: default;
        }

        .stat-card.no-access:hover {
            transform: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .stat-icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
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

        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-header h2 {
            color: #1a1c2c;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-header h2 i {
            color: #4F46E5;
            font-size: 1.3rem;
        }

        .filter-actions {
            display: flex;
            gap: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4F46E5, #6366F1);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
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
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-input {
            flex: 2;
            min-width: 220px;
        }

        .btn-reset {
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
        }

        .btn-reset:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }

        .department-section {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .department-header {
            padding: 24px 32px;
            border-bottom: 3px solid;
            display: flex;
            align-items: center;
            gap: 18px;
            background: #f8fafc;
            cursor: pointer;
            transition: background 0.2s ease;
            user-select: none;
        }

        .department-header:hover {
            background: #f1f5f9;
        }

        .dept-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: white;
            flex-shrink: 0;
        }

        .dept-info {
            flex: 1;
        }

        .dept-info h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a1c2c;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dept-code {
            font-size: 0.85rem;
            font-weight: 500;
            color: #64748b;
            background: #e2e8f0;
            padding: 2px 14px;
            border-radius: 20px;
        }

        .dept-info p {
            color: #64748b;
            font-size: 0.95rem;
        }

        .dept-toggle {
            font-size: 1.4rem;
            color: #94a3b8;
            flex-shrink: 0;
            transition: transform 0.3s ease;
            width: 32px;
            text-align: center;
        }

        .dept-toggle.expanded {
            transform: rotate(180deg);
        }

        .department-content {
            display: none;
            padding: 0 32px 32px;
        }

        .department-content.expanded {
            display: block;
        }

        .qualification-section {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-top: 18px;
            overflow: hidden;
        }

        .qualification-section:first-child {
            margin-top: 24px;
        }

        .qualification-section:hover {
            border-color: #cbd5e1;
        }

        .qualification-header {
            padding: 18px 24px;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: background 0.2s ease;
            user-select: none;
        }

        .qualification-header:hover {
            background: #f1f5f9;
        }

        .qualification-title {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .qual-toggle {
            color: #94a3b8;
            font-size: 1rem;
            transition: transform 0.3s ease;
            display: inline-block;
            width: 20px;
            text-align: center;
        }

        .qual-toggle.expanded {
            transform: rotate(90deg);
        }

        .qual-icon {
            color: #7C3AED;
            font-size: 1.1rem;
        }

        .qualification-title h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a1c2c;
        }

        .task-count-badge {
            background: #e2e8f0;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
        }

        .btn-add-task-sm {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', sans-serif;
        }

        .btn-add-task-sm:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }

        .qualification-content {
            padding: 20px 24px 24px;
            display: none;
            background: white;
        }

        .qualification-content.expanded {
            display: block;
        }

        .unit-group {
            margin-bottom: 16px;
        }

        .unit-group:last-child {
            margin-bottom: 0;
        }

        .unit-group-label {
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            border-left: 4px solid;
        }

        .unit-group-label .unit-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .unit-group-label .unit-icon.core { background: #8b5cf6; }

        .unit-group-label span {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .competency-card {
            border: 1px solid #eef2f6;
            border-radius: 8px;
            margin-bottom: 10px;
            overflow: hidden;
            background: #ffffff;
            transition: all 0.3s ease;
        }

        .competency-card:last-child {
            margin-bottom: 0;
        }

        .competency-card:hover {
            border-color: #d5dce6;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .competency-header {
            padding: 10px 14px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #eef2f6;
        }

        .competency-code-badge {
            font-size: 0.7rem;
            font-weight: 600;
            font-family: 'Poppins', monospace;
            color: #4F46E5;
            background: #EEF2FF;
            padding: 2px 10px;
            border-radius: 30px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .competency-name-text {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            flex: 1;
            min-width: 0;
            word-break: break-word;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .competency-tasks {
            padding: 8px 14px 14px;
            background: #ffffff;
        }

        .task-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px !important;
            margin-bottom: 4px !important;
            min-height: 40px;
            background: white;
            border-radius: 6px;
            border: 1px solid #eef2f6;
            transition: all 0.2s ease;
        }

        .task-item:hover {
            border-color: #cbd5e1;
            background: #fafbfc;
        }

        .task-item:last-child {
            margin-bottom: 0 !important;
        }

        .task-name-only {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }

        .task-bullet {
            color: #94a3b8;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .task-name-text {
            font-size: 0.9rem;
            font-weight: 400;
            color: #1a1c2c;
            line-height: 1.5;
            flex: 1;
            min-width: 0;
            word-break: break-word;
        }

        .task-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
            margin-left: 10px;
        }

        .task-actions button {
            border: none;
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', sans-serif;
            min-height: 30px;
        }

        .btn-view-task {
            background: #EEF2FF;
            color: #4F46E5;
        }

        .btn-view-task:hover {
            background: #DBEAFE;
            transform: translateY(-2px);
        }

        .btn-edit-task {
            background: #FFFBEB;
            color: #F59E0B;
        }

        .btn-edit-task:hover {
            background: #FEF3C7;
            transform: translateY(-2px);
        }

        .btn-delete-task {
            background: #FEF2F2;
            color: #EF4444;
        }

        .btn-delete-task:hover {
            background: #FEE2E2;
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 2rem;
            color: #cbd5e1;
            display: block;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 0.95rem;
        }

        .empty-state .sub-text {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 25px;
            padding: 44px 48px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .modal-box h3 {
            color: #1a1c2c;
            margin-bottom: 32px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .modal-box h3 i {
            color: #4F46E5;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #475569;
        }

        .form-group label .required {
            color: #ef4444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            font-family: 'Poppins', sans-serif;
            background: white;
            color: #1e293b;
            width: 100%;
            transition: all 0.3s ease;
        }

        #modal_competency_name,
        #edit_competency_name {
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .task-rows-container {
            margin-top: 4px;
        }

        .task-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: end;
            padding: 10px 14px;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 8px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .task-row:hover {
            border-color: #cbd5e1;
        }

        .task-row .form-group {
            margin-bottom: 0;
        }

        .task-row .form-group input {
            padding: 10px 14px;
            font-size: 0.9rem;
        }

        .btn-remove-task {
            background: #FEF2F2;
            color: #EF4444;
            border: none;
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            width: 44px;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .btn-remove-task:hover {
            background: #FEE2E2;
            transform: translateY(-2px);
        }

        .btn-remove-task:disabled {
            opacity: 0.3;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-add-another {
            background: transparent;
            color: #4F46E5;
            border: 2px dashed #4F46E5;
            padding: 12px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
            margin-top: 4px;
        }

        .btn-add-another:hover {
            background: #EEF2FF;
            border-color: #6366F1;
            transform: translateY(-2px);
        }

        .modal-actions {
            display: flex;
            gap: 14px;
            margin-top: 28px;
        }

        .modal-actions .btn-save {
            flex: 1;
            padding: 14px;
            background: linear-gradient(135deg, #4F46E5, #6366F1);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.3);
        }

        .modal-actions .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
        }

        .modal-actions .btn-cancel-modal {
            padding: 14px 28px;
            background: #f1f5f9;
            color: #64748b;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .modal-actions .btn-cancel-modal:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .delete-modal .modal-box {
            max-width: 420px;
            text-align: center;
            padding: 30px 35px;
        }

        .delete-modal .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .delete-modal .modal-question {
            color: #475569;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .delete-modal .modal-task-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 1rem;
            background: #f1f5f9;
            padding: 4px 16px;
            border-radius: 6px;
            display: inline-block;
            margin: 6px 0 4px 0;
        }

        .delete-modal .modal-footer {
            justify-content: center;
            border-top: none;
            padding-top: 0;
            margin-top: 14px;
            gap: 10px;
        }

        .delete-modal .modal-footer .btn-cancel-delete {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
            padding: 8px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            min-width: 100px;
        }

        .delete-modal .modal-footer .btn-cancel-delete:hover {
            background: #e2e8f0;
        }

        .delete-modal .modal-footer .btn-confirm-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            min-width: 100px;
        }

        .delete-modal .modal-footer .btn-confirm-delete:hover {
            background: #dc2626;
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .toast {
            position: fixed;
            top: 24px;
            right: 24px;
            padding: 16px 28px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            font-size: 1rem;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            font-family: 'Poppins', sans-serif;
        }

        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }
        .toast.warning { background: #f59e0b; }

        .newly-added {
            animation: highlightNew 1.5s ease-out;
        }

        @keyframes highlightNew {
            0% { background: #DBEAFE; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.3); }
            100% { background: transparent; box-shadow: none; }
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 10px; }

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
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-tasks"></i> Task Management</h1>
                <p>Manage all Core performance tasks across departments</p>
            </div>
            <div class="header-right">
                <div class="profile-badge">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="profile-name"><?= htmlspecialchars($display_name) ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>

        <div id="statsGridContainer" class="stats-grid"></div>

        <div class="filter-section">
            <div class="filter-header">
                <h2><i class="fas fa-search"></i> Search & Filter</h2>
                <div class="filter-actions">
                    <?php if ($is_coordinator || ($is_trainer && !empty($trainer_qualification_ids))): ?>
                    <button class="btn-primary" onclick="openAddTaskModal()">
                        <i class="fas fa-plus-circle"></i> Add Core Task
                    </button>
                    <?php endif; ?>
                    <?php if ($is_coordinator): ?>
                    <button class="btn-danger" id="deleteAllBtn" onclick="openDeleteAllModal()">
                        <i class="fas fa-trash-alt"></i> Delete All Core Tasks
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="filter-row">
                <select class="filter-select" id="globalCourseFilter">
                    <option value="">All Departments</option>
                    <?php 
                    $courses_to_show = $all_courses;
                    if ($is_trainer && !$is_coordinator && !empty($trainer_accessible_depts)) {
                        $courses_to_show = array_filter($all_courses, function($course) use ($trainer_accessible_depts) {
                            return in_array($course['course_code'], $trainer_accessible_depts);
                        });
                    }
                    foreach ($courses_to_show as $course): 
                    ?>
                    <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="globalUnitFilter">
                    <option value="">All Units</option>
                    <option value="core">CORE</option>
                </select>
                <input type="text" class="filter-input" id="globalSearchInput" placeholder="Search tasks by name...">
                <button class="btn-reset" onclick="resetAllFilters()">
                    <i class="fas fa-undo-alt"></i> Reset
                </button>
            </div>
        </div>

        <div id="departmentsContainer">
            <?php if (empty($tasks_hierarchy) || !$has_accessible_content): ?>
            <div class="no-access-message">
                <i class="fas fa-lock"></i>
                <h3>No Accessible Tasks</h3>
                <p>You don't have any qualifications assigned. Please contact the coordinator.</p>
            </div>
            <?php else: ?>
            <?php foreach ($tasks_hierarchy as $course_id => $course_data): 
                if ($is_trainer && !$is_coordinator && !empty($trainer_accessible_depts)) {
                    if (!in_array($course_data['course_code'], $trainer_accessible_depts)) {
                        continue;
                    }
                }
                
                $color = $course_data['color'] ?? '#4F46E5';
                $icon = 'fa-building';
                if ($course_data['course_code'] == 'HRS') $icon = 'fa-utensils';
                elseif ($course_data['course_code'] == 'CSS') $icon = 'fa-laptop-code';
                elseif ($course_data['course_code'] == 'ATS') $icon = 'fa-car';
            ?>
            <div class="department-section" data-course-id="<?= $course_id ?>">
                <div class="department-header" onclick="toggleDepartment(this)" style="border-bottom-color: <?= $color ?>">
                    <div class="dept-icon" style="background: <?= $color ?>">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    <div class="dept-info">
                        <h3>
                            <?= htmlspecialchars($course_data['course_name']) ?>
                            <span class="dept-code"><?= htmlspecialchars($course_data['course_code']) ?></span>
                        </h3>
                        <p>
                            <?= count($course_data['qualifications']) ?> qualification(s) available
                        </p>
                    </div>
                    <div class="dept-toggle">▸</div>
                </div>
                
                <div class="department-content" style="display:none;">
                    <?php foreach ($course_data['qualifications'] as $qual_id => $qual_data): 
                        $total_tasks = 0;
                        foreach ($qual_data['competencies'] as $comp) {
                            $total_tasks += $comp['task_count'];
                        }
                    ?>
                    <div class="qualification-section" data-qualification-id="<?= $qual_id ?>">
                        <div class="qualification-header" onclick="toggleQualification(this)">
                            <div class="qualification-title">
                                <span class="qual-toggle">▸</span>
                                <i class="fas fa-certificate qual-icon"></i>
                                <h4><?= htmlspecialchars($qual_data['qualification_name']) ?></h4>
                                <span class="task-count-badge" id="taskCount_<?= $qual_id ?>">
                                    <?= $total_tasks ?> task<?= $total_tasks != 1 ? 's' : '' ?>
                                </span>
                            </div>
                            <?php if ($is_coordinator || ($is_trainer && in_array($qual_id, $trainer_qualification_ids))): ?>
                            <button class="btn-add-task-sm" onclick="event.stopPropagation(); openAddTaskModalWithQualification(<?= $course_id ?>, <?= $qual_id ?>)">
                                <i class="fas fa-plus"></i> Add Core Task
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="qualification-content" id="qualContent_<?= $qual_id ?>" style="display:none;">
                            <?php if (empty($qual_data['competencies'])): ?>
                            <div class="empty-state" style="padding:20px;">
                                <i class="fas fa-inbox"></i>
                                <p>No Core competencies configured for this qualification.</p>
                                <span class="sub-text">Click "Add Core Task" to create your first Core competency.</span>
                            </div>
                            <?php else: ?>
                                <?php 
                                $grouped = [];
                                foreach ($qual_data['competencies'] as $comp) {
                                    $unit = $comp['unit'];
                                    if (!isset($grouped[$unit])) {
                                        $grouped[$unit] = [];
                                    }
                                    $grouped[$unit][] = $comp;
                                }
                                ?>
                                <?php foreach ($grouped as $unit_key => $competencies): ?>
                                <div class="unit-group" data-unit="core">
                                    <div class="unit-group-label" style="border-left-color: <?= $unit_display['core']['color'] ?>">
                                        <div class="unit-icon core">
                                            <i class="fas <?= $unit_display['core']['icon'] ?>"></i>
                                        </div>
                                        <span><?= $unit_display['core']['label'] ?></span>
                                    </div>
                                    
                                    <?php foreach ($competencies as $comp): ?>
                                    <div class="competency-card" data-competency-id="<?= $comp['id'] ?>" data-qualification-id="<?= $qual_id ?>">
                                        <div class="competency-header">
                                            <?php if (!empty($comp['code'])): ?>
                                            <span class="competency-code-badge"><?= htmlspecialchars($comp['code']) ?></span>
                                            <?php endif; ?>
                                            <span class="competency-name-text"><?= htmlspecialchars(strtoupper($comp['name'])) ?></span>
                                        </div>
                                        
                                        <div class="competency-tasks" id="compTasks_<?= $comp['id'] ?>">
                                            <?php if (!empty($comp['tasks'])): ?>
                                                <?php foreach ($comp['tasks'] as $task): ?>
                                                <div class="task-item" data-task-id="<?= $task['id'] ?>" data-qualification-id="<?= $qual_id ?>" data-competency-id="<?= $comp['id'] ?>">
                                                    <div class="task-name-only">
                                                        <span class="task-bullet">•</span>
                                                        <span class="task-name-text"><?= htmlspecialchars($task['task_name']) ?></span>
                                                    </div>
                                                    <div class="task-actions">
                                                        <button class="btn-view-task" onclick="event.stopPropagation(); viewTask(<?= $task['id'] ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($is_coordinator || ($is_trainer && in_array($qual_id, $trainer_qualification_ids))): ?>
                                                        <button class="btn-edit-task" onclick="event.stopPropagation(); editTask(<?= $task['id'] ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn-delete-task" onclick="event.stopPropagation(); openDeleteModal(<?= $task['id'] ?>, '<?= htmlspecialchars(addslashes($task['task_name'])) ?>')">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="empty-state" style="padding:8px;">
                                                    <p style="font-size:0.85rem; color:#94a3b8;">No tasks for this competency</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Task Modal -->
    <div id="addTaskModal" class="modal-overlay">
        <div class="modal-box">
            <h3><i class="fas fa-plus-circle"></i> <span id="modalTitle">Add Core Performance Task</span></h3>
            <form id="addTaskForm">
                <input type="hidden" name="add_task" value="1">
                <input type="hidden" name="course_id" id="modal_course_id">
                <input type="hidden" name="qualification_id" id="modal_qualification_id">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Department <span class="required">*</span></label>
                        <select id="modal_course_select" required onchange="loadQualifications()">
                            <option value="">Select Department</option>
                            <?php 
                            $courses_to_show = $all_courses;
                            if ($is_trainer && !$is_coordinator && !empty($trainer_accessible_depts)) {
                                $courses_to_show = array_filter($all_courses, function($course) use ($trainer_accessible_depts) {
                                    return in_array($course['course_code'], $trainer_accessible_depts);
                                });
                            }
                            foreach ($courses_to_show as $course): 
                            ?>
                            <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Qualification <span class="required">*</span></label>
                        <select id="modal_qualification_select" required>
                            <option value="">Select Department first</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Unit of Competency <span class="required">*</span></label>
                        <select id="modal_unit_of_competency" required>
                            <option value="core" selected>Core</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Competency Name <span class="required">*</span></label>
                        <input type="text" id="modal_competency_name" name="competency_name" placeholder="Enter competency name" required maxlength="255" style="text-transform: uppercase;">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Competency Code</label>
                        <input type="text" id="modal_competency_code" name="competency_code" placeholder="Enter competency code" maxlength="50">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Tasks <span class="required">*</span></label>
                        <div class="task-rows-container" id="taskRowsContainer">
                            <div class="task-row" data-row-index="0">
                                <div class="form-group" style="flex:1;">
                                    <input type="text" name="task_names[]" placeholder="Enter task name" required maxlength="500">
                                </div>
                                <button type="button" class="btn-remove-task" onclick="removeTaskRow(this)" disabled>
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn-add-another" onclick="addTaskRow()">
                            <i class="fas fa-plus-circle"></i> Add Another Task
                        </button>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-save" id="addTaskSaveBtn" onclick="submitTaskForm('add')">
                        <i class="fas fa-save"></i> Add Core Tasks
                    </button>
                    <button type="button" class="btn-cancel-modal" onclick="closeAddTaskModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div id="editTaskModal" class="modal-overlay">
        <div class="modal-box">
            <h3><i class="fas fa-edit"></i> Edit Core Performance Task</h3>
            <form id="editTaskForm">
                <input type="hidden" name="edit_task" value="1">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="course_id" id="edit_course_id">
                <input type="hidden" name="qualification_id" id="edit_qualification_id">
                <input type="hidden" name="competency_id" id="edit_competency_id_field">
                <input type="hidden" name="existing_code" id="edit_existing_code">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Department <span class="required">*</span></label>
                        <select id="edit_course_select" required onchange="loadEditQualifications()">
                            <option value="">Select Department</option>
                            <?php 
                            $courses_to_show = $all_courses;
                            if ($is_trainer && !$is_coordinator && !empty($trainer_accessible_depts)) {
                                $courses_to_show = array_filter($all_courses, function($course) use ($trainer_accessible_depts) {
                                    return in_array($course['course_code'], $trainer_accessible_depts);
                                });
                            }
                            foreach ($courses_to_show as $course): 
                            ?>
                            <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Qualification <span class="required">*</span></label>
                        <select id="edit_qualification_select" required>
                            <option value="">Select Department first</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Unit of Competency <span class="required">*</span></label>
                        <select id="edit_unit_of_competency" required>
                            <option value="core" selected>Core</option>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Competency Name <span class="required">*</span></label>
                        <input type="text" id="edit_competency_name" name="competency_name" placeholder="Enter competency name" required maxlength="255" style="text-transform: uppercase;">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Competency Code</label>
                        <input type="text" id="edit_competency_code" name="competency_code" placeholder="Enter competency code" maxlength="50">
                    </div>
                    
                    <div class="form-group full-width">
                        <label>Task Name <span class="required">*</span></label>
                        <input type="text" name="task_name" id="edit_task_name" required maxlength="500">
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-save" id="editTaskSaveBtn" onclick="submitTaskForm('edit')">
                        <i class="fas fa-save"></i> Update Core Task
                    </button>
                    <button type="button" class="btn-cancel-modal" onclick="closeModalAndReset('editTaskModal')">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Task Modal -->
    <div id="viewTaskModal" class="modal-overlay">
        <div class="modal-box">
            <h3><i class="fas fa-eye"></i> Task Details</h3>
            <div id="viewTaskContent"></div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel-modal" onclick="closeModal('viewTaskModal')" style="width:100%;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Single Task Modal -->
    <div id="deleteTaskModal" class="modal-overlay delete-modal">
        <div class="modal-box">
            <div class="modal-title">Delete Core Task</div>
            <div class="modal-question">Are you sure you want to delete this Core task?</div>
            <div class="modal-task-name" id="deleteTaskName">Loading...</div>
            <div class="modal-footer">
                <button class="btn-cancel-delete" onclick="closeModal('deleteTaskModal')">Cancel</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn" onclick="confirmDeleteTask()">Yes</button>
            </div>
        </div>
    </div>

    <!-- Delete All Tasks Modal -->
    <div id="deleteAllModal" class="modal-overlay delete-modal">
        <div class="modal-box">
            <div class="modal-title">Delete All Core Tasks</div>
            <div class="modal-question">Are you sure you want to delete ALL Core performance tasks and competencies?</div>
            <div class="modal-task-name" style="background:#fef2f2; color:#b91c1c; font-size:0.85rem; padding:6px 14px;">
                <i class="fas fa-exclamation-triangle"></i> Basic/Common data will be preserved. This cannot be undone.
            </div>
            <div class="modal-footer">
                <button class="btn-cancel-delete" onclick="closeModal('deleteAllModal')">Cancel</button>
                <button class="btn-confirm-delete" id="confirmDeleteAllBtn" onclick="confirmDeleteAllTasks()">Yes, Delete All</button>
            </div>
        </div>
    </div>

    <script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/tesda_compliance/assets/js/jquery.min.js"></script>
    <script src="/tesda_compliance/assets/js/app.js"></script>

    <script>
        let taskRowCount = 1;
        let isTrainer = <?= json_encode($is_trainer && !$is_coordinator) ?>;
        let trainerQualIds = <?= json_encode($trainer_qualification_ids) ?>;
        let trainerAccessibleDepts = <?= json_encode($trainer_accessible_depts) ?>;
        let deleteTaskId = null;
        let deleteTaskName = '';
        let deleteTaskElement = null;
        let deleteCompetencyId = null;
        let isReloadingHierarchy = false;

        function parseJsonResponse(response) {
            return response.text().then(function(rawText) {
                if (!rawText || rawText.trim() === '') {
                    throw new Error('Empty response from server');
                }
                try {
                    return JSON.parse(rawText);
                } catch (e) {
                    var firstBrace = rawText.indexOf('{');
                    var lastBrace = rawText.lastIndexOf('}');
                    if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
                        var jsonPart = rawText.substring(firstBrace, lastBrace + 1);
                        try {
                            return JSON.parse(jsonPart);
                        } catch (e2) {
                            throw new Error('Invalid JSON. Server said: ' + rawText.substring(0, 200));
                        }
                    }
                    throw new Error('Invalid JSON. Server said: ' + rawText.substring(0, 200));
                }
            });
        }

        function isSuccessResponse(data) {
            if (!data) return false;
            return data.success === true || data.status === 'success';
        }

        function resetAddTaskForm() {
            const form = document.getElementById('addTaskForm');
            if (form) form.reset();
            document.getElementById('modal_course_id').value = '';
            document.getElementById('modal_qualification_id').value = '';
            const qualSelect = document.getElementById('modal_qualification_select');
            if (qualSelect) qualSelect.innerHTML = '<option value="">Select Department first</option>';
            const unitSelect = document.getElementById('modal_unit_of_competency');
            if (unitSelect) unitSelect.value = 'core';
            document.getElementById('modal_competency_name').value = '';
            document.getElementById('modal_competency_code').value = '';
            const container = document.getElementById('taskRowsContainer');
            if (container) {
                container.innerHTML = `
                    <div class="task-row" data-row-index="0">
                        <div class="form-group" style="flex:1;">
                            <input type="text" name="task_names[]" placeholder="Enter task name" required maxlength="500">
                        </div>
                        <button type="button" class="btn-remove-task" onclick="removeTaskRow(this)" disabled>
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                `;
            }
            taskRowCount = 1;
        }

        function showToast(message, type) {
            type = type || 'success';
            const existing = document.querySelector('.toast');
            if (existing) existing.remove();
            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            var iconClass = 'fa-check-circle';
            if (type === 'error') iconClass = 'fa-exclamation-circle';
            if (type === 'warning') iconClass = 'fa-exclamation-triangle';
            toast.innerHTML = '<i class="fas ' + iconClass + '"></i> ' + message;
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 6000);
        }

        function updateTaskCount(qualificationId) {
            const qualSection = document.querySelector('.qualification-section[data-qualification-id="' + qualificationId + '"]');
            if (!qualSection) return;
            const badge = qualSection.querySelector('.task-count-badge');
            if (!badge) return;
            const visibleTasks = qualSection.querySelectorAll('.task-item');
            const totalCount = visibleTasks.length;
            badge.textContent = totalCount + ' task' + (totalCount != 1 ? 's' : '');
        }

        function initializePusher() {
            if (typeof Pusher === 'undefined') return;
            try {
                const pusher = new Pusher('9bfff3facb287d7bbcbd', {
                    cluster: 'ap1',
                    forceTLS: true
                });
                const channel = pusher.subscribe('compliance-channel');
                channel.bind('competency_updated', function(data) {
                    if (data && (data.action === 'task_added' || data.action === 'task_edited')) {
                        updateStatCards();
                        if (!isReloadingHierarchy) {
                            isReloadingHierarchy = true;
                            setTimeout(function() {
                                reloadHierarchy();
                                isReloadingHierarchy = false;
                            }, 300);
                        }
                    }
                });
            } catch (e) {}
        }

        function reloadHierarchy() {
            fetch('tasks.php?ajax=get_hierarchy&t=' + Date.now())
                .then(function(response) { return parseJsonResponse(response); })
                .then(function(data) {
                    if (data.success && data.html !== undefined) {
                        document.getElementById('departmentsContainer').innerHTML = data.html;
                        const currentCourseFilter = document.getElementById('globalCourseFilter').value;
                        const currentUnitFilter = document.getElementById('globalUnitFilter').value;
                        const currentSearch = document.getElementById('globalSearchInput').value;
                        if (currentCourseFilter || currentUnitFilter || currentSearch) {
                            applyGlobalFilter();
                        }
                    }
                })
                .catch(function(err) { console.error('Error reloading hierarchy:', err); });
        }

        function updateStatCards() {
            fetch('tasks.php?ajax=get_stats&t=' + Date.now())
                .then(function(response) { return parseJsonResponse(response); })
                .then(function(data) {
                    if (data.success) {
                        const container = document.getElementById('statsGridContainer');
                        let html = '';
                        html += '<div class="stat-card">';
                        html += '<div class="stat-icon-wrapper" style="background: #4F46E5;"><i class="fas fa-tasks"></i></div>';
                        html += '<div class="stat-content"><h3>' + data.total_tasks + '</h3><p>Total Core Performance Tasks</p></div>';
                        html += '</div>';

                        if (data.department_competencies && data.department_competencies.length > 0) {
                            data.department_competencies.forEach(function(dept) {
                                let iconClass = 'fa-building';
                                const code = dept.code;
                                if (code == 'HRS') iconClass = 'fa-utensils';
                                else if (code == 'CSS') iconClass = 'fa-laptop-code';
                                else if (code == 'ATS') iconClass = 'fa-car';

                                const hasAccess = dept.has_access !== false;
                                const countDisplay = hasAccess ? dept.count : 0;

                                html += '<div class="stat-card ' + (!hasAccess ? 'no-access' : '') + '">';
                                html += '<div class="stat-icon-wrapper" style="background: ' + dept.color + ';"><i class="fas ' + iconClass + '"></i></div>';
                                html += '<div class="stat-content"><h3>' + countDisplay + '</h3><p>' + dept.name + '</p></div>';
                                html += '</div>';
                            });
                        }
                        container.innerHTML = html;
                    }
                })
                .catch(function() {});
        }

        function toggleDepartment(header) {
            const content = header.nextElementSibling;
            const icon = header.querySelector('.dept-toggle');
            if (!content || !icon) return;

            const isExpanded = content.style.display === 'block' || content.classList.contains('expanded');

            if (isExpanded) {
                content.style.display = 'none';
                content.classList.remove('expanded');
                icon.textContent = '▸';
                icon.classList.remove('expanded');
                content.querySelectorAll('.qualification-content').forEach(function(qc) {
                    qc.style.display = 'none';
                    qc.classList.remove('expanded');
                });
                content.querySelectorAll('.qual-toggle').forEach(function(qt) {
                    qt.textContent = '▸';
                    qt.classList.remove('expanded');
                });
            } else {
                content.style.display = 'block';
                content.classList.add('expanded');
                icon.textContent = '▾';
                icon.classList.add('expanded');
            }
        }

        function toggleQualification(header) {
            const container = header.nextElementSibling;
            const icon = header.querySelector('.qual-toggle');
            if (!container || !icon) return;

            const isExpanded = container.style.display === 'block' || container.classList.contains('expanded');

            if (isExpanded) {
                container.style.display = 'none';
                container.classList.remove('expanded');
                icon.textContent = '▸';
                icon.classList.remove('expanded');
            } else {
                container.style.display = 'block';
                container.classList.add('expanded');
                icon.textContent = '▾';
                icon.classList.add('expanded');
            }
        }

        function loadQualifications() {
            const courseId = document.getElementById('modal_course_select').value;
            document.getElementById('modal_course_id').value = courseId;
            const qualSelect = document.getElementById('modal_qualification_select');
            if (!courseId) {
                qualSelect.innerHTML = '<option value="">Select Department first</option>';
                return;
            }
            fetch('tasks.php?ajax=get_qualifications&course_id=' + courseId)
                .then(function(response) { return parseJsonResponse(response); })
                .then(function(data) {
                    qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(function(qual) {
                            if (isTrainer && trainerQualIds.indexOf(qual.id) === -1) return;
                            qualSelect.innerHTML += '<option value="' + qual.id + '">' + qual.qualification_name + '</option>';
                        });
                        if (qualSelect.options.length <= 1) {
                            qualSelect.innerHTML = '<option value="">No accessible qualifications</option>';
                        }
                    } else {
                        qualSelect.innerHTML = '<option value="">No qualifications available</option>';
                    }
                })
                .catch(function() {
                    qualSelect.innerHTML = '<option value="">Error loading qualifications</option>';
                });
        }

        function loadEditQualifications() {
            const courseId = document.getElementById('edit_course_select').value;
            document.getElementById('edit_course_id').value = courseId;
            const qualSelect = document.getElementById('edit_qualification_select');
            if (!courseId) {
                qualSelect.innerHTML = '<option value="">Select Department first</option>';
                return;
            }
            fetch('tasks.php?ajax=get_qualifications&course_id=' + courseId)
                .then(function(response) { return parseJsonResponse(response); })
                .then(function(data) {
                    qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(function(qual) {
                            if (isTrainer && trainerQualIds.indexOf(qual.id) === -1) return;
                            qualSelect.innerHTML += '<option value="' + qual.id + '">' + qual.qualification_name + '</option>';
                        });
                    } else {
                        qualSelect.innerHTML = '<option value="">No qualifications available</option>';
                    }
                })
                .catch(function() {
                    qualSelect.innerHTML = '<option value="">Error loading qualifications</option>';
                });
        }

        function applyGlobalFilter() {
            const courseId = document.getElementById('globalCourseFilter').value;
            const unitFilter = document.getElementById('globalUnitFilter').value;
            const search = document.getElementById('globalSearchInput').value.toLowerCase().trim();

            document.querySelectorAll('.department-section').forEach(function(dept) {
                const deptCourseId = dept.dataset.courseId;
                let deptHasVisible = false;

                dept.querySelectorAll('.qualification-section').forEach(function(qual) {
                    let qualHasVisible = false;

                    qual.querySelectorAll('.unit-group').forEach(function(unitGroup) {
                        const unitType = unitGroup.dataset.unit || '';
                        let unitHasVisible = false;

                        unitGroup.querySelectorAll('.competency-card').forEach(function(comp) {
                            let compHasVisible = false;

                            comp.querySelectorAll('.task-item').forEach(function(task) {
                                const taskNameEl = task.querySelector('.task-name-text');
                                const taskName = taskNameEl ? taskNameEl.textContent.toLowerCase() : '';
                                const deptHeader = dept.querySelector('.dept-info h3');
                                const deptName = deptHeader ? deptHeader.textContent.toLowerCase() : '';

                                let visible = true;
                                if (courseId && courseId !== deptCourseId) visible = false;
                                if (visible && unitFilter && unitType !== unitFilter) visible = false;
                                if (visible && search) {
                                    if (taskName.indexOf(search) === -1 && deptName.indexOf(search) === -1) visible = false;
                                }
                                task.style.display = visible ? 'flex' : 'none';
                                if (visible) compHasVisible = true;
                            });
                            if (compHasVisible) unitHasVisible = true;
                        });
                        if (unitHasVisible) qualHasVisible = true;
                    });

                    const qualContainer = qual.querySelector('.qualification-content');
                    const qualToggleIcon = qual.querySelector('.qual-toggle');
                    if (qualContainer) {
                        if (qualHasVisible) {
                            qualContainer.style.display = 'block';
                            qualContainer.classList.add('expanded');
                            if (qualToggleIcon) {
                                qualToggleIcon.textContent = '▾';
                                qualToggleIcon.classList.add('expanded');
                            }
                        } else {
                            qualContainer.style.display = 'none';
                            qualContainer.classList.remove('expanded');
                            if (qualToggleIcon) {
                                qualToggleIcon.textContent = '▸';
                                qualToggleIcon.classList.remove('expanded');
                            }
                        }
                    }
                    if (qualHasVisible) deptHasVisible = true;
                });

                const deptContent = dept.querySelector('.department-content');
                const deptToggleIcon = dept.querySelector('.dept-toggle');
                if (deptContent) {
                    if (deptHasVisible) {
                        deptContent.style.display = 'block';
                        deptContent.classList.add('expanded');
                        if (deptToggleIcon) {
                            deptToggleIcon.textContent = '▾';
                            deptToggleIcon.classList.add('expanded');
                        }
                    } else {
                        deptContent.style.display = 'none';
                        deptContent.classList.remove('expanded');
                        if (deptToggleIcon) {
                            deptToggleIcon.textContent = '▸';
                            deptToggleIcon.classList.remove('expanded');
                        }
                    }
                }
            });
        }

        function resetAllFilters() {
            document.getElementById('globalCourseFilter').value = '';
            document.getElementById('globalUnitFilter').value = '';
            document.getElementById('globalSearchInput').value = '';

            document.querySelectorAll('.task-item').forEach(function(el) { el.style.display = 'flex'; });
            document.querySelectorAll('.qualification-content').forEach(function(el) {
                el.style.display = 'none';
                el.classList.remove('expanded');
            });
            document.querySelectorAll('.department-content').forEach(function(el) {
                el.style.display = 'none';
                el.classList.remove('expanded');
            });
            document.querySelectorAll('.dept-toggle').forEach(function(el) {
                el.classList.remove('expanded');
                el.textContent = '▸';
            });
            document.querySelectorAll('.qual-toggle').forEach(function(el) {
                el.classList.remove('expanded');
                el.textContent = '▸';
            });
        }

        function openAddTaskModal() {
            document.getElementById('modalTitle').textContent = 'Add Core Performance Task';
            resetAddTaskForm();
            document.getElementById('addTaskModal').classList.add('active');
        }

        function openAddTaskModalWithQualification(courseId, qualificationId) {
            resetAddTaskForm();
            const select = document.getElementById('modal_course_select');
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value == courseId) { select.selectedIndex = i; break; }
            }
            loadQualifications();
            setTimeout(function() {
                const qualSelect = document.getElementById('modal_qualification_select');
                for (let i = 0; i < qualSelect.options.length; i++) {
                    if (qualSelect.options[i].value == qualificationId) { qualSelect.selectedIndex = i; break; }
                }
            }, 100);
            document.getElementById('modal_course_id').value = courseId;
            document.getElementById('modal_qualification_id').value = qualificationId;
            document.getElementById('modalTitle').textContent = 'Add Core Performance Task';
            document.getElementById('addTaskModal').classList.add('active');
        }

        function closeAddTaskModal() {
            document.getElementById('addTaskModal').classList.remove('active');
            resetAddTaskForm();
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeModalAndReset(id) {
            closeModal(id);
            if (id === 'editTaskModal') {
                document.getElementById('edit_course_select').value = '';
                document.getElementById('edit_qualification_select').innerHTML = '<option value="">Select Department first</option>';
                document.getElementById('edit_unit_of_competency').value = 'core';
                document.getElementById('edit_competency_name').value = '';
                document.getElementById('edit_competency_code').value = '';
                document.getElementById('edit_task_name').value = '';
                document.getElementById('edit_id').value = '';
            }
        }

        function addTaskRow() {
            const container = document.getElementById('taskRowsContainer');
            const rowCount = container.querySelectorAll('.task-row').length;
            const row = document.createElement('div');
            row.className = 'task-row';
            row.dataset.rowIndex = rowCount;
            row.innerHTML = '<div class="form-group" style="flex:1;">' +
                '<input type="text" name="task_names[]" placeholder="Enter task name" required maxlength="500">' +
                '</div>' +
                '<button type="button" class="btn-remove-task" onclick="removeTaskRow(this)">' +
                '<i class="fas fa-trash-alt"></i></button>';
            container.appendChild(row);
            updateRemoveButtons();
        }

        function removeTaskRow(button) {
            const container = document.getElementById('taskRowsContainer');
            const rows = container.querySelectorAll('.task-row');
            if (rows.length <= 1) {
                showToast('At least one task is required.', 'error');
                return;
            }
            const row = button.closest('.task-row');
            row.remove();
            updateRemoveButtons();
        }

        function updateRemoveButtons() {
            const container = document.getElementById('taskRowsContainer');
            const rows = container.querySelectorAll('.task-row');
            const buttons = container.querySelectorAll('.btn-remove-task');
            buttons.forEach(function(btn) {
                btn.disabled = rows.length <= 1;
            });
        }

        function openDeleteModal(id, name) {
            deleteTaskId = id;
            deleteTaskName = name;
            document.getElementById('deleteTaskName').textContent = name;
            deleteTaskElement = document.querySelector('.task-item[data-task-id="' + id + '"]');
            if (deleteTaskElement) {
                const compSection = deleteTaskElement.closest('.competency-card');
                if (compSection) deleteCompetencyId = compSection.dataset.competencyId;
            }
            document.getElementById('deleteTaskModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function confirmDeleteTask() {
            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.textContent = 'Deleting...';

            const formData = new URLSearchParams();
            formData.append('delete_task', '1');
            formData.append('id', deleteTaskId);

            const qualSection = deleteTaskElement ? deleteTaskElement.closest('.qualification-section') : null;
            if (qualSection) formData.append('qualification_id', qualSection.dataset.qualificationId);

            fetch('tasks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(function(response) { return parseJsonResponse(response); })
            .then(function(data) {
                if (isSuccessResponse(data)) {
                    showToast(data.message || 'Task deleted successfully!', 'success');
                    closeModal('deleteTaskModal');
                    
                    if (deleteTaskElement) {
                        const compCard = deleteTaskElement.closest('.competency-card');
                        const unitGroup = compCard ? compCard.closest('.unit-group') : null;
                        
                        deleteTaskElement.remove();
                        
                        if (compCard && compCard.querySelectorAll('.task-item').length === 0) {
                            compCard.remove();
                            if (unitGroup && unitGroup.querySelectorAll('.competency-card').length === 0) {
                                unitGroup.remove();
                            }
                        }
                    }
                    
                    if (qualSection) updateTaskCount(qualSection.dataset.qualificationId);
                    updateStatCards();
                    
                    // Show restore notification if trainees were restored to eligible
                    if (data.restored_count && data.restored_count > 0) {
                        setTimeout(function() {
                            showToast('✅ ' + data.restored_count + ' trainee(s) restored to "Ready for Assessment".', 'success');
                        }, 1500);
                    }
                    
                    btn.disabled = false;
                    btn.textContent = 'Yes';
                } else {
                    showToast(data.message || 'Failed to delete task.', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Yes';
                }
            })
            .catch(function(error) {
                console.error('Delete error:', error);
                showToast(error.message || 'An error occurred while deleting.', 'error');
                btn.disabled = false;
                btn.textContent = 'Yes';
            });
        }

        function openDeleteAllModal() {
            document.getElementById('deleteAllModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function confirmDeleteAllTasks() {
            const btn = document.getElementById('confirmDeleteAllBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

            fetch('tasks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_all_tasks=1'
            })
            .then(function(response) { return parseJsonResponse(response); })
            .then(function(data) {
                if (isSuccessResponse(data)) {
                    showToast(data.message || 'All Core tasks deleted successfully!', 'success');
                    closeModal('deleteAllModal');
                    
                    document.querySelectorAll('.task-item').forEach(function(el) { el.remove(); });
                    document.querySelectorAll('.competency-card').forEach(function(el) { el.remove(); });
                    document.querySelectorAll('.unit-group').forEach(function(el) { el.remove(); });
                    
                    document.querySelectorAll('.task-count-badge').forEach(function(badge) {
                        badge.textContent = '0 tasks';
                    });
                    
                    updateStatCards();
                    
                    btn.disabled = false;
                    btn.textContent = 'Yes, Delete All';
                } else {
                    showToast(data.message || 'Delete failed', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Yes, Delete All';
                }
            })
            .catch(function(error) {
                console.error('Delete all error:', error);
                showToast(error.message || 'An error occurred', 'error');
                btn.disabled = false;
                btn.textContent = 'Yes, Delete All';
            });
        }

        function submitTaskForm(action) {
            const isEdit = action === 'edit';
            const form = isEdit ? document.getElementById('editTaskForm') : document.getElementById('addTaskForm');
            const formData = new FormData(form);

            const courseId = document.getElementById(isEdit ? 'edit_course_select' : 'modal_course_select').value;
            const qualificationId = document.getElementById(isEdit ? 'edit_qualification_select' : 'modal_qualification_select').value;
            const unitOfCompetency = document.getElementById(isEdit ? 'edit_unit_of_competency' : 'modal_unit_of_competency').value;
            let competencyName = document.getElementById(isEdit ? 'edit_competency_name' : 'modal_competency_name').value;
            const competencyCode = document.getElementById(isEdit ? 'edit_competency_code' : 'modal_competency_code').value;

            competencyName = (competencyName || '').toUpperCase().trim();

            if (!courseId) { showToast('Please select a department.', 'error'); return; }
            if (!qualificationId) { showToast('Please select a qualification.', 'error'); return; }
            if (!unitOfCompetency || unitOfCompetency !== 'core') { showToast('Only Core Competencies are allowed.', 'error'); return; }
            if (!competencyName) { showToast('Please enter a competency name.', 'error'); return; }

            formData.set('qualification_id', qualificationId);
            formData.set('unit_of_competency', 'core');
            formData.set('competency_name', competencyName);

            fetch('tasks.php', { method: 'POST', body: formData })
            .then(function(response) { return parseJsonResponse(response); })
            .then(function(data) {
                if (isSuccessResponse(data)) {
                    showToast(data.message || 'Task(s) saved successfully.', 'success');
                    
                    if (data.eligibility_reset && data.reset_count > 0) {
                        setTimeout(function() {
                            showToast('⚠️ ' + data.reset_count + ' trainee(s) reset to "In Progress" because a new task was added.', 'warning');
                        }, 1500);
                        
                        if (data.pre_assessment_cleared > 0) {
                            setTimeout(function() {
                                showToast('ℹ️ ' + data.pre_assessment_cleared + ' pre-assessment record(s) cleared.', 'warning');
                            }, 3000);
                        }
                    }
                    
                    if (isEdit) {
                        closeModalAndReset('editTaskModal');
                        setTimeout(function() { reloadHierarchy(); updateStatCards(); }, 200);
                    } else {
                        closeModal('addTaskModal');
                        resetAddTaskForm();
                        setTimeout(function() { reloadHierarchy(); updateStatCards(); }, 200);
                    }
                } else {
                    showToast(data.message || 'Operation failed', 'error');
                }
            })
            .catch(function(error) {
                showToast(error.message || 'An error occurred.', 'error');
            });
        }

        function viewTask(id) {
            fetch('tasks.php?ajax=get_task&task_id=' + id)
                .then(function(response) { return parseJsonResponse(response); })
                .then(function(data) {
                    if (data.success) {
                        const task = data.data;
                        document.getElementById('viewTaskContent').innerHTML =
                            '<div style="display:grid;gap:16px;padding:8px 0;">' +
                            '<div><strong>Department:</strong> ' + (task.course_name || 'N/A') + '</div>' +
                            '<div><strong>Qualification:</strong> ' + (task.qualification_name || 'N/A') + '</div>' +
                            '<div><strong>Competency:</strong> ' + (task.competency_name || 'N/A') + '</div>' +
                            '<div><strong>Task:</strong> ' + (task.task_name || 'N/A') + '</div>' +
                            '</div>';
                        document.getElementById('viewTaskModal').classList.add('active');
                    }
                });
        }

        function editTask(id) {
            fetch('tasks.php?ajax=get_task&task_id=' + id)
                .then(function(response) { return parseJsonResponse(response); })
                .then(function(data) {
                    if (data.success) {
                        const task = data.data;
                        document.getElementById('edit_id').value = task.id;
                        document.getElementById('edit_task_name').value = task.task_name || '';
                        document.getElementById('edit_competency_name').value = (task.competency_name || '').toUpperCase();
                        document.getElementById('edit_competency_code').value = task.code_no || '';
                        document.getElementById('edit_competency_id_field').value = task.competency_id || '';

                        const courseSelect = document.getElementById('edit_course_select');
                        for (let i = 0; i < courseSelect.options.length; i++) {
                            if (courseSelect.options[i].value == task.course_id) { courseSelect.selectedIndex = i; break; }
                        }
                        loadEditQualifications();

                        setTimeout(function() {
                            const qualSelect = document.getElementById('edit_qualification_select');
                            for (let i = 0; i < qualSelect.options.length; i++) {
                                if (qualSelect.options[i].value == task.qualification_id) { qualSelect.selectedIndex = i; break; }
                            }
                        }, 100);

                        document.getElementById('editTaskModal').classList.add('active');
                    }
                });
        }

        window.onclick = function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                const modalId = e.target.id;
                if (modalId === 'editTaskModal') closeModalAndReset(modalId);
                else if (modalId === 'deleteTaskModal' || modalId === 'deleteAllModal') closeModal(modalId);
                else if (modalId === 'addTaskModal') closeAddTaskModal();
                else e.target.classList.remove('active');
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            updateStatCards();
            document.getElementById('globalCourseFilter').addEventListener('change', applyGlobalFilter);
            document.getElementById('globalUnitFilter').addEventListener('change', applyGlobalFilter);
            let timer;
            document.getElementById('globalSearchInput').addEventListener('input', function() {
                clearTimeout(timer);
                timer = setTimeout(applyGlobalFilter, 300);
            });

            document.querySelectorAll('.department-content').forEach(function(el) {
                el.classList.remove('expanded');
                el.style.display = 'none';
            });
            document.querySelectorAll('.qualification-content').forEach(function(el) {
                el.classList.remove('expanded');
                el.style.display = 'none';
            });
            document.querySelectorAll('.dept-toggle').forEach(function(el) {
                el.classList.remove('expanded');
                el.textContent = '▸';
            });
            document.querySelectorAll('.qual-toggle').forEach(function(el) {
                el.classList.remove('expanded');
                el.textContent = '▸';
            });

            setTimeout(initializePusher, 1000);
        });

        setInterval(updateStatCards, 30000);
    </script>
</body>
</html>