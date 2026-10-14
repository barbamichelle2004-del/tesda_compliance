<?php
// api/mark-all-done.php - Dedicated endpoint for Mark All Done
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/EligibilityService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$user = getCurrentUser();
$db = $pdo;
$eligibilityService = new EligibilityService($db);

// Function to mark a single task as done
function markTaskDone($db, $trainee_id, $task_id, $evaluator_id = null) {
    try {
        $evaluation_date = date('Y-m-d H:i:s');
        
        // Get trainee department/course info
        $trainee_info = $db->prepare("
            SELECT t.*, b.batch_name, c.course_name, c.course_code, c.id as course_id
            FROM trainees t
            LEFT JOIN batches b ON t.batch_id = b.id
            LEFT JOIN courses c ON b.course_id = c.id
            WHERE t.id = ?
        ");
        $trainee_info->execute([$trainee_id]);
        $trainee = $trainee_info->fetch(PDO::FETCH_ASSOC);
        
        $department = $trainee['course_code'] ?? $trainee['course_name'] ?? 'General';
        $section = $trainee['section'] ?? 'A';
        
        // Get task info
        $task_info = $db->prepare("SELECT * FROM performance_tasks WHERE id = ?");
        $task_info->execute([$task_id]);
        $task = $task_info->fetch(PDO::FETCH_ASSOC);
        
        // Start transaction
        $db->beginTransaction();
        
        // 1. Update evaluation_tracker
        $updateTracker = $db->prepare("
            UPDATE evaluation_tracker 
            SET remarks = 'Done', 
                status = 'complied',
                completed_at = ?,
                evaluated_by = ?,
                evaluated_at = ?
            WHERE trainee_id = ? AND task_id = ?
        ");
        $updateTracker->execute([$evaluation_date, $evaluator_id, $evaluation_date, $trainee_id, $task_id]);
        
        // 2. Sync to task_evaluations table
        $checkEval = $db->prepare("SELECT id FROM task_evaluations WHERE trainee_id = ? AND task_id = ?");
        $checkEval->execute([$trainee_id, $task_id]);
        if ($checkEval->rowCount() > 0) {
            $update = $db->prepare("
                UPDATE task_evaluations
                SET status = 'Complied', 
                    evaluation_date = ?,
                    trainer_id = ?
                WHERE trainee_id = ? AND task_id = ?
            ");
            $update->execute([$evaluation_date, $evaluator_id, $trainee_id, $task_id]);
        } else {
            $insert = $db->prepare("
                INSERT INTO task_evaluations (trainee_id, task_id, status, evaluation_date, trainer_id)
                VALUES (?, ?, 'Complied', ?, ?)
            ");
            $insert->execute([$trainee_id, $task_id, $evaluation_date, $evaluator_id]);
        }
        
        // 3. Sync to trainee_task_records table
        $checkRecord = $db->prepare("SELECT id FROM trainee_task_records WHERE trainee_id = ? AND task_id = ?");
        $checkRecord->execute([$trainee_id, $task_id]);
        if ($checkRecord->rowCount() > 0) {
            $update = $db->prepare("
                UPDATE trainee_task_records
                SET status = 'Complied',
                    evaluation_date = ?,
                    updated_at = NOW()
                WHERE trainee_id = ? AND task_id = ?
            ");
            $update->execute([$evaluation_date, $trainee_id, $task_id]);
        } else {
            $insert = $db->prepare("
                INSERT INTO trainee_task_records
                (trainee_id, task_id, department, section, task_type, status, evaluation_date, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'Complied', ?, NOW(), NOW())
            ");
            $task_type = $task['task_type'] ?? 'Regular';
            $insert->execute([$trainee_id, $task_id, $department, $section, $task_type, $evaluation_date]);
        }
        
        // 4. Insert into evaluation_history
        $getEvalId = $db->prepare("SELECT id FROM task_evaluations WHERE trainee_id = ? AND task_id = ?");
        $getEvalId->execute([$trainee_id, $task_id]);
        $evaluation_id = $getEvalId->fetchColumn();
        
        if ($evaluation_id) {
            $history = $db->prepare("
                INSERT INTO evaluation_history
                (evaluation_id, previous_status, new_status, changed_by, change_reason, changed_at)
                VALUES (?, 'Non-Complied', 'Complied', ?, 'Marked as done', NOW())
            ");
            $history->execute([$evaluation_id, $evaluator_id]);
        }
        
        // Commit transaction
        $db->commit();
        
        error_log("Task $task_id marked done for trainee $trainee_id");
        return true;
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error marking task done: " . $e->getMessage());
        return false;
    }
}

// Function to check and update eligibility
function checkAndUpdateEligibility($db, $trainee_id, $eligibilityService) {
    try {
        // Get trainee's course_id
        $stmt = $db->prepare("
            SELECT b.course_id, c.course_name
            FROM trainees t
            JOIN batches b ON t.batch_id = b.id
            JOIN courses c ON b.course_id = c.id
            WHERE t.id = ?
        ");
        $stmt->execute([$trainee_id]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        $course_id = $course['course_id'] ?? 0;
        
        if (!$course_id) {
            return ['is_eligible' => false, 'total_tasks' => 0, 'completed_tasks' => 0];
        }
        
        // Get total tasks for this course
        $total_stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM performance_tasks 
            WHERE course_id = ?
        ");
        $total_stmt->execute([$course_id]);
        $total_tasks = $total_stmt->fetchColumn();
        
        // Get completed tasks for this trainee
        $completed_stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM evaluation_tracker 
            WHERE trainee_id = ? AND remarks = 'Done'
        ");
        $completed_stmt->execute([$trainee_id]);
        $completed_tasks = $completed_stmt->fetchColumn();
        
        // Check if all tasks are completed
        $is_eligible = ($completed_tasks >= $total_tasks && $total_tasks > 0);
        
        // Update eligibility in database
        $checkElig = $db->prepare("SELECT id FROM evaluation_eligibility WHERE trainee_id = ?");
        $checkElig->execute([$trainee_id]);
        
        if ($checkElig->rowCount() > 0) {
            $update = $db->prepare("
                UPDATE evaluation_eligibility 
                SET is_eligible = ?, 
                    total_tasks = ?, 
                    completed_tasks = ?,
                    eligibility_date = CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                    checked_at = NOW()
                WHERE trainee_id = ?
            ");
            $update->execute([$is_eligible ? 1 : 0, $total_tasks, $completed_tasks, $is_eligible ? 1 : 0, $trainee_id]);
        } else {
            $insert = $db->prepare("
                INSERT INTO evaluation_eligibility 
                (trainee_id, is_eligible, total_tasks, completed_tasks, eligibility_date, checked_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $insert->execute([$trainee_id, $is_eligible ? 1 : 0, $total_tasks, $completed_tasks, $is_eligible ? date('Y-m-d H:i:s') : null]);
        }
        
        return [
            'is_eligible' => $is_eligible,
            'total_tasks' => (int)$total_tasks,
            'completed_tasks' => (int)$completed_tasks,
            'course_name' => $course['course_name'] ?? ''
        ];
        
    } catch (PDOException $e) {
        error_log("Error checking eligibility: " . $e->getMessage());
        return ['is_eligible' => false, 'total_tasks' => 0, 'completed_tasks' => 0];
    }
}

// Main handler
try {
    $trainee_id = isset($_POST['trainee_id']) ? (int)$_POST['trainee_id'] : 0;
    
    if (!$trainee_id) {
        throw new Exception('Trainee ID is required');
    }
    
    // Get all incomplete tasks for this trainee
    $task_stmt = $db->prepare("
        SELECT id, task_id 
        FROM evaluation_tracker 
        WHERE trainee_id = ? AND remarks = 'Not Done'
    ");
    $task_stmt->execute([$trainee_id]);
    $incomplete_tasks = $task_stmt->fetchAll();
    
    $affected_rows = 0;
    $errors = [];
    
    if (!empty($incomplete_tasks)) {
        foreach ($incomplete_tasks as $task) {
            if (markTaskDone($db, $trainee_id, $task['task_id'], $user['id'])) {
                $affected_rows++;
            } else {
                $errors[] = "Failed to update task ID: {$task['task_id']}";
            }
        }
    }
    
    // Always check eligibility after marking tasks
    $eligibility = checkAndUpdateEligibility($db, $trainee_id, $eligibilityService);
    
    if ($affected_rows > 0 || empty($incomplete_tasks)) {
        echo json_encode([
            'success' => true,
            'message' => $affected_rows > 0 ? "Successfully marked {$affected_rows} task(s) as done" : "No incomplete tasks found for this trainee",
            'affected_rows' => $affected_rows,
            'errors' => $errors,
            'eligibility' => $eligibility
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Failed to mark tasks as done",
            'affected_rows' => 0,
            'eligibility' => $eligibility
        ]);
    }
    
} catch (Exception $e) {
    error_log("Mark All Done Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>