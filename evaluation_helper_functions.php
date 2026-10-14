<?php
// evaluation_helper_functions.php - Common functions for evaluation module
// FIXED: Complete status standardization with proper database mapping

// ============================================================
// STATUS STANDARDIZATION FUNCTIONS
// ============================================================

/**
 * Standardize evaluation status to database format
 * Converts various input formats to unified database status
 * 
 * @param string $status Input status from form/UI
 * @return string Standardized status for database
 */
function standardizeEvaluationStatus($status) {
    $status = trim(strtolower($status ?? ''));
    
    // Map all variations to standardized database values
    $statusMap = [
        // COMPLIED variations -> 'complied'
        'complied' => 'complied',
        'compliant' => 'complied',
        'done' => 'complied',
        'complete' => 'complied',
        'completed' => 'complied',
        'pass' => 'complied',
        'passed' => 'complied',
        'yes' => 'complied',
        'true' => 'complied',
        '1' => 'complied',
        'ok' => 'complied',
        'good' => 'complied',
        'satisfactory' => 'complied',
        'ready' => 'complied',
        'available' => 'complied',
        'approved' => 'complied',
        
        // NOT COMPLIED variations -> 'not_complied' (with underscore)
        'not_complied' => 'not_complied',
        'not complied' => 'not_complied',
        'notcomplied' => 'not_complied',
        'not-complied' => 'not_complied',
        'noncomplied' => 'not_complied',
        'non_complied' => 'not_complied',
        'non complied' => 'not_complied',
        'not done' => 'not_complied',
        'notdone' => 'not_complied',
        'incomplete' => 'not_complied',
        'pending' => 'not_complied',
        'fail' => 'not_complied',
        'failed' => 'not_complied',
        'no' => 'not_complied',
        'false' => 'not_complied',
        '0' => 'not_complied',
        'not okay' => 'not_complied',
        'unsatisfactory' => 'not_complied',
        'not passed' => 'not_complied',
        'not ok' => 'not_complied',
        'not ready' => 'not_complied',
        'unavailable' => 'not_complied',
        'rejected' => 'not_complied',
        
        // Default
        '' => 'not_complied',
        'null' => 'not_complied',
        'none' => 'not_complied'
    ];
    
    return $statusMap[$status] ?? 'not_complied';
}

/**
 * Standardize remarks values for database
 * Returns 'Done' or 'Not Done' consistently
 */
function standardizeRemarks($remarks) {
    $remarks = trim(strtolower($remarks ?? ''));
    
    $doneValues = ['done', 'completed', 'complied', 'pass', 'passed', 'yes', 'true', '1', 'ok', 'good', 'satisfactory', 'approved'];
    $notDoneValues = ['not done', 'notdone', 'incomplete', 'pending', 'fail', 'failed', 'no', 'false', '0', 'not okay', 'unsatisfactory', 'not passed', 'rejected', 'not ready'];
    
    if (in_array($remarks, $doneValues)) {
        return 'Done';
    }
    
    if (in_array($remarks, $notDoneValues)) {
        return 'Not Done';
    }
    
    if ($remarks === 'complied') {
        return 'Done';
    }
    
    if ($remarks === 'not_complied') {
        return 'Not Done';
    }
    
    return $remarks ? ucfirst($remarks) : 'Not Done';
}

/**
 * Get standardized display status for UI
 */
function getDisplayStatus($status) {
    $status = trim(strtolower($status ?? ''));
    
    if ($status === 'complied') {
        return 'Done';
    }
    
    if ($status === 'not_complied') {
        return 'Not Done';
    }
    
    if (in_array($status, ['done', 'complete', 'completed', 'pass', 'passed'])) {
        return 'Done';
    }
    
    if (in_array($status, ['incomplete', 'pending', 'fail', 'failed'])) {
        return 'Not Done';
    }
    
    return ucfirst($status);
}

// ============================================================
// SAVE EVALUATION - COMPLETE FIX WITH PROPER INSERT/UPDATE
// ============================================================

/**
 * Save or update evaluation record with proper status standardization
 */
function saveEvaluation($db, $trainee_id, $task_id, $status, $remarks = null, $evaluated_by = null) {
    try {
        error_log("saveEvaluation called: trainee_id=$trainee_id, task_id=$task_id, status=$status, remarks=$remarks, evaluated_by=$evaluated_by");
        
        $standardizedStatus = standardizeEvaluationStatus($status);
        $standardizedRemarks = standardizeRemarks($remarks ?? $status);
        
        if (!$trainee_id || !$task_id) {
            error_log("saveEvaluation: Missing trainee_id or task_id");
            return false;
        }
        
        $task_check = $db->prepare("SELECT id, task_name FROM performance_tasks WHERE id = ?");
        $task_check->execute([$task_id]);
        $task = $task_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$task) {
            error_log("saveEvaluation: Task ID $task_id not found");
            return false;
        }
        
        $trainee_check = $db->prepare("SELECT id FROM trainees WHERE id = ?");
        $trainee_check->execute([$trainee_id]);
        if (!$trainee_check->fetch()) {
            error_log("saveEvaluation: Trainee ID $trainee_id not found");
            return false;
        }
        
        $check = $db->prepare("
            SELECT id, remarks, status 
            FROM evaluation_tracker 
            WHERE trainee_id = ? AND task_id = ?
        ");
        $check->execute([$trainee_id, $task_id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $update = $db->prepare("
                UPDATE evaluation_tracker 
                SET status = ?, 
                    remarks = ?,
                    evaluated_by = ?,
                    evaluated_at = CASE WHEN ? = 'complied' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $result = $update->execute([
                $standardizedStatus,
                $standardizedRemarks,
                $evaluated_by,
                $standardizedStatus,
                $existing['id']
            ]);
            
            if ($result) {
                error_log("saveEvaluation: Updated record ID {$existing['id']} - status: $standardizedStatus");
                return $existing['id'];
            }
            return false;
        } else {
            $evaluated_at = ($standardizedStatus === 'complied') ? date('Y-m-d H:i:s') : null;
            
            // ✅ FIXED: activity_title -> performance_task
            $insert = $db->prepare("
                INSERT INTO evaluation_tracker 
                (trainee_id, task_id, performance_task, remarks, status, evaluated_by, evaluated_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $result = $insert->execute([
                $trainee_id,
                $task_id,
                $task['task_name'],
                $standardizedRemarks,
                $standardizedStatus,
                $evaluated_by,
                $evaluated_at
            ]);
            
            if ($result) {
                $new_id = $db->lastInsertId();
                error_log("saveEvaluation: INSERT new record ID $new_id");
                return $new_id;
            }
            return false;
        }
        
    } catch (PDOException $e) {
        error_log("Error in saveEvaluation: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// MARK TASK DONE - FIXED
// ============================================================

function markTaskDone($db, $tracker_id, $user_id) {
    try {
        if ($tracker_id > 0) {
            $stmt = $db->prepare("
                UPDATE evaluation_tracker 
                SET status = 'complied', 
                    remarks = 'Done', 
                    evaluated_by = ?, 
                    evaluated_at = NOW(), 
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $tracker_id]);
            
            error_log("markTaskDone: Updated tracker_id $tracker_id to 'complied'");
            return ['success' => true, 'tracker_id' => $tracker_id, 'message' => 'Task marked as done'];
        } else {
            error_log("markTaskDone: No tracker_id provided");
            return false;
        }
    } catch (PDOException $e) {
        error_log("Error in markTaskDone: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// RESET TASK - FIXED
// ============================================================

function resetTask($db, $tracker_id, $user_id) {
    try {
        $stmt = $db->prepare("
            UPDATE evaluation_tracker 
            SET status = 'not_complied', 
                remarks = 'Not Done', 
                evaluated_by = NULL, 
                evaluated_at = NULL, 
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$tracker_id]);
        
        error_log("resetTask: Reset tracker_id $tracker_id to 'not_complied'");
        return ['success' => true, 'tracker_id' => $tracker_id, 'message' => 'Task reset'];
    } catch (PDOException $e) {
        error_log("Error in resetTask: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// CREATE AND MARK TASK DONE - FIXED
// ============================================================

function createAndMarkTaskDone($db, $trainee_id, $task_id, $user_id) {
    try {
        error_log("createAndMarkTaskDone: trainee_id=$trainee_id, task_id=$task_id");
        
        $result = saveEvaluation($db, $trainee_id, $task_id, 'complied', 'Done', $user_id);
        
        if ($result) {
            return ['success' => true, 'tracker_id' => $result, 'message' => 'Task marked as done'];
        }
        return false;
    } catch (Exception $e) {
        error_log("Error in createAndMarkTaskDone: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// ENSURE TRAINEE TASKS
// ============================================================

function ensureTraineeTasks($db, $trainee_id) {
    try {
        $stmt = $db->prepare("SELECT qualification_id FROM trainees WHERE id = ?");
        $stmt->execute([$trainee_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $qualification_id = $result['qualification_id'] ?? 0;
        
        if (!$qualification_id) {
            error_log("ensureTraineeTasks: Missing qualification_id for trainee $trainee_id");
            return 0;
        }
        
        $task_stmt = $db->prepare("
            SELECT pt.id, pt.task_name
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            WHERE cs.qualification_id = ?
        ");
        $task_stmt->execute([$qualification_id]);
        $tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($tasks)) {
            return 0;
        }
        
        $assigned_count = 0;
        foreach ($tasks as $task) {
            $check = $db->prepare("SELECT id FROM evaluation_tracker WHERE trainee_id = ? AND task_id = ?");
            $check->execute([$trainee_id, $task['id']]);
            
            if ($check->rowCount() == 0) {
                // ✅ FIXED: activity_title -> performance_task
                $insert = $db->prepare("
                    INSERT INTO evaluation_tracker 
                    (trainee_id, task_id, performance_task, remarks, status, created_at, updated_at)
                    VALUES (?, ?, ?, 'Not Done', 'not_complied', NOW(), NOW())
                ");
                $insert->execute([$trainee_id, $task['id'], $task['task_name']]);
                $assigned_count++;
            }
        }
        
        return $assigned_count;
    } catch (PDOException $e) {
        error_log("Error ensuring trainee tasks: " . $e->getMessage());
        return 0;
    }
}

// ============================================================
// GET NON-COMPLIANT COUNT
// ============================================================

function getNonCompliantCount($db, $department_filter = null, $qualification_filter = null) {
    try {
        $sql = "
            SELECT COUNT(DISTINCT et.trainee_id) as total
            FROM evaluation_tracker et
            LEFT JOIN trainees t ON et.trainee_id = t.id
            WHERE 1=1
        ";
        
        $params = [];
        $sql .= " AND (LOWER(et.status) = 'not_complied' OR LOWER(et.remarks) != 'done')";
        
        if ($department_filter) {
            $sql .= " AND t.department = ?";
            $params[] = $department_filter;
        }
        
        if ($qualification_filter) {
            $sql .= " AND t.qualification_id = ?";
            $params[] = $qualification_filter;
        }
        
        $sql .= " AND EXISTS (SELECT 1 FROM evaluation_tracker et2 WHERE et2.trainee_id = t.id)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['total'] ?? 0);
    } catch (PDOException $e) {
        error_log("Error getting non-compliant count: " . $e->getMessage());
        return 0;
    }
}

// ============================================================
// GET TASK STATUS
// ============================================================

function getTaskStatus($db, $trainee_id, $task_id) {
    try {
        $stmt = $db->prepare("
            SELECT id, status, remarks, evaluated_by, evaluated_at 
            FROM evaluation_tracker 
            WHERE trainee_id = ? AND task_id = ?
        ");
        $stmt->execute([$trainee_id, $task_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting task status: " . $e->getMessage());
        return null;
    }
}
?>