<?php


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ============================================================
// SESSION — must match login.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

try {
    require_once __DIR__ . '/../config/config.php';

    // Auth guard removed — dashboard loads freely
    $user_role       = $_SESSION['role'] ?? '';
    $user_department = $_SESSION['department'] ?? '';
    $user_id         = $_SESSION['user_id'] ?? 0;
    $db = $pdo;

    // ==========================================
    // DETERMINE USER SCOPE
    // ==========================================
    $is_coordinator = ($user_role === 'coordinator' || $user_role === 'admin');
    $is_trainer = ($user_role === 'trainer');

    // Get trainer qualification IDs if trainer
    $trainer_qualification_ids = [];
    if ($is_trainer && $user_id > 0) {
        try {
            $q_stmt = $db->prepare("
                SELECT qualification_id 
                FROM trainer_qualifications 
                WHERE user_id = ?
            ");
            $q_stmt->execute([$user_id]);
            $trainer_qualification_ids = $q_stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            try {
                $q_stmt = $db->prepare("
                    SELECT qualification_id 
                    FROM user_qualifications 
                    WHERE user_id = ?
                ");
                $q_stmt->execute([$user_id]);
                $trainer_qualification_ids = $q_stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e2) {
                $trainer_qualification_ids = [];
            }
        }
    }

    // ==========================================
    // 1. TOTAL TRAINEES - EXCLUDE SOFT-DELETED
    // ==========================================
    $trainee_query = "
        SELECT COUNT(*) 
        FROM trainees 
        WHERE deleted_at IS NULL
        AND (status IS NULL OR (status != 'deleted' AND status != 'Completed'))
    ";
    $trainee_params = [];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $trainee_query .= " AND qualification_id IN ($placeholders)";
        $trainee_params = $trainer_qualification_ids;
    } elseif ($is_trainer && empty($trainer_qualification_ids)) {
        $trainee_query .= " AND 1=0";
    }
    
    $stmt = $db->prepare($trainee_query);
    $stmt->execute($trainee_params);
    $total_trainees = (int) $stmt->fetchColumn();

    // ==========================================
    // 2. TOTAL TASKS - COUNT ALL TASKS
    // ==========================================
    $task_query = "
        SELECT COUNT(DISTINCT pt.id) as total
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
    ";
    $task_params = [];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $task_query .= " WHERE cs.qualification_id IN ($placeholders)";
        $task_params = $trainer_qualification_ids;
    } elseif ($is_trainer && empty($trainer_qualification_ids)) {
        $task_query .= " WHERE 1=0";
    }
    
    $stmt = $db->prepare($task_query);
    $stmt->execute($task_params);
    $total_tasks = (int) $stmt->fetchColumn();

    // ==========================================
    // 3. COMPLETED ALL TASKS (100% completion)
    // ==========================================
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
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $completed_query .= " AND t.qualification_id IN ($placeholders)";
        $completed_params = $trainer_qualification_ids;
    } elseif ($is_trainer && empty($trainer_qualification_ids)) {
        $completed_query .= " AND 1=0";
    }
    
    $completed_query .= "
        GROUP BY t.id
        HAVING 
            COUNT(DISTINCT pt.id) > 0
            AND COUNT(DISTINCT CASE 
                WHEN LOWER(et.remarks) IN ('complied', 'completed', 'done') 
                THEN pt.id 
                ELSE NULL 
            END) = COUNT(DISTINCT pt.id)
    ";
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM ($completed_query) as completed_trainees");
    $stmt->execute($completed_params);
    $completed_count = (int) $stmt->fetchColumn();

    // ==========================================
    // 4. NOT COMPLIED
    // ==========================================
    $not_complied_count = $total_trainees - $completed_count;

    // ==========================================
    // 5. ELIGIBLE FOR PRE-ASSESSMENT
    // ==========================================
    // Uses `pre_assessment` table as source of truth
    $eligible_query = "
        SELECT COUNT(DISTINCT t.id) as eligible_count
        FROM trainees t
        LEFT JOIN pre_assessment pa ON pa.trainee_id = t.id
        WHERE t.deleted_at IS NULL
        AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
        AND pa.id IS NOT NULL
    ";
    
    $eligible_params = [];
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $eligible_query .= " AND t.qualification_id IN ($placeholders)";
        $eligible_params = $trainer_qualification_ids;
    } elseif ($is_trainer && empty($trainer_qualification_ids)) {
        $eligible_query .= " AND 1=0";
    }
    
    $stmt = $db->prepare($eligible_query);
    $stmt->execute($eligible_params);
    $eligible_count = (int) $stmt->fetchColumn();
    
    $not_yet_eligible = $total_trainees - $eligible_count;

    // ==========================================
    // 6. RECENTLY COMPLETED TRAINEES
    // ==========================================
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
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $recent_query .= " AND t.qualification_id IN ($placeholders)";
        $recent_params = $trainer_qualification_ids;
    } elseif ($is_trainer && empty($trainer_qualification_ids)) {
        $recent_query .= " AND 1=0";
    }
    
    $recent_query .= "
        GROUP BY t.id, t.first_name, t.last_name, t.department
        HAVING 
            COUNT(DISTINCT pt.id) > 0
            AND COUNT(DISTINCT CASE 
                WHEN LOWER(et.remarks) IN ('complied', 'completed', 'done') 
                THEN pt.id 
                ELSE NULL 
            END) = COUNT(DISTINCT pt.id)
        ORDER BY completed_at DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($recent_query);
    $stmt->execute($recent_params);
    $recent_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 7. COMPLIANCE RATE
    // ==========================================
    $compliance_rate = $total_trainees > 0 ? round(($completed_count / $total_trainees) * 100) : 0;
    $completed_percent = $total_trainees > 0 ? round(($completed_count / $total_trainees) * 100) : 0;
    $not_complied_percent = $total_trainees > 0 ? round(($not_complied_count / $total_trainees) * 100) : 0;
    $overall_completion_percent = $total_trainees > 0 ? round(($completed_count / $total_trainees) * 100, 1) : 0;

    // ==========================================
    // 8. DEPARTMENT STATISTICS
    // FIXED: Uses `pre_assessment` table as source of truth
    // This matches the Pre-Assessment module logic exactly
    // ==========================================
    $dept_stats = [];
    
    if ($is_coordinator) {
        // ==========================================
        // COORDINATOR: GLOBAL SCOPE - sees all departments
        // ==========================================
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
        
        $stmt = $db->prepare($dept_query);
        $stmt->execute();
        $dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($is_trainer && !empty($trainer_qualification_ids)) {
        // ==========================================
        // TRAINER: Only their assigned department
        // ==========================================
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        
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
            $trainer_qualification_ids,
            $trainer_qualification_ids
        );
        
        $stmt = $db->prepare($dept_query);
        $stmt->execute($dept_params);
        $dept_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==========================================
    // 9. RECENT ACTIVITIES
    // ==========================================
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

    // ==========================================
    // 10. COMPLETED TODAY
    // ==========================================
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
    
    if ($is_trainer && !empty($trainer_qualification_ids)) {
        $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
        $today_query .= " AND t.qualification_id IN ($placeholders)";
        $today_params = $trainer_qualification_ids;
    } elseif ($is_trainer && empty($trainer_qualification_ids)) {
        $today_query .= " AND 1=0";
    }
    
    $stmt = $db->prepare($today_query);
    $stmt->execute($today_params);
    $completed_today = (int) $stmt->fetchColumn();

    // ==========================================
    // 11. AUTO-DETECT CHANGES & TRIGGER PUSHER
    // ==========================================
    try {
        // Build fingerprint with ALL data including eligible_count and dept_stats
        $current_fingerprint = [
            'total_trainees' => $total_trainees,
            'total_tasks' => $total_tasks,
            'completed_count' => $completed_count,
            'not_complied_count' => $not_complied_count,
            'eligible_count' => $eligible_count,
            'dept_stats' => array_map(function($d) {
                return [
                    'code' => $d['dept_code'] ?? $d['course_code'] ?? '',
                    'total' => (int)($d['total_trainees'] ?? 0),
                    'completed' => (int)($d['students_completed'] ?? 0),
                    'not_complied' => (int)($d['students_not_complied'] ?? 0),
                ];
            }, $dept_stats)
        ];
        
        $fingerprint_hash = md5(json_encode($current_fingerprint));
        
        $last_fingerprint = $_SESSION['dashboard_fingerprint'] ?? null;
        $last_check_time = $_SESSION['dashboard_last_check'] ?? 0;
        $now = time();
        
        if ($last_fingerprint !== null 
            && $last_fingerprint !== $fingerprint_hash 
            && ($now - $last_check_time) >= 2
        ) {
            // Data changed! Trigger Pusher event to notify other browsers
            require_once __DIR__ . '/../helpers/helpers/PusherHelper.php';
            
            $trigger_payload = [
                'event_type' => 'dashboard_updated',
                'triggered_by' => $user_id,
                'scope' => $is_coordinator ? 'global' : 'trainer',
                'summary' => [
                    'trainees' => $total_trainees,
                    'tasks' => $total_tasks,
                    'completed' => $completed_count,
                    'not_complied' => $not_complied_count,
                    'eligible' => $eligible_count
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            triggerPusherUpdate('dashboard_updated', $trigger_payload, 'compliance-channel');
            
            $_SESSION['dashboard_fingerprint'] = $fingerprint_hash;
            $_SESSION['dashboard_last_check'] = $now;
        } else if ($last_fingerprint === null) {
            // First load - just save fingerprint without triggering
            $_SESSION['dashboard_fingerprint'] = $fingerprint_hash;
            $_SESSION['dashboard_last_check'] = $now;
        }
    } catch (Exception $e) {
        error_log("Pusher auto-trigger failed: " . $e->getMessage());
    }

    // ==========================================
    // RETURN JSON RESPONSE
    // ==========================================
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'scope' => $is_coordinator ? 'global' : 'trainer',
        'data' => [
            // Main stats
            'total_trainees' => $total_trainees,
            'total_tasks' => $total_tasks,
            'total_students_completed' => $completed_count,
            'students_with_not_complied' => $not_complied_count,
            
            // Additional stats
            'completed_count' => $completed_count,
            'not_complied_count' => $not_complied_count,
            'eligible_count' => $eligible_count,
            'not_yet_eligible' => $not_yet_eligible,
            'compliance_rate' => $compliance_rate,
            'students_completed_today' => $completed_today,
            'eligible_for_preassessment' => $eligible_count,
            
            // Percentages
            'completed_percent' => $completed_percent,
            'not_complied_percent' => $not_complied_percent,
            'overall_completion_percent' => $overall_completion_percent,
            
            // Lists
            'recent_completed' => $recent_completed,
            'recent_eligible' => $recent_completed,
            'recent_activities' => $recent_activities,
            
            // Department stats (uses pre_assessment table now)
            'departments' => $dept_stats
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in get_dashboard_stats: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}