<?php
// helpers/WebSocketHelper.php - Centralized WebSocket trigger helper
// UPDATED: Complete implementation with event triggers for all dashboard updates

class WebSocketHelper {
    private static $instance = null;
    private $socketHost;
    private $socketPort;
    private $useSSL;
    private $socketPath;
    
    private function __construct() {
        $this->socketHost = defined('WEBSOCKET_HOST') ? WEBSOCKET_HOST : 'localhost';
        $this->socketPort = defined('WEBSOCKET_PORT') ? WEBSOCKET_PORT : 3000;
        $this->useSSL = defined('WEBSOCKET_SSL') ? WEBSOCKET_SSL : false;
        $this->socketPath = defined('WEBSOCKET_PATH') ? WEBSOCKET_PATH : '/api/trigger';
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Main trigger function for WebSocket events
     * 
     * @param string $eventType The type of event (e.g., 'evaluation_updated', 'trainee_added')
     * @param array $payload Additional data to send
     * @return bool True on success, false on failure
     */
    public function triggerDashboardSync($eventType, $payload = []) {
        try {
            // Build the data payload
            $data = [
                'event_type' => $eventType,
                'payload' => $payload,
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => $_SESSION['user_id'] ?? 'system',
                'role' => $_SESSION['role'] ?? 'unknown',
                'department' => $_SESSION['department'] ?? 'unknown',
                'qualification_ids' => $_SESSION['qualification_ids'] ?? []
            ];
            
            // Build the URL
            $protocol = $this->useSSL ? 'https' : 'http';
            $url = "{$protocol}://{$this->socketHost}:{$this->socketPort}{$this->socketPath}";
            
            // Send via cURL to Node.js WebSocket server
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_FAILONERROR, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Log the response for debugging
            if ($httpCode === 200) {
                error_log("WebSocket trigger successful: {$eventType}");
            } else if ($error) {
                error_log("WebSocket trigger error: " . $error);
            } else {
                error_log("WebSocket trigger HTTP: {$httpCode} - Response: " . substr($response, 0, 200));
            }
            
            curl_close($ch);
            
            // Return true even if connection fails to prevent disrupting the main flow
            return true;
            
        } catch (Exception $e) {
            error_log("WebSocket trigger exception: " . $e->getMessage());
            // Return true to not disrupt the main flow
            return true;
        }
    }
    
    // ==========================================
    // HELPER METHODS FOR SPECIFIC EVENTS
    // ==========================================
    
    /**
     * Trigger evaluation update event
     */
    public function triggerEvaluationUpdate($traineeId, $taskId, $status, $remarks = '', $department = '') {
        return $this->triggerDashboardSync('evaluation_updated', [
            'trainee_id' => $traineeId,
            'task_id' => $taskId,
            'status' => $status,
            'remarks' => $remarks,
            'department' => $department,
            'evaluated_by' => $_SESSION['user_id'] ?? null
        ]);
    }
    
    /**
     * Trigger task completion event (all tasks done)
     */
    public function triggerTaskCompletion($traineeId, $traineeName, $completedCount, $totalCount) {
        return $this->triggerDashboardSync('task_completed', [
            'trainee_id' => $traineeId,
            'trainee_name' => $traineeName,
            'completed_tasks' => $completedCount,
            'total_tasks' => $totalCount,
            'completion_percent' => $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0
        ]);
    }
    
    /**
     * Trigger trainee added event
     */
    public function triggerTraineeAdded($traineeId, $traineeName, $department = '') {
        return $this->triggerDashboardSync('trainee_added', [
            'trainee_id' => $traineeId,
            'trainee_name' => $traineeName,
            'department' => $department
        ]);
    }
    
    /**
     * Trigger trainee updated event
     */
    public function triggerTraineeUpdated($traineeId, $traineeName, $department = '') {
        return $this->triggerDashboardSync('trainee_updated', [
            'trainee_id' => $traineeId,
            'trainee_name' => $traineeName,
            'department' => $department
        ]);
    }
    
    /**
     * Trigger trainee deleted event
     */
    public function triggerTraineeDeleted($traineeId, $traineeName) {
        return $this->triggerDashboardSync('trainee_deleted', [
            'trainee_id' => $traineeId,
            'trainee_name' => $traineeName
        ]);
    }
    
    /**
     * Trigger pre-assessment ready event
     */
    public function triggerPreAssessmentReady($traineeId, $traineeName, $department = '') {
        return $this->triggerDashboardSync('pre_assessment_ready', [
            'trainee_id' => $traineeId,
            'trainee_name' => $traineeName,
            'department' => $department
        ]);
    }
    
    /**
     * Trigger notification sent event
     */
    public function triggerNotificationSent($traineeId, $traineeName, $type = 'email') {
        return $this->triggerDashboardSync('notification_sent', [
            'trainee_id' => $traineeId,
            'trainee_name' => $traineeName,
            'notification_type' => $type
        ]);
    }
    
    /**
     * Trigger department stats update event
     */
    public function triggerDepartmentStatsUpdate($departmentCode, $data = []) {
        return $this->triggerDashboardSync('department_stats_updated', [
            'department' => $departmentCode,
            'data' => $data
        ]);
    }
    
    /**
     * Trigger new activity item (for Recent System Activity widget)
     */
    public function triggerNewActivity($label, $description, $link = '#', $icon = 'fa-bell', $iconColor = '#2563eb', $iconBg = '#dbeafe') {
        return $this->triggerDashboardSync('new_activity', [
            'label' => $label,
            'description' => $description,
            'link' => $link,
            'icon' => $icon,
            'icon_color' => $iconColor,
            'icon_bg' => $iconBg,
            'timestamp' => date('h:i A')
        ]);
    }
    
    /**
     * Trigger a full dashboard data refresh event
     */
    public function triggerFullDashboardRefresh() {
        return $this->triggerDashboardSync('dashboard_refresh', [
            'force_refresh' => true
        ]);
    }
}

// ==========================================
// GLOBAL HELPER FUNCTIONS
// ==========================================

/**
 * Global helper function - Main trigger
 */
function trigger_dashboard_sync($eventType, $payload = []) {
    return WebSocketHelper::getInstance()->triggerDashboardSync($eventType, $payload);
}

/**
 * Evaluation update helper
 */
function trigger_evaluation_update($traineeId, $taskId, $status, $remarks = '', $department = '') {
    return WebSocketHelper::getInstance()->triggerEvaluationUpdate($traineeId, $taskId, $status, $remarks, $department);
}

/**
 * Task completion helper
 */
function trigger_task_completion($traineeId, $traineeName, $completedCount, $totalCount) {
    return WebSocketHelper::getInstance()->triggerTaskCompletion($traineeId, $traineeName, $completedCount, $totalCount);
}

/**
 * Trainee added helper
 */
function trigger_trainee_added($traineeId, $traineeName, $department = '') {
    return WebSocketHelper::getInstance()->triggerTraineeAdded($traineeId, $traineeName, $department);
}

/**
 * Trainee updated helper
 */
function trigger_trainee_updated($traineeId, $traineeName, $department = '') {
    return WebSocketHelper::getInstance()->triggerTraineeUpdated($traineeId, $traineeName, $department);
}

/**
 * Trainee deleted helper
 */
function trigger_trainee_deleted($traineeId, $traineeName) {
    return WebSocketHelper::getInstance()->triggerTraineeDeleted($traineeId, $traineeName);
}

/**
 * Pre-Assessment ready helper
 */
function trigger_pre_assessment_ready($traineeId, $traineeName, $department = '') {
    return WebSocketHelper::getInstance()->triggerPreAssessmentReady($traineeId, $traineeName, $department);
}

/**
 * Notification sent helper
 */
function trigger_notification_sent($traineeId, $traineeName, $type = 'email') {
    return WebSocketHelper::getInstance()->triggerNotificationSent($traineeId, $traineeName, $type);
}

/**
 * Department stats update helper
 */
function trigger_department_stats_update($departmentCode, $data = []) {
    return WebSocketHelper::getInstance()->triggerDepartmentStatsUpdate($departmentCode, $data);
}

/**
 * New activity helper - Add item to Recent System Activity
 */
function trigger_new_activity($label, $description, $link = '#', $icon = 'fa-bell', $iconColor = '#2563eb', $iconBg = '#dbeafe') {
    return WebSocketHelper::getInstance()->triggerNewActivity($label, $description, $link, $icon, $iconColor, $iconBg);
}

/**
 * Full dashboard refresh helper
 */
function trigger_full_dashboard_refresh() {
    return WebSocketHelper::getInstance()->triggerFullDashboardRefresh();
}

// ==========================================
// CONVENIENCE FUNCTIONS FOR COMMON SCENARIOS
// ==========================================

/**
 * Quick helper for evaluation marking
 */
function mark_task_completed_with_websocket($pdo, $traineeId, $taskId, $userId, $remarks = 'Done') {
    try {
        // Update the evaluation record
        $stmt = $pdo->prepare("
            UPDATE evaluation_tracker 
            SET remarks = ?, 
                status = 'complied', 
                evaluated_by = ?, 
                evaluated_at = NOW(), 
                updated_at = NOW() 
            WHERE trainee_id = ? AND task_id = ?
        ");
        $stmt->execute([$remarks, $userId, $traineeId, $taskId]);
        
        // Get trainee name
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM trainees WHERE id = ?");
        $stmt->execute([$traineeId]);
        $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
        $traineeName = ($trainee['first_name'] ?? '') . ' ' . ($trainee['last_name'] ?? '');
        
        // Trigger WebSocket update
        trigger_evaluation_update($traineeId, $taskId, 'completed', $remarks);
        trigger_new_activity(
            'Task Completed',
            "{$traineeName} completed a task",
            "evaluation.php?trainee_id={$traineeId}",
            'fa-check-circle',
            '#10b981',
            '#d1fae5'
        );
        trigger_dashboard_sync('dashboard_refresh', ['force' => true]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error in mark_task_completed_with_websocket: " . $e->getMessage());
        return false;
    }
}

/**
 * Quick helper for trainee addition
 */
function add_trainee_with_websocket($pdo, $data) {
    try {
        // Insert trainee
        $stmt = $pdo->prepare("
            INSERT INTO trainees 
            (first_name, last_name, middle_initial, suffix, year_level, section, email, contact, department, sex, qualification_id, batch_code) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['middle_initial'] ?? '',
            $data['suffix'] ?? '',
            $data['year_level'] ?? '1st Year',
            $data['section'] ?? 'A',
            $data['email'] ?? '',
            $data['contact'] ?? '',
            $data['department'] ?? '',
            $data['sex'] ?? 'Male',
            $data['qualification_id'] ?? null,
            $data['batch_code'] ?? ''
        ]);
        
        $traineeId = $pdo->lastInsertId();
        $traineeName = $data['first_name'] . ' ' . $data['last_name'];
        
        // Trigger WebSocket update
        trigger_trainee_added($traineeId, $traineeName, $data['department'] ?? '');
        trigger_new_activity(
            'New Trainee Added',
            "{$traineeName} was added to the system",
            "trainees.php?view={$traineeId}",
            'fa-user-plus',
            '#059669',
            '#d1fae5'
        );
        trigger_dashboard_sync('dashboard_refresh', ['force' => true]);
        
        return $traineeId;
    } catch (PDOException $e) {
        error_log("Error in add_trainee_with_websocket: " . $e->getMessage());
        return false;
    }
}